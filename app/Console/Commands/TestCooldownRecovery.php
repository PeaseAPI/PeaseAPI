<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Ability;
use App\Models\Channel;
use App\Models\CodingPlanAccount;
use App\Models\CodingPlanModelRatio;
use App\Models\CodingPlanVendor;
use App\Relay\Common\RelayInfo;
use App\Services\ChannelSelectService;
use App\Services\CodingPlanPoolService;
use App\Services\CodingPlanRatioService;
use App\Services\OptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 冷却恢复自检（P9-3：上游 429/配额超限 → 冷却恢复点 → 到点自动切回低成本源）。
 *
 * 覆盖：上游恢复点解析（Retry-After 秒数/HTTP 日期、JSON reset_* 字段秒/毫秒/日期、
 * 中英文案、无信息 → null）/ quota_exceeded 冷却写入（账号级 cooldown_until + 渠道级同步，
 * 无窗口信息保守默认 5h）/ pickAccount 跳过冷却账号 / recoverExpiredCooldowns 到点恢复 /
 * resetExpiredWindows 窗口恢复清冷却 / cost_first 调度过滤与恢复自动切回 /
 * static 策略 pickChannel SQL 原样（冷却不生效，由 relay 层 failover 兜底）。
 * 临时行在数据库事务内写入，结束后回滚并清缓存，零残留。
 *
 * 用法：php artisan coding-plan:test-cooldown-recovery
 */
class TestCooldownRecovery extends Command
{
    protected $signature = 'coding-plan:test-cooldown-recovery';

    protected $description = '冷却恢复自检：Retry-After/文案解析 + 账号/渠道 cooldown_until + 到点自动切回（事务内零残留）';

    /** 自检临时厂商（独特前缀防撞库内数据） */
    private const VENDOR = '__selftest_cd_v__';

    private const VENDOR_B = '__selftest_cd_w__';

    private const MODEL = 'selftest-cd-model';

    private const OPTION_KEY = 'ModelRouteStrategy';

    public function handle(): int
    {
        $pass = 0;
        $fail = 0;
        $check = function (string $name, bool $ok) use (&$pass, &$fail): void {
            $pass += $ok ? 1 : 0;
            $fail += $ok ? 0 : 1;
            $this->line(($ok ? '  <fg=green>✓</> ' : '  <fg=red>✗</> ').$name);
        };

        $pool = app(CodingPlanPoolService::class);
        $select = app(ChannelSelectService::class);
        $now = time();

        // B 段造数：独立渠道 + 账号（不挂 ability，不影响调度断言）
        $mk = function (string $suffix, array $accountExtra = []) use ($now): array {
            $ch = Channel::create([
                'type' => 1,
                'key' => 'sk-cd-b-'.$suffix,
                'name' => '__selftest_cd_b'.$suffix.'__',
                'status' => 1,
                'priority' => 1,
                'weight' => 0,
                'response_time' => 0,
                'group' => 'default',
                'models' => [self::MODEL],
                'created_time' => $now,
            ]);
            $acc = CodingPlanAccount::create(array_merge([
                'vendor' => self::VENDOR_B,
                'billing_mode' => CodingPlanAccount::BILLING_MODE_CREDIT,
                'account_name' => '__selftest_cd_b'.$suffix.'__',
                'api_key' => base64_encode('sk-cd-b-'.$suffix),
                'unit_exchange_rate' => 0,
                'status' => CodingPlanAccount::STATUS_ENABLED,
                'channel_id' => (int) $ch->id,
                'priority' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ], $accountExtra));

            return [$ch, $acc];
        };

        DB::beginTransaction();
        try {
            OptionService::set(self::OPTION_KEY, 'cost_first');
            $ids = $this->seed();

            $this->info('【A】上游恢复点解析（Retry-After / JSON / 文案）');
            $info = new RelayInfo;
            $info->responseStatus = 429;
            $info->responseHeaders = ['Retry-After' => '120'];
            $check('Retry-After 秒数 → now+120',
                abs(($info->resolveQuotaCooldown() ?? 0) - ($now + 120)) <= 2);

            $info = new RelayInfo;
            $info->responseStatus = 429;
            $info->responseHeaders = ['retry-after' => gmdate('D, d M Y H:i:s', $now + 3600).' GMT'];
            $check('Retry-After HTTP 日期（小写头名）→ now+3600',
                abs(($info->resolveQuotaCooldown() ?? 0) - ($now + 3600)) <= 5);

            $info = new RelayInfo;
            $info->responseStatus = 429;
            $info->responseBody = json_encode(['error' => ['reset_time' => $now + 600, 'message' => 'quota exceeded']]);
            $check('JSON error.reset_time（秒级）→ now+600',
                ($info->resolveQuotaCooldown() ?? 0) === $now + 600);

            $info = new RelayInfo;
            $info->responseStatus = 429;
            $info->responseBody = json_encode(['resetTime' => ($now + 600) * 1000]);
            $check('JSON resetTime 毫秒 → /1000 = now+600',
                ($info->resolveQuotaCooldown() ?? 0) === $now + 600);

            $info = new RelayInfo;
            $info->responseStatus = 429;
            $info->responseBody = json_encode(['resets_at' => date('Y-m-d H:i:s', $now + 1800)]);
            $check('JSON resets_at 日期字符串 → now+1800±2',
                abs(($info->resolveQuotaCooldown() ?? 0) - ($now + 1800)) <= 2);

            $info = new RelayInfo;
            $info->responseStatus = 429;
            $info->responseBody = 'Resets at '.date('Y-m-d H:i', $now + 900);
            $check('文案 Resets at 日期时间 → now+900±60',
                abs(($info->resolveQuotaCooldown() ?? 0) - ($now + 900)) <= 60);

            $info = new RelayInfo;
            $info->responseStatus = 429;
            $info->responseBody = '已达时间窗上限，重置于 '.date('H:i', $now + 600);
            $got = $info->resolveQuotaCooldown();
            $check('中文文案 重置于 HH:MM → ≈now+600（±120s）',
                $got !== null && abs($got - ($now + 600)) <= 120);

            $info = new RelayInfo;
            $info->responseStatus = 429;
            $info->responseBody = 'quota exhausted';
            $check('无任何恢复信息 → null（由池服务派生窗口/保守 5h）',
                $info->resolveQuotaCooldown() === null);

            $info = new RelayInfo;
            $info->responseStatus = 429;
            $info->responseHeaders = ['Retry-After' => '0'];
            $info->responseBody = 'quota exhausted';
            $check('Retry-After: 0（无效）→ 忽略 → null',
                $info->resolveQuotaCooldown() === null);

            $this->info('【B】quota_exceeded 冷却写入（账号级 + 渠道级）');
            // B1：传入上游解析值 → 精准冷却
            [$chB1, $accB1] = $mk('1');
            $pool->recordUsage($accB1, 0, [
                'channel_id' => (int) $chB1->id,
                'cooldown_until' => $now + 600,
            ], false, 'quota_exceeded');
            $accB1->refresh();
            $check('传入解析值 → 账号 EXHAUSTED + cooldown_until=now+600',
                $accB1->status === CodingPlanAccount::STATUS_EXHAUSTED
                && (int) $accB1->cooldown_until === $now + 600);
            $check('渠道级同步 = now+600（池全不可用）',
                (int) Channel::find($chB1->id)->cooldown_until === $now + 600);

            // B2：无窗口信息（quota 全 0）→ 保守默认 5h
            [$chB2, $accB2] = $mk('2');
            $pool->recordUsage($accB2, 0, ['channel_id' => (int) $chB2->id], false, 'quota_exceeded');
            $accB2->refresh();
            $check('无窗口信息（quota 全 0）→ 保守默认 now+5h',
                $accB2->status === CodingPlanAccount::STATUS_EXHAUSTED
                && abs((int) $accB2->cooldown_until - ($now + 18000)) <= 5);
            $check('渠道级同步 5h',
                abs((int) Channel::find($chB2->id)->cooldown_until - ($now + 18000)) <= 5);

            // B3：quota_5h>0 → 窗口初始化为恢复点
            [$chB3, $accB3] = $mk('3', ['quota_5h' => 100, 'used_5h' => 50]);
            $pool->recordUsage($accB3, 0, ['channel_id' => (int) $chB3->id], false, 'quota_exceeded');
            $accB3->refresh();
            $check('quota_5h>0 → reset_5h_at 初始化 now+5h 且 cooldown=min 窗口',
                abs((int) $accB3->reset_5h_at - ($now + 18000)) <= 5
                && abs((int) $accB3->cooldown_until - ($now + 18000)) <= 5);

            $this->info('【C】账号冷却与恢复（pickAccount / recoverExpiredCooldowns）');
            $check('冷却中 → pickAccount(VENDOR_B) 返回 null（池内唯一账号全冷却）',
                $pool->pickAccount(self::VENDOR_B) === null);

            // 模拟时间推进 18100s（> 5h）→ 冷却到点恢复
            $recovered = $pool->recoverExpiredCooldowns(self::VENDOR_B, $now + 18100);
            $accB1->refresh();
            $accB2->refresh();
            $accB3->refresh();
            $check('到点恢复（模拟 +18100s）→ 3 账号 ENABLED + cooldown 清 0',
                $recovered === 3
                && $accB1->status === CodingPlanAccount::STATUS_ENABLED
                && (int) $accB1->cooldown_until === 0
                && (int) $accB2->cooldown_until === 0);
            $check('渠道级随之清 0（池恢复可用）',
                (int) Channel::find($chB1->id)->cooldown_until === 0);
            $check('恢复后 pickAccount 可选回',
                $pool->pickAccount(self::VENDOR_B)?->id === $accB1->id);

            $this->info('【D】窗口恢复（resetExpiredWindows）清账号冷却');
            // 造窗口账号：已耗尽 + 窗口到期 + 带未来冷却（模拟「冷却与窗口并存」）
            [$chD, $accD] = $mk('d', [
                'quota_5h' => 10,
                'used_5h' => 10,
                'reset_5h_at' => $now - 10,
                'cooldown_until' => $now + 600,
            ]);
            CodingPlanAccount::where('id', $accD->id)->update([
                'status' => CodingPlanAccount::STATUS_EXHAUSTED,
                'updated_at' => $now,
            ]);
            $pool->resetExpiredWindows(self::VENDOR_B, $now);
            $accD->refresh();
            $check('窗口到期恢复 → ENABLED + used 清 0 + cooldown 清 0',
                $accD->status === CodingPlanAccount::STATUS_ENABLED
                && (int) $accD->used_5h === 0
                && (int) $accD->cooldown_until === 0);

            $this->info('【E】调度过滤与恢复自动切回（cost_first）');
            $check('初始 pickChannel → channel_co（成本最低）',
                (int) $select->pickChannel(self::MODEL)->id === $ids['channel_co']);

            // 主账号正常耗尽（success 用完最后一单位）
            $main = CodingPlanAccount::find($ids['account_main']);
            $main->quota_5h = 10;
            $main->used_5h = 10;
            $main->save();
            $pool->recordUsage($main, 1, ['channel_id' => (int) $ids['channel_co']], true);
            $main->refresh();
            $check('正常耗尽 → EXHAUSTED + 渠道级冷却（>now）',
                $main->status === CodingPlanAccount::STATUS_EXHAUSTED
                && (int) Channel::find($ids['channel_co'])->cooldown_until > $now);

            $check('冷却中 → candidateChannels 不含 channel_co',
                ! $select->candidateChannels(self::MODEL)->contains('id', $ids['channel_co']));
            $check('冷却中 → pickChannel 切到次选 channel_alt',
                (int) $select->pickChannel(self::MODEL)->id === $ids['channel_alt']);

            // 模拟窗口重置 + 冷却到点 → 自动切回低成本源
            CodingPlanAccount::where('id', $main->id)->update([
                'used_5h' => 0,
                'reset_5h_at' => $now + 18000,
                'cooldown_until' => $now - 5,
                'status' => CodingPlanAccount::STATUS_EXHAUSTED,
                'updated_at' => $now,
            ]);
            $pool->recoverExpiredCooldowns(self::VENDOR, $now);
            $check('冷却到点恢复 → 渠道级清 0（重新可用）',
                (int) Channel::find($ids['channel_co'])->cooldown_until === 0);
            $check('恢复后 pickChannel 自动切回低成本 channel_co',
                (int) $select->pickChannel(self::MODEL)->id === $ids['channel_co']);

            $this->info('【F】static 策略不受冷却影响（pickChannel SQL 原样）');
            // 把主账号重新打入耗尽 + 渠道冷却
            CodingPlanAccount::where('id', $main->id)->update([
                'used_5h' => 10,
                'status' => CodingPlanAccount::STATUS_ENABLED,
                'updated_at' => $now,
            ]);
            $pool->recordUsage(CodingPlanAccount::find($main->id), 1, ['channel_id' => (int) $ids['channel_co']], true);
            $check('渠道冷却中（channel_co cooldown>now）',
                (int) Channel::find($ids['channel_co'])->cooldown_until > $now);

            OptionService::set(self::OPTION_KEY, 'static');
            $check('static pickChannel 仍选到冷却渠道（SQL 未动，relay 层 failover 兜底）',
                (int) $select->pickChannel(self::MODEL)->id === $ids['channel_co']);
        } finally {
            DB::rollBack();
            app(CodingPlanRatioService::class)->flushCache();
            // flushCache 全量分支按 vendors/ratios 表枚举 vendor——自检行已回滚不在表内，
            // 其缓存键会被漏清并污染下一轮运行，必须显式 forget（P9-1 踩坑）
            foreach ([self::VENDOR, self::VENDOR_B] as $code) {
                Cache::forget('coding_plan_ratios:'.$code);
                Cache::forget('coding_plan_vendor:'.$code);
            }
            OptionService::set(self::OPTION_KEY, 'static');
        }

        $this->info('【G】零残留');
        $check('vendors 无自检行', ! CodingPlanVendor::where('code', 'like', '__selftest_cd_%')->exists());
        $check('accounts 无自检行', ! CodingPlanAccount::where('vendor', 'like', '__selftest_cd_%')->exists());
        $check('ratios 无自检行', ! CodingPlanModelRatio::where('vendor', 'like', '__selftest_cd_%')->exists());
        $check('channels/abilities 无自检行',
            ! Channel::where('name', 'like', '__selftest_cd_%')->exists()
            && ! Ability::where('model', self::MODEL)->exists());

        $this->info("通过 {$pass} / 失败 {$fail}");

        return $fail === 0 ? 0 : 1;
    }

    private function seed(): array
    {
        $now = time();

        foreach ([[self::VENDOR, '自检冷却厂商V'], [self::VENDOR_B, '自检冷却厂商W']] as [$code, $name]) {
            CodingPlanVendor::create([
                'code' => $code,
                'name' => $name,
                'billing_mode' => CodingPlanVendor::BILLING_MODE_CREDIT,
                'unit_exchange_rate' => 1.0,
                'currency' => 'CNY',
                'status' => 1,
                'sort' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $channelCo = Channel::create([
            'type' => 1,
            'key' => 'sk-cd-co',
            'name' => '__selftest_cd_channel_co__',
            'status' => 1,
            'priority' => 10,
            'weight' => 0,
            'response_time' => 0,
            'group' => 'default',
            'models' => [self::MODEL],
            'created_time' => $now,
        ]);
        $channelAlt = Channel::create([
            'type' => 1,
            'key' => 'sk-cd-alt',
            'name' => '__selftest_cd_channel_alt__',
            'status' => 1,
            'priority' => 5,
            'weight' => 0,
            'response_time' => 0,
            'group' => 'default',
            'models' => [self::MODEL],
            'created_time' => $now,
        ]);

        $accountMain = CodingPlanAccount::create([
            'vendor' => self::VENDOR,
            'billing_mode' => CodingPlanAccount::BILLING_MODE_CREDIT,
            'account_name' => '__selftest_cd_main__',
            'api_key' => base64_encode('sk-cd-main'),
            'unit_exchange_rate' => 0,
            'status' => CodingPlanAccount::STATUS_ENABLED,
            'channel_id' => (int) $channelCo->id,
            'priority' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([[self::VENDOR, 0.002, 0.006], [self::VENDOR_B, 0.002, 0.006]] as [$vendor, $input, $output]) {
            CodingPlanModelRatio::create([
                'vendor' => $vendor,
                'model' => self::MODEL,
                'match_type' => CodingPlanModelRatio::MATCH_EXACT,
                'cost_mode' => CodingPlanModelRatio::COST_PER_TOKEN_PARTS,
                'input_rate' => $input,
                'cached_rate' => 0.0,
                'output_rate' => $output,
                'status' => 1,
                'sort' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([[$channelCo->id, 10], [$channelAlt->id, 5]] as [$channelId, $priority]) {
            Ability::create([
                'group' => 'default',
                'model' => self::MODEL,
                'channel_id' => (int) $channelId,
                'enabled' => 1,
                'priority' => $priority,
            ]);
        }

        return [
            'channel_co' => (int) $channelCo->id,
            'channel_alt' => (int) $channelAlt->id,
            'account_main' => (int) $accountMain->id,
        ];
    }
}
