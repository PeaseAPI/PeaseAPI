<?php

declare(strict_types=1);

namespace App\News\Providers;

use App\Models\Channel;
use Illuminate\Support\Facades\Http;

/**
 * 新闻 Provider 抽象基类
 *
 * 提供通用的凭证解析、HTTP 请求封装与配置读取能力。
 */
abstract class AbstractNewsProvider implements NewsProviderInterface
{
    protected int $timeout;

    public function __construct()
    {
        $this->timeout = (int) config('pease-api.news.timeout', 30);
    }

    /**
     * 从渠道获取 API Key（支持多 Key 轮询，随机选取）
     */
    protected function getKey(Channel $channel): string
    {
        $keys = $channel->getKeys();
        if (empty($keys)) {
            return '';
        }

        return $keys[array_rand($keys)];
    }

    /**
     * 获取上游 Base URL（渠道自定义优先，否则用类型默认）
     */
    protected function getBaseUrl(Channel $channel): string
    {
        $baseUrl = trim((string) $channel->base_url);
        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/');
        }

        return rtrim($this->getChannelType()->baseUrl(), '/');
    }

    /**
     * 从渠道 setting JSON 中读取配置项
     *
     * @param  mixed  $default
     */
    protected function getSetting(Channel $channel, string $key, $default = null): mixed
    {
        $setting = $channel->setting ?? [];

        return $setting[$key] ?? $default;
    }

    /**
     * 是否在结果中附带原始上游数据
     */
    protected function shouldIncludeRaw(): bool
    {
        return (bool) config('pease-api.news.include_raw', false);
    }

    /**
     * 发起 GET 请求
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    protected function httpGet(string $url, array $params = [], array $headers = []): array
    {
        $response = Http::withHeaders($headers)->timeout($this->timeout)->get($url, $params);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Upstream request failed: HTTP '.$response->status().' - '.$response->body(),
                $response->status()
            );
        }

        return $response->json() ?? [];
    }

    /**
     * 发起 POST 请求
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    protected function httpPost(string $url, array $body = [], array $headers = []): array
    {
        $response = Http::withHeaders($headers)->timeout($this->timeout)->post($url, $body);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Upstream request failed: HTTP '.$response->status().' - '.$response->body(),
                $response->status()
            );
        }

        return $response->json() ?? [];
    }
}
