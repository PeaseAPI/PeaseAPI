<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Relay\Common\RelayHandler;
use App\Relay\Common\RelayInfo;
use App\Relay\Constant\RelayFormat;
use App\Relay\Constant\RelayProtocol;
use App\Services\BillingService;
use App\Services\SensitiveWordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relay Controller - 对标 new-api relay/main.go
 *
 * 处理所有 relay 请求，包括:
 * - Chat Completions (流式/非流式)
 * - Embeddings
 * - Images
 * - Audio
 * - Claude Messages
 * - Gemini
 * - Responses
 */
class RelayController extends Controller
{
    protected RelayHandler $relayHandler;

    public function __construct(RelayHandler $relayHandler)
    {
        $this->relayHandler = $relayHandler;
    }

    /**
     * Chat Completions - 对标 POST /v1/chat/completions
     */
    public function chatCompletions(Request $request): Response
    {
        $channel = $request->attributes->get('selected_channel');

        if (! $channel) {
            return $this->relayError(__('No channel selected'), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $body = json_decode($request->getContent(), true);
        $isStream = ($body['stream'] ?? false) === true;

        if ($isStream) {
            return $this->handleStream($request, $channel, RelayFormat::OpenAI);
        }

        return $this->handleNormal($request, $channel, RelayFormat::OpenAI);
    }

    /**
     * Completions - 对标 POST /v1/completions
     */
    public function completions(Request $request): Response
    {
        $channel = $request->attributes->get('selected_channel');

        if (! $channel) {
            return $this->relayError(__('No channel selected'), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->handleNormal($request, $channel, RelayFormat::OpenAICompletions);
    }

    /**
     * Responses API - 对标 POST /v1/responses
     */
    public function responses(Request $request): Response
    {
        $channel = $request->attributes->get('selected_channel');

        if (! $channel) {
            return $this->relayError(__('No channel selected'), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $body = json_decode($request->getContent(), true);
        $isStream = ($body['stream'] ?? false) === true;

        if ($isStream) {
            return $this->handleStream($request, $channel, RelayFormat::OpenAIResponses);
        }

        return $this->handleNormal($request, $channel, RelayFormat::OpenAIResponses);
    }

    /**
     * Responses Compact - 对标 POST /v1/responses/compact
     */
    public function responsesCompact(Request $request): Response
    {
        return $this->handleNormal($request, $request->attributes->get('selected_channel'), RelayFormat::OpenAIResponsesCompaction);
    }

    /**
     * Embeddings - 对标 POST /v1/embeddings
     */
    public function embeddings(Request $request): Response
    {
        $channel = $request->attributes->get('selected_channel');

        if (! $channel) {
            return $this->relayError(__('No channel selected'), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->handleNormal($request, $channel, RelayFormat::Embedding);
    }

    /**
     * Image Generations - 对标 POST /v1/images/generations
     */
    public function imageGenerations(Request $request): Response
    {
        $channel = $request->attributes->get('selected_channel');

        if (! $channel) {
            return $this->relayError(__('No channel selected'), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->handleNormal($request, $channel, RelayFormat::OpenAIImage);
    }

    /**
     * Image Edits - 对标 POST /v1/images/edits
     */
    public function imageEdits(Request $request): Response
    {
        $channel = $request->attributes->get('selected_channel');

        return $this->handleNormal($request, $channel, RelayFormat::OpenAIImage);
    }

    /**
     * Edits - 对标 POST /v1/edits
     */
    public function edits(Request $request): Response
    {
        $channel = $request->attributes->get('selected_channel');

        return $this->handleNormal($request, $channel, RelayFormat::OpenAI);
    }

    /**
     * Audio Transcriptions - 对标 POST /v1/audio/transcriptions
     */
    public function audioTranscriptions(Request $request): Response
    {
        $channel = $request->attributes->get('selected_channel');

        return $this->handleNormal($request, $channel, RelayFormat::OpenAIAudio);
    }

    /**
     * Audio Translations - 对标 POST /v1/audio/translations
     */
    public function audioTranslations(Request $request): Response
    {
        $channel = $request->attributes->get('selected_channel');

        return $this->handleNormal($request, $channel, RelayFormat::OpenAIAudio);
    }

    /**
     * Audio Speech - 对标 POST /v1/audio/speech
     */
    public function audioSpeech(Request $request): Response
    {
        $channel = $request->attributes->get('selected_channel');

        return $this->handleNormal($request, $channel, RelayFormat::OpenAIAudio);
    }

    /**
     * Rerank - 对标 POST /v1/rerank
     */
    public function rerank(Request $request): Response
    {
        $channel = $request->attributes->get('selected_channel');

        return $this->handleNormal($request, $channel, RelayFormat::Rerank);
    }

    /**
     * Moderations - 对标 POST /v1/moderations
     */
    public function moderations(Request $request): Response
    {
        return response()->json([
            'id' => 'mod-'.uniqid(),
            'model' => 'text-moderation-007',
            'results' => [],
        ]);
    }

    /**
     * Claude Messages API - 对标 POST /v1/messages
     *
     * 支持两种入站格式：
     * 1. Anthropic 原生格式（检测到 anthropic-version 请求头）→ 透传，不做格式转换
     * 2. OpenAI 格式（默认）→ 使用 ClaudeAdapter 做格式转换
     */
    public function claudeMessages(Request $request): Response
    {
        $channel = $request->attributes->get('selected_channel');

        if (! $channel) {
            return $this->relayError(__('No channel selected'), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // 检测入站请求是否为 Anthropic 原生格式
        $isAnthropicNative = $this->isAnthropicNativeRequest($request);

        $body = json_decode($request->getContent(), true);
        $isStream = ($body['stream'] ?? false) === true;

        if ($isStream) {
            return $this->handleStream($request, $channel, RelayFormat::Claude, $isAnthropicNative);
        }

        return $this->handleNormal($request, $channel, RelayFormat::Claude, $isAnthropicNative);
    }

    /**
     * 检测入站请求是否为 Anthropic 原生格式
     *
     * 判断依据：
     * - 存在 `anthropic-version` 请求头（Anthropic SDK 必发）
     * - 或存在 `x-api-key` 请求头且不存在 `Authorization: Bearer` 头
     * - 或请求体包含 Anthropic 特有字段（如 max_tokens 而非 max_completion_tokens）
     */
    protected function isAnthropicNativeRequest(Request $request): bool
    {
        // 1. anthropic-version 请求头 — 最可靠的判断
        if ($request->header('anthropic-version')) {
            return true;
        }

        // 2. x-api-key 请求头且无 Authorization: Bearer — Anthropic SDK 使用 x-api-key
        if ($request->header('x-api-key') && ! str_starts_with($request->header('authorization', ''), 'Bearer ')) {
            return true;
        }

        // 3. 请求体结构判断：Anthropic 格式有 messages 数组但没有 OpenAI 特有的 n / frequency_penalty 等字段
        $body = json_decode($request->getContent(), true);
        if (is_array($body)) {
            $hasAnthropicFields = isset($body['max_tokens']) || isset($body['system']);
            $hasOpenAIFields = isset($body['n']) || isset($body['frequency_penalty'])
                || isset($body['presence_penalty']) || isset($body['logprobs']);

            if ($hasAnthropicFields && ! $hasOpenAIFields) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gemini Embeddings - 对标 POST /v1/engines/{model}/embeddings
     */
    public function geminiEmbeddings(Request $request): Response
    {
        $channel = $request->attributes->get('selected_channel');

        return $this->handleNormal($request, $channel, RelayFormat::Embedding);
    }

    /**
     * Gemini Relay - 对标 POST /v1/models/{path}
     */
    public function geminiRelay(Request $request): Response
    {
        $channel = $request->attributes->get('selected_channel');

        if (! $channel) {
            return $this->relayError(__('No channel selected'), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $body = json_decode($request->getContent(), true);
        $isStream = ($body['stream'] ?? false) === true;

        if ($isStream) {
            return $this->handleStream($request, $channel, RelayFormat::Gemini);
        }

        return $this->handleNormal($request, $channel, RelayFormat::Gemini);
    }

    /**
     * WebSocket Realtime - 对标 GET /v1/realtime
     */
    public function realtime(Request $request): JsonResponse
    {
        return $this->relayError(__('WebSocket realtime not implemented yet'), Response::HTTP_NOT_IMPLEMENTED);
    }

    /**
     * Playground Chat - 对标 POST /pg/chat/completions
     */
    public function playground(Request $request): Response
    {
        return $this->chatCompletions($request);
    }

    /**
     * 路由别名：POST /api/v1/chat/completions（routes/api.php 引用 chat）
     */
    public function chat(Request $request): Response
    {
        return $this->chatCompletions($request);
    }

    /**
     * 路由别名：POST /api/pg/chat/completions（routes/api.php 引用 playgroundChat）
     */
    public function playgroundChat(Request $request): Response
    {
        return $this->chatCompletions($request);
    }

    /**
     * 路由别名：GET /api/v1/models（routes/api.php 引用 models）
     */
    public function models(Request $request): JsonResponse
    {
        return app(ModelController::class)->list($request);
    }

    /**
     * 路由别名：GET /api/v1/models/{model}（routes/api.php 引用 model）
     */
    public function model(Request $request, string $model): JsonResponse
    {
        return app(ModelController::class)->retrieve($request, $model);
    }

    /**
     * 兜底路由：未知 /api/* 路径（携带有效令牌时命中）。
     * 对标 new-api：返回 OpenAI 风格 404，而不是致命错误。
     */
    public function catchAll(Request $request, string $path): JsonResponse
    {
        return response()->json([
            'error' => [
                'message' => sprintf('Invalid URL (%s %s)', $request->method(), '/'.$path),
                'type' => 'invalid_request_error',
                'code' => 'unknown_url',
            ],
        ], 404);
    }

    /**
     * Dashboard Subscription - 对标 GET /dashboard/billing/subscription
     */
    public function dashboardSubscription(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        return response()->json([
            'subscription' => [
                'status' => 'inactive',
                'plan_id' => null,
                'current_period_end' => null,
            ],
        ]);
    }

    /**
     * Dashboard Usage - 对标 GET /dashboard/billing/usage
     */
    public function dashboardUsage(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        return response()->json([
            'usage' => [
                'total_usage' => 0,
                'usage_by_model' => [],
            ],
        ]);
    }

    /**
     * Not Implemented - 对标未实现的接口
     */
    public function notImplemented(Request $request): JsonResponse
    {
        return $this->relayError(__('This endpoint is not implemented yet'), Response::HTTP_NOT_IMPLEMENTED);
    }

    /**
     * 处理普通请求 (非流式)
     */
    protected function handleNormal(Request $request, ?Channel $channel, string $format, bool $isAnthropicNative = false): Response
    {
        $relayInfo = new RelayInfo;
        $relayInfo->request = $request;
        $relayInfo->channel = $channel;
        $relayInfo->relayFormat = $format;
        // 注入 Token / 用户身份（计费与 Coding Plan 订阅校验依赖）
        $relayInfo->hydrateFromRequest($request);

        // 设置入站协议类型
        if ($isAnthropicNative) {
            $relayInfo->relayProtocol = RelayProtocol::Anthropic;
        }

        // 敏感词请求内容检查（预扣之前：命中 → 400，不扣费、不写消费日志、不调用上游）
        $sensitiveError = $this->sensitivePromptError($request, $relayInfo);
        if ($sensitiveError !== null) {
            return $sensitiveError;
        }

        // 请求前预扣额度（余额不足 → 429；此时尚未产生任何上游调用）
        $preError = app(BillingService::class)->preConsume($relayInfo);
        if ($preError !== null) {
            return response()->json(['error' => $preError], 429);
        }

        try {
            // 适配器可能直接 echo 上游内容（new-api 风格），捕获后统一走 Response 返回，
            // 保证 HTTP 状态码与响应体唯一、可预测。
            ob_start();
            $result = $this->relayHandler->handle($relayInfo);
            ob_end_clean();

            $this->logRequest($request, $relayInfo, $result);

            if ($result instanceof Response) {
                return $result;
            }

            $body = is_string($result) ? $result : json_encode($result);
            $status = $relayInfo->responseStatus > 0 ? $relayInfo->responseStatus : Response::HTTP_OK;

            // 敏感词响应内容检查（StopOnSensitiveEnabled；上游成本已产生，计费照常）
            $sensitive = app(SensitiveWordService::class);
            $sensitiveHit = $sensitive->checkResponse($body);
            if ($sensitiveHit !== null) {
                $sensitive->logHit('response', $sensitiveHit, $relayInfo->userId, $relayInfo->modelName);

                return response()->json(SensitiveWordService::errorPayload(), Response::HTTP_BAD_REQUEST);
            }

            return response($body, $status, ['Content-Type' => 'application/json']);
        } catch (\Exception $e) {
            return $this->relayError($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * 处理流式请求 (SSE)；余额不足时返回 429 JSON（Response 优于 StreamedResponse 精确类型）
     */
    protected function handleStream(Request $request, ?Channel $channel, string $format, bool $isAnthropicNative = false): Response
    {
        $relayInfo = new RelayInfo;
        $relayInfo->request = $request;
        $relayInfo->channel = $channel;
        $relayInfo->relayFormat = $format;
        $relayInfo->isStream = true;
        // 注入 Token / 用户身份（计费与 Coding Plan 订阅校验依赖）
        $relayInfo->hydrateFromRequest($request);

        // 设置入站协议类型
        if ($isAnthropicNative) {
            $relayInfo->relayProtocol = RelayProtocol::Anthropic;
        }

        // 敏感词请求内容检查（预扣之前：命中 → 400 JSON；SSE 响应头尚未发出，可正常返回非流式错误）
        $sensitiveError = $this->sensitivePromptError($request, $relayInfo);
        if ($sensitiveError !== null) {
            return $sensitiveError;
        }

        // 请求前预扣额度（余额不足 → 429 JSON；SSE 响应头尚未发出，可正常返回非流式错误）
        $preError = app(BillingService::class)->preConsume($relayInfo);
        if ($preError !== null) {
            return response()->json(['error' => $preError], 429);
        }

        return new StreamedResponse(function () use ($request, $relayInfo, $isAnthropicNative) {
            // 设置 SSE 头
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');

            try {
                // 通过回调统一输出，并跟踪上游是否已发送 [DONE]，避免重复结束标记
                $doneSent = false;
                // 敏感词响应内容检查（StopOn）：滚动窗口扫描原始输出；命中 → 下发错误事件并截断后续输出
                $sensitive = app(SensitiveWordService::class);
                $sensitiveWindow = '';
                $sensitiveHit = false;
                $this->relayHandler->handleStream($relayInfo, function ($chunk) use (&$doneSent, $sensitive, &$sensitiveWindow, &$sensitiveHit, $isAnthropicNative, $relayInfo) {
                    $chunk = (string) $chunk;

                    if ($sensitive->shouldCheckResponse() && ! $sensitiveHit) {
                        $sensitiveWindow = mb_substr($sensitiveWindow.$chunk, -SensitiveWordService::STREAM_WINDOW);
                        $sensitiveHitWord = $sensitive->findIn($sensitiveWindow);
                        if ($sensitiveHitWord !== null) {
                            $sensitiveHit = true;
                            $sensitive->logHit('response:stream', $sensitiveHitWord, $relayInfo->userId, $relayInfo->modelName);

                            if ($isAnthropicNative) {
                                echo 'event: error'."\n";
                                echo 'data: '.json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'your request contains sensitive words']])."\n\n";
                            } else {
                                echo 'data: '.json_encode(SensitiveWordService::errorPayload())."\n\n";
                            }
                            @ob_flush();
                            @flush();

                            return;
                        }
                    }

                    if ($sensitiveHit) {
                        return; // 命中后丢弃后续输出
                    }

                    echo $chunk;
                    if (str_contains($chunk, '[DONE]')) {
                        $doneSent = true;
                    }
                    @ob_flush();
                    @flush();
                });

                // 记录日志
                $this->logStreamRequest($request, $relayInfo);

                if (! $doneSent) {
                    echo "data: [DONE]\n\n";
                    @flush();
                }
            } catch (\Exception $e) {
                if ($isAnthropicNative) {
                    // Anthropic 原生 SSE 错误事件
                    echo 'event: error'."\n";
                    echo 'data: '.json_encode(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => $e->getMessage()]])."\n\n";
                } else {
                    echo 'data: '.json_encode(['error' => ['message' => $e->getMessage()]])."\n\n";
                }
            }

            // OpenAI 协议以 [DONE] 结束，Anthropic 协议以 message_stop 事件结束（由上游发送）
            if (! $isAnthropicNative) {
                echo "data: [DONE]\n\n";
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * 记录请求日志
     */
    protected function logRequest(Request $request, RelayInfo $info, $result): void
    {
        // 调用 BillingService 计算费用并记录日志
        // 这里简化处理
    }

    /**
     * 记录流式请求日志
     */
    protected function logStreamRequest(Request $request, RelayInfo $info): void
    {
        // 流式完成后记录
    }

    /**
     * 敏感词请求内容检查（Round 17）：命中 → 400 OpenAI 风格错误。
     * 必须在 preConsume 之前调用：拒绝时不产生预扣/计费/消费日志，也不调用上游。
     * 错误消息不回显命中词，避免词表枚举探针（命中词只进服务端日志）。
     */
    protected function sensitivePromptError(Request $request, RelayInfo $relayInfo): ?Response
    {
        $sensitive = app(SensitiveWordService::class);
        if (! $sensitive->isEnabled() || ! $sensitive->shouldCheckPrompt()) {
            return null;
        }

        $decoded = json_decode($request->getContent(), true);
        if (! is_array($decoded)) {
            return null;
        }

        $hit = $sensitive->findInPayload($decoded);
        if ($hit === null) {
            return null;
        }

        $sensitive->logHit('prompt', $hit, $relayInfo->userId, $relayInfo->modelName);

        return response()->json(SensitiveWordService::errorPayload(), Response::HTTP_BAD_REQUEST);
    }

    /**
     * 返回 Relay 错误响应 (OpenAI 格式)
     */
    protected function relayError(string $message, int $code = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return response()->json([
            'error' => [
                'message' => $message,
                'type' => 'invalid_request_error',
                'code' => 'relay_error',
            ],
        ], $code);
    }
}
