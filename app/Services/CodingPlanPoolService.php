<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CodingPlanAccount;
use App\Models\CodingPlanUsageLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Coding Plan 账号池调度服务
 *
 * 职责：
 *  - 按供应商分组，从账号池中选择一个“可用”账号（按优先级 + 月使用率排序）
 *  - 同供应商账号用完（过期/配额耗尽/超月使用率阈值）时，自动切换下一个账号
 *  - 记录每次提交消耗，原子递增计数器
 *  - 检测并重置 5h / 周 / 月滚动窗口计数器
 */
class CodingPlanPoolService
{
    /**
     * 为指定供应商选择一个可用账号。
     *
     * 选择策略：
     *  1) status = 启用 且未过期
     *  2) 各周期配额未耗尽
     *  3) 优先使用“月使用率未超阈值”的账号；若全部超阈值但仍可用，则按月使用率升序取最低
     *  4) 按优先级升序、id 升序兜底
     */
    public function pickAccount(string $vendor): ?CodingPlanAccount
    {
        $now = time();

        // 先重置到期窗口，避免选到计数器未刷新的账号
        $this->resetExpiredWindows($vendor, $now);

        $accounts = CodingPlanAccount::where('vendor', $vendor)
            ->where('status', '!=', CodingPlanAccount::STATUS_DISABLED)
            ->where(function ($q) use ($now) {
                $q->where('expires_at', 0)->orWhere('expires_at', '>', $now);
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            return null;
        }

        // 第一轮：可用且未超月使用率阈值
        $candidates = $accounts->filter(function (CodingPlanAccount $a) {
            return $a->hasAvailableQuota() && ! $a->exceedsMonthlyThreshold();
        });

        // 第二轮：可用但已超阈值（仍可继续用直到硬上限）
        if ($candidates->isEmpty()) {
            $candidates = $accounts->filter(function (CodingPlanAccount $a) {
                return $a->hasAvailableQuota();
            });
        }

        if ($candidates->isEmpty()) {
            return null;
        }

        // 在候选中按月使用率升序（优先用得少的），再按优先级
        return $candidates->sortBy(function (CodingPlanAccount $a) {
            return [$a->monthlyUsageRate(), $a->priority, $a->id];
        })->first();
    }

    /**
     * 记录一次消耗（原子递增 + 写流水）。
     *
     * 单位为供应商原生单位（按次模式=提交次数；积分模式=比率表折算值，可有小数）。
     * 仅成功请求计入池计数（失败/上游配额错误不消耗配额），失败仍写流水便于审计。
     * 上游配额超限类错误（quota_exceeded）会立即将账号标记为耗尽，避免被反复选中持续失败。
     *
     * @param  float  $units  本次消耗的供应商原生单位
     * @param  array  $meta  额外信息：user_id/channel_id/model/request_id/tokens/units/credits/meta(比率快照)
     */
    public function recordUsage(CodingPlanAccount $account, float $units = 1.0, array $meta = [], bool $success = true, ?string $error = null): void
    {
        $now = time();
        $units = round(max(0.0, $units), 4);
        $credits = round((float) ($meta['credits'] ?? 0), 4);
        // SQL 原子增量使用安全的数字字面量
        $unitsLiteral = number_format($units, 4, '.', '');

        DB::transaction(function () use ($account, $units, $unitsLiteral, $credits, $meta, $success, $error, $now) {
            $increments = [
                'last_used_at' => $now,
                'updated_at' => $now,
            ];

            // 仅成功请求消耗池配额
            if ($success && $units > 0) {
                $increments['used_5h'] = DB::raw('used_5h + '.$unitsLiteral);
                $increments['used_weekly'] = DB::raw('used_weekly + '.$unitsLiteral);
                $increments['used_monthly'] = DB::raw('used_monthly + '.$unitsLiteral);
            }

            $affected = CodingPlanAccount::where('id', $account->id)
                ->where('updated_at', $account->updated_at)
                ->update($increments);

            if ($affected === 0) {
                // 并发冲突时退化为直接更新
                CodingPlanAccount::where('id', $account->id)->update($increments);
            }

            // 同步内存模型
            if ($success && $units > 0) {
                $account->used_5h = (float) $account->used_5h + $units;
                $account->used_weekly = (float) $account->used_weekly + $units;
                $account->used_monthly = (float) $account->used_monthly + $units;
            }
            $account->last_used_at = $now;
            $account->updated_at = $now;

            CodingPlanUsageLog::create([
                'account_id' => $account->id,
                'vendor' => $account->vendor,
                'user_id' => $meta['user_id'] ?? 0,
                'channel_id' => $meta['channel_id'] ?? $account->channel_id,
                'model' => $meta['model'] ?? null,
                'count' => $meta['count'] ?? ($account->isCreditBilling() ? 0 : $units),
                'units' => $units,
                'credits' => $credits,
                'prompt_tokens' => $meta['prompt_tokens'] ?? 0,
                'completion_tokens' => $meta['completion_tokens'] ?? 0,
                'total_tokens' => $meta['total_tokens'] ?? 0,
                'request_id' => $meta['request_id'] ?? null,
                'success' => $success,
                'error' => $error,
                'meta' => $meta['meta'] ?? null,
                'created_at' => $now,
            ]);

            // 检查是否需要标记为耗尽（仅成功请求才可能真正耗尽）
            if ($success && ! $account->hasAvailableQuota()) {
                CodingPlanAccount::where('id', $account->id)
                    ->where('status', '!=', CodingPlanAccount::STATUS_EXHAUSTED)
                    ->update([
                        'status' => CodingPlanAccount::STATUS_EXHAUSTED,
                        'updated_at' => $now,
                    ]);
                $account->status = CodingPlanAccount::STATUS_EXHAUSTED;
                Log::info('CodingPlan account exhausted, will auto-switch', [
                    'account_id' => $account->id,
                    'vendor' => $account->vendor,
                ]);
            }

            // 上游配额超限类错误：立即标记耗尽，下次请求自动切换到同供应商其他账号
            // （窗口到期后由 resetExpiredWindows 自动恢复并重试）
            if (! $success && $error === 'quota_exceeded') {
                $exhaustedUpdate = [
                    'status' => CodingPlanAccount::STATUS_EXHAUSTED,
                    'updated_at' => $now,
                ];
                // 初始化恢复窗口，确保即使使用量为 0 也能在窗口到期后自动恢复
                if ($account->quota_5h > 0 && $account->reset_5h_at <= $now) {
                    $exhaustedUpdate['reset_5h_at'] = $now + 5 * 3600;
                }
                if ($account->quota_weekly > 0 && $account->reset_weekly_at <= $now) {
                    $exhaustedUpdate['reset_weekly_at'] = $now + 7 * 24 * 3600;
                }
                if ($account->quota_monthly > 0 && $account->reset_monthly_at <= $now) {
                    $exhaustedUpdate['reset_monthly_at'] = $now + 30 * 24 * 3600;
                }

                CodingPlanAccount::where('id', $account->id)
                    ->where('status', '!=', CodingPlanAccount::STATUS_EXHAUSTED)
                    ->update($exhaustedUpdate);
                $account->status = CodingPlanAccount::STATUS_EXHAUSTED;
                Log::warning('CodingPlan account exhausted by upstream quota error', [
                    'account_id' => $account->id,
                    'vendor' => $account->vendor,
                ]);
            }
        });
    }

    /**
     * 自动选择并记录：若当前账号不可用则自动切换到同供应商下一个账号。
     *
     * @return array{account: CodingPlanAccount|null, switched: bool}
     */
    public function pickAndPrepare(string $vendor): array
    {
        $account = $this->pickAccount($vendor);

        return ['account' => $account, 'switched' => true];
    }

    /**
     * 重置已到期的滚动窗口计数器（5h / 周 / 月）。
     * 在选号前调用，保证计数器新鲜。
     */
    public function resetExpiredWindows(?string $vendor = null, ?int $now = null): int
    {
        $now = $now ?? time();
        $reset = 0;

        $baseQuery = CodingPlanAccount::query();
        if ($vendor !== null) {
            $baseQuery->where('vendor', $vendor);
        }

        // 5h 窗口
        $accounts5h = (clone $baseQuery)
            ->where('quota_5h', '>', 0)
            ->where('reset_5h_at', '>', 0)
            ->where('reset_5h_at', '<=', $now)
            // 因上游配额错误被标记耗尽的账号即使无使用量也参与重试，
            // 避免上游配额恢复后账号无法自动启用
            ->where(function ($q) {
                $q->where('used_5h', '>', 0)
                    ->orWhere('status', CodingPlanAccount::STATUS_EXHAUSTED);
            })
            ->get();
        foreach ($accounts5h as $a) {
            CodingPlanAccount::where('id', $a->id)->update([
                'used_5h' => 0,
                'reset_5h_at' => $now + 5 * 3600,
                // 窗口重置后若账号因耗尽被标记，恢复为启用（若其他窗口仍耗尽，后续请求会重新标记）
                'status' => $a->status === CodingPlanAccount::STATUS_EXHAUSTED
                    ? CodingPlanAccount::STATUS_ENABLED
                    : $a->status,
                'updated_at' => $now,
            ]);
            $reset++;
        }

        // 周窗口
        $accountsWeek = (clone $baseQuery)
            ->where('quota_weekly', '>', 0)
            ->where('reset_weekly_at', '>', 0)
            ->where('reset_weekly_at', '<=', $now)
            ->where(function ($q) {
                $q->where('used_weekly', '>', 0)
                    ->orWhere('status', CodingPlanAccount::STATUS_EXHAUSTED);
            })
            ->get();
        foreach ($accountsWeek as $a) {
            CodingPlanAccount::where('id', $a->id)->update([
                'used_weekly' => 0,
                'reset_weekly_at' => $now + 7 * 24 * 3600,
                'status' => $a->status === CodingPlanAccount::STATUS_EXHAUSTED
                    ? CodingPlanAccount::STATUS_ENABLED
                    : $a->status,
                'updated_at' => $now,
            ]);
            $reset++;
        }

        // 月窗口
        $accountsMonth = (clone $baseQuery)
            ->where('quota_monthly', '>', 0)
            ->where('reset_monthly_at', '>', 0)
            ->where('reset_monthly_at', '<=', $now)
            ->where(function ($q) {
                $q->where('used_monthly', '>', 0)
                    ->orWhere('status', CodingPlanAccount::STATUS_EXHAUSTED);
            })
            ->get();
        foreach ($accountsMonth as $a) {
            CodingPlanAccount::where('id', $a->id)->update([
                'used_monthly' => 0,
                'reset_monthly_at' => $now + 30 * 24 * 3600,
                'status' => $a->status === CodingPlanAccount::STATUS_EXHAUSTED
                    ? CodingPlanAccount::STATUS_ENABLED
                    : $a->status,
                'updated_at' => $now,
            ]);
            $reset++;
        }

        return $reset;
    }

    /**
     * 将已过期账号标记为禁用（定时任务调用）。
     */
    public function disableExpiredAccounts(?int $now = null): int
    {
        $now = $now ?? time();

        return CodingPlanAccount::where('expires_at', '>', 0)
            ->where('expires_at', '<=', $now)
            ->where('status', '!=', CodingPlanAccount::STATUS_DISABLED)
            ->update([
                'status' => CodingPlanAccount::STATUS_DISABLED,
                'updated_at' => $now,
            ]);
    }

    /**
     * 获取某供应商账号池的概览（管理后台用）。
     *
     * 返回供应商原生单位与平台积分（统一折算池口径）双份统计。
     */
    public function vendorOverview(string $vendor): array
    {
        $accounts = CodingPlanAccount::where('vendor', $vendor)->orderBy('priority')->orderBy('id')->get();
        $total = $accounts->count();
        $active = $accounts->filter(fn ($a) => $a->hasAvailableQuota())->count();
        $exhausted = $accounts->where('status', CodingPlanAccount::STATUS_EXHAUSTED)->count();
        $disabled = $accounts->where('status', CodingPlanAccount::STATUS_DISABLED)->count();

        // 统一折算池口径：各账号配额/消耗按各自「生效汇率」折算为平台积分后汇总
        // （生效汇率 = 账号级 > 供应商默认 > 1，与计费口径一致）
        $quotaCredits = 0.0;
        $usedCredits = 0.0;
        foreach ($accounts as $a) {
            $quotaCredits += (float) $a->quota_monthly * $a->effectiveExchangeRate();
            $usedCredits += (float) $a->used_monthly * $a->effectiveExchangeRate();
        }

        $unitName = $accounts->first()?->unitDisplayName() ?? '次';

        return [
            'vendor' => $vendor,
            'unit_name' => $unitName,
            'total' => $total,
            'active' => $active,
            'exhausted' => $exhausted,
            'disabled' => $disabled,
            // 原生单位口径（月窗口）
            'monthly_quota' => round((float) $accounts->sum('quota_monthly'), 2),
            'monthly_used' => round((float) $accounts->sum('used_monthly'), 2),
            // 平台积分口径（统一折算池）
            'credits_quota' => round($quotaCredits, 2),
            'credits_used' => round($usedCredits, 2),
            'credits_remaining' => round(max(0.0, $quotaCredits - $usedCredits), 2),
            'accounts' => $accounts->map(fn ($a) => [
                'id' => $a->id,
                'account_name' => $a->account_name,
                'billing_mode' => $a->billing_mode,
                'unit_name' => $a->unitDisplayName(),
                // 生效汇率（账号级 > 供应商默认 > 1），与 credits_* 汇总口径一致
                'unit_exchange_rate' => $a->effectiveExchangeRate(),
                'status' => $a->status,
                'priority' => $a->priority,
                'expires_at' => $a->expires_at,
                'remaining' => $a->remaining(),
                'monthly_usage_rate' => $a->monthlyUsageRate(),
                'monthly_threshold' => $a->monthly_usage_threshold,
            ]),
        ];
    }
}
