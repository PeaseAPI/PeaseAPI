<?php

declare(strict_types=1);

namespace App\Services;

use App\Relay\Common\RelayInfo;
use Illuminate\Support\Facades\DB;

/**
 * Relay 计费服务 - 转发成功后的计费扣除
 *
 * 对标 new-api service.PostConsumeQuota：
 * - 计算 quota（TextQuotaService 倍率体系，ModelPrice 固定价优先）
 * - 原子扣减用户/Token 额度（SQL 侧增量更新，允许扣至负数——上游已成功）
 * - 累计用户请求数、Token 用量、渠道用量
 */
class BillingService
{
    public function __construct(private TextQuotaService $rates) {}

    /**
     * 后计费：计算费用并扣减，返回消费 quota（同时回填 $info 计费上下文）
     */
    public function postConsume(RelayInfo $info): int
    {
        if ($info->userId <= 0) {
            return 0;
        }

        $model = $info->modelName !== '' ? $info->modelName : $info->requestModel;
        if ($model === '') {
            return 0;
        }

        // 分组取值：Token 组优先，其次用户组（对标 new-api）
        $group = $info->tokenGroup !== ''
            ? $info->tokenGroup
            : ($info->userGroup !== '' ? $info->userGroup : 'default');

        $quota = $this->rates->calculateTotalCostWithCache(
            $info->promptTokens,
            $info->cachedTokens,
            $info->completionTokens,
            $model,
            $group
        );

        // 回填计费上下文（消费日志 other 使用）
        $info->quota = $quota;
        $info->modelRatio = $this->rates->getModelRatio($model);
        $info->completionRatio = $this->rates->getCompletionRatio($model);
        $info->groupRatio = $this->rates->getGroupRatio($group);
        $info->cacheRatio = $this->rates->getCacheRatio($model);

        // 用户额度：无条件原子扣减（可至负数），请求数 +1
        DB::table('users')->where('id', $info->userId)->update([
            'quota' => DB::raw('quota - '.$quota),
            'used_quota' => DB::raw('used_quota + '.$quota),
            'request_count' => DB::raw('request_count + 1'),
        ]);

        // Token 额度：受限 Token 扣 remain；无限 Token 只累计 used
        if ($info->tokenId > 0) {
            $updates = ['used_quota' => DB::raw('used_quota + '.$quota)];
            if (! $info->tokenUnlimited) {
                $updates['remain_quota'] = DB::raw('remain_quota - '.$quota);
            }
            DB::table('tokens')->where('id', $info->tokenId)->update($updates);
        }

        // 渠道用量累计
        if ($info->channelId > 0) {
            DB::table('channels')->where('id', $info->channelId)->update([
                'used_quota' => DB::raw('used_quota + '.$quota),
            ]);
        }

        return $quota;
    }

    /**
     * 请求前预扣额度（对标 new-api preConsumeQuota）
     *
     * - 预扣量 = PreConsumedQuota 选项（默认 500；<=0 关闭预扣）
     * - 可用余额 = min(用户 quota, 受限 Token remain_quota)
     * - 原子条件扣减（WHERE quota >= pre）防并发超扣；Token 扣减失败时回滚用户部分
     *
     * 余额不足返回 OpenAI 风格错误数组（调用方返回 429），成功返回 null 并写入 $info->preConsumedQuota。
     *
     * @return array{message: string, type: string, code: string, param: null}|null
     */
    public function preConsume(RelayInfo $info): ?array
    {
        $pre = (int) OptionService::get('PreConsumedQuota', 500);
        if ($pre <= 0 || $info->userId <= 0) {
            return null;
        }

        $userRow = DB::table('users')->where('id', $info->userId)->first(['quota']);
        if ($userRow === null) {
            return null;
        }

        $tokenRemain = null;
        if ($info->tokenId > 0 && ! $info->tokenUnlimited) {
            $tokenRow = DB::table('tokens')->where('id', $info->tokenId)->first(['remain_quota']);
            $tokenRemain = $tokenRow !== null ? (int) $tokenRow->remain_quota : 0;
        }

        $available = (int) $userRow->quota;
        if ($tokenRemain !== null) {
            $available = min($available, $tokenRemain);
        }

        if ($available < $pre) {
            return $this->insufficientBalanceError($pre, $available);
        }

        $deducted = DB::table('users')
            ->where('id', $info->userId)
            ->where('quota', '>=', $pre)
            ->decrement('quota', $pre);
        if ((int) $deducted === 0) {
            // 并发扣减后余额已不足
            return $this->insufficientBalanceError($pre, $available);
        }

        if ($tokenRemain !== null) {
            $tokenDeducted = DB::table('tokens')
                ->where('id', $info->tokenId)
                ->where('remain_quota', '>=', $pre)
                ->decrement('remain_quota', $pre);
            if ((int) $tokenDeducted === 0) {
                // Token 侧失败：回滚用户预扣
                DB::table('users')->where('id', $info->userId)->increment('quota', $pre);

                return $this->insufficientBalanceError($pre, $available);
            }
        }

        $info->preConsumedQuota = $pre;

        return null;
    }

    /**
     * 退回预扣额度（请求失败退款 / 成功结算后冲销；净扣费 = 实际计费）
     */
    public function refundPreConsumed(RelayInfo $info): void
    {
        $pre = $info->preConsumedQuota;
        if ($pre <= 0) {
            return;
        }
        $info->preConsumedQuota = 0;

        DB::table('users')->where('id', $info->userId)->increment('quota', $pre);
        if ($info->tokenId > 0 && ! $info->tokenUnlimited) {
            DB::table('tokens')->where('id', $info->tokenId)->increment('remain_quota', $pre);
        }
    }

    /**
     * 余额不足错误（OpenAI 风格；429 Too Many Requests）
     *
     * @return array{message: string, type: string, code: string, param: null}
     */
    private function insufficientBalanceError(int $pre, int $available): array
    {
        return [
            'message' => 'Insufficient balance: pre-consumed quota '.$pre.' required, available '.$available.'. Please top up.',
            'type' => 'insufficient_quota',
            'code' => 'insufficient_balance',
            'param' => null,
        ];
    }
}
