<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Suno Controller - 对标 new-api controller/suno.go
 * 
 * 处理 Suno 音乐生成相关 API:
 * - /suno/submit/{action} - 提交任务
 * - /suno/fetch - 获取结果
 */
class SunoController extends Controller
{
    /**
     * 提交 Suno 任务 - 对标 POST /suno/submit/{action}
     */
    public function submit(Request $request, string $action): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        $data = $request->all();
        $data['user_id'] = $user->id;
        $data['action'] = $action;
        
        // 选择一个可用的 Suno 渠道
        $channel = $this->selectChannel();
        
        if (!$channel) {
            return response()->json([
                'code' => 1,
                'message' => 'No available channel',
            ]);
        }
        
        // 创建任务记录
        $task = $this->createTask($user->id, $channel->id, $action, $data);
        
        // 提交到上游 API
        $result = $this->submitToUpstream($channel, $action, $data);
        
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
     * 批量获取 Suno 结果 - 对标 POST /suno/fetch
     */
    public function fetch(Request $request): JsonResponse
    {
        $taskIds = $request->input('task_ids', []);
        
        $tasks = Task::whereIn('id', $taskIds)->get();
        
        $results = [];
        foreach ($tasks as $task) {
            $results[] = $this->getTaskResult($task);
        }
        
        return response()->json([
            'code' => 0,
            'data' => $results,
        ]);
    }

    /**
     * 获取单个 Suno 结果 - 对标 GET /suno/fetch/{id}
     */
    public function fetchOne(Request $request, string $id): JsonResponse
    {
        $task = Task::find($id);
        
        if (!$task) {
            return response()->json([
                'code' => 1,
                'message' => 'Task not found',
            ], 404);
        }
        
        // 如果任务还在处理中，查询上游状态
        if ($task->status === 'pending') {
            $this->pollTaskStatus($task);
        }
        
        return response()->json([
            'code' => 0,
            'data' => $this->getTaskResult($task),
        ]);
    }

    /**
     * 选择可用的 Suno 渠道
     */
    protected function selectChannel(): ?Channel
    {
        return Channel::where('type', \App\Enums\ChannelType::Suno)
            ->where('status', 1)
            ->orderBy('priority', 'desc')
            ->first();
    }

    /**
     * 创建任务记录
     */
    protected function createTask(int $userId, int $channelId, string $action, array $data): Task
    {
        return Task::create([
            'user_id' => $userId,
            'channel_id' => $channelId,
            'platform' => 'suno',
            'type' => $action,
            'status' => 'pending',
            'input' => json_encode($data),
        ]);
    }

    /**
     * 提交到上游 API
     */
    protected function submitToUpstream(Channel $channel, string $action, array $data): array
    {
        // TODO: 实现实际的 Suno API 调用
        // 这里需要根据 channel 的配置调用上游 API
        
        try {
            $baseUrl = $channel->base_url ?: 'https://api.suno.ai';
            $key = $channel->key;
            
            // 示例实现
            // $response = Http::withHeaders([
            //     'Authorization' => "Bearer {$key}",
            //     'Content-Type' => 'application/json',
            // ])->post("{$baseUrl}/{$action}", $data);
            
            return [
                'success' => true,
                'task_id' => uniqid('suno_'),
            ];
        } catch (\Exception $e) {
            Log::error('Suno submit failed', [
                'error' => $e->getMessage(),
                'channel_id' => $channel->id,
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * 获取任务结果
     */
    protected function getTaskResult(Task $task): array
    {
        return [
            'id' => $task->id,
            'task_id' => $task->external_id,
            'status' => $task->status,
            'progress' => $task->progress,
            'audio_url' => $task->output->audio_url ?? null,
            'video_url' => $task->output->video_url ?? null,
            'image_url' => $task->output->image_url ?? null,
            'created_at' => $task->created_at->toIso8601String(),
        ];
    }

    /**
     * 轮询任务状态
     */
    protected function pollTaskStatus(Task $task): void
    {
        $channel = Channel::find($task->channel_id);
        
        if (!$channel || !$task->external_id) {
            return;
        }
        
        try {
            $baseUrl = $channel->base_url ?: 'https://api.suno.ai';
            $key = $channel->key;
            
            // 示例实现
            // $response = Http::withHeaders([
            //     'Authorization' => "Bearer {$key}",
            // ])->get("{$baseUrl}/fetch/{$task->external_id}");
            
            // 如果任务完成，更新状态
            // $task->update([
            //     'status' => 'completed',
            //     'output' => $response->json(),
            // ]);
        } catch (\Exception $e) {
            Log::error('Suno poll failed', [
                'error' => $e->getMessage(),
                'task_id' => $task->id,
            ]);
        }
    }
}