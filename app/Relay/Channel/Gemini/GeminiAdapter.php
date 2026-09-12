<?php

declare(strict_types=1);

namespace App\Relay\Channel\Gemini;

use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\GeminiCompatibleTrait;
use App\Relay\Common\RelayInfo;

/**
 * Google Gemini API 适配器（Generative Language API）
 *
 * - 入站 OpenAI Chat Completions → Gemini 协议转换（system → systemInstruction、
 *   assistant → model、多模态数组聚合 text 段）
 * - 流式：:streamGenerateContent?alt=sse → OpenAI chunk 转换 + usageMetadata 计费
 *   （GeminiCompatibleTrait：断连感知 + curl_multi 每秒探活，与 OpenAICompatibleTrait 同构）
 * - 非流式 usageMetadata 计费（prompt/candidates/thoughts/cachedContent）
 * - 渠道 base_url 可覆盖网关根（getUpstreamUrl 版本段去重）
 */
class GeminiAdapter extends BaseAdapter
{
    use GeminiCompatibleTrait;

    protected string $name = 'gemini';

    protected int $apiType = 50; // ChannelType::GOOGLE_GEMINI

    public function formatRequest(RelayInfo $info): void
    {
        $body = $info->requestBody;
        $info->isStream = (bool) ($body['stream'] ?? false);

        $model = $info->upstreamModelName !== ''
            ? $info->upstreamModelName
            : (string) ($body['model'] ?? 'gemini-1.5-pro');

        $info->upstreamBody = json_encode($this->buildGeminiChatBody($body));

        $action = $info->isStream ? 'streamGenerateContent' : 'generateContent';
        $info->upstreamUrl = $info->getUpstreamUrl('/v1beta/models/'.rawurlencode($model).':'.$action);
    }

    public function doRequest(RelayInfo $info): void
    {
        if ($info->isStream) {
            return; // 流式由 streamHandler 处理（对齐 OpenAIAdapter 结构）
        }

        $ch = curl_init($info->upstreamUrl.'?key='.rawurlencode($this->resolveApiKey($info)));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $info->upstreamBody,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
        ]);

        $info->responseBody = curl_exec($ch);
        $info->responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    public function formatResponse(RelayInfo $info): void
    {
        $this->formatGeminiCompatibleResponse($info);
    }

    public function errorHandler(RelayInfo $info): void
    {
        $this->formatGeminiCompatibleError($info);
    }

    protected function buildGeminiStreamUrl(RelayInfo $info): string
    {
        // formatRequest 已按 isStream 选择 :streamGenerateContent，仅补 SSE 标记
        return $info->upstreamUrl.'?alt=sse&key='.rawurlencode($this->resolveApiKey($info));
    }

    /**
     * @return array<string, string>
     */
    protected function buildGeminiStreamHeaders(RelayInfo $info): array
    {
        return ['Content-Type' => 'application/json'];
    }

    private function resolveApiKey(RelayInfo $info): string
    {
        return $info->apiKey !== '' ? $info->apiKey : (string) ($info->channel->key ?? '');
    }
}
