<?php

declare(strict_types=1);

namespace App\News;

use App\Enums\ChannelType;
use App\News\Providers\ExaProvider;
use App\News\Providers\GoogleCustomSearchProvider;
use App\News\Providers\NewsApiProvider;
use App\News\Providers\NewsProviderInterface;
use App\News\Providers\TavilyProvider;

/**
 * 新闻 Provider 注册表 / 工厂
 *
 * 维护 provider 标识 -> 适配器实例的映射，并支持按 ChannelType 反查。
 */
class NewsProviderRegistry
{
    /** @var array<string, NewsProviderInterface> */
    protected array $providers = [];

    public function __construct()
    {
        $this->register(new GoogleCustomSearchProvider);
        $this->register(new NewsApiProvider);
        $this->register(new TavilyProvider);
        $this->register(new ExaProvider);
    }

    public function register(NewsProviderInterface $provider): void
    {
        $this->providers[$provider->getProviderKey()] = $provider;
    }

    public function get(string $key): ?NewsProviderInterface
    {
        return $this->providers[$key] ?? null;
    }

    public function getByChannelType(ChannelType $type): ?NewsProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->getChannelType() === $type) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @return array<string, NewsProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * 返回可序列化的 Provider 列表（供 API 暴露给前端）
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $result = [];
        foreach ($this->providers as $key => $provider) {
            $type = $provider->getChannelType();
            $result[] = [
                'key' => $key,
                'channel_type' => $type->value,
                'label' => $type->label(),
                'default_base_url' => $type->baseUrl(),
            ];
        }

        return $result;
    }
}
