<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Ability;
use App\Models\Channel;
use App\Setting\RatioSetting\ModelRatio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Distributor 中间件 - 对标 new-api middleware/distributor.go
 * 
 * 职责:
 * 1. 解析请求获取模型名称
 * 2. 检查 Token 模型限制
 * 3. 使用渠道亲和性缓存
 * 4. 选择最佳渠道
 * 5. 将选中的渠道信息存入请求属性
 */
class Distributor
{
    /**
     * 处理请求
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. 获取模型名称和分组
        $modelRequest = $this->getModelRequest($request);
        
        if ($modelRequest['model'] === '') {
            return response()->json([
                'error' => [
                    'message' => '模型名称不能为空',
                    'type' => 'invalid_request_error',
                    'code' => 'model_name_required',
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        // 2. 检查 Token 模型限制
        $token = $request->attributes->get('token');
        if ($token) {
            $modelLimitEnabled = $request->attributes->get('token_model_limit_enabled', false);
            if ($modelLimitEnabled) {
                $modelLimit = $request->attributes->get('token_model_limit', []);
                $matchName = $this->formatMatchingModelName($modelRequest['model']);
                
                if (!isset($modelLimit[$matchName]) || !$modelLimit[$matchName]) {
                    return response()->json([
                        'error' => [
                            'message' => "Token 无权访问模型: {$modelRequest['model']}",
                            'type' => 'invalid_request_error',
                            'code' => 'token_model_forbidden',
                        ],
                    ], Response::HTTP_FORBIDDEN);
                }
            }
        }

        // 3. 获取用户分组
        $usingGroup = $request->attributes->get('using_group', 'default');

        // 4. 尝试从渠道亲和性缓存获取
        $channel = $this->getChannelFromAffinity($request, $modelRequest['model'], $usingGroup);

        // 5. 如果没有 affinity 渠道，进行正常选择
        if (!$channel) {
            $channel = $this->selectChannel($modelRequest['model'], $usingGroup, $request->path());
        }

        // 6. 如果仍未找到渠道
        if (!$channel) {
            $groupDisplay = $usingGroup;
            if ($usingGroup === 'auto') {
                $groupDisplay = "auto({$modelRequest['group']})";
            }
            
            return response()->json([
                'error' => [
                    'message' => "分组 {$groupDisplay} 下没有找到模型 {$modelRequest['model']} 的可用渠道",
                    'type' => 'invalid_request_error',
                    'code' => 'no_channel_available',
                ],
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // 7. 将选中的渠道存入请求属性
        $request->attributes->set('selected_channel', $channel);
        $request->attributes->set('using_group', $modelRequest['group'] ?: $usingGroup);

        return $next($request);
    }

    /**
     * 从请求中获取模型信息
     */
    protected function getModelRequest(Request $request): array
    {
        $model = '';
        $group = 'default';

        // 从请求体获取
        $content = $request->getContent();
        $body = json_decode($content, true);
        
        if (is_array($body)) {
            $model = $body['model'] ?? '';
            // 检查 group 参数
            $group = $body['group'] ?? $request->query('group', 'default');
        }

        return [
            'model' => $model,
            'group' => $group,
        ];
    }

    /**
     * 格式化匹配模型名称 (对标 FormatMatchingModelName)
     * 处理 gpts, thinking-* 等模型名称
     */
    protected function formatMatchingModelName(string $model): string
    {
        // 直接返回原始名称，后续可扩展
        return $model;
    }

    /**
     * 从渠道亲和性缓存获取渠道
     */
    protected function getChannelFromAffinity(Request $request, string $model, string $usingGroup): ?Channel
    {
        $affinityKey = "channel_affinity:{$request->attributes->get('api_user_id', 0)}:{$usingGroup}:{$model}";
        $preferredChannelId = cache()->get($affinityKey);
        
        if (!$preferredChannelId) {
            return null;
        }

        $channel = Channel::find($preferredChannelId);
        
        if (!$channel || $channel->status !== 1) {
            return null;
        }

        // 检查渠道是否支持当前请求路径和模型
        if (!$this->channelSupportsRequestPath($channel, $request->path(), $model)) {
            return null;
        }

        return $channel;
    }

    /**
     * 选择渠道 (对标 CacheGetRandomSatisfiedChannel)
     */
    protected function selectChannel(string $model, string $group, string $requestPath): ?Channel
    {
        // 获取支持该模型的渠道
        $ability = Ability::where('name', $this->getAbilityNameByPath($requestPath))
            ->where('enabled', true)
            ->first();

        if (!$ability) {
            return null;
        }

        // 查询启用的渠道
        $query = Channel::whereHas('abilities', function ($q) use ($ability, $model, $group) {
            $q->where('ability_id', $ability->id)
              ->where('enabled', true);
        })->where('status', 1);

        // 按优先级和权重排序
        $channels = $query->orderBy('priority', 'desc')
            ->orderByRaw('RAND()')
            ->get();

        // 这里可以添加更多选择逻辑 (如健康检查、响应时间等)
        return $channels->first();
    }

    /**
     * 根据请求路径获取能力名称
     */
    protected function getAbilityNameByPath(string $path): string
    {
        $abilityMap = [
            '/v1/chat/completions' => 'chat.completions',
            '/v1/completions' => 'completions',
            '/v1/embeddings' => 'embeddings',
            '/v1/images/generations' => 'images.generations',
            '/v1/audio/transcriptions' => 'audio.transcriptions',
            '/v1/rerank' => 'rerank',
            '/v1/messages' => 'claude.messages',
            '/v1/responses' => 'responses',
        ];

        foreach ($abilityMap as $pattern => $ability) {
            if (str_starts_with($path, $pattern)) {
                return $ability;
            }
        }

        return 'chat.completions';
    }

    /**
     * 检查渠道是否支持请求路径
     */
    protected function channelSupportsRequestPath(Channel $channel, string $path, string $model): bool
    {
        // 获取渠道支持的模型列表
        $models = $channel->models ?? [];
        
        if (is_string($models)) {
            $models = json_decode($models, true) ?: [];
        }

        // 检查模型是否在支持列表中
        if (!empty($models) && !in_array($model, $models, true)) {
            return false;
        }

        return true;
    }
}