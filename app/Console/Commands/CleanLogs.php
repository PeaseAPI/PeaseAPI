<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Log;
use Illuminate\Console\Command;

class CleanLogs extends Command
{
    protected $signature = 'logs:clean {--days=30}';

    protected $description = 'Clean old logs';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        // created_at 为 int 时间戳列，必须传整数边界（传 Carbon 会被 MySQL 前缀转数字导致静默 no-op）
        $cutoff = now()->subDays($days)->getTimestamp();
        $count = Log::where('created_at', '<', $cutoff)->delete();
        $this->info('Deleted '.$count.' old logs.');

        return 0;
    }
}
