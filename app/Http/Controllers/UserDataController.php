<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Usedata;
use App\Models\UsedataFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 用量数据控制器 - 对标 new-api controller/data.go
 */
class UserDataController extends Controller
{
    /**
     * 获取所有用量数据（管理员）
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);
        $userId = $request->input('user_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $query = Usedata::query()
            ->when($userId, fn ($q, $v) => $q->where('user_id', $v))
            ->when($startDate, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($endDate, fn ($q, $v) => $q->where('date', '<=', $v))
            ->orderByDesc('date');

        return $this->success($query->paginate($perPage));
    }

    /**
     * 获取用户用量统计（管理员）
     */
    public function users(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = Usedata::query()
            ->select('user_id')
            ->selectRaw('SUM(quota_used) as total_quota')
            ->selectRaw('SUM(prompt_tokens + completion_tokens) as total_tokens')
            ->groupBy('user_id')
            ->orderByDesc('total_quota');

        return $this->success($query->paginate($perPage));
    }

    /**
     * 获取当前用户用量
     */
    public function self(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('user_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $query = Usedata::where('user_id', $userId)
            ->when($startDate, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($endDate, fn ($q, $v) => $q->where('date', '<=', $v))
            ->orderByDesc('date');

        return $this->success($query->get());
    }

    /**
     * 获取流量数据（管理员）
     */
    public function flow(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);
        $userId = $request->input('user_id');

        $query = UsedataFlow::query()
            ->when($userId, fn ($q, $v) => $q->where('user_id', $v))
            ->orderByDesc('id');

        return $this->success($query->paginate($perPage));
    }

    /**
     * 获取当前用户流量
     */
    public function flowSelf(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('user_id');
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = UsedataFlow::where('user_id', $userId)
            ->orderByDesc('id');

        return $this->success($query->paginate($perPage));
    }
}
