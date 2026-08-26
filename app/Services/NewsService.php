<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Token;
use App\Models\User;
use App\News\NewsProviderRegistry;
use App\News\NewsSearchRequest;
use App\News\NewsSearchResult;
use App\News\Providers\NewsProviderInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log as LogFacade;
use Illuminate\Support\Str;

/**
 * 新闻 / 搜索 API 聚合服务
 *
 * 流程：选择 Provider + 渠道 -> 预扣配额 -> 调用上游 -> 归一化响应 -> 结算配额 + 记日志
 */
class NewsService
{
    /** 纯新闻 ChannelType（走 /news 端点） */
    protected array $newsChannelTypes = [
        ChannelType::NEWS_API,
    ];

    /** 通用搜索 ChannelType（走 /search 端点） */
    protected array $searchChannelTypes = [
        ChannelType::TAVILY,
        ChannelType::EXA,
        ChannelType::BRAVE_SEARCH,
        ChannelType::GOOGLE_CUSTOM_SEARCH,
    ];

    public function __construct(
        protected NewsProviderRegistry $registry,
        protected LogService $logService,
    ) {}

        /**
     * 执行新闻搜索（仅使用新闻类 Provider）
     *
     * @return array<string, mixed>
     */
    public function search(Request $request): array
    {
        return $this->doSearch($request, 'news');
    }

    /**
     * 执行网页搜索（仅使用搜索类 Provider）
     *
     * @return array<string, mixed>
     */
    public function searchWeb(Request $request): array
    {
        return $this->doSearch($request, 'search');
    }

    /**
     * 统一搜索逻辑
     *
     * @param  'news'|'search'  $mode  news = 仅新闻渠道，search = 仅搜索渠道
     * @return array<string, mixed>
     */
    protected function doSearch(Request $request, string $mode): array
    {
        $searchRequest = NewsSearchRequest::fromArray($request->all());

        $errors = $searchRequest->validate();
        if (! empty($errors)) {
            return [
                'success' => false,
                'code' => 'invalid_request',
                'message' => 'Validation failed',
                'errors' => $errors,
            ];
        }

        /** @var Token $token */
        $token = $request->attributes->get('token');
        /** @var User $user */
        $user = $request->attributes->get('api_user');
        $userId = (int) $request->attributes->get('user_id', 0);
        $tokenId = (int) $request->attributes->get('token_id', 0);
        $userGroup = (string) $request->attributes->get('user_group', 'default');
        $requestId = (string) Str::uuid();

                [$provider, $channel] = $this->selectProviderAndChannel($searchRequest->provider, $userGroup, $mode);

        if (! $provider || ! $channel) {
            return [
                'success' => false,
                'code' => 'no_channel',
                'message' => __('No available news/search channel'),
            ];
        }

        $providerKey = $provider->getProviderKey();
        $quota = $this->calculateQuota($providerKey);

        if (! $this->preConsumeQuota($token, $user, $quota)) {
            return [
                'success' => false,
                'code' => 'quota_exceeded',
                'message' => __('Insufficient quota'),
            ];
        }

        try {
            $result = $provider->search($searchRequest, $channel);
            $result->provider = $providerKey;

            $this->postSettle(
                $channel, $quota, $providerKey, $searchRequest, $result,
                $userId, $tokenId, $requestId, $request->ip()
            );

            return [
                'success' => true,
                'data' => $result->toArray(),
                'quota_consumed' => $quota,
                'request_id' => $requestId,
            ];
        } catch (\Exception $e) {
            $this->refundQuota($token, $user, $quota);

            LogFacade::error('News search failed: '.$e->getMessage(), [
                'provider' => $providerKey,
                'query' => $searchRequest->query,
                'channel_id' => $channel->id,
            ]);

            return [
                'success' => false,
                'code' => 'upstream_error',
                'message' => $e->getMessage(),
                'request_id' => $requestId,
            ];
        }
    }

    /**
     * 返回可用 Provider 列表
     *
     * @return array<int, array<string, mixed>>
     */
    public function listProviders(): array
    {
        return $this->registry->list();
    }

    /**
     * 选择 Provider 和渠道
     *
     * @return array{0: ?NewsProviderInterface, 1: ?Channel}
     */
        protected function selectProviderAndChannel(?string $providerKey, string $userGroup, string $mode = 'news'): array
    {
        if ($providerKey) {
            $provider = $this->registry->get($providerKey);
            if (! $provider) {
                return [null, null];
            }

            return [$provider, $this->findChannel($provider->getChannelType(), $userGroup)];
        }

        $channelTypes = $mode === 'search' ? $this->searchChannelTypes : $this->newsChannelTypes;

        foreach ($channelTypes as $type) {
            $provider = $this->registry->getByChannelType($type);
            if (! $provider) {
                continue;
            }
            $channel = $this->findChannel($type, $userGroup);
            if ($channel) {
                return [$provider, $channel];
            }
        }

        return [null, null];
    }

    /**
     * 查找可用渠道（按 type + 分组 + 优先级）
     */
    protected function findChannel(ChannelType $type, string $userGroup): ?Channel
    {
        return Channel::byType($type->value)
            ->enabled()
            ->where(function ($q) use ($userGroup) {
                $q->whereRaw('FIND_IN_SET(?, `group`)', [$userGroup])
                    ->orWhereRaw('FIND_IN_SET(?, `group`)', ['default'])
                    ->orWhere('group', '')
                    ->orWhereNull('group');
            })
            ->orderByDesc('priority')
            ->orderByDesc('weight')
            ->first();
    }

    /**
     * 计算单次搜索配额消耗
     */
    protected function calculateQuota(string $providerKey): int
    {
        $perProvider = config('pease-api.news.quota_per_search', []);
        if (isset($perProvider[$providerKey])) {
            return (int) $perProvider[$providerKey];
        }

        return (int) config('pease-api.news.default_quota_per_search', 1);
    }

    /**
     * 预扣配额（原子条件更新，避免超扣）
     */
    protected function preConsumeQuota(Token $token, User $user, int $quota): bool
    {
        if ($quota <= 0 || $token->unlimited_quota) {
            return true;
        }

        $affected = DB::table('tokens')
            ->where('id', $token->id)
            ->where('remain_quota', '>=', $quota)
            ->update([
                'remain_quota' => DB::raw('remain_quota - '.$quota),
                'used_quota' => DB::raw('used_quota + '.$quota),
            ]);

        if ($affected === 0) {
            return false;
        }

        DB::table('users')
            ->where('id', $user->id)
            ->update([
                'quota' => DB::raw('GREATEST(quota - '.$quota.', 0)'),
                'used_quota' => DB::raw('used_quota + '.$quota),
            ]);

        return true;
    }

    /**
     * 退还预扣配额（上游失败时）
     */
    protected function refundQuota(Token $token, User $user, int $quota): void
    {
        if ($quota <= 0 || $token->unlimited_quota) {
            return;
        }

        DB::table('tokens')
            ->where('id', $token->id)
            ->update([
                'remain_quota' => DB::raw('remain_quota + '.$quota),
                'used_quota' => DB::raw('used_quota - '.$quota),
            ]);

        DB::table('users')
            ->where('id', $user->id)
            ->update([
                'quota' => DB::raw('quota + '.$quota),
                'used_quota' => DB::raw('used_quota - '.$quota),
            ]);
    }

    /**
     * 结算：更新渠道使用量 + 写入日志
     *
     * @param  NewsSearchResult  $result
     */
    protected function postSettle(
        Channel $channel,
        int $quota,
        string $providerKey,
        NewsSearchRequest $searchRequest,
        $result,
        int $userId,
        int $tokenId,
        string $requestId,
        string $ip,
    ): void {
        DB::table('channels')
            ->where('id', $channel->id)
            ->update(['used_quota' => DB::raw('used_quota + '.$quota)]);

        $this->logService->createLog([
            'user_id' => $userId,
            'token_id' => $tokenId,
            'channel_id' => $channel->id,
            'ability_id' => 0,
            'type' => 2,
            'model' => 'news:'.$providerKey,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'quota' => $quota,
            'request_id' => $requestId,
            'ip' => $ip,
            'detail' => json_encode([
                'query' => $searchRequest->query,
                'provider' => $providerKey,
                'max_results' => $searchRequest->maxResults,
                'results_count' => count($result->articles),
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => time(),
        ]);
    }
}
