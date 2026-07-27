<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Api\ChannelApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 渠道管理控制器（路由兼容层）
 *
 * 完整业务实现位于 App\Http\Controllers\Api\ChannelApiController。
 * 本类继承其全部能力，仅为兼容 routes/api.php 中的历史方法命名而补充别名方法。
 */
class ChannelController extends ChannelApiController
{
    /**
     * 别名：路由使用 updateAllBalances，实际实现为 updateBalanceAll
     * GET /api/channel/update_balance
     */
    public function updateAllBalances(): JsonResponse
    {
        return $this->updateBalanceAll();
    }

    /**
     * 别名：路由使用 revealKey，实际实现为 getKey
     * POST /api/channel/{id}/key
     */
    public function revealKey(int $id): JsonResponse
    {
        return $this->getKey($id);
    }

    /**
     * 别名：路由使用 codexResetUsage，实际实现为 codexUsageReset
     * POST /api/channel/{id}/codex/usage/reset
     */
    public function codexResetUsage(int $id): JsonResponse
    {
        return $this->codexUsageReset($id);
    }

    /**
     * 别名：路由使用 applyUpstreamUpdate，实际实现为 applyUpstreamUpdates
     * POST /api/channel/upstream_updates/apply
     */
    public function applyUpstreamUpdate(Request $request): JsonResponse
    {
        return $this->applyUpstreamUpdates($request);
    }

    /**
     * 别名：路由使用 detectUpstreamUpdate，实际实现为 detectUpstreamUpdates
     * POST /api/channel/upstream_updates/detect
     */
    public function detectUpstreamUpdate(Request $request): JsonResponse
    {
        return $this->detectUpstreamUpdates($request);
    }

    /**
     * GET /api/ratio_sync/channels  可同步倍率的渠道列表 (stub)
     */
    public function ratioSyncChannels(): JsonResponse
    {
        if ($err = $this->requireAdmin()) return $err;

        return response()->json([
            'success' => false,
            'message' => '倍率同步暂未实现',
        ], 501);
    }

    /**
     * POST /api/ratio_sync/fetch  拉取上游倍率 (stub)
     */
    public function fetchRatios(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) return $err;

        return response()->json([
            'success' => false,
            'message' => '倍率同步暂未实现',
        ], 501);
    }
}