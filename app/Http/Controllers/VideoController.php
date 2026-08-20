<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Video Controller - 对标 new-api controller/video.go
 *
 * 处理视频生成相关 API:
 * - /v1/video/generations - 通用视频生成
 * - /kling/v1/videos/* - Kling
 * - /jimeng/* - 即梦
 */
class VideoController extends Controller
{
    /**
     * 通用视频生成 - 对标 POST /v1/video/generations
     */
    public function generate(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $data = $request->all();
        $data['user_id'] = $user->id;

        // 选择合适的视频渠道
        $channel = $this->selectVideoChannel($data['model'] ?? 'kling');

        if (! $channel) {
            return response()->json([
                'code' => 1,
                'message' => __('No available video channel'),
            ]);
        }

        // 创建任务
        $task = $this->createTask($user->id, $channel->id, 'video', $data);

        // 提交到上游
        $result = $this->submitToUpstream($channel, $data);

        if ($result['success']) {
            $task->update([
                'status' => 'pending',
                'external_id' => $result['task_id'] ?? null,
            ]);
        }

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'] ?? '',
            'data' => [
                'task_id' => $task->id,
            ],
        ]);
    }

    /**
     * 获取视频 - 对标 GET /v1/video/generations/{task_id}
     */
    public function getVideo(Request $request, string $taskId): JsonResponse
    {
        $task = Task::find($taskId);

        if (! $task) {
            return response()->json([
                'code' => 1,
                'message' => __('Task not found'),
            ], 404);
        }

        // 如果还在处理中，轮询状态
        if ($task->status === 'pending') {
            $this->pollTaskStatus($task);
        }

        return response()->json([
            'code' => 0,
            'data' => $this->formatTask($task),
        ]);
    }

    /**
     * Kling 文生视频 - 对标 POST /kling/v1/videos/text2video
     */
    public function klingText2Video(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $data = $request->all();
        $data['user_id'] = $user->id;

        $channel = $this->selectChannel(ChannelType::KLING);

        if (! $channel) {
            return response()->json([
                'code' => 1,
                'message' => __('No available Kling channel'),
            ]);
        }

        $task = $this->createTask($user->id, $channel->id, 'kling_text2video', $data);

        $result = $this->submitKling($channel, 'text2video', $data);

        if ($result['success']) {
            $task->update([
                'status' => 'pending',
                'external_id' => $result['task_id'] ?? null,
            ]);
        }

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'] ?? '',
            'data' => [
                'task_id' => $task->id,
            ],
        ]);
    }

    /**
     * Kling 图生视频 - 对标 POST /kling/v1/videos/image2video
     */
    public function klingImage2Video(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $data = $request->all();
        $data['user_id'] = $user->id;

        $channel = $this->selectChannel(ChannelType::KLING);

        if (! $channel) {
            return response()->json([
                'code' => 1,
                'message' => __('No available Kling channel'),
            ]);
        }

        $task = $this->createTask($user->id, $channel->id, 'kling_image2video', $data);

        $result = $this->submitKling($channel, 'image2video', $data);

        if ($result['success']) {
            $task->update([
                'status' => 'pending',
                'external_id' => $result['task_id'] ?? null,
            ]);
        }

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'] ?? '',
            'data' => [
                'task_id' => $task->id,
            ],
        ]);
    }

    /**
     * 获取 Kling 任务 - 对标 GET /kling/v1/videos/text2video/{task_id}
     */
    public function getKling(Request $request, string $taskId): JsonResponse
    {
        $task = Task::find($taskId);

        if (! $task) {
            return response()->json([
                'code' => 1,
                'message' => __('Task not found'),
            ], 404);
        }

        if ($task->status === 'pending') {
            $this->pollTaskStatus($task);
        }

        return response()->json([
            'code' => 0,
            'data' => $this->formatTask($task),
        ]);
    }

    /**
     * 即梦 - 对标 POST /jimeng/
     */
    public function jimeng(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $data = $request->all();
        $data['user_id'] = $user->id;

        $channel = $this->selectChannel(ChannelType::JIMENG);

        if (! $channel) {
            return response()->json([
                'code' => 1,
                'message' => __('No available Jimeng channel'),
            ]);
        }

        $task = $this->createTask($user->id, $channel->id, 'jimeng', $data);

        $result = $this->submitJimeng($channel, $data);

        if ($result['success']) {
            $task->update([
                'status' => 'pending',
                'external_id' => $result['task_id'] ?? null,
            ]);
        }

        return response()->json([
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['message'] ?? '',
            'data' => [
                'task_id' => $task->id,
            ],
        ]);
    }

    /**
     * 视频混剪 - 对标 POST /v1/videos/{video_id}/remix
     */
    public function remix(Request $request, string $videoId): JsonResponse
    {
        // TODO: 实现视频混剪
        return response()->json([
            'code' => 0,
            'message' => __('Not implemented yet'),
        ]);
    }

    /**
     * 选择视频渠道
     */
    protected function selectVideoChannel(string $model): ?Channel
    {
        $typeMap = [
            'kling' => ChannelType::KLING,
            'kling-video' => ChannelType::KLING,
            'sora' => ChannelType::SORA,
            'vidu' => ChannelType::VIDU,
            'jimeng' => ChannelType::JIMENG,
            'jimeng-video' => ChannelType::JIMENG,
            'haiwoo' => ChannelType::HAILUO,
            'hailuo' => ChannelType::HAILUO,
        ];

        $type = $typeMap[$model] ?? ChannelType::KLING;

        return $this->selectChannel($type);
    }

    /**
     * 选择渠道
     */
    protected function selectChannel(ChannelType $type): ?Channel
    {
        return Channel::where('type', $type->value)
            ->where('status', 1)
            ->orderBy('priority', 'desc')
            ->first();
    }

    /**
     * 创建任务
     */
    protected function createTask(int $userId, int $channelId, string $type, array $data): Task
    {
        return Task::create([
            'user_id' => $userId,
            'channel_id' => $channelId,
            'platform' => 'video',
            'type' => $type,
            'status' => 'pending',
            'input' => json_encode($data),
        ]);
    }

    /**
     * 提交到上游
     */
    protected function submitToUpstream(Channel $channel, array $data): array
    {
        // TODO: 实现
        return ['success' => true, 'task_id' => uniqid('video_')];
    }

    /**
     * 提交 Kling
     */
    protected function submitKling(Channel $channel, string $action, array $data): array
    {
        // TODO: 实现
        return ['success' => true, 'task_id' => uniqid('kling_')];
    }

    /**
     * 提交即梦
     */
    protected function submitJimeng(Channel $channel, array $data): array
    {
        // TODO: 实现
        return ['success' => true, 'task_id' => uniqid('jimeng_')];
    }

    /**
     * 格式化任务
     */
    protected function formatTask(Task $task): array
    {
        return [
            'id' => $task->id,
            'task_id' => $task->external_id,
            'status' => $task->status,
            'progress' => $task->progress,
            'video_url' => $task->output->video_url ?? null,
            'cover_url' => $task->output->cover_url ?? $task->output->image_url ?? null,
            'created_at' => $task->created_at->toIso8601String(),
        ];
    }

    /**
     * 混剪视频 - 对标 POST /v1/videos
     */
    public function create(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $data = $request->all();
        $data['user_id'] = $user->id;

        // TODO: 实现
        return response()->json([
            'code' => 0,
            'message' => __('Not implemented yet'),
        ]);
    }

    /**
     * 获取视频内容 - 对标 GET /v1/videos/{task_id}/content
     */
    public function getVideoContent(Request $request, string $taskId): JsonResponse
    {
        $task = Task::find($taskId);

        if (! $task || $task->status !== 'completed') {
            return response()->json([
                'code' => 1,
                'message' => __('Video not ready'),
            ], 404);
        }

        // 返回视频内容或重定向到视频URL
        return response()->json([
            'code' => 0,
            'data' => [
                'video_url' => $task->output->video_url ?? null,
            ],
        ]);
    }

    /**
     * 获取 Kling 视频 - 统一入口
     */
    public function getKlingVideo(Request $request, string $taskId): JsonResponse
    {
        return $this->getKling($request, $taskId);
    }

    /**
     * 轮询任务状态
     */
    protected function pollTaskStatus(Task $task): void
    {
        $channel = Channel::find($task->channel_id);

        if (! $channel || ! $task->external_id) {
            return;
        }

        try {
            // TODO: 实现轮询逻辑
        } catch (\Exception $e) {
            Log::error('Video poll failed', [
                'error' => $e->getMessage(),
                'task_id' => $task->id,
            ]);
        }
    }
}
