<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Midjourney;
use App\Models\Channel;
use App\Enums\ChannelType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Midjourney 服务 - 对标 new-api service/midjourney.go
 * 
 * 处理 Midjourney 任务提交、查询、图片代理等功能
 */
class MidjourneyService
{
    /**
     * 提交 Imagine 任务
     */
    public function submitImagine(Request $request): array
    {
        $prompt = $request->input('prompt', '');
        $channel = $this->selectChannel($request);

        if (!$channel) {
            return ['success' => false, 'message' => '没有可用的 Midjourney 渠道'];
        }

        $mj = Midjourney::create([
            'user_id' => $request->attributes->get('user_id', 0),
            'channel_id' => $channel->id,
            'action' => 'imagine',
            'status' => 'submitted',
            'prompt' => $prompt,
            'parameters' => json_encode($request->except(['prompt'])),
        ]);

        return [
            'success' => true,
            'data' => [
                'id' => $mj->id,
                'status' => $mj->status,
            ],
        ];
    }

    /**
     * 提交 Action 任务 (upscale, variation 等)
     */
    public function submitAction(Request $request): array
    {
        $channel = $this->selectChannel($request);

        if (!$channel) {
            return ['success' => false, 'message' => '没有可用的 Midjourney 渠道'];
        }

        $mj = Midjourney::create([
            'user_id' => $request->attributes->get('user_id', 0),
            'channel_id' => $channel->id,
            'action' => $request->input('action', 'upsample'),
            'status' => 'submitted',
            'prompt' => $request->input('prompt', ''),
            'parameters' => json_encode($request->except(['prompt', 'action'])),
        ]);

        return [
            'success' => true,
            'data' => [
                'id' => $mj->id,
                'status' => $mj->status,
            ],
        ];
    }

    /**
     * 提交 Describe 任务
     */
    public function submitDescribe(Request $request): array
    {
        $channel = $this->selectChannel($request);

        if (!$channel) {
            return ['success' => false, 'message' => '没有可用的 Midjourney 渠道'];
        }

        $mj = Midjourney::create([
            'user_id' => $request->attributes->get('user_id', 0),
            'channel_id' => $channel->id,
            'action' => 'describe',
            'status' => 'submitted',
            'prompt' => $request->input('image_url', ''),
            'parameters' => json_encode($request->all()),
        ]);

        return [
            'success' => true,
            'data' => [
                'id' => $mj->id,
                'status' => $mj->status,
            ],
        ];
    }

    /**
     * 提交 Blend 任务
     */
    public function submitBlend(Request $request): array
    {
        $channel = $this->selectChannel($request);

        if (!$channel) {
            return ['success' => false, 'message' => '没有可用的 Midjourney 渠道'];
        }

        $mj = Midjourney::create([
            'user_id' => $request->attributes->get('user_id', 0),
            'channel_id' => $channel->id,
            'action' => 'blend',
            'status' => 'submitted',
            'prompt' => '',
            'parameters' => json_encode($request->all()),
        ]);

        return [
            'success' => true,
            'data' => [
                'id' => $mj->id,
                'status' => $mj->status,
            ],
        ];
    }

    /**
     * 获取任务状态
     */
    public function fetchTask(int $id): array
    {
        $mj = Midjourney::find($id);

        if (!$mj) {
            return ['success' => false, 'message' => '任务不存在'];
        }

        return [
            'success' => true,
            'data' => [
                'id' => $mj->id,
                'action' => $mj->action,
                'status' => $mj->status,
                'prompt' => $mj->prompt,
                'image_url' => $mj->image_url ?? '',
                'progress' => $mj->progress ?? '0%',
                'fail_reason' => $mj->fail_reason ?? '',
                'submit_time' => $mj->created_at?->timestamp ?? 0,
                'start_time' => $mj->started_at ?? 0,
                'finish_time' => $mj->finished_at ?? 0,
            ],
        ];
    }

    /**
     * 获取图片种子
     */
    public function getImageSeed(int $id): array
    {
        $mj = Midjourney::find($id);

        if (!$mj) {
            return ['success' => false, 'message' => '任务不存在'];
        }

        return [
            'success' => true,
            'data' => [
                'id' => $mj->id,
                'image_seed' => $mj->image_seed ?? '',
            ],
        ];
    }

    /**
     * 条件查询任务列表
     */
    public function listByCondition(Request $request): array
    {
        $query = Midjourney::query();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        $tasks = $query->orderByDesc('id')
            ->paginate($request->input('size', 20));

        return [
            'success' => true,
            'data' => $tasks->items(),
        ];
    }

    /**
     * 获取用户 MJ 任务列表
     */
    public function getUserTasks(int $userId, int $page = 1, int $size = 20): array
    {
        $tasks = Midjourney::where('user_id', $userId)
            ->orderByDesc('id')
            ->paginate($size);

        return [
            'success' => true,
            'data' => $tasks->items(),
        ];
    }

    /**
     * 获取所有 MJ 任务 (管理员)
     */
    public function getAllTasks(int $page = 1, int $size = 20): array
    {
        $tasks = Midjourney::orderByDesc('id')
            ->paginate($size);

        return [
            'success' => true,
            'data' => $tasks->items(),
        ];
    }

    /**
     * 代理图片
     */
    public function proxyImage(string $imageId): ?string
    {
        $mj = Midjourney::where('image_id', $imageId)->first();

        if (!$mj || empty($mj->image_url)) {
            return null;
        }

        try {
            $response = Http::get($mj->image_url);
            return $response->body();
        } catch (\Exception $e) {
            Log::error('MJ image proxy failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Insight Face 换脸
     */
    public function insightFaceSwap(Request $request): array
    {
        $channel = $this->selectChannel($request);

        if (!$channel) {
            return ['success' => false, 'message' => '没有可用的渠道'];
        }

        $mj = Midjourney::create([
            'user_id' => $request->attributes->get('user_id', 0),
            'channel_id' => $channel->id,
            'action' => 'insight-face-swap',
            'status' => 'submitted',
            'prompt' => '',
            'parameters' => json_encode($request->all()),
        ]);

        return [
            'success' => true,
            'data' => [
                'id' => $mj->id,
                'status' => $mj->status,
            ],
        ];
    }

    /**
     * 上传 Discord 图片
     */
    public function uploadDiscordImages(Request $request): array
    {
        $channel = $this->selectChannel($request);

        if (!$channel) {
            return ['success' => false, 'message' => '没有可用的渠道'];
        }

        return [
            'success' => true,
            'data' => [
                'image_urls' => [],
            ],
        ];
    }

    /**
     * 选择 Midjourney 渠道
     */
    protected function selectChannel(Request $request): ?Channel
    {
        return Channel::where('type', ChannelType::GEM)
            ->where('status', 1)
            ->first();
    }
}