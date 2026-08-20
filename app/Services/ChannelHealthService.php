<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ChannelHealthService
{
    public function checkHealth(Channel $channel): array
    {
        $cacheKey = "channel_health:{$channel->id}";
        $cached = Cache::get($cacheKey);
        if ($cached && (time() - $cached['checked_at']) < 60) {
            return $cached;
        }

        $result = ['channel_id' => $channel->id, 'status' => 'unknown', 'latency' => 0, 'checked_at' => time()];

        try {
            $start = microtime(true);
            $baseUrl = rtrim($channel->base_url, '/');
            $testUrl = $baseUrl.'/models';

            $headers = ['Authorization' => 'Bearer '.$channel->key];
            $response = Http::withHeaders($headers)->timeout(10)->get($testUrl);
            $latency = (int) ((microtime(true) - $start) * 1000);

            $result['latency'] = $latency;
            $result['status'] = $response->successful() ? 'healthy' : 'degraded';
        } catch (\Exception $e) {
            $result['status'] = 'down';
            $result['error'] = $e->getMessage();
        }

        Cache::put($cacheKey, $result, 120);

        return $result;
    }

    public function checkAllChannels(): array
    {
        $channels = Channel::where('status', 1)->get();
        $results = [];
        foreach ($channels as $channel) {
            $results[$channel->id] = $this->checkHealth($channel);
        }

        return $results;
    }

    public function disableUnhealthyChannel(Channel $channel): void
    {
        $failKey = "channel_fail_count:{$channel->id}";
        $fails = Cache::get($failKey, 0) + 1;
        Cache::put($failKey, $fails, 600);

        if ($fails >= config('pease-api.channel.max_fail_count', 5)) {
            $channel->update(['status' => 0]);
            Cache::forget($failKey);
        }
    }
}
