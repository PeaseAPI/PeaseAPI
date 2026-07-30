<?php

declare(strict_types=1);

namespace App\Relay\Common;

use App\Enums\ChannelType;
use App\Relay\Channel\ChannelAdapterInterface;
use App\Relay\Channel\OpenAI\OpenAIAdapter;
use App\Relay\Constant\RelayMode;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Relay 核心处理器
 * 对标 new-api relay/common/relay_handler.go
 */
class RelayHandler
{
    protected ?RelayInfo $info = null;
    protected ?ChannelAdapterInterface $adapter = null;

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
        $this->info = $info;
        
        try {
            $this->selectAdapter();
            $this->parseRequestBody();

            // Coding Plan 账号池：在上游请求前选取可用账号并覆盖凭证
            $this->info->applyCodingPlanAccount();

            $this->adapter->formatRequest($this->info);
            $this->adapter->doRequest($this->info);

            if ($this->isError()) {
                $this->adapter->errorHandler($this->info);

                // 记录 Coding Plan 使用（失败），若为配额超限则标记账号耗尽
                if ($this->info->codingPlanAccount !== null) {
                    $isQuotaError = $this->info->isCodingPlanQuotaError();
                    $this->info->recordCodingPlanUsage(
                        false,
                        $isQuotaError ? 'quota_exceeded' : 'upstream_error_' . $this->info->responseStatus
                    );
                }

                return $this->info->responseBody;
            }

            $this->adapter->formatResponse($this->info);
            $this->adapter->doResponse($this->info);

            // 记录 Coding Plan 使用（成功）
            $this->info->recordCodingPlanUsage(true);
            
            return $this->info->responseBody;
        } catch (Exception $e) {
            Log::error('Relay 处理失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 记录 Coding Plan 使用（异常）
            if ($this->info && $this->info->codingPlanAccount !== null) {
                $this->info->recordCodingPlanUsage(false, 'exception: ' . $e->getMessage());
            }

            $this->info->responseStatus = 500;
            return json_encode([
                'error' => [
                    'message' => 'Internal server error: ' . $e->getMessage(),
                    'type' => 'server_error',
                    'code' => 'internal_error',
                ],
            ]);
        }
    }

    public function handleStream(RelayInfo $info, callable $callback): void
    {
        $this->info = $info;
        
        try {
            $this->selectAdapter();
            $this->parseRequestBody();

            // Coding Plan 账号池：在上游请求前选取可用账号并覆盖凭证
            $this->info->applyCodingPlanAccount();

            $this->adapter->formatRequest($this->info);
            $this->adapter->streamHandler($this->info, $callback);

            // 流式完成后记录 Coding Plan 使用（成功）
            $this->info->recordCodingPlanUsage(true);
            
        } catch (Exception $e) {
            Log::error('流式 Relay 处理失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 记录 Coding Plan 使用（异常）
            if ($this->info && $this->info->codingPlanAccount !== null) {
                $this->info->recordCodingPlanUsage(false, 'stream_exception: ' . $e->getMessage());
            }
            
            $callback("data: " . json_encode([
                'error' => [
                    'message' => $e->getMessage(),
                    'type' => 'server_error',
                ]
            ]) . "\n\n");
        }
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
            in_array($channelType, $claudeTypes, true) => new \App\Relay\Channel\Claude\ClaudeAdapter(),
            in_array($channelType, $geminiTypes, true) => new \App\Relay\Channel\Gemini\GeminiAdapter(),
            in_array($channelType, $awsTypes, true) => new \App\Relay\Channel\AWS\AWSAdapter(),
            in_array($channelType, $vertexTypes, true) => new \App\Relay\Channel\Vertex\VertexAdapter(),
            default => new \App\Relay\Channel\OpenAI\OpenAIAdapter(),
        };
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