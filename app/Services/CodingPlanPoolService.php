<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;
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

        // P9-3：恢复冷却到点的账号（上游 429 打标且带 cooldown_until 的，尤其无窗口信息的）
        $this->recoverExpiredCooldowns($vendor, $now);

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

        // 汇率快照留痕（P7-3）：厂商官方币种非基准 CNY 时，记录本次计费
        // 「若按官方价折算」所用的汇率与时刻，便于事后审计（汇率随时间变化）
        $logMeta = $meta['meta'] ?? null;
        if (is_array($logMeta) && ! isset($logMeta['fx'])) {
            $vendorCurrency = CurrencyExchangeService::vendorOfficialCurrency($account->vendor);
            $fx = CurrencyExchangeService::snapshotFor($vendorCurrency);
            if ($fx !== null) {
                $logMeta['fx'] = $fx;
            }
        }

        DB::transaction(function () use ($account, $units, $unitsLiteral, $credits, $meta, $success, $error, $now, $logMeta) {
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
                'meta' => $logMeta,
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

                // P9-3：正常耗尽也同步渠道级冷却（恢复点=窗口 reset_*_at，由 refreshChannelCooldown 推导）
                $this->refreshChannelCooldown((int) ($meta['channel_id'] ?? $account->channel_id), $now);
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

                // P9-3：账号级冷却恢复点——优先上游解析值（Retry-After/重置文案，RelayHandler 传入），
                // 其次已初始化/已有的窗口恢复点；均无（quota_* 全 0）时保守默认 5h 窗口，
                // 修复无周期配额限制的账号被 429 打标耗尽后 reset_*_at 恒为 0 → 永不恢复的缺口
                $cooldown = (int) ($meta['cooldown_until'] ?? 0);
                if ($cooldown <= $now) {
                    $windowPoints = array_filter([
                        (int) ($exhaustedUpdate['reset_5h_at'] ?? $account->reset_5h_at),
                        (int) ($exhaustedUpdate['reset_weekly_at'] ?? $account->reset_weekly_at),
                        (int) ($exhaustedUpdate['reset_monthly_at'] ?? $account->reset_monthly_at),
                    ], function ($t) use ($now) {
                        return $t > $now;
                    });
                    $cooldown = $windowPoints ? min($windowPoints) : $now + 5 * 3600;
                }
                $exhaustedUpdate['cooldown_until'] = $cooldown;

                CodingPlanAccount::where('id', $account->id)
                    ->where('status', '!=', CodingPlanAccount::STATUS_EXHAUSTED)
                    ->update($exhaustedUpdate);
                $account->status = CodingPlanAccount::STATUS_EXHAUSTED;
                $account->cooldown_until = $cooldown;
                Log::warning('CodingPlan account exhausted by upstream quota error', [
                    'account_id' => $account->id,
                    'vendor' => $account->vendor,
                    'cooldown_until' => $cooldown,
                ]);

                // P9-3：账号池全不可用时同步渠道级冷却（供调度与 failover 候选过滤）
                $this->refreshChannelCooldown((int) ($meta['channel_id'] ?? $account->channel_id), $now);
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
        $affectedChannelIds = [];

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
                // P9-3：窗口重置 = 配额恢复，账号级冷却一并清除
                'cooldown_until' => 0,
                'updated_at' => $now,
            ]);
            $a->cooldown_until = 0;
            if ((int) $a->channel_id > 0) {
                $affectedChannelIds[(int) $a->channel_id] = true;
            }
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
                // P9-3：窗口重置 = 配额恢复，账号级冷却一并清除
                'cooldown_until' => 0,
                'updated_at' => $now,
            ]);
            $a->cooldown_until = 0;
            if ((int) $a->channel_id > 0) {
                $affectedChannelIds[(int) $a->channel_id] = true;
            }
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
                // P9-3：窗口重置 = 配额恢复，账号级冷却一并清除
                'cooldown_until' => 0,
                'updated_at' => $now,
            ]);
            $a->cooldown_until = 0;
            if ((int) $a->channel_id > 0) {
                $affectedChannelIds[(int) $a->channel_id] = true;
            }
            $reset++;
        }

        // P9-3：受影响渠道的渠道级冷却随之刷新（池内恢复出可用账号 → 清冷却）
        foreach (array_keys($affectedChannelIds) as $channelId) {
            $this->refreshChannelCooldown($channelId, $now);
        }

        return $reset;
    }

    /**
     * P9-3：恢复冷却到点的账号。
     *
     * 上游 429 打标耗尽且带 cooldown_until 的账号——尤其 quota_* 全 0（未启用周期限制）
     * 的账号没有窗口恢复点，resetExpiredWindows 永远不会处理它们，只能在此恢复。
     * 由 pickAccount 前置调用（同供应商范围），也可全池调用。
     *
     * @return int 恢复的账号数
     */
    public function recoverExpiredCooldowns(?string $vendor = null, ?int $now = null): int
    {
        $now = $now ?? time();

        $query = CodingPlanAccount::query()
            ->where('cooldown_until', '>', 0)
            ->where('cooldown_until', '<=', $now)
            ->where('status', CodingPlanAccount::STATUS_EXHAUSTED);
        if ($vendor !== null) {
            $query->where('vendor', $vendor);
        }
        $accounts = $query->get();

        foreach ($accounts as $a) {
            CodingPlanAccount::where('id', $a->id)->update([
                'status' => CodingPlanAccount::STATUS_ENABLED,
                'cooldown_until' => 0,
                'updated_at' => $now,
            ]);
            $a->status = CodingPlanAccount::STATUS_ENABLED;
            $a->cooldown_until = 0;

            if ((int) $a->channel_id > 0) {
                $this->refreshChannelCooldown((int) $a->channel_id, $now);
            }
        }

        return count($accounts);
    }

    /**
     * P9-3：刷新渠道级冷却恢复点。
     *
     * 账号池内仍有可用账号 → 清渠道冷却（0）；
     * 全部不可用 → 取最早恢复点（各账号 cooldown_until / 窗口 reset_*_at 的未来点最小值；
     * 均无 → 保守默认 5h 窗口）。供 cost_first 调度（candidateChannels）与
     * failover 候选过滤读取，恢复到点自动切回低成本源；static 策略 pickChannel 不读此字段
     * （P9-1 承诺 SQL 原样零改动），由 relay 层 failover 兜底。
     *
     * @return int 渠道冷却恢复点（0=无冷却）
     */
    public function refreshChannelCooldown(int $channelId, int $now): int
    {
        if ($channelId <= 0) {
            return 0;
        }

        $accounts = CodingPlanAccount::query()
            ->where('channel_id', $channelId)
            ->where('status', '!=', CodingPlanAccount::STATUS_DISABLED)
            ->get();

        if ($accounts->isEmpty()) {
            // 非账号池渠道：不动 channels 字段
            return 0;
        }

        $available = $accounts->filter(function (CodingPlanAccount $a) {
            return $a->hasAvailableQuota();
        });

        if ($available->isNotEmpty()) {
            if ((int) Channel::where('id', $channelId)->value('cooldown_until') !== 0) {
                Channel::where('id', $channelId)->update(['cooldown_until' => 0]);
            }

            return 0;
        }

        $points = [];
        foreach ($accounts as $a) {
            if ((int) $a->cooldown_until > $now) {
                $points[] = (int) $a->cooldown_until;

                continue;
            }
            foreach ([(int) $a->reset_5h_at, (int) $a->reset_weekly_at, (int) $a->reset_monthly_at] as $t) {
                if ($t > $now) {
                    $points[] = $t;

                    break;
                }
            }
        }
        $until = $points ? min($points) : $now + 5 * 3600;

        Channel::where('id', $channelId)->update(['cooldown_until' => $until]);

        return $until;
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
