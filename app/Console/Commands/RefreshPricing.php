<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RefreshPricing extends Command
{
    protected $signature = 'pricing:refresh';

    protected $description = 'Refresh model pricing cache';

    public function handle(): int
    {
        $this->info('Refreshing pricing cache...');
        Cache::forget('model_pricing');
        $this->info('Pricing cache cleared.');

        return 0;
    }
}
