<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SystemTaskStatus;
use App\Enums\SystemTaskType;
use App\Models\SystemTask;
use Illuminate\Database\Eloquent\Builder;

class SystemTaskService
{
    public function query(): Builder
    {
        return SystemTask::query();
    }

    public function find(int $id): ?SystemTask
    {
        return SystemTask::find($id);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function create(SystemTaskType $type, array $params = [], ?int $userId = null): SystemTask
    {
        return SystemTask::create([
            'type' => $type,
            'status' => SystemTaskStatus::Pending,
            'params' => $params,
            'user_id' => $userId,
        ]);
    }

    public function current(): ?SystemTask
    {
        return SystemTask::where('status', SystemTaskStatus::Running)
            ->orWhere('status', SystemTaskStatus::Pending)
            ->orderByDesc('id')
            ->first();
    }

    public function markRunning(SystemTask $task): SystemTask
    {
        $task->update([
            'status' => SystemTaskStatus::Running,
            'started_at' => now(),
        ]);

        return $task->refresh();
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function markDone(SystemTask $task, ?array $result = null): SystemTask
    {
        $task->update([
            'status' => SystemTaskStatus::Done,
            'result' => $result,
            'finished_at' => now(),
        ]);

        return $task->refresh();
    }

    public function markFailed(SystemTask $task, string $error): SystemTask
    {
        $task->update([
            'status' => SystemTaskStatus::Failed,
            'error' => $error,
            'finished_at' => now(),
        ]);

        return $task->refresh();
    }
}
