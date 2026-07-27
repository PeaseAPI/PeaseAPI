<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SystemInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 系统信息控制器 - 对标 new-api controller/system-info.go
 *
 * 管理集群实例（节点）信息
 */
class SystemInfoController extends Controller
{
    /**
     * 系统信息页面
     */
    public function index()
    {
        return view('admin.system-info');
    }

    public function instances(Request $request): JsonResponse
    {
        $query = SystemInstance::query()
            ->when($request->input('node_name'), fn ($q, $v) => $q->where('node_name', 'like', "%{$v}%"))
            ->orderByDesc('last_heartbeat');

        return $this->paginate($query->paginate((int) $request->input('per_page', 20)));
    }

    public function cleanup(Request $request): JsonResponse
    {
        $minutes = (int) $request->input('minutes', 10);

        $count = SystemInstance::where('last_heartbeat', '<', now()->subMinutes($minutes))->delete();

        return $this->success(['deleted' => $count], "已清理 {$count} 个过期实例");
    }

    public function destroy(string $nodeName): JsonResponse
    {
        $instance = SystemInstance::where('node_name', $nodeName)->first();
        if (! $instance) {
            return $this->error('实例不存在', 404);
        }

        $instance->delete();

        return $this->success(null, '实例已删除');
    }
}