<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CodingPlanPoolService;
use Illuminate\Console\Command;

class ResetCodingPlanUsage extends Command
{
    protected $signature = 'coding-plan:reset';

    protected $description = 'Reset expired Coding Plan usage windows and disable expired accounts';

    public function handle(CodingPlanPoolService $pool): int
    {
        $this->info('Resetting Coding Plan usage windows...');

        $windows = $pool->resetExpiredWindows();
        $this->info("Reset {$windows} usage windows.");

        $disabled = $pool->disableExpiredAccounts();
        if ($disabled > 0) {
            $this->warn("Disabled {$disabled} expired accounts.");
        }

        return 0;
    }
}
