<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Log;
use App\Models\User;
use App\Services\LogService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 日志控制器 - 对标 new-api controller/log.go + usage.go
 */
class LogController extends Controller
{
    public function __construct(protected LogService $logService) {}

    /**
     * GET /api/log/（管理员）— 全量日志；非管理员强制 user_id
     */
    public function index(Request $request): JsonResponse
    {
        [$filters, $page, $pageSize] = $this->listParams($request);
        $this->applyViewerScope($request, $filters);

        return response()->json([
            'success' => true,
            'data' => $this->logService->getLogs($filters, $page, $pageSize),
        ]);
    }

    /**
     * GET /api/log/self — 当前用户日志
     */
    public function selfLogs(Request $request): JsonResponse
    {
        [$filters, $page, $pageSize] = $this->listParams($request);
        $filters['user_id'] = $this->viewerId($request);
        unset($filters['username']);

        return response()->json([
            'success' => true,
            'data' => $this->logService->getLogs($filters, $page, $pageSize),
        ]);
    }

    /**
     * GET /api/log/stat（管理员）— 统计 {quota, rpm, tpm}
     */
    public function stat(Request $request): JsonResponse
    {
        $filters = $this->listParams($request)[0];
        $this->applyViewerScope($request, $filters);

        return response()->json([
            'success' => true,
            'data' => $this->logService->getLogsStat($filters),
        ]);
    }

    /**
     * GET /api/log/self/stat — 当前用户统计
     */
    public function selfStat(Request $request): JsonResponse
    {
        $filters = $this->listParams($request)[0];
        $filters['user_id'] = $this->viewerId($request);
        unset($filters['username']);

        return response()->json([
            'success' => true,
            'data' => $this->logService->getLogsStat($filters),
        ]);
    }

    /**
     * GET /api/log/search（管理员）— 关键字搜索
     */
    public function search(Request $request): JsonResponse
    {
        [$filters, $page, $pageSize] = $this->listParams($request);
        $this->applyViewerScope($request, $filters);

        return response()->json([
            'success' => true,
            'data' => $this->logService->searchLogs($filters, $page, $pageSize),
        ]);
    }

    /**
     * GET /api/log/self/search — 当前用户关键字搜索
     */
    public function searchSelfLogs(Request $request): JsonResponse
    {
        [$filters, $page, $pageSize] = $this->listParams($request);
        $filters['user_id'] = $this->viewerId($request);
        unset($filters['username']);

        return response()->json([
            'success' => true,
            'data' => $this->logService->searchLogs($filters, $page, $pageSize),
        ]);
    }

    /**
     * GET /api/log/token（管理员）— 按 Token 维度查询日志
     */
    public function tokenLogs(Request $request): JsonResponse
    {
        [$filters, $page, $pageSize] = $this->listParams($request);
        $this->applyViewerScope($request, $filters);

        return response()->json([
            'success' => true,
            'data' => $this->logService->getLogs($filters, $page, $pageSize),
        ]);
    }

    /**
     * GET /api/data/self — 当前用户用量（时间桶 × 模型聚合，QuotaDataItem[]）
     */
    public function selfData(Request $request): JsonResponse
    {
        $filters = $this->timeRangeParams($request);
        $filters['user_id'] = $this->viewerId($request);
        unset($filters['username']);

        return response()->json([
            'success' => true,
            'data' => $this->logService->quotaData($filters, $this->bucketSeconds($request), 'model_name'),
        ]);
    }

    /**
     * GET /api/data/flow/self — 当前用户流量明细（组×token×渠道×模型聚合）
     */
    public function selfFlow(Request $request): JsonResponse
    {
        $filters = $this->timeRangeParams($request);
        $filters['user_id'] = $this->viewerId($request);
        unset($filters['username']);

        return response()->json([
            'success' => true,
            'data' => $this->logService->flowData($filters),
        ]);
    }

    /**
     * GET|HEAD /api/dashboard/billing/usage — OpenAI 计费兼容；total_usage 单位为美元
     * （对标 one-api GetUsage：消费 quota / QuotaPerUnit）
     */
    public function dashboardUsage(Request $request): JsonResponse
    {
        $userId = $this->viewerId($request);
        $start = (int) ($request->input('start_timestamp')
            ?: strtotime((string) $request->input('start_date', now()->subDays(30)->toDateString())));
        $end = (int) ($request->input('end_timestamp')
            ?: (strtotime((string) $request->input('end_date', now()->toDateString())) + 86400));
        $end = max($end, $start + 1);

        $quota = (int) Log::query()
            ->where('user_id', $userId)
            ->where('type', Log::TYPE_CONSUME)
            ->whereBetween('created_at', [$start, $end])
            ->sum('quota');

        return response()->json([
            'success' => true,
            'object' => 'list',
            'total_usage' => round((float) $quota / max(1.0, SubscriptionService::quotaPerUnit()), 2),
        ]);
    }

    /**
     * GET /log/channel_affinity_usage_cache - 渠道亲和键用量查询（管理端）
     *
     * 上游缓存命中统计（hit/total/tokens/last_seen_at）依赖规则型亲和引擎与
     * 用量日志关联，当前简化实现回显查询参数并返回零值统计；
     * 规则引擎上线后在此填充真实数据。
     */
    public function affinityCacheStat(Request $request): JsonResponse
    {
        $ruleName = trim((string) $request->input('rule_name', ''));
        $usingGroup = trim((string) $request->input('using_group', ''));
        $keyHint = trim((string) $request->input('key_hint', ''));
        $keyFp = trim((string) $request->input('key_fp', ''));

        if ($ruleName === '' || $keyFp === '') {
            return response()->json([
                'success' => false,
                'message' => __('rule_name and key_fp are required'),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'rule_name' => $ruleName,
                'using_group' => $usingGroup,
                'key_hint' => $keyHint,
                'key_fp' => $keyFp,
                'window_seconds' => 0,
                'hit' => 0,
                'total' => 0,
                'last_seen_at' => 0,
            ],
        ]);
    }

    /**
     * 列表参数（p/page_size + 结构化过滤；兼容 start_time/end_time 旧键）
     *
     * @return array{0: array<string, mixed>, 1: int, 2: int} [filters, page, pageSize]
     */
    private function listParams(Request $request): array
    {
        $filters = $request->only([
            'user_id', 'token_id', 'token_name', 'channel_id', 'channel', 'type',
            'username', 'model_name', 'model', 'group', 'start_timestamp',
            'end_timestamp', 'request_id', 'upstream_request_id',
            'keyword', 'search_type',
        ]);
        $filters['start_timestamp'] = (int) ($filters['start_timestamp'] ?? 0);
        $filters['end_timestamp'] = (int) ($filters['end_timestamp'] ?? 0);
        $filters['start_timestamp'] = $filters['start_timestamp'] ?: (int) $request->input('start_time', 0);
        $filters['end_timestamp'] = $filters['end_timestamp'] ?: (int) $request->input('end_time', 0);
        unset($filters['start_time'], $filters['end_time']);

        $page = max(1, (int) $request->input('p', 1));
        $pageSize = (int) $request->input('page_size', 20);

        return [$filters, $page, $pageSize];
    }

    /**
     * 时间窗口参数（start_timestamp/end_timestamp，兼容 start_date/end_date）
     *
     * @return array<string, mixed>
     */
    private function timeRangeParams(Request $request): array
    {
        $start = (int) ($request->input('start_timestamp') ?: strtotime((string) $request->input('start_date', now()->subDays(7)->toDateString())));
        $end = (int) ($request->input('end_timestamp') ?: (strtotime((string) $request->input('end_date', now()->toDateString())) + 86400));

        return [
            'start_timestamp' => max(0, $start),
            'end_timestamp' => max($start + 1, $end),
        ];
    }

    /**
     * 聚合粒度（default_time=hour/day/week，默认 day）
     */
    private function bucketSeconds(Request $request): int
    {
        return match ((string) $request->input('default_time', 'day')) {
            'hour' => 3600,
            'week' => 604800,
            default => 86400,
        };
    }

    /**
     * 管理员可见全部，普通用户强制自身
     */
    private function applyViewerScope(Request $request, array &$filters): void
    {
        $user = $request->user();
        if ($user instanceof User && (int) $user->role < 100) {
            $filters['user_id'] = (int) $user->id;
            unset($filters['username']);
        }
    }

    /**
     * 当前用户 ID（sanctum 用户或 TokenAuth 注入的 attributes）
     */
    private function viewerId(Request $request): int
    {
        $user = $request->user();

        return (int) ($user?->id ?? $request->attributes->get('user_id', 0));
    }
}
