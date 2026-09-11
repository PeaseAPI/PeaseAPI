<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PollTaskJob;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class PollTasks extends Command
{
    protected $signature = 'task:poll';

    protected $description = 'Poll async tasks for status updates';

    public function handle(): int
    {
        $this->info('Polling async tasks...');

        // tasks 表是签到任务表（无 status 列）；异步任务轮询子系统（TaskPollingService）尚未实现。
        // 做列存在性守卫，避免计划任务每次运行都抛 SQL 异常；未来加列后自动恢复轮询。
        if (! Schema::hasColumn('tasks', 'status')) {
            $this->info('tasks.status column not found; async task polling is not implemented yet, skip.');

            return 0;
        }

        $pending = Task::whereIn('status', ['pending', 'running'])->get();
        foreach ($pending as $task) {
            PollTaskJob::dispatch($task->id);
        }
        $this->info('Dispatched '.$pending->count().' tasks.');

        return 0;
    }
}
