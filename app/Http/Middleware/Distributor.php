<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Channel;
use App\Services\ChannelAffinityService;
use App\Services\ChannelSelectService;
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
     * 渠道亲和性（affinity）记录使用解析后的分组，避免读到恒缺省的 using_group。
     */
    protected function resolveUsingGroup(Request $request): string
    {
        // 0. 上游已显式解析的分组（R12 契约：尊重预设的 using_group 属性）
        $preset = $request->attributes->get('using_group');
        if (is_string($preset) && $preset !== '') {
            return $preset;
        }

        // 1. Token 自带分组优先（new-api 语义：令牌级分组覆盖）
        $token = $request->attributes->get('token');
        if ($token && ! empty($token->group)) {
            return (string) $token->group;
        }

        // 2. TokenAuth 注入的用户分组
        $userGroup = (string) $request->attributes->get('user_group', '');

        return $userGroup !== '' ? $userGroup : 'default';
    }

    /**
     * 处理请求
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. 获取模型名称
        $modelRequest = $this->getModelRequest($request);

        if ($modelRequest['model'] === '') {
            return response()->json([
                'error' => [
                    'message' => __('Model name cannot be empty'),
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

                if (! isset($modelLimit[$matchName]) || ! $modelLimit[$matchName]) {
                    return response()->json([
                        'error' => [
                            'message' => __('Token is not allowed to access model: :model', ['model' => $modelRequest['model']]),
                            'type' => 'invalid_request_error',
                            'code' => 'token_model_forbidden',
                        ],
                    ], Response::HTTP_FORBIDDEN);
                }
            }
        }

        // 3. 解析用户分组（token group → user group → default；忽略请求体注入的 group）
        $usingGroup = $this->resolveUsingGroup($request);

        // 4. 尝试从渠道亲和性缓存获取
        $channel = $this->getChannelFromAffinity($request, $modelRequest['model'], $usingGroup);

        // 5. 如果没有 affinity 渠道，进行正常选择（按 model+group 查 abilities）
        if (! $channel) {
            $channel = app(ChannelSelectService::class)->pickChannel($modelRequest['model'], $usingGroup);
        }

        // 6. 如果仍未找到渠道
        if (! $channel) {
            $groupDisplay = $usingGroup;
            if ($usingGroup === 'auto') {
                $groupDisplay = "auto({$modelRequest['group']})";
            }

            return response()->json([
                'error' => [
                    'message' => __('No available channel for model :model in group :group', ['group' => $groupDisplay, 'model' => $modelRequest['model']]),
                    'type' => 'invalid_request_error',
                    'code' => 'no_channel_available',
                ],
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // 7. 将选中的渠道存入请求属性
        $request->attributes->set('selected_channel', $channel);
        $request->attributes->set('using_group', $usingGroup);

        $response = $next($request);

        // 8. 转发成功后记录渠道亲和（仅 ChannelAffinityEnabled 开启时生效）。
        //    RelayHandler 无重试循环，selected_channel 即实际使用的渠道。
        if ($response->getStatusCode() < 400) {
            $userId = (int) $request->attributes->get('user_id', 0);
            $finalChannel = $request->attributes->get('selected_channel');

            if ($userId > 0 && $finalChannel instanceof Channel) {
                ChannelAffinityService::record(
                    $userId,
                    $finalChannel->id,
                    $usingGroup,
                    $modelRequest['model']
                );
            }
        }

        return $response;
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
        $userId = (int) $request->attributes->get('user_id', 0);
        $preferredChannelId = ChannelAffinityService::preferredChannelId($userId, $usingGroup, $model);

        if (! $preferredChannelId) {
            return null;
        }

        $channel = Channel::find($preferredChannelId);

        if (! $channel || $channel->status !== 1) {
            return null;
        }

        // 检查渠道是否支持当前请求路径和模型
        if (! $this->channelSupportsRequestPath($channel, $request->path(), $model)) {
            return null;
        }

        return $channel;
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
        if (! empty($models) && ! in_array($model, $models, true)) {
            return false;
        }

        return true;
    }
}
