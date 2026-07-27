<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Token;
use App\Models\Log;

/**
 * 配额管理服务 - 用户配额扣减、退还
 */
class QuotaService
{
    /**
     * 扣减用户配额
     */
    public function deductUserQuota(User $user, int $amount): bool
    {
        if ($user->quota < $amount) {
            return false;
        }
        
        $user->decrement('quota', $amount);
        $user->increment('used_quota', $amount);
        
        return true;
    }

    /**
     * 退还用户配额
     */
    public function refundUserQuota(User $user, int $amount): bool
    {
        $user->increment('quota', $amount);
        $user->decrement('used_quota', $amount);
        
        return true;
    }

    /**
     * 扣减Token配额
     */
    public function deductTokenQuota(Token $token, int $amount): bool
    {
        if ($token->unlimited_quota) {
            return true;
        }
        
        if ($token->remain_quota < $amount && !$token->unlimited_quota) {
            return false;
        }
        
        $token->decrement('remain_quota', $amount);
        $token->increment('used_quota', $amount);
        
        return true;
    }

    /**
     * 退还Token配额
     */
    public function refundTokenQuota(Token $token, int $amount): bool
    {
        if ($token->unlimited_quota) {
            return true;
        }
        
        $token->increment('remain_quota', $amount);
        $token->decrement('used_quota', $amount);
        
        return true;
    }

    /**
     * 检查用户配额是否足够
     */
    public function hasEnoughUserQuota(User $user, int $amount): bool
    {
        return $user->quota >= $amount;
    }

    /**
     * 检查Token配额是否足够
     */
    public function hasEnoughTokenQuota(Token $token, int $amount): bool
    {
        if ($token->unlimited_quota) {
            return true;
        }
        
        return $token->remain_quota >= $amount;
    }

    /**
     * 获取用户可用配额
     */
    public function getUserAvailableQuota(User $user): int
    {
        return $user->quota;
    }

    /**
     * 获取Token可用配额
     */
    public function getTokenAvailableQuota(Token $token): int
    {
        if ($token->unlimited_quota) {
            return PHP_INT_MAX;
        }
        
        return $token->remain_quota;
    }

    /**
     * 重置用户配额（订阅重置）
     */
    public function resetUserQuota(User $user, int $amount): bool
    {
        $user->update(['quota' => $amount]);
        return true;
    }

    /**
     * 添加配额（充值/兑换）
     */
    public function addQuota(User $user, int $amount, string $source = 'top_up'): bool
    {
        $user->increment('quota', $amount);
        
        // 记录充值日志
        Log::create([
            'user_id' => $user->id,
            'type' => $source === 'top_up' ? 1 : 2, // 1=充值, 2=兑换
            'content' => "{$source}: +{$amount}",
            'quota' => $amount,
            'created_time' => time(),
        ]);
        
        return true;
    }

    /**
     * 批量检查多个Token配额
     */
    public function batchCheckTokenQuota(array $tokens, int $amount): array
    {
        $results = [];
        
        foreach ($tokens as $token) {
            $results[$token->id] = $this->hasEnoughTokenQuota($token, $amount);
        }
        
        return $results;
    }

    /**
     * 获取用户配额使用统计
     */
    public function getUserQuotaStats(User $user): array
    {
        return [
            'total' => $user->quota + $user->used_quota,
            'available' => $user->quota,
            'used' => $user->used_quota,
            'usage_percent' => ($user->quota + $user->used_quota) > 0 
                ? round($user->used_quota / ($user->quota + $user->used_quota) * 100, 2)
                : 0,
        ];
    }
}