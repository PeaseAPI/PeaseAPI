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
        $count = Log::where('created_at', '<', now()->subDays($days))->delete();
        $this->info('Deleted '.$count.' old logs.');

        return 0;
    }
}
