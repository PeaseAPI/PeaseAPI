<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Ability;
use App\Models\Channel;
use App\Models\CodingPlanAccount;
use App\Models\CodingPlanModelRatio;
use App\Models\CodingPlanUsageLog;
use App\Models\CodingPlanVendor;
use App\Relay\Common\RelayHandler;
use App\Relay\Common\RelayInfo;
use App\Services\CodingPlanRatioService;
use App\Services\OptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;

/**
 * 跨源 failover 自检（P9-2：首选源账号池耗尽 → 自动切换次选源渠道）。
 *
 * 覆盖：池健康路径零变化 / 池耗尽 cost_first 切换（routeDecision 结构、
 * model_mapping 重映射与 isModelMapped 重置）/ 多候选落选成本留痕 /
 * 路由决策写入 coding_plan_usage_logs.meta.route / 全部候选失败抛原始异常 /
 * static 策略序 failover。临时行在数据库事务内写入，结束后回滚并清缓存，零残留。
 *
 * 用法：php artisan coding-plan:test-cost-failover
 */
class TestCostFailover extends Command
{
    protected $signature = 'coding-plan:test-cost-failover';

    protected $description = '跨源 failover 自检：池耗尽自动切次选源 + 路由决策落用量日志（事务内零残留）';

    /** 自检临时厂商（独特前缀防撞库内数据） */
    private const VENDOR_A = '__selftest_fo_a__';

    private const VENDOR_B = '__selftest_fo_b__';

    private const MODEL = 'selftest-failover-model';

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

        // establishUpstreamChannel 是 protected：反射调用（P9-2 新增的 failover 入口）
        $establish = function (RelayInfo $info): RelayHandler {
            $handler = new RelayHandler($info);
            $method = new ReflectionMethod(RelayHandler::class, 'establishUpstreamChannel');
            $method->setAccessible(true);
            $method->invoke($handler);

            return $handler;
        };

        // 每段用全新 RelayInfo：渠道凭证/账号/路由决策互不残留
        $newInfo = function (Channel $channel): RelayInfo {
            $info = new RelayInfo;
            $info->userId = 0; // 无用户：CodingPlanRequireSubscription 默认 false，订阅校验放行
            $info->tokenGroup = 'default';
            $info->startTime = microtime(true);
            $info->requestId = uniqid('selftest_fo_', true);
            $info->setChannel($channel);
            $info->setModel(self::MODEL);

            return $info;
        };

        DB::beginTransaction();
        try {
            OptionService::set(self::OPTION_KEY, 'cost_first');
            $ids = $this->seed();

            $channelA = Channel::find($ids['channel_a']);
            $channelB = Channel::find($ids['channel_b']);
            $channelC = Channel::find($ids['channel_c']);

            $this->info('【A】池健康路径零变化');
            $info = $newInfo($channelA);
            $establish($info);
            $check('池健康：仍选首选源渠道 a', (int) $info->channelId === $ids['channel_a']);
            $check('无路由决策残留（正常路径零开销）', $info->routeDecision === []);
            $check('账号池凭证覆盖生效', $info->apiKey === 'sk-fo-a');
            $check('model_mapping 重映射生效（mA）',
                $info->upstreamModelName === 'mA-mapped' && $info->isModelMapped);

            $this->info('【B】池耗尽 → cost_first 切换次选源');
            CodingPlanAccount::where('id', $ids['account_a'])->update([
                'status' => CodingPlanAccount::STATUS_EXHAUSTED,
            ]);
            $info = $newInfo($channelA);
            $establish($info);
            $rd = $info->routeDecision;
            $check('自动切换到成本次位渠道 b', (int) $info->channelId === $ids['channel_b']);
            $check('reason=cost_failover', ($rd['reason'] ?? '') === 'cost_failover');
            $check('strategy=cost_first', ($rd['strategy'] ?? '') === 'cost_first');
            $check('failed=channel_a / final=channel_b',
                ($rd['failed_channel_id'] ?? 0) === $ids['channel_a']
                && ($rd['final_channel_id'] ?? 0) === $ids['channel_b']);
            $check('attempted[0] = channel_a（pool_exhausted）',
                ($rd['attempted'][0]['channel_id'] ?? 0) === $ids['channel_a']
                && ($rd['attempted'][0]['reason'] ?? '') === 'pool_exhausted');
            $check('次选渠道账号池凭证覆盖生效', $info->apiKey === 'sk-fo-b' && $info->codingVendor === self::VENDOR_B);
            $check('换渠道后 model_mapping 重映射（b 无映射 → 原名且 isModelMapped 重置）',
                $info->upstreamModelName === self::MODEL && ! $info->isModelMapped);

            $this->info('【C】多候选落选：落选记录携带成本');
            CodingPlanAccount::where('id', $ids['account_b'])->update([
                'status' => CodingPlanAccount::STATUS_EXHAUSTED,
            ]);
            $info = $newInfo($channelA);
            $establish($info);
            $rd = $info->routeDecision;
            $check('a/b 均耗尽 → 切换到普通 API 渠道 c（跨源兜底归宿）',
                (int) $info->channelId === $ids['channel_c'] && $info->codingVendor === '');
            $check('attempted[1] = channel_b（pool_exhausted + cost_per_1k=0.015）',
                ($rd['attempted'][1]['channel_id'] ?? 0) === $ids['channel_b']
                && ($rd['attempted'][1]['reason'] ?? '') === 'pool_exhausted'
                && abs(($rd['attempted'][1]['cost_per_1k'] ?? 0) - 0.015) < 1e-9);
            $check('普通渠道走渠道自身凭证', $info->apiKey === 'sk-plain');

            $this->info('【D】路由决策写入用量日志');
            CodingPlanAccount::where('id', $ids['account_b'])->update([
                'status' => CodingPlanAccount::STATUS_ENABLED,
            ]);
            $info = $newInfo($channelA);
            $info->promptTokens = 100;
            $info->completionTokens = 50;
            $establish($info);
            $check('b 恢复后 failover 命中 b（成本序 b < c 垫底）', (int) $info->channelId === $ids['channel_b']);
            $info->recordCodingPlanUsage(true);
            $log = CodingPlanUsageLog::where('account_id', $ids['account_b'])->orderByDesc('id')->first();
            $route = $log?->meta['route'] ?? null;
            $check('coding_plan_usage_logs.meta.route 落库（reason/final/attempted）',
                is_array($route)
                && ($route['reason'] ?? '') === 'cost_failover'
                && ($route['final_channel_id'] ?? 0) === $ids['channel_b']
                && ($route['attempted'][0]['channel_id'] ?? 0) === $ids['channel_a']);

            $this->info('【E】全部候选失败抛原始异常');
            CodingPlanAccount::where('id', $ids['account_b'])->update([
                'status' => CodingPlanAccount::STATUS_EXHAUSTED,
            ]);
            Ability::where('channel_id', $ids['channel_c'])->update(['enabled' => 0]);
            $info = $newInfo($channelA);
            $threw = false;
            $message = '';
            try {
                $establish($info);
            } catch (RuntimeException $e) {
                $threw = true;
                $message = $e->getMessage();
            }
            $check('无可用候选 → 抛原始池耗尽异常（外层退款路径不变）',
                $threw && str_contains($message, 'account pool exhausted'));

            $this->info('【F】static 策略序 failover');
            OptionService::set(self::OPTION_KEY, 'static');
            Ability::where('channel_id', $ids['channel_c'])->update(['enabled' => 1]);
            CodingPlanAccount::where('id', $ids['account_b'])->update([
                'status' => CodingPlanAccount::STATUS_ENABLED,
            ]);
            $info = $newInfo($channelA);
            $establish($info);
            $check('static：池耗尽后按 priority 序切到 b（priority 5 > c 3）',
                (int) $info->channelId === $ids['channel_b']
                && ($info->routeDecision['strategy'] ?? '') === 'static');
        } finally {
            DB::rollBack();
            app(CodingPlanRatioService::class)->flushCache();
            // flushCache 全量分支按 vendors/ratios 表枚举 vendor——自检行已回滚不在表内，
            // 其缓存键会被漏清并污染下一轮运行，必须显式 forget（P9-1 踩坑）
            foreach ([self::VENDOR_A, self::VENDOR_B] as $code) {
                Cache::forget('coding_plan_ratios:'.$code);
                Cache::forget('coding_plan_vendor:'.$code);
            }
            OptionService::set(self::OPTION_KEY, 'static');
        }

        $this->info('【G】零残留');
        $check('vendors 无自检行', ! CodingPlanVendor::where('code', 'like', '__selftest_fo_%')->exists());
        $check('accounts 无自检行', ! CodingPlanAccount::where('vendor', 'like', '__selftest_fo_%')->exists());
        $check('ratios 无自检行', ! CodingPlanModelRatio::where('vendor', 'like', '__selftest_fo_%')->exists());
        $check('channels/abilities 无自检行',
            ! Channel::where('name', 'like', '__selftest_fo_%')->exists()
            && ! Ability::where('model', self::MODEL)->exists());
        $check('usage_logs 无自检行', ! CodingPlanUsageLog::where('model', self::MODEL)->exists());
        $check('自检 vendor 缓存键无残留',
            Cache::get('coding_plan_ratios:'.self::VENDOR_A) === null
            && Cache::get('coding_plan_ratios:'.self::VENDOR_B) === null);

        $this->newLine();
        $this->info("通过 {$pass} / 失败 {$fail}");

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 事务内造数：a/b 两个 Coding Plan 池渠道 + c 普通 API 渠道。
     *
     * 成本（per_token_parts 探针 0.75in+0.25out）：a=0.003 < b=0.015 < c（无池，垫底）；
     * priority：a=10 > b=5 > c=3；channel_a 带 model_mapping（验证换渠道重映射）。
     */
    private function seed(): array
    {
        $now = time();

        foreach ([
            [self::VENDOR_A, '自检池厂商A'],
            [self::VENDOR_B, '自检池厂商B'],
        ] as [$code, $name]) {
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

        $channelA = Channel::create([
            'type' => 1,
            'key' => 'sk-channel-a',
            'name' => '__selftest_fo_channel_a__',
            'status' => 1,
            'priority' => 10,
            'weight' => 0,
            'response_time' => 0,
            'group' => 'default',
            'models' => [self::MODEL],
            'model_mapping' => json_encode([self::MODEL => 'mA-mapped']),
            'created_time' => $now,
        ]);
        $channelB = Channel::create([
            'type' => 1,
            'key' => 'sk-channel-b',
            'name' => '__selftest_fo_channel_b__',
            'status' => 1,
            'priority' => 5,
            'weight' => 0,
            'response_time' => 0,
            'group' => 'default',
            'models' => [self::MODEL],
            'created_time' => $now,
        ]);
        $channelC = Channel::create([
            'type' => 1,
            'key' => 'sk-plain',
            'name' => '__selftest_fo_channel_c__',
            'status' => 1,
            'priority' => 3,
            'weight' => 0,
            'response_time' => 0,
            'group' => 'default',
            'models' => [self::MODEL],
            'created_time' => $now,
        ]);

        foreach ([
            [self::VENDOR_A, 'sk-fo-a', $channelA->id, 10],
            [self::VENDOR_B, 'sk-fo-b', $channelB->id, 5],
        ] as [$vendor, $plain, $channelId, $priority]) {
            CodingPlanAccount::create([
                'vendor' => $vendor,
                'billing_mode' => CodingPlanAccount::BILLING_MODE_CREDIT,
                'account_name' => '__selftest_fo_'.strtolower(substr($vendor, -1)).'__',
                'api_key' => base64_encode($plain),
                'unit_exchange_rate' => 0, // 0 = 跟随供应商默认（1.0）
                'status' => CodingPlanAccount::STATUS_ENABLED,
                'channel_id' => (int) $channelId,
                'priority' => $priority,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([
            [self::VENDOR_A, 0.002, 0.006], // 探针 = 0.003
            [self::VENDOR_B, 0.010, 0.030], // 探针 = 0.015
        ] as [$vendor, $input, $output]) {
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

        foreach ([
            [$channelA->id, 10],
            [$channelB->id, 5],
            [$channelC->id, 3],
        ] as [$channelId, $priority]) {
            Ability::create([
                'group' => 'default',
                'model' => self::MODEL,
                'channel_id' => (int) $channelId,
                'enabled' => 1,
                'priority' => $priority,
            ]);
        }

        return [
            'channel_a' => (int) $channelA->id,
            'channel_b' => (int) $channelB->id,
            'channel_c' => (int) $channelC->id,
            'account_a' => (int) CodingPlanAccount::where('vendor', self::VENDOR_A)->value('id'),
            'account_b' => (int) CodingPlanAccount::where('vendor', self::VENDOR_B)->value('id'),
        ];
    }
}
