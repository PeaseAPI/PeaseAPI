<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Option;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * 部署管理控制器 - 对标 new-api controller/deployment.go
 * 管理 io.net 等外部部署集成
 */
class DeploymentController extends Controller
{
    /**
     * 获取部署设置（Root）
     */
    public function settings(): JsonResponse
    {
        $settings = [
            'io_net_enabled' => (bool) Option::where('key', 'IoNetEnabled')->value('value'),
            'io_net_api_key' => (string) Option::where('key', 'IoNetApiKey')->value('value'),
            'io_net_endpoint' => (string) Option::where('key', 'IoNetEndpoint')->value('value'),
        ];

        return $this->success($settings);
    }

    /**
     * 更新部署设置（Root）
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'io_net_enabled' => ['sometimes', 'boolean'],
            'io_net_api_key' => ['sometimes', 'string'],
            'io_net_endpoint' => ['sometimes', 'string', 'url'],
        ]);

        foreach ($data as $key => $value) {
            $optionKey = match ($key) {
                'io_net_enabled' => 'IoNetEnabled',
                'io_net_api_key' => 'IoNetApiKey',
                'io_net_endpoint' => 'IoNetEndpoint',
                default => null,
            };

            if ($optionKey) {
                Option::updateOrCreate(
                    ['key' => $optionKey],
                    ['value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value],
                );
            }
        }

        return $this->success(null, '部署设置已更新');
    }

    /**
     * 测试部署连接（Root）
     */
    public function testConnection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'api_key' => ['required', 'string'],
            'endpoint' => ['required', 'string', 'url'],
        ]);

        try {
            $response = Http::withToken($data['api_key'])
                ->timeout(15)
                ->get($data['endpoint'].'/api/v1/status');

            if ($response->successful()) {
                return $this->success($response->json(), '连接成功');
            }

            return $this->error('连接失败: HTTP '.$response->status());
        } catch (\Throwable $e) {
            return $this->error('连接异常: '.$e->getMessage());
        }
    }

    /**
     * 获取部署列表（Root）
     */
    public function index(): JsonResponse
    {
        $deployments = Option::where('key', 'like', 'Deployment%')->get();

        return $this->success($deployments);
    }
}
