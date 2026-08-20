<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Relay\Common\RelayHandler;
use App\Relay\Common\RelayInfo;
use App\Relay\Constant\RelayFormat;
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
     */
    public function claudeMessages(Request $request): Response
    {
        $channel = $request->attributes->get('selected_channel');

        if (! $channel) {
            return $this->relayError(__('No channel selected'), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $body = json_decode($request->getContent(), true);
        $isStream = ($body['stream'] ?? false) === true;

        if ($isStream) {
            return $this->handleStream($request, $channel, RelayFormat::Claude);
        }

        return $this->handleNormal($request, $channel, RelayFormat::Claude);
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
    protected function handleNormal(Request $request, $channel, string $format): Response
    {
        $relayInfo = new RelayInfo;
        $relayInfo->request = $request;
        $relayInfo->channel = $channel;
        $relayInfo->relayFormat = $format;

        try {
            $result = $this->relayHandler->handle($relayInfo);

            // 记录日志
            $this->logRequest($request, $relayInfo, $result);

            if ($result instanceof Response) {
                return $result;
            }

            return response()->json($result);
        } catch (\Exception $e) {
            return $this->relayError($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * 处理流式请求 (SSE)
     */
    protected function handleStream(Request $request, $channel, string $format): StreamedResponse
    {
        return new StreamedResponse(function () use ($request, $channel, $format) {
            $relayInfo = new RelayInfo;
            $relayInfo->request = $request;
            $relayInfo->channel = $channel;
            $relayInfo->relayFormat = $format;
            $relayInfo->isStream = true;

            // 设置 SSE 头
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');

            try {
                $this->relayHandler->handleStream($relayInfo, function ($chunk) {
                    echo $chunk;
                    ob_flush();
                    flush();
                });

                // 记录日志
                $this->logStreamRequest($request, $relayInfo);
            } catch (\Exception $e) {
                echo 'data: '.json_encode(['error' => ['message' => $e->getMessage()]])."\n\n";
            }

            echo "data: [DONE]\n\n";
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
