<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Ability;
use App\Models\Channel;
use App\Models\ModelMeta;
use App\Models\Token;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Model Controller - 对标 new-api controller/model.go
 *
 * 处理模型相关 API:
 * - /v1/models - 模型列表
 * - /v1/models/{model} - 模型详情
 */
class ModelController extends Controller
{
    /**
     * 列出所有可用模型 - 对标 GET /v1/models
     */
    public function list(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $token = $request->attributes->get('token');

        // 获取用户分组
        $group = $this->getUserGroup($user, $token);

        // 从 abilities 表获取该分组可用的模型
        $abilities = Ability::where('group', $group)
            ->where('enabled', true)
            ->get()
            ->groupBy('model');

        $models = [];
        $now = time();
        $metadata = ModelMeta::whereIn('model_name', $abilities->keys())
            ->get(['model_name', 'description', 'tags'])
            ->keyBy('model_name');

        foreach ($abilities as $model => $ability) {
            $channel = Channel::find($ability->first()->channel_id ?? 0);

            $models[] = [
                'id' => $model,
                'object' => 'model',
                'created' => $now,
                'owned_by' => $channel ? $channel->name : 'openai',
                'permission' => [],
                'root_model' => $model,
                'parent_model' => null,
                'description' => (string) ($metadata->get($model)?->description ?? ''),
                'tags' => (string) ($metadata->get($model)?->tags ?? ''),
            ];
        }

        // 按 ID 排序
        usort($models, fn ($a, $b) => strcmp($a['id'], $b['id']));

        return response()->json([
            'object' => 'list',
            'data' => $models,
        ]);
    }

    /**
     * 获取模型详情 - 对标 GET /v1/models/{model}
     */
    public function retrieve(Request $request, string $model): JsonResponse
    {
        $user = $request->attributes->get('user');
        $token = $request->attributes->get('token');

        $group = $this->getUserGroup($user, $token);

        // 检查模型是否对该分组可用
        $ability = Ability::where('group', $group)
            ->where('model', $model)
            ->where('enabled', true)
            ->first();

        if (! $ability) {
            return response()->json([
                'error' => [
                    'message' => "Model {$model} not found or not available for this group",
                    'type' => 'invalid_request_error',
                    'code' => 'model_not_found',
                ],
            ], 404);
        }

        $channel = Channel::find($ability->channel_id);
        $metadata = ModelMeta::where('model_name', $model)->first(['description', 'tags']);

        return response()->json([
            'id' => $model,
            'object' => 'model',
            'created' => time(),
            'owned_by' => $channel ? $channel->name : 'openai',
            'permission' => [],
            'root_model' => $model,
            'parent_model' => null,
            'description' => (string) ($metadata?->description ?? ''),
            'tags' => (string) ($metadata?->tags ?? ''),
            'meta' => [
                'parser' => $channel ? $channel->type : 1,
            ],
        ]);
    }

    /**
     * 列出 Gemini 模型 - 对标 GET /v1beta/models
     */
    public function listGemini(Request $request): JsonResponse
    {
        // 复用 list 方法
        return $this->list($request);
    }

    /**
     * 获取用户分组
     */
    protected function getUserGroup(?User $user, ?Token $token): string
    {
        // 优先使用 token 的 group
        if ($token && ! empty($token->group)) {
            return $token->group;
        }

        // 其次使用用户的 group
        if ($user && ! empty($user->group)) {
            return $user->group;
        }

        return 'default';
    }
}
