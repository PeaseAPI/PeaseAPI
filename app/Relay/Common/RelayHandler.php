<?php

declare(strict_types=1);

namespace App\Relay\Common;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Relay\Channel\AnthropicNative\AnthropicNativeAdapter;
use App\Relay\Channel\AWS\AWSAdapter;
use App\Relay\Channel\ChannelAdapterInterface;
use App\Relay\Channel\Claude\ClaudeAdapter;
use App\Relay\Channel\Gemini\GeminiAdapter;
use App\Relay\Channel\OpenAI\OpenAIAdapter;
use App\Relay\Channel\Vertex\VertexAdapter;
use App\Relay\Constant\RelayMode;
use App\Relay\Constant\RelayProtocol;
use App\Services\BillingService;
use App\Services\ChannelSelectService;
use App\Services\CostRouteService;
use App\Services\LogService;
use App\Setting\OperationSetting\ModelRouteSetting;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Relay 核心处理器
 * 对标 new-api relay/common/relay_handler.go
 */
class RelayHandler
{
    protected ?RelayInfo $info = null;

    protected ?ChannelAdapterInterface $adapter = null;

    /**
     * 渠道由控制器直接赋值而未走 setChannel 时，补齐 base_url / api_key / apiType 等
     */
    protected function ensureChannelInitialized(): void
    {
        if ($this->info !== null
            && $this->info->channel instanceof Channel
            && $this->info->channelId === 0) {
            $this->info->setChannel($this->info->channel);
        }
    }

    public function __construct(?RelayInfo $info = null)
    {
        $this->info = $info;
    }

    public static function make(RelayInfo $info): self
    {
        return new self($info);
    }

    public function handle(RelayInfo $info): array|string
    {
        // 客户端断开（取消/超时）不再杀死脚本：输出写入静默失败，链路走完整结算
        ignore_user_abort(true);
        $this->info = $info;
        $this->ensureChannelInitialized();

        try {
            $this->selectAdapter();
            $this->parseRequestBody();

            // Coding Plan 账号池：在上游请求前选取可用账号并覆盖凭证
            // （P9-2：池耗尽时自动跨源 failover 到次选渠道）
            $this->establishUpstreamChannel();

            $this->adapter->formatRequest($this->info);
            $this->adapter->doRequest($this->info);

            if ($this->isError()) {
                $this->adapter->errorHandler($this->info);

                // 记录 Coding Plan 使用（失败），若为配额超限则标记账号耗尽
                if ($this->info->codingPlanAccount !== null) {
                    $isQuotaError = $this->info->isCodingPlanQuotaError();
                    $this->info->recordCodingPlanUsage(
                        false,
                        $isQuotaError ? 'quota_exceeded' : 'upstream_error_'.$this->info->responseStatus
                    );
                }

                // 上游失败：退回请求前预扣额度
                $this->refundPreConsumed();

                return $this->info->responseBody;
            }

            $this->adapter->formatResponse($this->info);
            $this->adapter->doResponse($this->info);

            // 记录 Coding Plan 使用（成功）
            $this->info->recordCodingPlanUsage(true);

            // 记录消费日志（成功，非流式）
            $this->logConsume();

            return $this->info->responseBody;
        } catch (Throwable $e) {
            // Throwable（含 curl_exec=false 赋给 string 属性抛出的 TypeError 等 Error）：
            // 确保任何异常路径都退回预扣额度，杜绝 Error 冒泡跳过退款/计费
            Log::error('Relay 处理失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 记录 Coding Plan 使用（异常）
            if ($this->info && $this->info->codingPlanAccount !== null) {
                $this->info->recordCodingPlanUsage(false, 'exception: '.$e->getMessage());
            }

            // 异常：退回请求前预扣额度
            $this->refundPreConsumed();

            $this->info->responseStatus = 500;

            return json_encode([
                'error' => [
                    'message' => 'Internal server error: '.$e->getMessage(),
                    'type' => 'server_error',
                    'code' => 'internal_error',
                ],
            ]);
        }
    }

    public function handleStream(RelayInfo $info, callable $callback): void
    {
        $this->info = $info;
        $this->ensureChannelInitialized();

        try {
            $this->selectAdapter();
            $this->parseRequestBody();

            // Coding Plan 账号池：在上游请求前选取可用账号并覆盖凭证
            // （P9-2：池耗尽时自动跨源 failover 到次选渠道）
            $this->establishUpstreamChannel();

            $this->adapter->formatRequest($this->info);
            $this->adapter->streamHandler($this->info, $callback);

            // 客户端中断：curl 已在上游传输中途中止，按已解析 usage 立即结算
            // （logConsume 内 postConsume 会冲销预扣额度并落消费日志），杜绝预扣泄漏
            if ($this->info->clientAborted) {
                $this->logConsume();

                return;
            }

            if ($this->isError()) {
                // 上游失败：退回请求前预扣额度，不计费不记消费日志
                $this->refundPreConsumed();

                return;
            }

            // 流式完成后记录 Coding Plan 使用（成功）
            $this->info->recordCodingPlanUsage(true);

            // 记录消费日志（成功，流式）
            $this->logConsume();

        } catch (Throwable $e) {
            Log::error('流式 Relay 处理失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 记录 Coding Plan 使用（异常）
            if ($this->info && $this->info->codingPlanAccount !== null) {
                $this->info->recordCodingPlanUsage(false, 'stream_exception: '.$e->getMessage());
            }

            // 异常：退回请求前预扣额度
            $this->refundPreConsumed();

            $callback('data: '.json_encode([
                'error' => [
                    'message' => $e->getMessage(),
                    'type' => 'server_error',
                ],
            ])."\n\n");
        }
    }

    /**
     * 成功转发后计费扣除并记录消费日志（任一失败不影响主流程）
     */
    protected function logConsume(): void
    {
        if ($this->info === null) {
            return;
        }

        // 计费扣除（对标 new-api PostConsumeQuota；失败仅记错误，不回滚响应）。
        // 无论计费成功与否都结算预扣：退回请求前预扣额度，净扣费 = 实际计费。
        try {
            app(BillingService::class)->postConsume($this->info);
        } catch (Throwable $e) {
            Log::error('Relay 计费扣除失败', [
                'error' => $e->getMessage(),
                'user_id' => $this->info->userId,
                'model' => $this->info->modelName,
            ]);
        } finally {
            $this->refundPreConsumed();
        }

        // 消费日志（quota 取 $info->quota 计费结果；内部已有 try/catch）
        app(LogService::class)->recordConsumeLog($this->info);
    }

    /**
     * 退回请求前预扣额度（任一失败不影响主流程）
     */
    protected function refundPreConsumed(): void
    {
        if ($this->info === null || $this->info->preConsumedQuota <= 0) {
            return;
        }

        try {
            app(BillingService::class)->refundPreConsumed($this->info);
        } catch (Throwable $e) {
            Log::error('Relay 预扣退款失败', [
                'error' => $e->getMessage(),
                'user_id' => $this->info->userId,
                'pre_consumed' => $this->info->preConsumedQuota,
            ]);
        }
    }

    /**
     * 选定上游渠道与 Coding Plan 账号（P9-2 跨源 failover）
     *
     * 正常路径与 RelayInfo::applyCodingPlanAccount 零差异；仅当渠道关联的
     * 账号池耗尽（pickAccount 无可用账号 / 全员 STATUS_EXHAUSTED，抛
     * RuntimeException 且消息含 account pool exhausted）时，按当前路由策略序
     * 自动切换次选源渠道：
     *  - cost_first：CostRouteService 成本升序（P9-1 排序器）
     *  - static：priority 降序 + id 升序（现网兜底语义）
     * 候选来自 candidateChannels（abilities 精确匹配 + status=1），排除已失败
     * 渠道，最多尝试 5 个；普通 API 渠道（无账号池）applyCodingPlanAccount
     * 直接通过，是跨源兜底的天然归宿。次选成功后重跑 setModel（各渠道
     * model_mapping 可能不同）并重选适配器。
     *
     * 路由决策（reason=cost_failover、落选候选与成本）记入
     * RelayInfo::routeDecision，最终随用量流水写入 coding_plan_usage_logs.meta.route。
     */
    protected function establishUpstreamChannel(): void
    {
        $failedChannelId = $this->info->channelId;

        try {
            $this->info->applyCodingPlanAccount();

            return;
        } catch (RuntimeException $e) {
            // 订阅校验失败等非池耗尽异常原样抛出，不参与 failover
            if (! str_contains($e->getMessage(), 'account pool exhausted')) {
                throw $e;
            }

            $this->failoverChannel($failedChannelId, $e);
        }
    }

    /**
     * 跨源 failover：按策略序逐个尝试次选渠道，全部失败时抛原始池耗尽异常
     */
    protected function failoverChannel(int $failedChannelId, RuntimeException $original): void
    {
        $model = $this->info->modelName;
        $group = $this->info->tokenGroup !== '' ? $this->info->tokenGroup : 'default';

        // 已失败渠道（首选源）作为首个落选记录，cost 首次失败时尚未计算
        $attempted = [[
            'channel_id' => $failedChannelId,
            'vendor' => $this->info->codingVendor,
            'cost_per_1k' => null,
            'reason' => 'pool_exhausted',
            'error' => $original->getMessage(),
        ]];

        $candidates = app(ChannelSelectService::class)->candidateChannels($model, $group)
            ->reject(fn (Channel $channel) => (int) $channel->id === $failedChannelId)
            ->values();

        if ($candidates->isEmpty()) {
            throw $original;
        }

        $strategy = ModelRouteSetting::strategy();
        if ($strategy === CostRouteService::STRATEGY_COST_FIRST) {
            // 成本升序明细（含 vendor/cost_per_1k，落选记录据此留痕）
            $details = app(CostRouteService::class)->sortChannelsByCost($candidates, $model);
        } else {
            // static：priority 降序 + id 升序（现网兜底语义）；不做成本计算，
            // 明细结构与 cost_first 对齐便于统一处理
            $details = $candidates
                ->sortBy('id')
                ->sortByDesc('priority')
                ->map(fn (Channel $channel) => [
                    'channel' => $channel,
                    'channel_id' => (int) $channel->id,
                    'priority' => (int) $channel->priority,
                    'vendor' => null,
                    'cost_per_1k' => null,
                ])
                ->values()
                ->all();
        }

        foreach (array_slice($details, 0, 5) as $detail) {
            /** @var Channel $candidate */
            $candidate = $detail['channel'];

            try {
                $this->info->setChannel($candidate);
                // 换渠道后重映射 model_mapping（各渠道映射可能不同）并重选适配器
                $this->info->setModel($this->info->requestModel !== '' ? $this->info->requestModel : $model);
                $this->selectAdapter();
                $this->info->applyCodingPlanAccount();
            } catch (RuntimeException $e) {
                $attempted[] = [
                    'channel_id' => (int) $candidate->id,
                    'vendor' => $detail['vendor'],
                    'cost_per_1k' => $detail['cost_per_1k'],
                    'reason' => str_contains($e->getMessage(), 'account pool exhausted')
                        ? 'pool_exhausted'
                        : 'apply_failed',
                    'error' => $e->getMessage(),
                ];

                continue;
            }

            $this->info->routeDecision = [
                'reason' => 'cost_failover',
                'strategy' => $strategy,
                'failed_channel_id' => $failedChannelId,
                'final_channel_id' => $this->info->channelId,
                'attempted' => $attempted,
                'decided_at' => time(),
            ];

            Log::info('Coding Plan 账号池耗尽，跨源 failover', [
                'request_id' => $this->info->requestId,
                'model' => $model,
                'group' => $group,
                'strategy' => $strategy,
                'failed_channel_id' => $failedChannelId,
                'final_channel_id' => $this->info->channelId,
                'attempted_count' => count($attempted),
            ]);

            return;
        }

        // 全部候选失败：抛原始池耗尽异常（外层按现有路径退款并返回错误）
        throw $original;
    }

    protected function selectAdapter(): void
    {
        $channelType = $this->info->channelType;

        $openAITypes = [
            ChannelType::OPENAI,
            ChannelType::OPENAI_SUM,
            ChannelType::OPENAI_DASHBOARD,
            ChannelType::OPENAI_TOKEN,
            ChannelType::OPENAI_COMPATIBLE,
            ChannelType::API2D,
            ChannelType::AZURE,
            ChannelType::PATH,
            ChannelType::CUSTOM,
            ChannelType::CODEX,
            ChannelType::STREAM,
            ChannelType::OPENROUTER,
            ChannelType::PERPLEXITY,
            ChannelType::XAI,
            ChannelType::LINGYI_WANWU,
            ChannelType::SUB_MODEL,
            ChannelType::ASHMOON,
            ChannelType::JINSHAN,
            ChannelType::SANLIAN,
            ChannelType::YIMG_CLOUD,
            ChannelType::SILICONFLOW,
            ChannelType::MOONSHOT,
            ChannelType::DEEPSEEK,
            ChannelType::MISTRAL,
            ChannelType::XINFERENCE,
            ChannelType::MOKA_AI,
            ChannelType::AI360,
            ChannelType::VOLCENGINE,
            ChannelType::CLOUDFLARE,
            ChannelType::MINIMAX,
            ChannelType::REPLICATE,
            ChannelType::COZE,
            ChannelType::DIFY,
            ChannelType::OLLAMA,
            ChannelType::BAIDU,
            ChannelType::BAIDU_V2,
            ChannelType::ALI,
            ChannelType::ZHIPU,
            ChannelType::ZHIPU_V4,
            ChannelType::XUNFEI,
            ChannelType::TENCENT,
            ChannelType::JINA,
            ChannelType::GROQ,
            ChannelType::STABILITY,
            ChannelType::QWEN,
            ChannelType::DOUBAO,
            ChannelType::YI,
            ChannelType::STEP,
        ];

        $claudeTypes = [
            ChannelType::ANTHROPIC,
        ];

        $geminiTypes = [
            ChannelType::GOOGLE_GEMINI,
            ChannelType::PALM,
            ChannelType::GEM,
        ];

        $awsTypes = [
            ChannelType::AWS,
        ];

        $vertexTypes = [
            ChannelType::VERTEX,
        ];

        $this->adapter = match (true) {
            // Anthropic 原生协议（入站为 Anthropic 格式，透传不做转换）
            $this->info->relayProtocol === RelayProtocol::Anthropic => new AnthropicNativeAdapter,
            // 注意：类型数组存的是枚举实例，与 int 的 channelType 比较前必须取 ->value，
            // 否则严格 in_array 恒为 false，所有渠道都会落入 default（OpenAIAdapter）
            in_array($channelType, self::enumValues($claudeTypes), true) => new ClaudeAdapter,
            in_array($channelType, self::enumValues($geminiTypes), true) => new GeminiAdapter,
            in_array($channelType, self::enumValues($awsTypes), true) => new AWSAdapter,
            in_array($channelType, self::enumValues($vertexTypes), true) => new VertexAdapter,
            default => new OpenAIAdapter,
        };
    }

    /**
     * @param  array<int, ChannelType>  $types
     * @return array<int, int>
     */
    private static function enumValues(array $types): array
    {
        return array_map(static fn (ChannelType $type): int => $type->value, $types);
    }

    protected function parseRequestBody(): void
    {
        if ($this->info->request === null) {
            return;
        }

        $mode = $this->info->relayMode;

        if (in_array($mode, [
            RelayMode::AudioTranscriptions,
            RelayMode::AudioTranslations,
            RelayMode::ImageEdits,
        ], true)) {
            $this->info->requestBody = $this->parseMultipartRequest();
        } else {
            $content = $this->info->request->getContent();
            $body = json_decode($content, true);
            $this->info->requestBody = is_array($body) ? $body : [];
        }

        if (isset($this->info->requestBody['model'])) {
            $this->info->setModel((string) $this->info->requestBody['model']);
        }
    }

    protected function parseMultipartRequest(): array
    {
        $body = [];

        if ($this->info->request) {
            foreach ($this->info->request->all() as $key => $value) {
                $body[$key] = $value;
            }
        }

        return $body;
    }

    protected function isError(): bool
    {
        if ($this->info->responseStatus >= 400) {
            return true;
        }

        $body = json_decode($this->info->responseBody, true);
        if (is_array($body) && isset($body['error'])) {
            return true;
        }

        return false;
    }

    public function getInfo(): RelayInfo
    {
        return $this->info;
    }

    public function getAdapter(): ChannelAdapterInterface
    {
        return $this->adapter;
    }
}
