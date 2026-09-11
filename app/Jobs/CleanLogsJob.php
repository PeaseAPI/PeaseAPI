<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $days = 30) {}

    public function handle(): void
    {
        // created_at 为 int 时间戳列，必须传整数边界（传 Carbon 会被 MySQL 前缀转数字导致静默 no-op）
        $cutoff = now()->subDays($this->days)->getTimestamp();
        $count = Log::where('created_at', '<', $cutoff)->delete();
    }
}
