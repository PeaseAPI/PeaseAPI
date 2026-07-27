<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\UserSubscription;

class ResetSubscriptions extends Command
{
    protected $signature = "subscription:reset";
    protected $description = "Reset subscription quotas";

    public function handle(): int
    {
        $this->info("Resetting subscription quotas...");
        $subs = UserSubscription::where("status", "active")->where("period_end", "<", now())->get();
        $count = 0;
        foreach ($subs as $sub) {
            if (method_exists($sub, "resetQuota")) {
                $sub->resetQuota();
                $count++;
            }
        }
        $this->info("Reset " . $count . " subscriptions.");
        return 0;
    }
}
