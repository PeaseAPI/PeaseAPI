<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\PromotionReminderMail;
use App\Models\CodingPlanPromotion;
use App\Models\CodingPlanPromotionReminder;
use App\Services\OptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * P2-3：Coding Plan 厂商活动到期提醒（每日 09:00）。
 *
 * 扫描启用中且有截止时间（ends_at 非空）的活动：
 *  1. 即将到期（remaining <= remind_days×86400 且未过期）：
 *     - 站内公告：Option「Notice」追加活动段落（全局一次，流水 user_id=0 防重发）；
 *     - 邮件：订阅该厂商套餐的有效用户（subscriptions ⋈ subscription_plans.coding_vendor）
 *       各收到一封 PromotionReminderMail（队列发送，流水 user_id 防重发）。
 *  2. 已到期（ends_at < now）：status 自动置 2（EXPIRED，前台默认查询不再返回），
 *     并记 kind=expired 流水（仅流水留痕，不再通知）。
 *
 * 防重发依赖 coding_plan_promotion_reminders 的 unique(promotion_id, kind, user_id)，
 * insertOrIgnore 冲突即跳过；管理员删除流水可强制重发一次。
 */
class CodingPlanRemindPromotions extends Command
{
    protected $signature = 'coding-plan:remind-promotions {--dry-run : 只统计将发生的动作，不写入任何数据}';

    protected $description = '扫描 Coding Plan 厂商活动：即将到期的发站内公告+邮件提醒，已到期的自动置过期并记流水';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = time();

        $promotions = CodingPlanPromotion::query()
            ->enabled()
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', 0)
            ->orderBy('ends_at')
            ->get();

        $stats = ['checked' => $promotions->count(), 'ending' => 0, 'announced' => 0, 'mailed' => 0, 'expired' => 0];

        foreach ($promotions as $promotion) {
            if ((int) $promotion->ends_at <= $now) {
                // —— 已到期：置过期 + 记流水（幂等）——
                if ($dryRun) {
                    $stats['expired']++;
                    $this->line("expired(dry): [{$promotion->vendor}] {$promotion->title}");

                    continue;
                }
                $expired = CodingPlanPromotion::query()
                    ->where('id', $promotion->id)
                    ->where('status', CodingPlanPromotion::STATUS_ENABLED)
                    ->update(['status' => CodingPlanPromotion::STATUS_EXPIRED, 'updated_at' => $now]);
                if ($expired) {
                    DB::table('coding_plan_promotion_reminders')->insertOrIgnore([
                        'promotion_id' => $promotion->id,
                        'kind' => CodingPlanPromotionReminder::KIND_EXPIRED,
                        'user_id' => CodingPlanPromotionReminder::AUDIENCE_GLOBAL,
                        'created_at' => $now,
                    ]);
                    $stats['expired']++;
                    $this->line("expired: [{$promotion->vendor}] {$promotion->title}");
                }

                continue;
            }

            $remaining = (int) $promotion->ends_at - $now;
            if ($remaining > max(0, (int) $promotion->remind_days) * 86400) {
                continue; // 未进入提醒窗口
            }
            $stats['ending']++;
            $remainingDays = (int) ceil($remaining / 86400);

            if ($dryRun) {
                $subscribers = $this->subscriberEmails($promotion->vendor);
                $this->line("ending(dry): [{$promotion->vendor}] {$promotion->title} 剩余 {$remainingDays} 天，公告 1 + 邮件 ".count($subscribers));

                continue;
            }

            // —— 1) 站内公告（全局一份）——
            $announced = DB::table('coding_plan_promotion_reminders')->insertOrIgnore([
                'promotion_id' => $promotion->id,
                'kind' => CodingPlanPromotionReminder::KIND_ENDING,
                'user_id' => CodingPlanPromotionReminder::AUDIENCE_GLOBAL,
                'created_at' => $now,
            ]);
            if ($announced) {
                $this->appendNotice($promotion, $remainingDays);
                $stats['announced']++;
            }

            // —— 2) 订阅该厂商套餐的用户邮件（每人一次）——
            foreach ($this->subscriberEmails($promotion->vendor) as $userId => $email) {
                $sent = DB::table('coding_plan_promotion_reminders')->insertOrIgnore([
                    'promotion_id' => $promotion->id,
                    'kind' => CodingPlanPromotionReminder::KIND_ENDING,
                    'user_id' => $userId,
                    'created_at' => $now,
                ]);
                if ($sent) {
                    Mail::to($email)->queue(new PromotionReminderMail($promotion, $remainingDays));
                    $stats['mailed']++;
                }
            }

            $this->line("ending: [{$promotion->vendor}] {$promotion->title} 剩余 {$remainingDays} 天，公告 {$announced}，邮件已入队");
        }

        $this->info(sprintf(
            '活动提醒完成：检查 %d 条，进入提醒窗口 %d 条（公告 %d，邮件 %d），置过期 %d%s',
            $stats['checked'], $stats['ending'], $stats['announced'], $stats['mailed'], $stats['expired'],
            $dryRun ? '（dry-run，未写入）' : ''
        ));

        return self::SUCCESS;
    }

    /**
     * 订阅指定厂商套餐的有效用户（user_id => email）。
     * 有效口径与 Subscription::isActive 一致：status=1 且 period_end >= now。
     *
     * @return array<int, string>
     */
    private function subscriberEmails(string $vendor): array
    {
        $now = time();

        return DB::table('subscriptions')
            ->join('subscription_plans', 'subscription_plans.id', '=', 'subscriptions.plan_id')
            ->join('users', 'users.id', '=', 'subscriptions.user_id')
            ->where('subscription_plans.coding_vendor', $vendor)
            ->where('subscriptions.status', 1)
            ->where('subscriptions.period_end', '>=', $now)
            ->whereNotNull('users.email')
            ->where('users.email', '!=', '')
            ->distinct()
            ->pluck('users.email', 'users.id')
            ->all();
    }

    /** 站内公告：Option「Notice」追加活动段落（用户端 GET Notice 读取） */
    private function appendNotice(CodingPlanPromotion $promotion, int $remainingDays): void
    {
        $section = sprintf(
            "\n\n### 活动提醒：%s\n\n%s\n\n- 厂商：%s（%s）\n- 截止：%s（约 %d 天后）\n%s",
            $promotion->title,
            (string) $promotion->description,
            $promotion->vendor,
            $promotion->kind,
            date('Y-m-d H:i', (int) $promotion->ends_at),
            $remainingDays,
            $promotion->source_url ? "- 官方说明：{$promotion->source_url}" : ''
        );

        $notice = (string) OptionService::get('Notice', '');
        OptionService::set('Notice', $notice.$section);
    }
}
