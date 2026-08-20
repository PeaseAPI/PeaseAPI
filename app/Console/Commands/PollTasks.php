<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PollTaskJob;
use App\Models\Task;
use Illuminate\Console\Command;

class PollTasks extends Command
{
    protected $signature = 'task:poll';

    protected $description = 'Poll async tasks for status updates';

    public function handle(): int
    {
        $this->info('Polling async tasks...');
        $pending = Task::whereIn('status', ['pending', 'running'])->get();
        foreach ($pending as $task) {
            PollTaskJob::dispatch($task->id);
        }
        $this->info('Dispatched '.$pending->count().' tasks.');

        return 0;
    }
}
