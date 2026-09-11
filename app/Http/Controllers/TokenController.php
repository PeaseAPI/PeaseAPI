<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Token;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Token Controller - 对齐 new-api controller/token.go
 *
 * 端点（routes/api.php，SPA「令牌」页面消费）：
 *  - GET    /api/token/?p=&size=        自有令牌分页列表（selfTokens）
 *  - GET    /api/token/search           按名称/密钥搜索
 *  - GET    /api/token/{id}             详情
 *  - POST   /api/token/                 创建
 *  - PUT    /api/token/                 更新（id 在请求体；?status_only=true 仅改状态）
 *  - DELETE /api/token/{id}[/]          删除（兼容 SPA 尾斜杠）
 *  - POST   /api/token/{id}/key         查看完整密钥（不含 sk- 前缀，前端自行补）
 *  - POST   /api/token/batch            批量删除
 *  - POST   /api/token/batch/keys       批量取完整密钥
 */
class TokenController extends Controller
{
    /** 密钥显示掩码（不含 sk- 前缀）：首字符 + **** + 末 4 位 */
    public static function maskKey(string $key): string
    {
        $body = str_starts_with($key, 'sk-') ? substr($key, 3) : $key;
        if (strlen($body) <= 5) {
            return $body;
        }

        return substr($body, 0, 1).'****'.substr($body, -4);
    }

    /** 去掉 sk- 前缀（SPA 展示时自行补 sk-） */
    public static function stripPrefix(string $key): string
    {
        return str_starts_with($key, 'sk-') ? substr($key, 3) : $key;
    }

    /**
     * SPA 序列化（public/src/features/keys/types.ts ApiKey）
     */
    private function serialize(Token $token): array
    {
        return [
            'id' => (int) $token->id,
            'name' => (string) $token->name,
            'key' => self::maskKey((string) $token->key),
            'status' => (int) $token->status,
            'remain_quota' => (int) $token->remain_quota,
            'used_quota' => (int) $token->used_quota,
            'unlimited_quota' => (bool) $token->unlimited_quota,
            'expired_time' => (int) $token->expired_time, // -1 = 永不过期
            'created_time' => (int) $token->created_time,
            'accessed_time' => (int) $token->accessed_time,
            'group' => (string) ($token->group ?? ''),
            'cross_group_retry' => (bool) $token->cross_group_retry,
            'model_limits_enabled' => (bool) $token->model_limits_enabled,
            'model_limits' => (string) ($token->model_limits ?? ''),
            'allow_ips' => (string) ($token->allow_ips ?? ''),
        ];
    }

    /**
     * 自有令牌分页列表
     */
    public function selfTokens(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = max(1, (int) $request->input('p', 1));
        $size = min(100, max(1, (int) $request->input('size', 10)));

        $query = Token::query()->where('user_id', $user->id);
        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }

        return response()->json($this->pageEnvelope($query, $page, $size));
    }

    /**
     * 搜索（keyword=名称模糊 / token=密钥片段）
     */
    public function search(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = max(1, (int) $request->input('p', 1));
        $size = min(100, max(1, (int) $request->input('size', 10)));

        $query = Token::query()->where('user_id', $user->id);
        $keyword = trim((string) $request->input('keyword', ''));
        $tokenKey = trim((string) $request->input('token', ''));
        if ($keyword !== '') {
            $query->where('name', 'like', "%{$keyword}%");
        }
        if ($tokenKey !== '') {
            $query->where('key', 'like', "%{$tokenKey}%");
        }

        return response()->json($this->pageEnvelope($query, $page, $size));
    }

    /**
     * 详情
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $token = Token::find($id);
        if (! $token) {
            return $this->error('令牌不存在', 404);
        }
        if ($error = $this->denyIfNotOwner($request, $token)) {
            return $error;
        }

        return $this->success($this->serialize($token));
    }

    /**
     * 创建
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'remain_quota' => 'nullable|integer|min:0',
            'expired_time' => 'nullable|integer',
            'unlimited_quota' => 'nullable|boolean',
            'status' => 'nullable|integer|in:1,2',
            'group' => 'nullable|string|max:64',
            'cross_group_retry' => 'nullable|boolean',
            'model_limits_enabled' => 'nullable|boolean',
            'model_limits' => 'nullable|string|max:2000',
            'allow_ips' => 'nullable|string|max:256',
        ]);

        $unlimited = (bool) ($validated['unlimited_quota'] ?? false);

        $token = Token::create([
            'user_id' => $user->id, // 一律归属当前用户，不接受请求体指定
            'name' => $validated['name'],
            'key' => 'sk-'.bin2hex(random_bytes(24)),
            'status' => (int) ($validated['status'] ?? 1),
            'remain_quota' => $unlimited ? 0 : (int) ($validated['remain_quota'] ?? 0),
            'used_quota' => 0,
            'unlimited_quota' => $unlimited,
            'expired_time' => (int) ($validated['expired_time'] ?? -1),
            'created_time' => time(),
            'accessed_time' => time(),
            'group' => (string) ($validated['group'] ?? ''),
            'cross_group_retry' => (bool) ($validated['cross_group_retry'] ?? false),
            'model_limits_enabled' => (bool) ($validated['model_limits_enabled'] ?? false),
            'model_limits' => $this->normalizeModelLimits($validated['model_limits'] ?? ''),
            'allow_ips' => (string) ($validated['allow_ips'] ?? ''),
        ]);

        return $this->success($this->serialize($token));
    }

    /**
     * 更新（id 在路由参数或请求体；?status_only=true 仅更新状态）
     */
    public function update(Request $request, ?int $id = null): JsonResponse
    {
        $id = $id ?: (int) $request->input('id', 0);
        if ($id <= 0) {
            return $this->error('缺少令牌 ID', 422);
        }

        $token = Token::find($id);
        if (! $token) {
            return $this->error('令牌不存在', 404);
        }
        if ($error = $this->denyIfNotOwner($request, $token)) {
            return $error;
        }

        if ($request->boolean('status_only')) {
            $validated = $request->validate([
                'status' => 'required|integer|in:1,2,3',
            ]);
            $token->update(['status' => (int) $validated['status']]);

            return $this->success($this->serialize($token->refresh()));
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'remain_quota' => 'nullable|integer|min:0',
            'expired_time' => 'nullable|integer',
            'unlimited_quota' => 'nullable|boolean',
            'status' => 'nullable|integer|in:1,2,3',
            'group' => 'nullable|string|max:64',
            'cross_group_retry' => 'nullable|boolean',
            'model_limits_enabled' => 'nullable|boolean',
            'model_limits' => 'nullable|string|max:2000',
            'allow_ips' => 'nullable|string|max:256',
        ]);

        $payload = [];
        foreach (['name', 'group', 'allow_ips'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = (string) $validated[$field];
            }
        }
        foreach (['cross_group_retry', 'model_limits_enabled', 'unlimited_quota'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = (bool) $validated[$field];
            }
        }
        if (array_key_exists('status', $validated)) {
            $payload['status'] = (int) $validated['status'];
        }
        if (array_key_exists('expired_time', $validated)) {
            $payload['expired_time'] = (int) $validated['expired_time'];
        }
        if (array_key_exists('remain_quota', $validated)) {
            $payload['remain_quota'] = (int) $validated['remain_quota'];
        }
        if (array_key_exists('model_limits', $validated)) {
            $payload['model_limits'] = $this->normalizeModelLimits((string) $validated['model_limits']);
        }
        if (($payload['unlimited_quota'] ?? $token->unlimited_quota) === true) {
            $payload['remain_quota'] = 0;
        }

        $token->update($payload);

        return $this->success($this->serialize($token->refresh()));
    }

    /**
     * 删除
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $token = Token::find($id);
        if (! $token) {
            return $this->error('令牌不存在', 404);
        }
        if ($error = $this->denyIfNotOwner($request, $token)) {
            return $error;
        }

        $token->delete();

        return $this->success(null, __('Token deleted'));
    }

    /**
     * 查看完整密钥（不含 sk- 前缀）
     */
    public function revealKey(Request $request, int $id): JsonResponse
    {
        $token = Token::find($id);
        if (! $token) {
            return $this->error('令牌不存在', 404);
        }
        if ($error = $this->denyIfNotOwner($request, $token)) {
            return $error;
        }

        return $this->success(['key' => self::stripPrefix((string) $token->key)]);
    }

    /**
     * 批量删除（仅自有令牌；管理员可删任意）
     */
    public function batchDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);
        $user = $request->user();

        $query = Token::whereIn('id', $validated['ids']);
        if ($user->role < 100) {
            $query->where('user_id', $user->id);
        }
        $count = $query->delete();

        return $this->success($count, __('Token deleted'));
    }

    /**
     * 批量取完整密钥
     */
    public function batchGetKeys(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);
        $user = $request->user();

        $query = Token::whereIn('id', $validated['ids']);
        if ($user->role < 100) {
            $query->where('user_id', $user->id);
        }

        $keys = [];
        foreach ($query->get() as $token) {
            $keys[(int) $token->id] = self::stripPrefix((string) $token->key);
        }

        return $this->success(['keys' => $keys]);
    }

    /**
     * 所有权校验：非本人且非管理员时拒绝
     */
    private function denyIfNotOwner(Request $request, Token $token): ?JsonResponse
    {
        $user = $request->user();
        if ($user && $user->role < 100 && (int) $token->user_id !== (int) $user->id) {
            return $this->error(__('Access denied'), 403);
        }

        return null;
    }

    /**
     * 分页信封（SPA GetApiKeysResponse：items/total/page/page_size）
     */
    private function pageEnvelope($query, int $page, int $size): array
    {
        $total = (clone $query)->count();
        $items = $query->orderByDesc('id')
            ->forPage($page, $size)
            ->get()
            ->map(fn (Token $t) => $this->serialize($t))
            ->values();

        return [
            'success' => true,
            'message' => '',
            'data' => [
                'items' => $items,
                'total' => $total,
                'page' => $page,
                'page_size' => $size,
            ],
        ];
    }

    /**
     * model_limits 规范化：逗号/换行分隔 → 去空白去重，逗号串存储（对齐 new-api）
     */
    private function normalizeModelLimits(string $raw): string
    {
        $parts = preg_split('/[,\n\r]+/', $raw) ?: [];
        $models = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && ! in_array($part, $models, true)) {
                $models[] = $part;
            }
        }

        return implode(',', $models);
    }
}
