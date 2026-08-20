<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Redemption;
use App\Models\User;
use App\Services\QuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 兑换码控制器 - 对齐 Go 版 controller/redemption.go
 *
 * 端点：
 *  - GET    /api/redemption/            列表（管理员）
 *  - GET    /api/redemption/search      搜索（管理员）
 *  - GET    /api/redemption/:id         详情（管理员）
 *  - POST   /api/redemption/            创建（管理员）
 *  - PUT    /api/redemption/            更新（管理员）
 *  - DELETE /api/redemption/:id         删除（管理员）
 *  - DELETE /api/redemption/invalid     删除无效（管理员）
 *  - POST   /api/redemption/            批量创建（管理员，带 batch=true）
 *  - POST   /api/user/self/redeem       用户兑换（需登录）
 */
class RedemptionController extends Controller
{
    public function __construct(
        private readonly QuotaService $quotaService,
    ) {}

    /**
     * 兑换码列表（管理员）
     */
    public function index(Request $request): JsonResponse
    {
        $query = Redemption::query();

        // 关键字搜索
        if ($keyword = $request->input('keyword')) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('key', 'like', "%{$keyword}%");
            });
        }

        // 状态过滤
        if ($request->has('status')) {
            $query->where('status', (int) $request->input('status'));
        }

        $orderBy = $request->input('order', 'id');
        $orderDir = $request->input('order_dir', 'desc');
        $query->orderBy($orderBy, $orderDir);

        $pageSize = (int) $request->input('p', 20);
        $redemptions = $query->paginate($pageSize);

        return $this->paginate($redemptions);
    }

    /**
     * 搜索兑换码（管理员）
     */
    public function search(Request $request): JsonResponse
    {
        $keyword = (string) $request->input('keyword', '');
        $query = Redemption::query();

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('key', 'like', "%{$keyword}%");
            });
        }

        $redemptions = $query->orderBy('id', 'desc')->limit(100)->get();

        return $this->success($redemptions);
    }

    /**
     * 兑换码详情（管理员）
     */
    public function show(int $id): JsonResponse
    {
        $redemption = Redemption::find($id);
        if (! $redemption) {
            return $this->error('兑换码不存在', 404);
        }

        return $this->success($redemption);
    }

    /**
     * 创建兑换码（管理员）- 支持批量
     */
    public function store(Request $request): JsonResponse
    {
        $batchSize = (int) $request->input('count', 1);

        // 单个创建
        if ($batchSize <= 1) {
            $data = $this->validateRedemption($request, true);
            $redemption = $this->createRedemption($data);

            return $this->success($redemption);
        }

        // 批量创建
        if ($batchSize > 100) {
            return $this->error('批量创建数量不能超过 100', 400);
        }

        $baseData = $this->validateRedemption($request, true);
        $redemptions = [];
        $now = time();

        DB::beginTransaction();
        try {
            for ($i = 0; $i < $batchSize; $i++) {
                $redemptions[] = $this->createRedemption($baseData, $now);
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('批量创建兑换码失败: '.$e->getMessage());

            return $this->error('批量创建失败: '.$e->getMessage(), 500);
        }

        return $this->success($redemptions);
    }

    /**
     * 更新兑换码（管理员）
     */
    public function update(Request $request): JsonResponse
    {
        $id = (int) $request->input('id');
        $redemption = Redemption::find($id);
        if (! $redemption) {
            return $this->error('兑换码不存在', 404);
        }

        $data = $this->validateRedemption($request, false);

        // key 不允许修改（防止已发放的兑换码被篡改）
        unset($data['key']);

        $redemption->update($data);

        return $this->success($redemption);
    }

    /**
     * 删除兑换码（管理员）
     */
    public function destroy(int $id): JsonResponse
    {
        $redemption = Redemption::find($id);
        if (! $redemption) {
            return $this->error('兑换码不存在', 404);
        }

        $redemption->delete();

        return $this->success();
    }

    /**
     * 删除无效兑换码（管理员）- 状态为禁用或已过期或已达最大使用次数
     */
    public function destroyInvalid(): JsonResponse
    {
        $now = time();
        $count = Redemption::where('status', 0)
            ->orWhere(function ($q) use ($now) {
                $q->where('expired_at', '>', 0)->where('expired_at', '<', $now);
            })
            ->orWhereColumn('used_count', '>=', 'max_use_count')
            ->delete();

        return $this->success(['deleted' => $count]);
    }

    /**
     * 用户兑换 - 对齐 Go 版 Redeem 逻辑
     */
    public function redeem(Request $request): JsonResponse
    {
        $request->validate(['key' => 'required|string']);

        $key = trim((string) $request->input('key'));
        /** @var User $user */
        $user = $request->user();

        try {
            $result = DB::transaction(function () use ($key, $user) {
                // 行锁防止并发兑换
                $redemption = Redemption::where('key', $key)->lockForUpdate()->first();
                if (! $redemption) {
                    throw new \DomainException('兑换码无效', 404);
                }

                if ($redemption->status !== 1) {
                    throw new \DomainException('兑换码已被禁用', 400);
                }

                // 过期检查
                if ($redemption->expired_at > 0 && $redemption->expired_at < time()) {
                    $redemption->update(['status' => 0]);
                    throw new \DomainException('兑换码已过期', 400);
                }

                // 使用次数检查
                if ($redemption->max_use_count > 0 && $redemption->used_count >= $redemption->max_use_count) {
                    throw new \DomainException('兑换码已达最大使用次数', 400);
                }

                // 防止同一用户重复兑换（通过 used_user_ids 字段，若存在）
                if (! empty($redemption->used_user_ids)) {
                    $usedUserIds = json_decode($redemption->used_user_ids, true) ?: [];
                    if (in_array($user->id, $usedUserIds, true)) {
                        throw new \DomainException('您已兑换过此兑换码', 400);
                    }
                    $usedUserIds[] = $user->id;
                    $redemption->used_user_ids = json_encode($usedUserIds);
                }

                // 递增使用次数
                $redemption->increment('used_count');
                $redemption->update([
                    'user_id' => $user->id,
                    'redeemed_at' => time(),
                ]);

                // 达到最大使用次数后自动禁用
                if ($redemption->max_use_count > 0 && $redemption->used_count >= $redemption->max_use_count) {
                    $redemption->update(['status' => 0]);
                }

                return $redemption;
            });
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), (int) $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('兑换码兑换失败: '.$e->getMessage());

            return $this->error('兑换失败，请稍后重试', 500);
        }

        // 在事务外发放配额（QuotaService 内部有独立事务 + 日志）
        $this->quotaService->addQuota($user, (int) $result->quota, 'redemption');
        $user->refresh();

        return $this->success([
            'message' => __('Redemption successful'),
            'quota' => $result->quota,
            'balance' => $user->quota,
        ]);
    }

    /**
     * 验证兑换码数据
     */
    private function validateRedemption(Request $request, bool $isCreate): array
    {
        $rules = [
            'name' => 'nullable|string|max:255',
            'quota' => 'required|integer|min:1',
            'max_use_count' => 'nullable|integer|min:0',
            'expired_at' => 'nullable|integer|min:0',
            'status' => 'nullable|integer|in:0,1',
        ];

        if ($isCreate) {
            $rules['key'] = 'nullable|string|max:64';
        }

        $validated = $request->validate($rules);

        return [
            'name' => $validated['name'] ?? '',
            'key' => $validated['key'] ?? $this->generateKey(),
            'quota' => (int) $validated['quota'],
            'max_use_count' => (int) ($validated['max_use_count'] ?? 1),
            'used_count' => 0,
            'expired_at' => (int) ($validated['expired_at'] ?? 0),
            'status' => (int) ($validated['status'] ?? 1),
            'created_time' => time(),
        ];
    }

    /**
     * 生成兑换码 - 对齐 Go 版：前缀 "sk-" + 随机字符
     */
    private function generateKey(): string
    {
        return 'sk-'.Str::random(24);
    }

    /**
     * 创建单个兑换码记录
     */
    private function createRedemption(array $data, ?int $now = null): Redemption
    {
        $data['created_time'] = $now ?? time();
        $data['key'] = $data['key'] ?? $this->generateKey();

        return Redemption::create($data);
    }
}
