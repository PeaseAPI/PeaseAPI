<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SystemTaskType;
use App\Jobs\CleanLogsJob;
use App\Services\SystemTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 系统任务控制器 - 对标 new-api controller/system-task.go
 *
 * 管理后台长耗时任务（如日志清理）
 */
class SystemTaskController extends Controller
{
    public function __construct(
        private readonly SystemTaskService $service,
    ) {}

    public function list(Request $request): JsonResponse
    {
        $status = $request->input('status');
        $type = $request->input('type');

        $query = $this->service->query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderByDesc('id');

        return $this->paginate($query->paginate((int) $request->input('per_page', 20)));
    }

    public function current(): JsonResponse
    {
        return $this->success($this->service->current());
    }

    public function show(int $taskId): JsonResponse
    {
        $task = $this->service->find($taskId);
        if (! $task) {
            return $this->error('任务不存在', 404);
        }

        return $this->success($task);
    }

    public function createLogCleanup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1'],
            'type' => ['nullable', 'integer'],
        ]);

        $task = $this->service->create(
            SystemTaskType::LogCleanup,
            [
                'days' => (int) $data['days'],
                'type' => $data['type'] ?? 0,
            ],
        );

        CleanLogsJob::dispatch($task->id);

        return $this->success($task, '日志清理任务已创建');
    }
}
