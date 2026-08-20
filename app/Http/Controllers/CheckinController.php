<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Checkin;
use App\Services\QuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 签到控制器
 *
 * 提供每日签到、连续签到加成、签到状态查询。
 * 依赖 options 表配置:
 *  - CheckinEnabled (bool)
 *  - CheckinQuota (int, 基础奖励)
 *  - CheckinStreakEnabled (bool)
 *  - CheckinStreakRules (JSON: [{"days":7,"quota":500},...])
 */
class CheckinController extends Controller
{
    public function __construct(
        private readonly QuotaService $quotaService,
    ) {}

    /**
     * 获取签到状态
     * GET /api/user/checkin
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        $enabled = $this->isCheckinEnabled();
        $todayStart = Carbon::today()->getTimestamp();
        $tomorrowStart = Carbon::tomorrow()->getTimestamp();

        $todayCheckin = $this->findCheckinInRange($user->id, $todayStart, $tomorrowStart);
        $streak = $this->calcStreak($user->id);

        $weekStart = Carbon::today()->subDays(6)->startOfDay()->getTimestamp();
        $recent = Checkin::where('user_id', $user->id)
            ->where('created_at', '>=', $weekStart)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (Checkin $c) => [
                'date' => Carbon::createFromTimestamp((int) $c->created_at)->toDateString(),
                'quota' => (int) $c->quota,
                'streak' => (int) $c->day,
            ])
            ->values();

        $baseQuota = $this->getBaseQuota();
        $todayBonus = $this->getStreakBonus($streak + ($todayCheckin ? 0 : 1));
        $tomorrowBonus = $this->getStreakBonus($streak + 1);

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => $enabled,
                'can_checkin' => $enabled && $todayCheckin === null,
                'streak' => $streak,
                'today' => [
                    'quota' => $baseQuota + $todayBonus,
                    'base' => $baseQuota,
                    'bonus' => $todayBonus,
                    'already_checked_in' => $todayCheckin !== null,
                ],
                'tomorrow_preview' => [
                    'quota' => $baseQuota + $tomorrowBonus,
                    'base' => $baseQuota,
                    'bonus' => $tomorrowBonus,
                    'streak' => $streak + 1,
                ],
                'recent' => $recent,
                'last_checkin' => $todayCheckin
                    ? Carbon::createFromTimestamp((int) $todayCheckin->created_at)->toIso8601String()
                    : null,
            ],
        ]);
    }

    /**
     * 执行签到
     * POST /api/user/checkin
     */
    public function checkin(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->isCheckinEnabled()) {
            return $this->error('签到功能未启用', 403);
        }

        $todayStart = Carbon::today()->getTimestamp();
        $tomorrowStart = Carbon::tomorrow()->getTimestamp();

        // 事务内做防重 + 计算 + 写入，避免并发重复签到
        try {
            $result = DB::transaction(function () use ($user, $todayStart, $tomorrowStart) {
                // 行锁用户，确保串行化（lockForUpdate 本身即加锁，无需赋值）
                DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

                $exists = Checkin::where('user_id', $user->id)
                    ->where('created_at', '>=', $todayStart)
                    ->where('created_at', '<', $tomorrowStart)
                    ->exists();

                if ($exists) {
                    return ['conflict' => true];
                }

                $yesterdayStart = Carbon::yesterday()->startOfDay()->getTimestamp();
                $hadYesterday = Checkin::where('user_id', $user->id)
                    ->where('created_at', '>=', $yesterdayStart)
                    ->where('created_at', '<', $todayStart)
                    ->exists();

                $newStreak = $hadYesterday ? ($this->calcStreak($user->id) + 1) : 1;

                $baseQuota = $this->getBaseQuota();
                $bonusQuota = $this->getStreakBonus($newStreak);
                $totalQuota = $baseQuota + $bonusQuota;

                Checkin::create([
                    'user_id' => $user->id,
                    'day' => $newStreak,
                    'quota' => $totalQuota,
                    'created_at' => $todayStart,
                ]);

                return [
                    'conflict' => false,
                    'streak' => $newStreak,
                    'base' => $baseQuota,
                    'bonus' => $bonusQuota,
                    'total' => $totalQuota,
                ];
            });
        } catch (Throwable $e) {
            Log::error('Checkin failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return $this->error('签到失败，请稍后重试', 500);
        }

        if ($result['conflict']) {
            return $this->error('今日已签到', 409);
        }

        // 事务外调用 addQuota（其内部会写日志并 increment）
        $this->quotaService->addQuota($user, $result['total'], 'checkin');
        $user->refresh();

        return response()->json([
            'success' => true,
            'message' => __('Check-in successful'),
            'data' => [
                'quota' => $result['total'],
                'base' => $result['base'],
                'bonus' => $result['bonus'],
                'streak' => $result['streak'],
                'total_quota' => (int) $user->quota,
            ],
        ]);
    }

    /**
     * 查询某时间区间内的签到记录
     */
    private function findCheckinInRange(int $userId, int $start, int $end): ?Checkin
    {
        return Checkin::where('user_id', $userId)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->first();
    }

    /**
     * 计算当前连续签到天数
     * 若今日已签到，则计入今天；若今日未签到，则从昨天开始倒推。
     */
    private function calcStreak(int $userId): int
    {
        $today = Carbon::today();
        $streak = 0;

        for ($i = 0; $i < 365; $i++) {
            $dayStart = $today->copy()->subDays($i)->startOfDay()->getTimestamp();
            $dayEnd = $dayStart + 86400;

            $checked = Checkin::where('user_id', $userId)
                ->where('created_at', '>=', $dayStart)
                ->where('created_at', '<', $dayEnd)
                ->exists();

            if ($checked) {
                $streak++;

                continue;
            }

            // 今日未签时跳过，不中断连续（从昨日继续倒推）
            if ($i === 0) {
                continue;
            }
            break;
        }

        return $streak;
    }

    private function isCheckinEnabled(): bool
    {
        $val = function_exists('getOption') ? getOption('CheckinEnabled', false) : false;

        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    private function getBaseQuota(): int
    {
        $val = function_exists('getOption') ? getOption('CheckinQuota', 100) : 100;

        return (int) $val;
    }

    private function isStreakEnabled(): bool
    {
        $val = function_exists('getOption') ? getOption('CheckinStreakEnabled', false) : false;

        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 连续签到加成
     *
     * 规则由 CheckinStreakRules(JSON) 配置，每条规则含 days 与 quota。
     * 命中最大满足的 days 即取其 quota 作为加成。
     */
    private function getStreakBonus(int $streak): int
    {
        if (! $this->isStreakEnabled() || $streak <= 1) {
            return 0;
        }

        $rulesJson = function_exists('getOption') ? getOption('CheckinStreakRules', '[]') : '[]';
        $rules = json_decode((string) $rulesJson, true);
        if (! is_array($rules)) {
            return 0;
        }

        $bonus = 0;
        foreach ($rules as $rule) {
            $days = (int) ($rule['days'] ?? 0);
            $quota = (int) ($rule['quota'] ?? 0);
            if ($days > 0 && $streak >= $days && $quota > $bonus) {
                $bonus = $quota;
            }
        }

        return $bonus;
    }
}
