<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Services\ChannelHealthService;
use App\Services\ChannelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 渠道管理 API
 * 1:1 对齐 Go 版 controller/channel.go + router/api-router.go
 */
class ChannelApiController extends Controller
{
    public function __construct(
        private ChannelService $channelService,
        private ChannelHealthService $healthService,
    ) {}

    /**
     * 辅助：校验管理员权限（role >= 10）
     */
    protected function requireAdmin(): ?JsonResponse
    {
        $user = Auth::user();
        if (! $user || $user->role < 10) {
            return response()->json(['success' => false, 'message' => __('No admin permission')], 403);
        }

        return null;
    }

    /**
     * GET /api/channel/  渠道列表
     * 支持 p/page_size, type, status, group, tag, search, sort
     */
    public function index(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $page = (int) $request->input('p', $request->input('page', 1));
        $pageSize = (int) $request->input('page_size', 10);

        $query = Channel::query();

        if ($request->has('type')) {
            $query->where('type', (int) $request->input('type'));
        }
        if ($request->has('status')) {
            $query->where('status', (int) $request->input('status'));
        }
        if ($request->has('group') && $request->filled('group')) {
            $query->whereRaw('FIND_IN_SET(?, `group`)', [$request->input('group')]);
        }
        if ($request->has('tag') && $request->filled('tag')) {
            $query->where('tag', $request->input('tag'));
        }
        if ($request->has('search') && $request->filled('search')) {
            $kw = $request->input('search');
            $query->where(function ($q) use ($kw) {
                $q->where('id', $kw)
                    ->orWhere('name', 'like', "%{$kw}%")
                    ->orWhere('tag', 'like', "%{$kw}%")
                    ->orWhere('models', 'like', "%{$kw}%");
            });
        }

        // 排序：对齐 Go 版支持 id, name, status, response_time, priority, balance
        $sort = $request->input('sort', '-id');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $sortField = ltrim($sort, '-');
        $allowedSort = ['id', 'name', 'status', 'response_time', 'priority', 'balance', 'used_quota', 'test_time'];
        if (! in_array($sortField, $allowedSort, true)) {
            $sortField = 'id';
        }
        $query->orderBy($sortField, $direction);

        $channels = $query->paginate($pageSize, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $channels->items(),
            'total' => $channels->total(),
        ]);
    }

    /**
     * GET /api/channel/search  搜索渠道
     */
    public function search(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $keyword = (string) $request->input('keyword', '');
        $channels = $this->channelService->search($keyword, 20);

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $channels->items(),
            'total' => $channels->total(),
        ]);
    }

    /**
     * GET /api/channel/models  所有渠道模型（去重合并）
     */
    public function models(): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $models = $this->channelService->getAllModels();

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $models,
        ]);
    }

    /**
     * GET /api/channel/models_enabled  启用的模型列表
     */
    public function modelsEnabled(): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $models = $this->channelService->getEnabledModels();

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $models,
        ]);
    }

    /**
     * GET /api/channel/ops  渠道操作元数据（类型/状态枚举）
     */
    public function ops(): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => [
                'status' => [
                    ['value' => 1, 'label' => '已启用'],
                    ['value' => 2, 'label' => '已禁用'],
                    ['value' => 3, 'label' => '自动禁用'],
                ],
            ],
        ]);
    }

    /**
     * GET /api/channel/{id}  渠道详情
     */
    public function show(int $id): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $channel = Channel::findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $channel,
        ]);
    }

    /**
     * POST /api/channel/  创建渠道
     */
    public function store(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|integer',
            'key' => 'required|string',
            'base_url' => 'nullable|string|max:511',
            'models' => 'nullable|array',
            'model_mapping' => 'nullable|array',
            'group' => 'nullable|string',
            'priority' => 'nullable|integer|min:0',
            'weight' => 'nullable|integer|min:0',
            'status' => 'nullable|integer|in:1,2,3',
            'test_model' => 'nullable|string',
            'tag' => 'nullable|string',
            'other' => 'nullable|string',
            'other_info' => 'nullable|array',
            'setting' => 'nullable|array',
            'param_override' => 'nullable|array',
            'header_override' => 'nullable|array',
            'status_code_mapping' => 'nullable|array',
            'auto_ban' => 'nullable|integer|in:0,1',
            'remark' => 'nullable|string',
            'channel_info' => 'nullable|array',
            'settings' => 'nullable|array',
            'openai_organization' => 'nullable|string',
        ]);

        $channel = $this->channelService->create($validated);

        return response()->json([
            'success' => true,
            'message' => __('Channel created successfully'),
            'data' => $channel,
        ], 201);
    }

    /**
     * PUT /api/channel/  更新渠道（id 在 body）
     * PUT /web-api/channels/{id}  更新渠道（id 在 URL，兼容 apiResource）
     */
    public function update(Request $request, int $id = 0): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        // 优先使用 URL 中的 id，回退到 body 中的 id
        if ($id <= 0) {
            $id = (int) $request->input('id');
        }
        if ($id <= 0) {
            return response()->json(['success' => false, 'message' => __('Missing channel id')], 400);
        }

        $channel = Channel::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'type' => 'sometimes|integer',
            'key' => 'sometimes|string',
            'base_url' => 'nullable|string|max:511',
            'models' => 'nullable|array',
            'model_mapping' => 'nullable|array',
            'group' => 'nullable|string',
            'priority' => 'nullable|integer|min:0',
            'weight' => 'nullable|integer|min:0',
            'status' => 'nullable|integer|in:1,2,3',
            'test_model' => 'nullable|string',
            'tag' => 'nullable|string',
            'other' => 'nullable|string',
            'other_info' => 'nullable|array',
            'setting' => 'nullable|array',
            'param_override' => 'nullable|array',
            'header_override' => 'nullable|array',
            'status_code_mapping' => 'nullable|array',
            'auto_ban' => 'nullable|integer|in:0,1',
            'remark' => 'nullable|string',
            'channel_info' => 'nullable|array',
            'settings' => 'nullable|array',
            'openai_organization' => 'nullable|string',
        ]);

        $this->channelService->update($channel, $validated);

        return response()->json([
            'success' => true,
            'message' => __('Channel updated successfully'),
            'data' => $channel->fresh(),
        ]);
    }

    /**
     * DELETE /api/channel/{id}  删除渠道
     */
    public function destroy(int $id): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $channel = Channel::findOrFail($id);
        $this->channelService->delete($channel);

        return response()->json([
            'success' => true,
            'message' => __('Channel deleted'),
        ]);
    }

    /**
     * POST /api/channel/batch  批量删除
     * body: {ids: [1,2,3]}
     */
    public function batchDelete(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $ids = $request->input('ids', []);
        if (! is_array($ids) || empty($ids)) {
            return response()->json(['success' => false, 'message' => __('Missing ids')], 400);
        }

        $count = $this->channelService->batchDelete(array_map('intval', $ids));

        return response()->json([
            'success' => true,
            'message' => __('Deleted :count channels', ['count' => $count]),
        ]);
    }

    /**
     * POST /api/channel/status/batch  批量更新状态
     * body: {ids:[], status:1}
     */
    public function batchStatus(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $ids = $request->input('ids', []);
        $status = (int) $request->input('status', 1);
        if (! is_array($ids) || empty($ids)) {
            return response()->json(['success' => false, 'message' => __('Missing ids')], 400);
        }

        $count = $this->channelService->batchUpdateStatus(array_map('intval', $ids), $status);

        return response()->json([
            'success' => true,
            'message' => __('Updated status for :count channels', ['count' => $count]),
        ]);
    }

    /**
     * POST /api/channel/{id}/status  更新单个状态
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $status = (int) $request->input('status', 1);
        $channel = Channel::findOrFail($id);
        $this->channelService->updateStatus($channel, $status);

        return response()->json([
            'success' => true,
            'message' => __('Status updated'),
        ]);
    }

    /**
     * DELETE /api/channel/disabled  删除所有禁用渠道
     */
    public function deleteDisabled(): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $count = $this->channelService->deleteDisabled();

        return response()->json([
            'success' => true,
            'message' => __('Deleted :count disabled channels', ['count' => $count]),
        ]);
    }

    /**
     * GET /api/channel/test  测试所有渠道
     */
    public function testAll(): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $result = $this->healthService->checkAllChannels();

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $result,
        ]);
    }

    /**
     * GET /api/channel/test/{id}  测试单个渠道
     */
    public function test(int $id): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $channel = Channel::findOrFail($id);
        $result = $this->channelService->testChannel($channel);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'] ?? '',
            'data' => $result,
        ]);
    }

    /**
     * GET /api/channel/update_balance  更新所有余额
     */
    public function updateBalanceAll(): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $result = $this->channelService->updateAllBalance();

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $result,
        ]);
    }

    /**
     * GET /api/channel/update_balance/{id}  更新单个余额
     */
    public function updateBalance(int $id): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $channel = Channel::findOrFail($id);
        $balance = $this->channelService->updateBalance($channel);

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => ['balance' => $balance],
        ]);
    }

    /**
     * POST /api/channel/fix  修复能力表（重建所有渠道 abilities）
     */
    public function fixAbilities(): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $count = $this->channelService->fixAllAbilities();

        return response()->json([
            'success' => true,
            'message' => __('Rebuilt ability tables for :count channels', ['count' => $count]),
        ]);
    }

    /**
     * GET /api/channel/fetch_models/{id}  拉取上游模型
     */
    public function fetchModels(int $id): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $channel = Channel::findOrFail($id);
        $models = $this->channelService->fetchUpstreamModels($channel);
        $metadata = $this->channelService->syncModelMetadata($models);

        return response()->json([
            'success' => $models !== [],
            'message' => '',
            'data' => $models,
            'metadata' => $metadata,
        ]);
    }

    /**
     * POST /api/channel/fetch_models  批量拉取模型
     * body: {ids: [1,2,3]}
     */
    public function batchFetchModels(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        // 新增渠道尚未入库时，直接使用表单中的地址和 Key 探测模型。
        if ($request->filled('base_url')) {
            $validated = $request->validate([
                'base_url' => 'required|string|max:511',
                'key' => 'nullable|string',
                'type' => 'nullable|integer',
                'header_override' => 'nullable',
            ]);
            $headers = $validated['header_override'] ?? [];
            if (is_string($headers)) {
                $headers = json_decode($headers, true) ?: [];
            }
            $result = $this->channelService->discoverModels(
                $validated['base_url'],
                $validated['key'] ?? '',
                (int) ($validated['type'] ?? 1),
                is_array($headers) ? $headers : []
            );

            return response()->json([
                'success' => $result['models'] !== [],
                'message' => $result['message'],
                'data' => $result['models'],
                'metadata' => $result['metadata'],
            ], $result['models'] === [] ? 422 : 200);
        }

        $ids = $request->input('ids', []);
        if (! is_array($ids) || empty($ids)) {
            return response()->json(['success' => false, 'message' => __('Missing ids')], 400);
        }

        $result = [];
        foreach (array_map('intval', $ids) as $id) {
            $channel = Channel::find($id);
            if ($channel) {
                $models = $this->channelService->fetchUpstreamModels($channel);
                $result[$id] = [
                    'models' => $models,
                    'metadata' => $this->channelService->syncModelMetadata($models),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $result,
        ]);
    }

    /**
     * POST /api/channel/tag/disabled  禁用标签下所有渠道
     * body: {tag: "xxx"}
     */
    public function disableByTag(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $tag = (string) $request->input('tag', '');
        if ($tag === '') {
            return response()->json(['success' => false, 'message' => __('Missing tag')], 400);
        }

        $count = $this->channelService->disableByTag($tag);

        return response()->json([
            'success' => true,
            'message' => __('Disabled :count channels', ['count' => $count]),
        ]);
    }

    /**
     * POST /api/channel/tag/enabled  启用标签下所有渠道
     * body: {tag: "xxx"}
     */
    public function enableByTag(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $tag = (string) $request->input('tag', '');
        if ($tag === '') {
            return response()->json(['success' => false, 'message' => __('Missing tag')], 400);
        }

        $count = $this->channelService->enableByTag($tag);

        return response()->json([
            'success' => true,
            'message' => __('Enabled :count channels', ['count' => $count]),
        ]);
    }

    /**
     * PUT /api/channel/tag  编辑渠道标签
     * body: {id, tag}
     */
    public function updateTag(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $id = (int) $request->input('id');
        $tag = (string) $request->input('tag', '');
        if ($id <= 0) {
            return response()->json(['success' => false, 'message' => __('Missing id')], 400);
        }

        $channel = Channel::findOrFail($id);
        $channel->update(['tag' => $tag]);

        return response()->json([
            'success' => true,
            'message' => __('Tags updated'),
        ]);
    }

    /**
     * GET /api/channel/tag/models  标签下模型列表
     */
    public function tagModels(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $tag = (string) $request->input('tag', '');
        $models = Channel::where('tag', $tag)
            ->where('status', 1)
            ->pluck('models')
            ->flatMap(fn ($m) => explode(',', (string) $m))
            ->map(fn ($m) => trim($m))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $models,
        ]);
    }

    /**
     * POST /api/channel/batch/tag  批量打标签
     * body: {ids:[], tag:""}
     */
    public function batchTag(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $ids = $request->input('ids', []);
        $tag = (string) $request->input('tag', '');
        if (! is_array($ids) || empty($ids)) {
            return response()->json(['success' => false, 'message' => __('Missing ids')], 400);
        }

        $count = Channel::whereIn('id', array_map('intval', $ids))->update(['tag' => $tag]);

        return response()->json([
            'success' => true,
            'message' => __('Updated tags for :count channels', ['count' => $count]),
        ]);
    }

    /**
     * POST /api/channel/copy/{id}  复制渠道
     */
    public function copy(int $id): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $channel = Channel::findOrFail($id);
        $newChannel = $this->channelService->copyChannel($channel);

        return response()->json([
            'success' => true,
            'message' => __('Channel copied'),
            'data' => $newChannel,
        ], 201);
    }

    /**
     * POST /api/channel/multi_key/manage  多 Key 管理
     * body: {id, action, keys}
     */
    public function multiKeyManage(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $id = (int) $request->input('id');
        $action = (string) $request->input('action', 'list');
        $keys = $request->input('keys', []);

        $channel = Channel::findOrFail($id);
        $result = $this->channelService->multiKeyManage($channel, $action, $keys);

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $result,
        ]);
    }

    /**
     * POST /api/channel/upstream_updates/apply  应用上游更新
     */
    public function applyUpstreamUpdates(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $id = (int) $request->input('id');
        $channel = Channel::findOrFail($id);
        $result = $this->channelService->applyUpstreamUpdates($channel);

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $result,
        ]);
    }

    /**
     * POST /api/channel/upstream_updates/apply_all  应用所有上游更新
     */
    public function applyAllUpstreamUpdates(): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $result = $this->channelService->applyAllUpstreamUpdates();

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $result,
        ]);
    }

    /**
     * POST /api/channel/upstream_updates/detect  检测上游更新
     */
    public function detectUpstreamUpdates(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $id = (int) $request->input('id');
        $channel = Channel::findOrFail($id);
        $result = $this->channelService->detectUpstreamUpdates($channel);

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $result,
        ]);
    }

    /**
     * POST /api/channel/upstream_updates/detect_all  检测所有上游更新
     */
    public function detectAllUpstreamUpdates(): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $result = $this->channelService->detectAllUpstreamUpdates();

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $result,
        ]);
    }

    /**
     * POST /api/channel/{id}/key  获取渠道 Key (Root + 安全验证)
     */
    public function getKey(int $id): JsonResponse
    {
        $user = Auth::user();
        if (! $user || $user->role < 100) {
            return response()->json(['success' => false, 'message' => __('Root permission required')], 403);
        }

        $channel = Channel::findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => ['key' => $channel->key],
        ]);
    }

    /**
     * GET /web-api/channels/{id}/health  渠道健康检查（复用 test 逻辑）
     */
    public function health(int $id): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $channel = Channel::findOrFail($id);
        $result = $this->channelService->testChannel($channel);

        return response()->json([
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? '',
            'data' => $result,
        ]);
    }

    /**
     * GET /web-api/channels/health/all  所有渠道健康检查（复用 testAll 逻辑）
     */
    public function healthAll(): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        $result = $this->healthService->checkAllChannels();

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $result,
        ]);
    }

    /**
     * POST /api/channel/{id}/codex/refresh  Codex 刷新 (stub)
     */
    public function codexRefresh(int $id): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        return response()->json([
            'success' => false,
            'message' => __('Codex channel is not implemented yet'),
        ], 501);
    }

    /**
     * GET /api/channel/{id}/codex/usage  Codex 用量 (stub)
     */
    public function codexUsage(int $id): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        return response()->json([
            'success' => false,
            'message' => __('Codex channel is not implemented yet'),
        ], 501);
    }

    /**
     * POST /api/channel/{id}/codex/usage/reset  重置 Codex 用量 (stub)
     */
    public function codexUsageReset(int $id): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        return response()->json([
            'success' => false,
            'message' => __('Codex channel is not implemented yet'),
        ], 501);
    }

    /**
     * POST /api/channel/ollama/pull  Ollama 拉取 (stub)
     */
    public function ollamaPull(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        return response()->json([
            'success' => false,
            'message' => __('Ollama pull is not implemented yet'),
        ], 501);
    }

    /**
     * POST /api/channel/ollama/pull/stream  Ollama 拉取流式 (stub)
     */
    public function ollamaPullStream(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        return response()->json([
            'success' => false,
            'message' => __('Ollama streaming pull is not implemented yet'),
        ], 501);
    }

    /**
     * DELETE /api/channel/ollama/delete  Ollama 删除 (stub)
     */
    public function ollamaDelete(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        return response()->json([
            'success' => false,
            'message' => __('Ollama delete is not implemented yet'),
        ], 501);
    }

    /**
     * GET /api/channel/ollama/version/{id}  Ollama 版本 (stub)
     */
    public function ollamaVersion(int $id): JsonResponse
    {
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        return response()->json([
            'success' => false,
            'message' => __('Ollama version query is not implemented yet'),
        ], 501);
    }
}
