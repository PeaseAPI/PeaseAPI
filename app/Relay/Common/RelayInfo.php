<?php

declare(strict_types=1);

namespace App\Relay\Common;

use App\Enums\ApiType;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Token;
use App\Models\User;
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

    // 请求信息
    public string $relayMode = '';
    public int $relayFormat = 0;
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

    /**
     * 从请求创建 RelayInfo
     */
    public static function fromRequest(Request $request, Token $token, User $user): self
    {
        $info = new self();
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
        $this->channelIsMultiKey = !empty($channelInfo['multi_key']);
        $this->channelMultiKeyIndex = (int) ($channelInfo['multi_key_index'] ?? 0);

        $this->channelSetting = $this->parseJson($channel->setting);
        $this->channelOtherSettings = $this->parseJson($channel->settings);
        $this->paramOverride = $this->parseJson($channel->param_override);
        $this->headersOverride = $this->parseJson($channel->header_override);

        $this->apiType = $this->getApiType($channel->type);
        $this->supportStreamOptions = $this->supportsStreamOptions($channel->type);
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
        return $baseUrl . ($path !== '' ? '/' . $path : '');
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
}