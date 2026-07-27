<?php

namespace App\Services;

use App\Models\User;
use App\Models\Log;

class BillingService
{
    public function deductQuota(User $user, int $amount, string $type = 'balance'): bool
    {
        if ($type === 'balance' && $user->balance < $amount) {
            return false;
        }

        if ($type === 'balance') {
            $user->decrement('balance', $amount);
        }

        return true;
    }

    public function addQuota(User $user, int $amount, string $source = 'top_up'): void
    {
        $user->increment('balance', $amount);
    }

    public function calculatePrice(string $model, int $promptTokens, int $completionTokens): int
    {
        $pricePerToken = config("pease-api.billing.prices.{$model}", 0);
        return (int)(($promptTokens + $completionTokens) * $pricePerToken);
    }

    public function getUserUsage(User $user, int $startTime = null, int $endTime = null): array
    {
        $query = Log::where('user_id', $user->id);
        if ($startTime) $query->where('created_at', '>=', $startTime);
        if ($endTime) $query->where('created_at', '<=', $endTime);

        $logs = $query->get();
        return [
            'total_requests' => $logs->count(),
            'total_tokens' => $logs->sum('prompt_tokens') + $logs->sum('completion_tokens'),
            'prompt_tokens' => $logs->sum('prompt_tokens'),
            'completion_tokens' => $logs->sum('completion_tokens'),
            'total_quota' => $logs->sum('quota'),
        ];
    }
}