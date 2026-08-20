<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Channel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * 倍率同步控制器 - 对标 new-api controller/ratio_sync.go
 */
class RatioSyncController extends Controller
{
    /**
     * 获取可同步渠道列表（Root）
     */
    public function channels(): JsonResponse
    {
        $channels = Channel::where('status', 1)
            ->whereNotNull('base_url')
            ->where('base_url', '!=', '')
            ->select(['id', 'name', 'type', 'base_url', 'models'])
            ->get();

        return $this->success($channels);
    }

    /**
     * 拉取上游倍率（Root）
     */
    public function fetch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel_id' => ['required', 'integer'],
            'url' => ['nullable', 'string', 'url'],
        ]);

        $channel = Channel::findOrFail($data['channel_id']);
        $url = $data['url'] ?? rtrim((string) $channel->base_url, '/').'/pricing';

        try {
            $response = Http::withToken((string) $channel->key)
                ->timeout(30)
                ->get($url);

            if (! $response->successful()) {
                return $this->error('上游返回错误状态码: '.$response->status());
            }

            return $this->success([
                'channel' => $channel->only(['id', 'name']),
                'pricing' => $response->json(),
            ]);
        } catch (\Throwable $e) {
            return $this->error('拉取上游倍率失败: '.$e->getMessage());
        }
    }
}
