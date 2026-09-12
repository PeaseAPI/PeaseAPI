<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Ability;
use App\Models\Channel;
use App\Models\CodingPlanAccount;
use App\Models\CodingPlanModelRatio;
use App\Models\CodingPlanVendor;
use App\Services\ChannelSelectService;
use App\Services\CodingPlanRatioService;
use App\Services\CostRouteService;
use App\Services\CurrencyExchangeService;
use App\Services\OptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 成本感知路由自检（P9-1 成本排序器 + ModelRouteStrategy 开关）。
 *
 * 覆盖：策略开关默认/切换/非法回退 / static 行为零变化 / cost_first 成本升序 /
 * 时段折扣改变排序 / 成本不可算垫底 / per_token_parts 探针口径（750/250、全 0 回退）/
 * per_1k_tokens 口径 / model_mapping 后比率解析 / cny_reference 汇率参考 /
 * 能力禁用剔除与垫底兜底 / 无候选。临时行（vendors/accounts/ratios/channels/abilities）
 * 在数据库事务内写入，结束后回滚并清缓存，零残留。
 *
 * 用法：php artisan coding-plan:test-cost-routing
 */
class TestCostRouting extends Command
{
    protected $signature = 'coding-plan:test-cost-routing';

    protected $description = '成本感知路由自检：策略开关/成本排序/时段折扣/探针口径/垫底兜底（事务内零残留）';

    /** 自检临时厂商（独特前缀防撞库内数据） */
    private const VENDOR_CHEAP = '__selftest_route_cheap__';

    private const VENDOR_PRICEY = '__selftest_route_pricey__';

    private const MODEL = 'selftest-route-model';

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

        $this->info('【A】ModelRouteSetting 策略开关');
        $router = new CostRouteService(app(CodingPlanRatioService::class));
        $check('无配置默认 static', $router->strategy() === 'static');
        OptionService::set(self::OPTION_KEY, 'cost_first');
        $check('cost_first 读取生效', $router->strategy() === 'cost_first');
        OptionService::set(self::OPTION_KEY, 'bogus');
        $check('非法值回退 static', $router->strategy() === 'static');

        $this->info('【B】static 零变化 + cost_first 排序');
        DB::beginTransaction();
        try {
            $ids = $this->seed();
            $selector = app(ChannelSelectService::class);

            // static（默认）行为零变化：priority 最高者胜（pricey=10 唯一最高，与成本无关）
            OptionService::set(self::OPTION_KEY, 'static');
            $picked = $selector->pickChannel(self::MODEL, 'default');
            $check('static：priority 最高渠道胜出（与成本无关）', $picked !== null && (int) $picked->id === $ids['pricey']);

            // cost_first：便宜渠道胜出（cheap 0.006 < pricey 0.02）
            OptionService::set(self::OPTION_KEY, 'cost_first');
            $picked = $selector->pickChannel(self::MODEL, 'default');
            $check('cost_first：成本最低渠道胜出', $picked !== null && (int) $picked->id === $ids['cheap']);

            // 明细与数值口径
            $details = $router->sortChannelsByCost($selector->candidateChannels(self::MODEL, 'default'), self::MODEL);
            $cheap = $this->detail($details, $ids['cheap']);
            $pricey = $this->detail($details, $ids['pricey']);
            $plain = $this->detail($details, $ids['plain']);
            $check('排序首位 = cheap', $details[0]['channel_id'] === $ids['cheap']);
            $check('per_token_parts 探针 = 0.75×in + 0.25×out（0.006）',
                $cheap['cost_per_1k'] !== null && abs($cheap['cost_per_1k'] - 0.006) < 1e-9);
            $check('per_1k_tokens × 汇率（0.01×2.0=0.02）',
                $pricey['cost_per_1k'] !== null && abs($pricey['cost_per_1k'] - 0.02) < 1e-9);
            $check('明细携带 vendor/exchange_rate/cost_mode',
                $cheap['vendor'] === self::VENDOR_CHEAP && $cheap['exchange_rate'] === 1.0
                && $cheap['cost_mode'] === CodingPlanModelRatio::COST_PER_TOKEN_PARTS);
            $check('无账号渠道 cost null 且垫底',
                $plain['cost_per_1k'] === null && $plain['vendor'] === null
                && $details[count($details) - 1]['channel_id'] === $ids['plain']);

            $this->info('【C】时段折扣改变排序');
            CodingPlanModelRatio::where('vendor', self::VENDOR_PRICEY)->update([
                'time_discounts' => json_encode([[
                    'name' => '全天自检窗口',
                    'days' => [1, 2, 3, 4, 5, 6, 7],
                    'start' => '00:00',
                    'end' => '24:00',
                    'discount' => 0.2,
                ]]),
            ]);
            app(CodingPlanRatioService::class)->flushCache(self::VENDOR_PRICEY);
            // 重建路由器：flushCache 只清目标实例的进程内 memo，
            // 本测试的 $router 是独立实例，需同步重建才能读到事务内变更
            $router = new CostRouteService(app(CodingPlanRatioService::class));
            $details = $router->sortChannelsByCost($selector->candidateChannels(self::MODEL, 'default'), self::MODEL);
            $pricey = $this->detail($details, $ids['pricey']);
            $check('折扣命中（0.02×0.2=0.004）反超 cheap（0.006）',
                $pricey['cost_per_1k'] !== null && abs($pricey['cost_per_1k'] - 0.004) < 1e-9
                && $details[0]['channel_id'] === $ids['pricey']);
            $check('明细携带命中窗口名与乘数',
                $pricey['time_window'] === '全天自检窗口' && $pricey['time_discount'] === 0.2);

            $this->info('【D】model_mapping 后比率解析');
            // cheap 渠道把请求模型映射为 mapped-model；比率表对该名 exact 更便宜
            // （probe-model 在 cheap vendor 无比率 → 不走映射时回退全局默认 unit_cost=1，差异巨大）
            CodingPlanModelRatio::create([
                'vendor' => self::VENDOR_CHEAP,
                'model' => 'selftest-route-mapped',
                'match_type' => CodingPlanModelRatio::MATCH_EXACT,
                'cost_mode' => CodingPlanModelRatio::COST_PER_1K_TOKENS,
                'unit_cost' => 0.001,
                'status' => 1,
                'sort' => 0,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
            Channel::where('id', $ids['cheap'])->update([
                'model_mapping' => json_encode([self::MODEL => 'selftest-route-mapped']),
            ]);
            app(CodingPlanRatioService::class)->flushCache(self::VENDOR_CHEAP);
            $router = new CostRouteService(app(CodingPlanRatioService::class));
            $details = $router->sortChannelsByCost($selector->candidateChannels(self::MODEL, 'default'), self::MODEL);
            $cheap = $this->detail($details, $ids['cheap']);
            $check('比率解析走映射后模型名（0.001 而非全局默认 1.0）',
                $cheap['probe_model'] === 'selftest-route-mapped'
                && $cheap['cost_per_1k'] !== null && abs($cheap['cost_per_1k'] - 0.001) < 1e-9);

            $this->info('【E】cny_reference 观测参考');
            $usdOption = OptionService::get(CurrencyExchangeService::USD_FALLBACK_OPTION);
            $expectedUsd = is_numeric($usdOption) && (float) $usdOption > 0 ? (float) $usdOption : null;
            $pricey = $this->detail($details, $ids['pricey']);
            if ($expectedUsd !== null) {
                // pricey 厂商官方币种 USD：cny_reference = 探针单位 × USD→CNY 市场汇率
                $expectedCny = round(0.01 * 0.2 * $expectedUsd, 6);
                $check('USD 厂商 cny_reference = 探针 × 市场汇率（'.($pricey['cny_reference'] ?? 'null').'）',
                    $pricey['cny_reference'] !== null && abs($pricey['cny_reference'] - $expectedCny) < 1e-6);
            } else {
                $check('USD 折算未配置 → cny_reference null（不虚报）', $pricey['cny_reference'] === null);
            }
            $check('CNY 厂商 cny_reference 恒 null（基准币种无需折算）', $cheap['cny_reference'] === null);

            $this->info('【F】回退与剔除');
            Ability::where('channel_id', $ids['cheap'])->update(['enabled' => 0]);
            $picked = $selector->pickChannel(self::MODEL, 'default');
            $check('能力禁用剔除后取下一成本位（pricey 折扣后 0.004）',
                $picked !== null && (int) $picked->id === $ids['pricey']);
            Ability::where('channel_id', $ids['pricey'])->update(['enabled' => 0]);
            $picked = $selector->pickChannel(self::MODEL, 'default');
            $check('仅剩成本不可算渠道时垫底兜底可选中',
                $picked !== null && (int) $picked->id === $ids['plain']);
            Ability::where('model', self::MODEL)->update(['enabled' => 0]);
            $check('无候选 → null', $selector->pickChannel(self::MODEL, 'default') === null);
            $check('组内无能力时不回退到他组模型（按 model 精确匹配）',
                $selector->candidateChannels(self::MODEL, 'vip')->count() === 0);
        } finally {
            DB::rollBack();
            app(CodingPlanRatioService::class)->flushCache();
            // flushCache 全量分支按 vendors/ratios 表枚举 vendor——自检行已回滚不在表内，
            // 其缓存键会被漏清并污染下一轮运行，必须显式 forget
            foreach ([self::VENDOR_CHEAP, self::VENDOR_PRICEY] as $code) {
                Cache::forget('coding_plan_ratios:'.$code);
                Cache::forget('coding_plan_vendor:'.$code);
            }
            CurrencyExchangeService::flushMemo();
            OptionService::set(self::OPTION_KEY, 'static');
        }

        $this->info('【G】零残留');
        $check('vendors 无自检行', ! CodingPlanVendor::where('code', 'like', '__selftest_route_%')->exists());
        $check('accounts 无自检行', ! CodingPlanAccount::where('vendor', 'like', '__selftest_route_%')->exists());
        $check('ratios 无自检行', ! CodingPlanModelRatio::where('vendor', 'like', '__selftest_route_%')->exists());
        $check('channels/abilities 无自检行',
            ! Channel::where('name', 'like', '__selftest_route_%')->exists()
            && ! Ability::where('model', self::MODEL)->exists());
        $check('自检 vendor 缓存键无残留',
            Cache::get('coding_plan_ratios:'.self::VENDOR_CHEAP) === null
            && Cache::get('coding_plan_ratios:'.self::VENDOR_PRICEY) === null);

        $this->info("通过 {$pass} / 失败 {$fail}");

        return $fail === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * 事务内造数：2 厂商 + 3 渠道 + 2 账号 + 2 比率行 + abilities
     *
     * 成本预设：cheap per_token_parts（0.75×0.004 + 0.25×0.012 = 0.006，汇率 1.0）
     * < pricey per_1k_tokens（0.01 × 汇率 2.0 = 0.02）；plain 无账号成本不可算；
     * priority pricey(10) > cheap(5) > plain(3) —— static 语义下 pricey 胜出。
     *
     * @return array{cheap: int, pricey: int, plain: int}
     */
    private function seed(): array
    {
        $now = time();

        CodingPlanVendor::create([
            'code' => self::VENDOR_CHEAP,
            'name' => '自检便宜厂商',
            'billing_mode' => CodingPlanVendor::BILLING_MODE_CREDIT,
            'unit_exchange_rate' => 1.0,
            'currency' => 'CNY',
            'status' => 1,
            'sort' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        CodingPlanVendor::create([
            'code' => self::VENDOR_PRICEY,
            'name' => '自检昂贵厂商',
            'billing_mode' => CodingPlanVendor::BILLING_MODE_CREDIT,
            'unit_exchange_rate' => 2.0,
            'currency' => 'USD',
            'status' => 1,
            'sort' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $cheap = Channel::create([
            'type' => 1,
            'key' => 'sk-selftest',
            'name' => '__selftest_route_cheap__',
            'status' => 1,
            'priority' => 5,
            'weight' => 0,
            'response_time' => 0,
            'group' => 'default',
            'models' => [self::MODEL],
            'created_time' => $now,
        ]);
        $pricey = Channel::create([
            'type' => 1,
            'key' => 'sk-selftest',
            'name' => '__selftest_route_pricey__',
            'status' => 1,
            'priority' => 10,
            'weight' => 0,
            'response_time' => 0,
            'group' => 'default',
            'models' => [self::MODEL],
            'created_time' => $now,
        ]);
        $plain = Channel::create([
            'type' => 1,
            'key' => 'sk-selftest',
            'name' => '__selftest_route_plain__',
            'status' => 1,
            'priority' => 3,
            'weight' => 0,
            'response_time' => 0,
            'group' => 'default',
            'models' => [self::MODEL],
            'created_time' => $now,
        ]);

        CodingPlanAccount::create([
            'vendor' => self::VENDOR_CHEAP,
            'billing_mode' => CodingPlanAccount::BILLING_MODE_CREDIT,
            'account_name' => '__selftest_cheap__',
            'api_key' => base64_encode('sk-selftest'),
            'unit_exchange_rate' => 0, // 0 = 跟随供应商默认（1.0）
            'status' => CodingPlanAccount::STATUS_ENABLED,
            'channel_id' => (int) $cheap->id,
            'priority' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        CodingPlanAccount::create([
            'vendor' => self::VENDOR_PRICEY,
            'billing_mode' => CodingPlanAccount::BILLING_MODE_CREDIT,
            'account_name' => '__selftest_pricey__',
            'api_key' => base64_encode('sk-selftest'),
            'unit_exchange_rate' => 2.0, // 账号级覆盖 = 供应商默认
            'status' => CodingPlanAccount::STATUS_ENABLED,
            'channel_id' => (int) $pricey->id,
            'priority' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        CodingPlanModelRatio::create([
            'vendor' => self::VENDOR_CHEAP,
            'model' => self::MODEL,
            'match_type' => CodingPlanModelRatio::MATCH_EXACT,
            'cost_mode' => CodingPlanModelRatio::COST_PER_TOKEN_PARTS,
            'input_rate' => 0.004,
            'cached_rate' => 0.0,
            'output_rate' => 0.012,
            'status' => 1,
            'sort' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        CodingPlanModelRatio::create([
            'vendor' => self::VENDOR_PRICEY,
            'model' => self::MODEL,
            'match_type' => CodingPlanModelRatio::MATCH_EXACT,
            'cost_mode' => CodingPlanModelRatio::COST_PER_1K_TOKENS,
            'unit_cost' => 0.01,
            'status' => 1,
            'sort' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([
            ['id' => $cheap->id, 'priority' => 5],
            ['id' => $pricey->id, 'priority' => 10],
            ['id' => $plain->id, 'priority' => 3],
        ] as $row) {
            Ability::create([
                'group' => 'default',
                'model' => self::MODEL,
                'channel_id' => (int) $row['id'],
                'enabled' => 1,
                'priority' => $row['priority'],
            ]);
        }

        return [
            'cheap' => (int) $cheap->id,
            'pricey' => (int) $pricey->id,
            'plain' => (int) $plain->id,
        ];
    }

    /**
     * 从明细数组中按渠道 id 取一条
     *
     * @param  array<int, array<string, mixed>>  $details
     * @return array<string, mixed>
     */
    private function detail(array $details, int $channelId): array
    {
        foreach ($details as $detail) {
            if ($detail['channel_id'] === $channelId) {
                return $detail;
            }
        }

        throw new RuntimeException("channel {$channelId} not in details");
    }
}
