<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Channel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncChannelCache extends Command
{
    protected $signature = 'channel:sync-cache';

    protected $description = 'Sync channel cache and abilities';

    public function handle(): int
    {
        $this->info('Syncing channel cache...');
        Cache::forget('channel_cache');
        $channels = Channel::where('status', 1)->get();
        Cache::put('channel_cache', $channels, now()->addHours(1));
        $this->info('Cached '.$channels->count().' channels.');

        return 0;
    }
}
