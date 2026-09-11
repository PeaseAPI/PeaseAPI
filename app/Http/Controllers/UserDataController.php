<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\LogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 用量数据控制器 - 对标 new-api controller/data.go
 *
 * usedata/usedata_flow 表未建（无迁移），统一从 logs 表按消费日志（type=2）实时聚合。
 */
class UserDataController extends Controller
{
    public function __construct(protected LogService $logService) {}

    /**
     * 获取所有用量数据（管理员）— 时间桶 × 模型，QuotaDataItem[]
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->timeRangeParams($request);
        if ($userId = (int) $request->input('user_id', 0)) {
            $filters['user_id'] = $userId;
        }
        if ($username = trim((string) $request->input('username', ''))) {
            $filters['username'] = $username;
        }

        return $this->success($this->logService->quotaData(
            $filters,
            $this->bucketSeconds($request),
            'model_name'
        ));
    }

    /**
     * 获取用户维度用量（管理员）— 时间桶 × 用户，QuotaDataItem[]
     */
    public function users(Request $request): JsonResponse
    {
        $filters = $this->timeRangeParams($request);
        if ($userId = (int) $request->input('user_id', 0)) {
            $filters['user_id'] = $userId;
        }
        if ($username = trim((string) $request->input('username', ''))) {
            $filters['username'] = $username;
        }

        return $this->success($this->logService->quotaData(
            $filters,
            $this->bucketSeconds($request),
            'user_id'
        ));
    }

    /**
     * 获取流量数据（管理员）— 组×token×渠道×模型，FlowQuotaDataItem[]
     */
    public function flow(Request $request): JsonResponse
    {
        $filters = $this->timeRangeParams($request);
        if ($userId = (int) $request->input('user_id', 0)) {
            $filters['user_id'] = $userId;
        }
        if ($username = trim((string) $request->input('username', ''))) {
            $filters['username'] = $username;
        }

        return $this->success($this->logService->flowData($filters));
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
}
