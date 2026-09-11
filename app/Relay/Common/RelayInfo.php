<?php

declare(strict_types=1);

namespace App\Relay\Common;

use App\Enums\ApiType;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\CodingPlanAccount;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Token;
use App\Models\User;
use App\Relay\Constant\RelayFormat;
use App\Relay\Constant\RelayProtocol;
use App\Services\CodingPlanPoolService;
use App\Services\CodingPlanRatioService;
use App\Services\OptionService;
use Illuminate\Http\Request;

/**
 * Relay 请求上下文
 *
 * 对标 new-api relay/common/relay_info.go
 * 贯穿整个 relay 生命周期的数据结构
 */
class RelayInfo
{
    // Token 信息
    public int $tokenId = 0;

    public string $tokenKey = '';

    public string $tokenGroup = '';

    public bool $tokenUnlimited = false;

    // 用户信息
    public int $userId = 0;

    public string $userGroup = 'default';

    public ?User $user = null;

    // 渠道信息
    public int $channelType = 0;

    public int $channelId = 0;

    public bool $channelIsMultiKey = false;

    public int $channelMultiKeyIndex = 0;

    public string $channelBaseUrl = '';

    public int $apiType = 0;

    public string $apiVersion = '';

    public string $apiKey = '';

    public string $organization = '';

    public int $channelCreateTime = 0;

    /** @var array<string, mixed> */
    public array $paramOverride = [];

    /** @var array<string, mixed> */
    public array $headersOverride = [];

    /** @var array<string, mixed> */
    public array $channelSetting = [];

    /** @var array<string, mixed> */
    public array $channelOtherSettings = [];

    public string $upstreamModelName = '';

    public bool $isModelMapped = false;

    public bool $supportStreamOptions = false;

    /** 请求信息 */
    public string $relayMode = '';

    /**
     * 入站请求格式
     *
     * @see RelayFormat
     */
    public string $relayFormat = '';

    /**
     * 入站请求协议类型
     *
     * @see RelayProtocol
     */
    public string $relayProtocol = 'openai';

    public string $requestModel = '';

    public string $modelName = '';

    public bool $isStream = false;

    public bool $isImage = false;

    public bool $isAudio = false;

    public bool $isRerank = false;

    public ?Request $request = null;

    public ?Channel $channel = null;

    // 时间信息
    public float $startTime = 0.0;

    public float $firstResponseTime = 0.0;

    public bool $isFirstResponse = true;

    // Token 计数
    public int $promptTokens = 0;

    public int $completionTokens = 0;

    /** 命中缓存的输入 token 数（OpenAI prompt_tokens_details.cached_tokens / Claude cache_read_input_tokens） */
    public int $cachedTokens = 0;

    public int $estimatePromptTokens = 0;

    // 计费信息
    public float $modelRatio = 1.0;

    public float $groupRatio = 1.0;

    public float $completionRatio = 1.0;

    public float $cacheRatio = 0.0;

    public int $quota = 0;

    public int $preConsumedQuota = 0;

    // 响应信息
    public int $responseStatus = 0;

    public string $responseBody = '';

    /** @var array<string, string> */
    public array $responseHeaders = [];

    // 流式处理
    public bool $sendLastReasoningResponse = false;

    public string $lastMessageType = 'none';

    public bool $hasSentThinkingContent = false;

    // Claude 转换信息
    public string $claudeLastMessagesType = 'none';

    public int $claudeIndex = 0;

    public ?array $claudeUsage = null;

    public string $claudeFinishReason = '';

    public bool $claudeDone = false;

    public int $claudeToolCallBaseIndex = 0;

    public int $claudeToolCallMaxIndexOffset = 0;

    // Reranker 信息
    /** @var array<int, mixed> */
    public array $rerankerDocuments = [];

    public bool $rerankerReturnDocuments = false;

    // Responses API 信息
    /** @var array<string, mixed> */
    public array $responsesBuiltInTools = [];

    // 请求体
    /** @var array<string, mixed> */
    public array $requestBody = [];

    // 上游请求（适配器使用）
    public string $upstreamBody = '';

    public string $upstreamUrl = '';

    // 其他渠道列表（重试时记录）
    public string $otherChannels = '';

    // IP
    public string $ip = '';

    // 请求ID
    public string $requestId = '';

    // Coding Plan 账号池（若当前渠道关联账号池，则持有选中的账号实例）
    public ?CodingPlanAccount $codingPlanAccount = null;

    public ?int $codingPlanAccountId = null;

    public string $codingVendor = '';

    public int $codingSubmitsPerRequest = 1;

    /** 用户当前有效的 Coding Plan 订阅（若中转前校验通过） */
    public ?Subscription $codingPlanSubscription = null;

    /** 用户 Coding Plan 订阅对应的套餐 */
    public ?SubscriptionPlan $codingPlanPlan = null;

    /** 本次请求的折算结果（units/credits/ratio），请求完成后填充 */
    public array $codingPlanCost = [];

    /**
     * 从请求属性补齐身份信息
     *
     * TokenAuth 中间件已将 token / api_user 放入 request attributes，
     * RelayController 手工构造 RelayInfo 时调用本方法注入用户身份，
     * 保证计费、Coding Plan 订阅校验与流水归属正确。
     */
    public function hydrateFromRequest(Request $request): void
    {
        $token = $request->attributes->get('token');
        $user = $request->attributes->get('api_user');

        if ($user instanceof User) {
            $this->user = $user;
            $this->userId = (int) $user->id;
            $this->userGroup = $user->group ?: 'default';
        }

        if ($token instanceof Token) {
            $this->tokenId = (int) $token->id;
            $this->tokenKey = $token->key;
            $this->tokenGroup = $token->group ?: $this->userGroup;
            $this->tokenUnlimited = (bool) $token->unlimited_quota;
        }

        // 渠道由控制器直接赋值时，同步初始化 base_url / api_key / apiType 等
        // （否则上游请求会以空凭证与相对 URI 发出）
        if ($this->channel instanceof Channel && $this->channelId === 0) {
            $this->setChannel($this->channel);
        }

        if ($this->ip === '') {
            $this->ip = $request->ip() ?: '';
        }
        if ($this->startTime === 0.0) {
            $this->startTime = microtime(true);
        }
        if ($this->requestId === '') {
            $this->requestId = uniqid('req_', true);
        }
    }

    /**
     * 从请求创建 RelayInfo
     */
    public static function fromRequest(Request $request, Token $token, User $user): self
    {
        $info = new self;
        $info->request = $request;
        $info->tokenId = (int) $token->id;
        $info->tokenKey = $token->key;
        $info->tokenGroup = $token->group ?: $user->group;
        $info->tokenUnlimited = (bool) $token->unlimited_quota;
        $info->userId = (int) $token->user_id;
        $info->userGroup = $user->group ?: 'default';
        $info->user = $user;
        $info->ip = $request->ip() ?: '';
        $info->startTime = microtime(true);
        $info->requestId = uniqid('req_', true);

        return $info;
    }

    /**
     * 设置渠道信息
     */
    public function setChannel(Channel $channel): void
    {
        $this->channel = $channel;
        $this->channelId = (int) $channel->id;
        $this->channelType = (int) $channel->type;
        $this->channelBaseUrl = $channel->base_url ?: $this->getDefaultBaseUrl($channel->type);
        $this->apiKey = $channel->key ?: '';
        $this->organization = $channel->openai_organization ?: '';
        $this->channelCreateTime = (int) ($channel->created_time ?? 0);

        $channelInfo = $this->parseJson($channel->channel_info);
        $this->channelIsMultiKey = ! empty($channelInfo['multi_key']);
        $this->channelMultiKeyIndex = (int) ($channelInfo['multi_key_index'] ?? 0);

        $this->channelSetting = $this->parseJson($channel->setting);
        $this->channelOtherSettings = $this->parseJson($channel->settings);
        $this->paramOverride = $this->parseJson($channel->param_override);
        $this->headersOverride = $this->parseJson($channel->header_override);

        $this->apiType = $this->getApiType($channel->type);
        $this->supportStreamOptions = $this->supportsStreamOptions($channel->type);
    }

    /**
     * 应用 Coding Plan 账号池凭证
     *
     * 检测当前渠道是否关联了 Coding Plan 账号池，若是则：
     *  1) 校验用户的 Coding Plan 订阅（按厂商匹配套餐，受 CodingPlanRequireSubscription 开关控制）
     *  2) 预检订阅余量（coding_quota，0 表示不限）
     *  3) 从池中选取一个可用账号，用账号的 api_key / base_url 覆盖当前凭证
     *
     * 应在 setChannel 之后、实际发起上游请求之前调用。
     *
     * @throws \RuntimeException 订阅校验失败或账号池全部耗尽时
     */
    public function applyCodingPlanAccount(): void
    {
        if (! $this->channel || $this->channelId <= 0) {
            return;
        }

        // 通过 channel_id 检测是否存在关联的 coding plan 账号
        $firstAccount = CodingPlanAccount::where('channel_id', $this->channelId)
            ->where('status', '!=', CodingPlanAccount::STATUS_DISABLED)
            ->first();

        if (! $firstAccount) {
            return; // 非 coding plan 渠道，走默认凭证
        }

        $vendor = $firstAccount->vendor;
        $this->codingVendor = $vendor;

        // 1) 用户订阅校验 + 每次请求提交数（来自套餐）
        $this->resolveCodingPlanSubscription($vendor);

        /** @var CodingPlanPoolService $pool */
        $pool = app(CodingPlanPoolService::class);

        // 2) 从池中选号
        $account = $pool->pickAccount($vendor);

        if ($account === null) {
            throw new \RuntimeException(
                "Coding plan account pool exhausted for vendor: {$vendor}"
            );
        }

        // 3) 用账号池凭证覆盖渠道凭证
        $plainKey = $account->getApiKeyPlain();
        if ($plainKey !== null && $plainKey !== '') {
            $this->apiKey = $plainKey;
        }
        if (! empty($account->base_url)) {
            $this->channelBaseUrl = $account->base_url;
        }

        $this->codingPlanAccount = $account;
        $this->codingPlanAccountId = $account->id;
    }

    /**
     * 校验用户针对指定厂商的 Coding Plan 订阅
     *
     * - OptionService 开关 CodingPlanRequireSubscription（默认 false，保持向后兼容）：
     *   开启后未订阅/未认证的请求将直接拒绝
     * - 校验通过时记录订阅与套餐，并从套餐读取 coding_submits_per_request
     *   （修复此前恒为 1 的问题）
     *
     * @throws \RuntimeException
     */
    protected function resolveCodingPlanSubscription(string $vendor): void
    {
        $requireSubscription = (bool) OptionService::get('CodingPlanRequireSubscription', false);

        if ($this->user === null || $this->userId <= 0) {
            if ($requireSubscription) {
                throw new \RuntimeException(
                    'Coding plan relay requires an authenticated user with an active subscription'
                );
            }

            return;
        }

        $now = time();

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()
            ->join('subscription_plans', 'subscription_plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.user_id', $this->userId)
            ->where('subscriptions.status', 1)
            ->where('subscription_plans.plan_type', 'coding_plan')
            ->where('subscription_plans.coding_vendor', $vendor)
            ->where(function ($q) use ($now) {
                $q->where('subscriptions.period_end', 0)->orWhere('subscriptions.period_end', '>', $now);
            })
            ->select('subscriptions.*')
            ->orderByDesc('subscriptions.id')
            ->first();

        if ($subscription === null) {
            if ($requireSubscription) {
                throw new \RuntimeException(
                    "No active Coding Plan subscription for vendor: {$vendor}. Please subscribe first."
                );
            }

            return;
        }

        $plan = $subscription->plan;
        if (! $plan instanceof SubscriptionPlan || ! $plan->isCodingPlan()) {
            return;
        }

        // 订阅余量预检（coding_quota=0 表示不限，仅受账号池约束）
        // 注意：coding_plan 套餐的 used_quota 单位为「平台积分」
        if ((int) $plan->coding_quota > 0 && (int) $subscription->used_quota >= (int) $plan->coding_quota) {
            throw new \RuntimeException(
                'Your Coding Plan subscription quota has been exhausted. Please renew or upgrade.'
            );
        }

        // 每次请求的提交数来自套餐（此前恒为 1）
        $this->codingSubmitsPerRequest = max(1, (int) $plan->coding_submits_per_request);
        $this->codingPlanSubscription = $subscription;
        $this->codingPlanPlan = $plan;
    }

    /**
     * 记录 Coding Plan 账号池的使用消耗
     *
     * 在上游请求完成后调用（成功或失败均记录）。处理流程：
     *  1. 按账号计费模式 + 模型折算比率表计算本次消耗（原生单位 + 平台积分）
     *  2. 池计数：仅成功请求计入（失败/上游配额错误不消耗配额）
     *  3. 用户订阅扣减：按平台积分扣减 subscriptions.used_quota（向上取整）
     *  4. 若上游返回配额超限类错误，将账号标记为耗尽，下次请求自动切换
     *
     * @param  bool  $success  请求是否成功
     * @param  string|null  $error  错误信息（失败时填写）
     */
    public function recordCodingPlanUsage(bool $success, ?string $error = null): void
    {
        if ($this->codingPlanAccount === null) {
            return;
        }

        $account = $this->codingPlanAccount;

        /** @var CodingPlanRatioService $ratioService */
        $ratioService = app(CodingPlanRatioService::class);

        // 1) 按折算比率计算本次消耗（分段口径需缓存命中 token 数）
        $cost = $ratioService->calcUsage(
            $account,
            $this->modelName,
            $this->promptTokens,
            $this->completionTokens,
            $this->codingSubmitsPerRequest,
            $this->cachedTokens
        );
        $this->codingPlanCost = $cost;

        $snapshot = $ratioService->snapshot(
            $cost['ratio'],
            $cost['units'],
            $cost['credits'],
            $account->isCreditBilling() ? 'credit' : 'per_request',
            $cost['time_window'] ?? null
        );

        // 2) 池计数 + 写流水
        /** @var CodingPlanPoolService $pool */
        $pool = app(CodingPlanPoolService::class);

        $pool->recordUsage(
            $account,
            $cost['units'],
            [
                'user_id' => $this->userId,
                'channel_id' => $this->channelId,
                'model' => $this->modelName,
                'request_id' => $this->requestId,
                'prompt_tokens' => $this->promptTokens,
                'cached_tokens' => $this->cachedTokens,
                'completion_tokens' => $this->completionTokens,
                'total_tokens' => $this->promptTokens + $this->completionTokens,
                'credits' => $cost['credits'],
                'count' => $account->isCreditBilling() ? 0 : $cost['units'],
                'meta' => $snapshot,
            ],
            $success,
            $error
        );

        // 3) 用户订阅扣减（平台积分，向上取整；仅成功请求）
        if ($success && $this->codingPlanSubscription !== null && $cost['credits'] > 0) {
            $credits = (int) ceil($cost['credits']);
            if ($credits > 0) {
                Subscription::query()
                    ->where('id', $this->codingPlanSubscription->id)
                    ->where('status', 1)
                    ->increment('used_quota', $credits);
                $this->codingPlanSubscription->used_quota = (int) $this->codingPlanSubscription->used_quota + $credits;
            }
        }
    }

    /**
     * 判断上游响应是否为配额超限类错误（用于触发账号标记耗尽）
     */
    public function isCodingPlanQuotaError(): bool
    {
        if ($this->codingPlanAccount === null) {
            return false;
        }

        // HTTP 429（限速/配额）或 402（需付费）是明确的配额类信号
        if ($this->responseStatus === 429 || $this->responseStatus === 402) {
            return true;
        }

        // 其余状态码（含 400）必须响应体明确出现配额类关键词才判为配额耗尽：
        // 裸词 "exceeded"/"insufficient" 会把「maximum context length exceeded」
        // 之类的 400 上下文超限误判为配额耗尽，导致健康账号被错误停用一个窗口
        $body = strtolower($this->responseBody);

        return str_contains($body, 'quota')
            || str_contains($body, 'rate limit')
            || str_contains($body, 'usage limit')
            || str_contains($body, 'limit reached')
            || str_contains($body, 'insufficient_quota')
            || str_contains($body, 'billing');
    }

    /**
     * 设置模型信息
     */
    public function setModel(string $model): void
    {
        $this->requestModel = $model;
        $this->modelName = $model;

        if ($this->channel && $this->channel->model_mapping) {
            $mapping = $this->parseJson($this->channel->model_mapping);
            if (isset($mapping[$model])) {
                $this->upstreamModelName = (string) $mapping[$model];
                $this->isModelMapped = true;
            } else {
                $this->upstreamModelName = $model;
            }
        } else {
            $this->upstreamModelName = $model;
        }
    }

    /**
     * 获取计费倍率对应的配额
     */
    public function getQuota(int $promptTokens, int $completionTokens): int
    {
        $ratio = (float) config("pease-api.billing.model_ratios.{$this->modelName}", 1.0);
        $groupRatio = (float) config("pease-api.billing.group_ratios.{$this->tokenGroup}", 1.0);
        $completionRatio = (float) config("pease-api.billing.completion_ratios.{$this->modelName}", 1.0);

        $this->modelRatio = $ratio;
        $this->groupRatio = $groupRatio;
        $this->completionRatio = $completionRatio;

        $quota = (int) ceil(
            ($promptTokens * $ratio + $completionTokens * $ratio * $completionRatio) * $groupRatio
        );

        return $quota;
    }

    /**
     * 获取上游 URL
     */
    public function getUpstreamUrl(string $path = ''): string
    {
        $baseUrl = rtrim($this->channelBaseUrl, '/');
        if ($path !== '' && str_starts_with($path, '/')) {
            $path = substr($path, 1);
        }

        return $baseUrl.($path !== '' ? '/'.$path : '');
    }

    /**
     * 获取默认 Base URL（对标源项目各渠道的默认地址）
     */
    private function getDefaultBaseUrl(int $channelType): string
    {
        // 使用数值匹配（对应 ChannelType 枚举值）
        return match ($channelType) {
            1, 15, 34, 6, 5 => 'https://api.openai.com', // OPENAI, OPENAI_SUM, OPENAI_DASHBOARD, OPENAI_TOKEN, OPENAI_COMPATIBLE
            4 => 'https://api.anthropic.com',             // ANTHROPIC (Claude)
            50, 26, 25 => 'https://generativelanguage.googleapis.com', // GOOGLE_GEMINI, PALM, GEM
            21 => 'https://us-central1-aiplatform.googleapis.com', // VERTEX
            22 => 'https://bedrock-runtime.us-east-1.amazonaws.com', // AWS
            11, 52 => 'https://dashscope.aliyuncs.com',  // ALI, QWEN
            9, 17 => 'https://aip.baidubce.com',         // BAIDU, BAIDU_V2
            48, 53 => 'https://hunyuan.tencentcloudapi.com', // TENCENT, HUNYUAN
            10, 39 => 'https://open.bigmodel.cn',        // ZHIPU, ZHIPU_V4
            16 => 'https://api.moonshot.cn',             // MOONSHOT
            51 => 'https://api.deepseek.com',            // DEEPSEEK
            12 => 'https://spark-api.xf-yun.com',       // XUNFEI
            18 => 'https://api.siliconflow.cn',          // SILICONFLOW
            23, 31 => 'https://api.cohere.ai',           // COHERE, COHERE_V2
            32 => 'http://localhost:11434',              // OLLAMA
            33 => 'https://api.coze.cn',                 // COZE
            36 => 'http://localhost:3000',               // DIFY
            24 => 'https://api.cloudflare.com',          // CLOUDFLARE
            57 => 'https://api.minimax.chat',            // MINIMAX
            45 => 'https://api.mistral.ai',              // MISTRAL
            14 => 'https://openrouter.ai',               // OPENROUTER
            40 => 'https://api.perplexity.ai',           // PERPLEXITY
            43 => 'https://api.replicate.com',           // REPLICATE
            44 => 'https://api.x.ai',                    // XAI
            37 => 'http://localhost:9997',               // XINFERENCE
            30, 58 => 'https://api.lingyiwanwu.com',     // LINGYI_WANWU, YI
            28 => 'https://api.mokaai.com',              // MOKA_AI
            13 => 'https://api.360.cn',                  // AI360
            46 => 'https://api.jina.ai',                 // JINA
            38, 56 => 'https://ark.cn-beijing.volces.com', // VOLCENGINE, DOUBAO
            20 => 'https://api.codex.io',                // CODEX
            default => 'https://api.openai.com',
        };
    }

    /**
     * 获取 API 类型
     */
    private function getApiType(int $channelType): int
    {
        return match ($channelType) {
            // OpenAI 兼容 (ApiType::OPENAI = 0)
            1, 15, 34, 6, 2, 3, 5, 7, 8, 20, 19, 14, 40, 44, 30, 42, 29, 27, 41, 35, 18,
            51, 52, 53, 54, 55, 56, 57, 58, 59 => 0, // ApiType::OPENAI
            // Claude (Anthropic, AWS) (ApiType::ANTHROPIC = 1)
            4, 22 => 1, // ApiType::ANTHROPIC
            // Gemini (Google) (ApiType::GOOGLE_GEMINI = 24)
            50, 26, 25, 21 => 24, // ApiType::GOOGLE_GEMINI
            // Cohere (ApiType::COHERE = 34)
            23, 31 => 34, // ApiType::COHERE
            default => 0, // ApiType::OPENAI
        };
    }

    /**
     * 是否支持流式选项
     */
    private function supportsStreamOptions(int $channelType): bool
    {
        return in_array($channelType, [
            1, 15, 5, 14, 3, 2, 7, 8, 20, 19, 18, 16, 51, 30, 44, 38, 45, 37, // OPENAI, OPENAI_SUM, OPENAI_COMPATIBLE, OPENROUTER, AZURE, API2D, PATH, CUSTOM, CODEX, STREAM, SILICONFLOW, MOONSHOT, DEEPSEEK, LINGYI_WANWU, XAI, VOLCENGINE, MISTRAL, XINFERENCE
        ], true);
    }

    /**
     * 解析 JSON 字段为数组
     *
     * @return array<string, mixed>
     */
    private function parseJson(mixed $json): array
    {
        if (empty($json)) {
            return [];
        }
        if (is_array($json)) {
            return $json;
        }
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 记录首次响应时间
     */
    public function recordFirstResponse(): void
    {
        if ($this->isFirstResponse) {
            $this->firstResponseTime = microtime(true);
            $this->isFirstResponse = false;
        }
    }

    /**
     * 获取请求耗时（毫秒）
     */
    public function getUseTime(): float
    {
        return round((microtime(true) - $this->startTime) * 1000, 2);
    }

    // ============================================
    // 请求/响应数据访问器（Task 异步任务适配器族使用）
    // ============================================

    /** 适配器产出的数据（doRequest → doResponse 传递） */
    public mixed $responseData = null;

    /** 处理错误（errorHandler 使用） */
    public ?array $error = null;

    /**
     * 获取请求体
     *
     * @return array<string, mixed>
     */
    public function getRequestBody(): array
    {
        return $this->requestBody;
    }

    /**
     * 覆写请求体
     *
     * @param  array<string, mixed>  $body
     */
    public function setRequestBody(array $body): void
    {
        $this->requestBody = $body;
    }

    /**
     * 获取请求参数（请求体优先，query 兜底）
     */
    public function getParam(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->requestBody)) {
            return $this->requestBody[$key];
        }

        return $this->request?->query($key, $default) ?? $default;
    }

    /**
     * 覆写响应体
     */
    public function setResponseBody(string $body): void
    {
        $this->responseBody = $body;
    }

    /**
     * 设置适配器产出数据
     */
    public function setResponseData(mixed $data): void
    {
        $this->responseData = $data;
    }

    /**
     * 获取适配器产出数据
     */
    public function getResponseData(): mixed
    {
        return $this->responseData;
    }

    /**
     * 获取处理错误
     *
     * @return array<string, mixed>|null
     */
    public function getError(): ?array
    {
        return $this->error;
    }
}
