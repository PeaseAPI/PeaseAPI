<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\MidjourneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Midjourney Controller - 对标 new-api controller/mj.go
 * 
 * 处理 Midjourney 相关的 API:
 * - /mj/image/{id} - 获取图片
 * - /mj/submit/* - 提交任务
 * - /mj/task/* - 任务查询
 */
class MidjourneyController extends Controller
{
    protected MidjourneyService $mjService;

    public function __construct(MidjourneyService $mjService)
    {
        $this->mjService = $mjService;
    }

    /**
     * 获取 MJ 图片 - 对标 GET /mj/image/{id}
     */
    public function getImage(Request $request, string $id): Response
    {
        $image = $this->mjService->getImage($id);
        
        if (!$image) {
            return response()->json([
                'error' => [
                    'message' => 'Image not found',
                    'type' => 'invalid_request_error',
                    'code' => 'not_found',
                ],
            ], 404);
        }
        
        return response($image, 200, [
            'Content-Type' => 'image/png',
        ]);
    }

    /**
     * 提交动作 - 对标 POST /mj/submit/action
     */
    public function submitAction(Request $request): JsonResponse
    {
        $data = $request->all();
        
        $result = $this->mjService->submitAction($data);
        
        return response()->json($result);
    }

    /**
     * 短链接 - 对标 POST /mj/submit/shorten
     */
    public function submitShorten(Request $request): JsonResponse
    {
        $prompt = $request->input('prompt');
        
        $result = $this->mjService->shorten($prompt);
        
        return response()->json($result);
    }

    /**
     * 模态框 - 对标 POST /mj/submit/modal
     */
    public function submitModal(Request $request): JsonResponse
    {
        $data = $request->all();
        
        $result = $this->mjService->submitModal($data);
        
        return response()->json($result);
    }

    /**
     * 提交 Imagine - 对标 POST /mj/submit/imagine
     */
    public function submitImagine(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $prompt = $request->input('prompt');
        $base64 = $request->input('base64');
        $channelId = $request->input('channel_id');
        
        // 如果没有指定 channel_id，自动选择一个
        if (!$channelId) {
            $channel = $this->mjService->selectChannel();
            $channelId = $channel->id ?? 0;
        }
        
        $result = $this->mjService->submitImagine($user->id, $channelId, $prompt, $base64);
        
        return response()->json($result);
    }

    /**
     * 提交 Change - 对标 POST /mj/submit/change
     */
    public function submitChange(Request $request): JsonResponse
    {
        $data = $request->all();
        
        $result = $this->mjService->submitChange($data);
        
        return response()->json($result);
    }

    /**
     * 简单变换 - 对标 POST /mj/submit/simple-change
     */
    public function submitSimpleChange(Request $request): JsonResponse
    {
        $data = $request->all();
        
        $result = $this->mjService->submitSimpleChange($data);
        
        return response()->json($result);
    }

    /**
     * 描述图片 - 对标 POST /mj/submit/describe
     */
    public function submitDescribe(Request $request): JsonResponse
    {
        $data = $request->all();
        
        $result = $this->mjService->submitDescribe($data);
        
        return response()->json($result);
    }

    /**
     * 混合图片 - 对标 POST /mj/submit/blend
     */
    public function submitBlend(Request $request): JsonResponse
    {
        $data = $request->all();
        
        $result = $this->mjService->submitBlend($data);
        
        return response()->json($result);
    }

    /**
     * 编辑 - 对标 POST /mj/submit/edits
     */
    public function submitEdits(Request $request): JsonResponse
    {
        $data = $request->all();
        
        $result = $this->mjService->submitEdits($data);
        
        return response()->json($result);
    }

    /**
     * 视频 - 对标 POST /mj/submit/video
     */
    public function submitVideo(Request $request): JsonResponse
    {
        $data = $request->all();
        
        $result = $this->mjService->submitVideo($data);
        
        return response()->json($result);
    }

    /**
     * 获取任务 - 对标 GET /mj/task/{id}/fetch
     */
    public function fetchTask(Request $request, string $id): JsonResponse
    {
        $task = $this->mjService->getTask($id);
        
        if (!$task) {
            return response()->json([
                'error' => [
                    'message' => 'Task not found',
                    'type' => 'invalid_request_error',
                    'code' => 'not_found',
                ],
            ], 404);
        }
        
        return response()->json($task);
    }

    /**
     * 获取图片种子 - 对标 GET /mj/task/{id}/image-seed
     */
    public function getImageSeed(Request $request, string $id): JsonResponse
    {
        $seed = $this->mjService->getImageSeed($id);
        
        return response()->json($seed);
    }

    /**
     * 条件查询 - 对标 POST /mj/task/list-by-condition
     */
    public function listByCondition(Request $request): JsonResponse
    {
        $data = $request->all();
        
        $result = $this->mjService->listByCondition($data);
        
        return response()->json($result);
    }

    /**
     * 换脸 - 对标 POST /mj/insight-face/swap
     */
    public function insightFaceSwap(Request $request): JsonResponse
    {
        $data = $request->all();
        
        $result = $this->mjService->insightFaceSwap($data);
        
        return response()->json($result);
    }

    /**
     * 上传 Discord 图片 - 对标 POST /mj/submit/upload-discord-images
     */
    public function uploadDiscordImages(Request $request): JsonResponse
    {
        $data = $request->all();
        
        $result = $this->mjService->uploadDiscordImages($data);
        
        return response()->json($result);
    }
}