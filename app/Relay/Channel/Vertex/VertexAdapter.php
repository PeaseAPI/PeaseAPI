<?php

declare(strict_types=1);

namespace App\Relay\Channel\Vertex;

use App\Relay\Channel\BaseAdapter;
use App\Relay\Common\RelayInfo;

/**
 * Google Vertex AI 适配器
 *
 * 与 Gemini API 共享请求/响应格式，但端点与认证不同：
 * - 端点: https://{region}-aiplatform.googleapis.com/v1/projects/{project}/locations/{region}/publishers/google/models/{model}:generateContent
 * - 认证: OAuth2 Bearer Token（由 Service Account JSON 签发，或直接使用 access token）
 *
 * 渠道 key 支持两种格式：
 *   1. JSON Service Account 凭据（含 private_key / client_email / project_id）
 *   2. 直接的 access token（配合 other 字段填写 project_id / region）
 */
class VertexAdapter extends BaseAdapter
{
    protected string $name = 'vertex';

    protected int $apiType = 21; // ChannelType::VERTEX

    /** @var array<string, string> access_token 缓存: hash(key) => "token|expires_at" */
    protected static array $tokenCache = [];

    public function formatRequest(RelayInfo $info): void
    {
        $body = $info->requestBody;
        $model = $body['model'] ?? 'gemini-1.5-pro';

        $geminiBody = [
            'contents' => $this->convertMessages($body['messages'] ?? []),
            'generationConfig' => [
                'maxOutputTokens' => $body['max_tokens'] ?? 4096,
                'temperature' => $body['temperature'] ?? 0.7,
                'topP' => $body['top_p'] ?? 0.95,
            ],
        ];

        if (! empty($body['system'])) {
            $geminiBody['systemInstruction'] = ['parts' => [['text' => $body['system']]]];
        }

        $info->upstreamBody = json_encode($geminiBody);

        [$project, $region] = $this->resolveProjectRegion($info);
        $action = ! empty($body['stream']) ? 'streamGenerateContent' : 'generateContent';
        $info->upstreamUrl = sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:%s',
            $region,
            $project,
            $region,
            $model,
            $action
        );
    }

    public function formatResponse(RelayInfo $info): void
    {
        $body = json_decode($info->responseBody, true);

        $reqBody = $info->requestBody ?? [];
        $model = is_array($reqBody) ? ($reqBody['model'] ?? 'gemini-1.5-pro') : 'gemini-1.5-pro';

        $openai = [
            'id' => 'chatcmpl-'.uniqid(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $model,
            'choices' => [],
        ];

        if (! empty($body['candidates'])) {
            $content = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $openai['choices'][] = [
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ];
        }

        $info->responseBody = json_encode($openai);
    }

    public function doRequest(RelayInfo $info): void
    {
        $channel = $info->channel;
        $accessToken = $this->getAccessToken($channel->key);

        $ch = curl_init($info->upstreamUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $info->upstreamBody,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer '.$accessToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);

        $info->responseBody = curl_exec($ch);
        $info->responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    public function streamHandler(RelayInfo $info): void
    {
        $channel = $info->channel;
        $accessToken = $this->getAccessToken($channel->key);

        // Vertex 流式需 alt=sse
        $url = $info->upstreamUrl.'?alt=sse';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $info->upstreamBody,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer '.$accessToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
        ]);

        $info->responseBody = curl_exec($ch);
        $info->responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    /**
     * 解析 project_id 与 region
     *
     * @return array{0:string,1:string} [project, region]
     */
    protected function resolveProjectRegion(RelayInfo $info): array
    {
        $channel = $info->channel;
        $region = 'us-central1';
        $project = '';

        $sa = $this->parseServiceAccount($channel->key);
        if ($sa !== null) {
            $project = $sa['project_id'] ?? '';
        }

        // other 字段可补充 region/project，格式: "region|project" 或 JSON
        $other = $channel->other ?? '';
        if ($other !== '') {
            if (str_starts_with($other, '{')) {
                $oj = json_decode($other, true);
                if (is_array($oj)) {
                    $region = $oj['region'] ?? $region;
                    $project = $project ?: ($oj['project_id'] ?? '');
                }
            } else {
                $parts = explode('|', $other);
                if (count($parts) >= 1 && $parts[0] !== '') {
                    $region = $parts[0];
                }
                if (count($parts) >= 2 && $parts[1] !== '') {
                    $project = $project ?: $parts[1];
                }
            }
        }

        return [$project, $region];
    }

    /**
     * 获取 OAuth2 access token（带缓存）
     */
    protected function getAccessToken(string $key): string
    {
        $cacheKey = hash('sha256', $key);
        $cached = self::$tokenCache[$cacheKey] ?? null;
        if ($cached !== null) {
            [$tok, $exp] = explode('|', $cached);
            if ((int) $exp > time() + 60) {
                return $tok;
            }
        }

        $sa = $this->parseServiceAccount($key);
        if ($sa !== null) {
            $token = $this->exchangeServiceAccountToken($sa);
        } else {
            // 直接当作 access token 使用
            $token = $key;
        }

        return $token;
    }

    /**
     * 解析 Service Account JSON
     *
     * @return array<string, mixed>|null
     */
    protected function parseServiceAccount(string $key): ?array
    {
        $key = trim($key);
        if (! str_starts_with($key, '{')) {
            return null;
        }
        $data = json_decode($key, true);
        if (! is_array($data) || empty($data['private_key']) || empty($data['client_email'])) {
            return null;
        }

        return $data;
    }

    /**
     * 使用 Service Account 签发 JWT 并交换 OAuth2 access token
     */
    protected function exchangeServiceAccountToken(array $sa): string
    {
        $now = time();
        $assertion = $this->buildJwt($sa, $now);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);

        $data = json_decode((string) $resp, true);
        $token = $data['access_token'] ?? '';
        $expiresIn = (int) ($data['expires_in'] ?? 3600);

        $cacheKey = hash('sha256', json_encode($sa));
        self::$tokenCache[$cacheKey] = $token.'|'.(string) ($now + $expiresIn);

        return $token;
    }

    /**
     * 构建 RS256 JWT（使用 openssl 扩展，无需额外依赖）
     */
    protected function buildJwt(array $sa, int $now): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/cloud-platform',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now,
        ];

        $segments = [];
        $segments[] = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $segments[] = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $signingInput = implode('.', $segments);

        $signature = '';
        openssl_sign($signingInput, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256);
        $segments[] = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return implode('.', $segments);
    }

    /**
     * 转换 OpenAI messages -> Gemini contents
     */
    protected function convertMessages(array $messages): array
    {
        $contents = [];
        foreach ($messages as $msg) {
            $contents[] = [
                'role' => $msg['role'] === 'user' ? 'user' : 'model',
                'parts' => [['text' => $msg['content']]],
            ];
        }

        return $contents;
    }
}
