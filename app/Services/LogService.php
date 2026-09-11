<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Log;
use App\Relay\Common\RelayInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log as LaravelLog;

class LogService
{
    /**
     * 分页日志列表（filters 见 filterQuery；对标 new-api GetAllLogs/GetUserLogs）
     *
     * @return array{items: Collection, total: int, page: int, page_size: int}
     */
    public function getLogs(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        return $this->pager($this->filterQuery($filters), $page, $pageSize);
    }

    /**
     * 关键字搜索（对标 new-api SearchLogs：keyword 模糊匹配 username/model_name/content/request_id/channel_name；
     * search_type=timestamp 时仅按时间等结构化条件过滤）
     *
     * @return array{items: Collection, total: int, page: int, page_size: int}
     */
    public function searchLogs(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $query = $this->filterQuery($filters);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $searchType = (string) ($filters['search_type'] ?? 'keyword');

        if ($keyword !== '' && $searchType === 'keyword') {
            $query->where(function (Builder $q) use ($keyword) {
                $q->where('username', 'like', "%{$keyword}%")
                    ->orWhere('model_name', 'like', "%{$keyword}%")
                    ->orWhere('content', 'like', "%{$keyword}%")
                    ->orWhere('request_id', 'like', "%{$keyword}%")
                    ->orWhere('channel_name', 'like', "%{$keyword}%");
            });
        }

        return $this->pager($query, $page, $pageSize);
    }

    /**
     * 日志统计（前端 LogStatistics：{quota, rpm, tpm}；rpm/tpm 为过滤窗口内均值）
     */
    public function getLogsStat(array $filters = []): array
    {
        $query = $this->filterQuery($filters);

        $end = (int) ($filters['end_timestamp'] ?? 0);
        $start = (int) ($filters['start_timestamp'] ?? 0);
        $end = $end > 0 ? $end : time();
        $start = $start > 0 ? $start : $end - 86400;
        $spanSeconds = max(60, $end - $start);

        $row = (clone $query)
            ->selectRaw('COALESCE(SUM(quota), 0) AS quota_sum')
            ->selectRaw('COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS token_sum')
            ->selectRaw('COUNT(*) AS cnt')
            ->first();

        $count = (int) ($row->cnt ?? 0);
        $tokens = (int) ($row->token_sum ?? 0);

        return [
            'quota' => (int) ($row->quota_sum ?? 0),
            'token_used' => $tokens,
            'count' => $count,
            'rpm' => round($count * 60 / $spanSeconds, 2),
            'tpm' => round($tokens * 60 / $spanSeconds, 2),
        ];
    }

    /**
     * 记录消费日志（Relay 成功后调用；对标 new-api RecordConsumeLog）
     *
     * quota 为 BillingService::postConsume 的计费结果（$info->quota）；
     * 任何写入异常都不允许影响转发主流程。
     */
    public function recordConsumeLog(RelayInfo $info): void
    {
        try {
            if ($info->userId <= 0) {
                return;
            }

            // LogConsumeEnabled=false 时关闭消费日志（对标 new-api 同名开关）
            if (! OptionService::get('LogConsumeEnabled', true)) {
                return;
            }

            $request = $info->request;
            $token = $request?->attributes->get('token');
            $apiUser = $request?->attributes->get('api_user');
            $requestId = (string) ($request?->header('x-request-id')
                ?? $request?->attributes->get('request_id')
                ?? '');

            Log::create([
                'user_id' => $info->userId,
                'created_at' => time(),
                'type' => Log::TYPE_CONSUME,
                'content' => sprintf(
                    'model %s, prompt %d, completion %d',
                    $info->modelName ?: $info->requestModel,
                    $info->promptTokens,
                    $info->completionTokens
                ),
                'username' => $info->user?->username ?? (string) ($apiUser?->username ?? ''),
                'token_name' => (string) ($token->name ?? ''),
                'model_name' => $info->modelName ?: $info->requestModel,
                'quota' => $info->quota,
                'prompt_tokens' => $info->promptTokens,
                'completion_tokens' => $info->completionTokens,
                'use_time' => (int) $info->getUseTime(),
                'is_stream' => $info->isStream,
                'channel_id' => $info->channelId,
                'channel_name' => (string) ($info->channel?->name ?? ''),
                'token_id' => $info->tokenId,
                'group' => $info->tokenGroup !== ''
                    ? $info->tokenGroup
                    : ($info->userGroup !== '' ? $info->userGroup : 'default'),
                'ip' => (string) ($request?->ip() ?? ''),
                'request_id' => $requestId,
                'other' => json_encode(array_filter([
                    'frt' => $info->firstResponseTime > 0 ? round($info->firstResponseTime - $info->startTime, 3) : null,
                    'model_ratio' => $info->modelRatio != 1.0 ? $info->modelRatio : null,
                    'completion_ratio' => $info->completionRatio != 1.0 ? $info->completionRatio : null,
                    'group_ratio' => $info->groupRatio != 1.0 ? $info->groupRatio : null,
                    'cache_ratio' => $info->cacheRatio != 0.0 ? $info->cacheRatio : null,
                    'cache_tokens' => $info->cachedTokens > 0 ? $info->cachedTokens : null,
                ], static fn ($v) => $v !== null)),
            ]);
        } catch (\Throwable $e) {
            LaravelLog::error('record consume log failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 写入一条日志（自动补 created_at；兼容旧调用方的 created_time 键）
     */
    public function createLog(array $data): Log
    {
        $data['created_at'] = (int) ($data['created_at'] ?? ($data['created_time'] ?? time()));

        return Log::create($data);
    }

    public function deleteOldLogs(int $days = 30): int
    {
        $cutoff = time() - ($days * 86400);

        return Log::where('created_at', '<', $cutoff)->delete();
    }

    /**
     * 用量聚合（前端 QuotaDataItem[]）：按 时间桶 × 维度（model_name/user_id）分组
     *
     * 桶表达式 created_at - (created_at % b)：MySQL/SQLite 通用的整数取整写法。
     */
    public function quotaData(array $filters = [], int $bucketSeconds = 86400, string $dimension = 'model_name'): array
    {
        $bucket = max(60, $bucketSeconds);
        $dim = $dimension === 'user_id' ? 'user_id' : 'model_name';

        $rows = $this->filterQuery($filters)
            ->where('type', Log::TYPE_CONSUME)
            ->selectRaw("{$dim} AS dim_value, (created_at - (created_at % ?)) AS bucket", [$bucket])
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS token_sum, COALESCE(SUM(quota), 0) AS quota_sum')
            ->groupBy('dim_value', 'bucket')
            ->orderByDesc('bucket')
            ->limit(500)
            ->get();

        return $rows->map(function ($r) use ($dim) {
            $item = [
                'model_name' => $dim === 'model_name' ? (string) $r->dim_value : '',
                'created_at' => (int) $r->bucket,
                'token_used' => (int) $r->token_sum,
                'count' => (int) $r->cnt,
                'quota' => (int) $r->quota_sum,
            ];
            if ($dim === 'user_id') {
                $item['user_id'] = (int) $r->dim_value;
            }

            return $item;
        })->all();
    }

    /**
     * 流量明细聚合（前端 FlowQuotaDataItem[]）：组 × token × 渠道 × 模型
     */
    public function flowData(array $filters = []): array
    {
        $rows = $this->filterQuery($filters)
            ->where('type', Log::TYPE_CONSUME)
            ->selectRaw('MAX(user_id) AS user_id, MAX(username) AS username, `group` AS use_group')
            ->selectRaw('MAX(token_id) AS token_id, MAX(token_name) AS token_name')
            ->selectRaw('MAX(channel_id) AS channel_id, MAX(channel_name) AS channel_name, model_name')
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS token_sum, COALESCE(SUM(quota), 0) AS quota_sum')
            ->groupBy('model_name', 'channel_id', 'token_id', 'group')
            ->orderByDesc('quota_sum')
            ->limit(200)
            ->get();

        return $rows->map(fn ($r) => [
            'user_id' => (int) $r->user_id,
            'username' => (string) $r->username,
            'node_name' => (string) $r->model_name,
            'use_group' => (string) $r->use_group,
            'token_id' => (int) $r->token_id,
            'token_name' => (string) $r->token_name,
            'channel_id' => (int) $r->channel_id,
            'channel_name' => (string) $r->channel_name,
            'model_name' => (string) $r->model_name,
            'token_used' => (int) $r->token_sum,
            'count' => (int) $r->cnt,
            'quota' => (int) $r->quota_sum,
        ])->all();
    }

    /**
     * 结构化过滤（对齐前端 GetLogsParams / new-api 查询参数）
     */
    private function filterQuery(array $filters): Builder
    {
        $query = Log::query();

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['token_id'])) {
            $query->where('token_id', (int) $filters['token_id']);
        }
        if (isset($filters['channel']) && $filters['channel'] !== '' && $filters['channel'] !== null) {
            $query->where('channel_id', (int) $filters['channel']);
        }
        if (! empty($filters['channel_id'])) {
            $query->where('channel_id', (int) $filters['channel_id']);
        }
        if (isset($filters['type']) && $filters['type'] !== '' && $filters['type'] !== null) {
            $query->where('type', (int) $filters['type']);
        }
        if (! empty($filters['username'])) {
            $query->where('username', (string) $filters['username']);
        }
        if (! empty($filters['token_name'])) {
            $query->where('token_name', (string) $filters['token_name']);
        }
        if (! empty($filters['model_name'])) {
            $query->where('model_name', (string) $filters['model_name']);
        }
        if (! empty($filters['model'])) {
            $query->where('model_name', (string) $filters['model']);
        }
        if (! empty($filters['group'])) {
            $query->where('group', (string) $filters['group']);
        }
        if (! empty($filters['start_timestamp'])) {
            $query->where('created_at', '>=', (int) $filters['start_timestamp']);
        }
        if (! empty($filters['end_timestamp'])) {
            $query->where('created_at', '<=', (int) $filters['end_timestamp']);
        }
        if (! empty($filters['start_time'])) {
            $query->where('created_at', '>=', (int) $filters['start_time']);
        }
        if (! empty($filters['end_time'])) {
            $query->where('created_at', '<=', (int) $filters['end_time']);
        }
        if (! empty($filters['request_id'])) {
            $query->where('request_id', (string) $filters['request_id']);
        }
        if (! empty($filters['upstream_request_id'])) {
            $query->where('upstream_request_id', (string) $filters['upstream_request_id']);
        }

        return $query;
    }

    /**
     * 统一分页形状（前端 GetLogsResponse：{items, total, page, page_size}）
     *
     * @return array{items: Collection, total: int, page: int, page_size: int}
     */
    private function pager(Builder $query, int $page, int $pageSize): array
    {
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));

        $total = (clone $query)->count();
        $items = $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, $pageSize)
            ->get();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }
}
