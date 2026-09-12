<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CodingPlanAccount;
use App\Models\CodingPlanModelRatio;
use App\Services\CodingPlanRatioService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Coding Plan 分时段折扣引擎自检（迁移 2026_09_12_000009 回归）。
 *
 * 覆盖：normalizeTimeDiscounts 规范化 / timeDiscountAt 命中判定（含北京时间口径）/
 * calcUsage 引擎集成。规范化与命中判定用内存实例；引擎集成在数据库事务内插入
 * __selftest__ 临时行，结束后回滚并清理对应比率缓存，零残留。
 *
 * 用法：php artisan coding-plan:test-time-discounts
 */
class TestCodingPlanTimeDiscounts extends Command
{
    protected $signature = 'coding-plan:test-time-discounts';

    protected $description = '分时段折扣引擎自检：规范化/命中判定/时区口径/calcUsage 集成（事务内零残留）';

    public function handle(): int
    {
        $pass = 0;
        $fail = 0;
        $check = function (string $name, bool $ok) use (&$pass, &$fail): void {
            $pass += $ok ? 1 : 0;
            $fail += $ok ? 0 : 1;
            $this->line(($ok ? '  <fg=green>✓</> ' : '  <fg=red>✗</> ').$name);
        };

        $this->info('【A】normalizeTimeDiscounts 规范化');
        $check('非法 JSON 字符串 → null', CodingPlanModelRatio::normalizeTimeDiscounts('{bad json') === null);
        $check('空数组 → null（不参与折扣）', CodingPlanModelRatio::normalizeTimeDiscounts([]) === null);
        $check('标量输入 → null', CodingPlanModelRatio::normalizeTimeDiscounts(42) === null);
        $norm = CodingPlanModelRatio::normalizeTimeDiscounts([
            ['name' => 'x', 'start' => '22:00', 'end' => '08:00', 'discount' => 0.5],
            ['name' => 'y', 'start' => '01:00', 'end' => '02:00', 'discount' => 1.0],
            ['name' => 'z', 'start' => '01:00', 'end' => '02:00', 'discount' => 0],
            ['name' => 'w', 'start' => '01:00', 'end' => '02:00', 'discount' => -0.2],
            ['name' => 'v', 'start' => '01:00', 'end' => '02:00', 'discount' => 1.5],
        ]);
        $check('折扣乘数必须位于 (0,1)：非法条目丢弃', $norm !== null && count($norm) === 1);
        $check('全部非法 → null', CodingPlanModelRatio::normalizeTimeDiscounts([['discount' => 2]]) === null);
        $norm = CodingPlanModelRatio::normalizeTimeDiscounts([[
            'days' => [1, 8, 0, 5, 5, '3'],
            'start' => '9:00',
            'end' => '24:00',
            'discount' => '0.5',
            'name' => str_repeat('长', 40),
        ]]);
        $check('days 越界过滤+去重+数字字符串转换 → [1,5,3]', $norm !== null && $norm[0]['days'] === [1, 5, 3]);
        $check('days 缺省 → 全周 [1..7]', CodingPlanModelRatio::normalizeTimeDiscounts([['start' => '01:00', 'end' => '02:00', 'discount' => 0.5]])[0]['days'] === [1, 2, 3, 4, 5, 6, 7]);
        $check('HH:MM 容错（9:00→09:00）且 24:00 保留', $norm !== null && $norm[0]['start'] === '09:00' && $norm[0]['end'] === '24:00');
        $check('非法时刻丢弃（24:30 / abc）', CodingPlanModelRatio::normalizeTimeDiscounts([['start' => '24:30', 'end' => '02:00', 'discount' => 0.5]]) === null && CodingPlanModelRatio::normalizeTimeDiscounts([['start' => 'abc', 'end' => '02:00', 'discount' => 0.5]]) === null);
        $check('start=end 条目丢弃', CodingPlanModelRatio::normalizeTimeDiscounts([['start' => '01:00', 'end' => '01:00', 'discount' => 0.5]]) === null);
        $check('name 缺省补位 + 截断 32 字符', $norm !== null && mb_strlen($norm[0]['name']) === 32);

        $this->info('【B】timeDiscountAt 命中判定（北京时间口径）');
        $zhipuStyle = fn (): CodingPlanModelRatio => new CodingPlanModelRatio(['time_discounts' => [
            ['name' => '工作日14-18测试窗', 'days' => [1, 2, 3, 4, 5], 'start' => '14:00', 'end' => '18:00', 'discount' => 0.8],
            ['name' => '夜间5折', 'days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '22:00', 'end' => '08:00', 'discount' => 0.5],
        ]]);
        // 2026-09-11 周五 / 2026-09-12 周六
        [$discount, $window] = $zhipuStyle()->timeDiscountAt(Carbon::create(2026, 9, 11, 15, 0, 0, 'Asia/Shanghai'));
        $check('工作日 15:00 命中 14-18 窗（0.8）', $discount === 0.8 && ($window['name'] ?? null) === '工作日14-18测试窗');
        $check('start 端点包含（14:00 命中）', $zhipuStyle()->timeDiscountAt(Carbon::create(2026, 9, 11, 14, 0, 0, 'Asia/Shanghai'))[0] === 0.8);
        $check('end 端点不包含（18:00 → 原价）', $zhipuStyle()->timeDiscountAt(Carbon::create(2026, 9, 11, 18, 0, 0, 'Asia/Shanghai'))[0] === 1.0);
        $check('跨零点窗后半夜命中（23:30 → 0.5）', $zhipuStyle()->timeDiscountAt(Carbon::create(2026, 9, 11, 23, 30, 0, 'Asia/Shanghai'))[0] === 0.5);
        $check('跨零点窗凌晨命中（周六 06:00 → 0.5）', $zhipuStyle()->timeDiscountAt(Carbon::create(2026, 9, 12, 6, 0, 0, 'Asia/Shanghai'))[0] === 0.5);
        $check('跨零点窗 end 端点不包含（周六 08:00 → 原价）', $zhipuStyle()->timeDiscountAt(Carbon::create(2026, 9, 12, 8, 0, 0, 'Asia/Shanghai'))[0] === 1.0);
        $check('days 过滤（周六 15:00 不命中工作日窗）', $zhipuStyle()->timeDiscountAt(Carbon::create(2026, 9, 12, 15, 0, 0, 'Asia/Shanghai'))[0] === 1.0);
        $check('窗口外时刻（周五 12:00 → 原价）', $zhipuStyle()->timeDiscountAt(Carbon::create(2026, 9, 11, 12, 0, 0, 'Asia/Shanghai'))[0] === 1.0);
        [$discount, $window] = $zhipuStyle()->timeDiscountAt(Carbon::create(2026, 9, 11, 15, 0, 0, 'UTC'));
        $check('时区解耦：UTC 15:00（=北京 23:00）命中夜间窗', $discount === 0.5 && ($window['name'] ?? null) === '夜间5折');
        $check('时区解耦：UTC 07:00（=北京 15:00）命中工作日窗', $zhipuStyle()->timeDiscountAt(Carbon::create(2026, 9, 11, 7, 0, 0, 'UTC'))[0] === 0.8);
        $mixed = new CodingPlanModelRatio(['time_discounts' => [
            ['name' => 'bad', 'start' => 'xx', 'end' => '08:00', 'discount' => 0.1],
            ['name' => 'ok', 'start' => '00:00', 'end' => '02:00', 'discount' => 0.3],
        ]]);
        [$discount, $window] = $mixed->timeDiscountAt(Carbon::create(2026, 9, 11, 1, 0, 0, 'Asia/Shanghai'));
        $check('畸形窗口跳过，后续窗口继续匹配', $discount === 0.3 && ($window['name'] ?? null) === 'ok');
        $overlap = new CodingPlanModelRatio(['time_discounts' => [
            ['name' => 'first', 'start' => '00:00', 'end' => '23:59', 'discount' => 0.9],
            ['name' => 'second', 'start' => '22:00', 'end' => '23:00', 'discount' => 0.5],
        ]]);
        $check('首条命中优先（重叠窗口取前者）', $overlap->timeDiscountAt(Carbon::create(2026, 9, 11, 22, 30, 0, 'Asia/Shanghai'))[0] === 0.9);
        [$discount, $window] = (new CodingPlanModelRatio)->timeDiscountAt(Carbon::create(2026, 9, 11, 15, 0, 0, 'Asia/Shanghai'));
        $check('未配置窗口 → 原价 [1.0, null]', $discount === 1.0 && $window === null);

        $this->info('【C】calcUsage 引擎集成（事务回滚零残留）');
        DB::beginTransaction();
        try {
            CodingPlanModelRatio::query()->create([
                'vendor' => '__selftest__',
                'model' => 'td-full-day',
                'match_type' => CodingPlanModelRatio::MATCH_EXACT,
                'cost_mode' => CodingPlanModelRatio::COST_PER_TOKEN_PARTS,
                'unit_cost' => 0,
                'input_rate' => 0.69,
                'cached_rate' => 0,
                'output_rate' => 0,
                'time_discounts' => [['name' => '全天5折自检', 'start' => '00:00', 'end' => '24:00', 'discount' => 0.5]],
                'unit_exchange_rate' => 1,
                'status' => 1,
                'sort' => 0,
                'remark' => 'coding-plan:test-time-discounts 临时行（事务回滚）',
            ]);
            CodingPlanModelRatio::query()->create([
                'vendor' => '__selftest__',
                'model' => 'td-plain',
                'match_type' => CodingPlanModelRatio::MATCH_EXACT,
                'cost_mode' => CodingPlanModelRatio::COST_PER_TOKEN_PARTS,
                'unit_cost' => 0,
                'input_rate' => 0.69,
                'cached_rate' => 0,
                'output_rate' => 0,
                'unit_exchange_rate' => 1,
                'status' => 1,
                'sort' => 0,
                'remark' => 'coding-plan:test-time-discounts 临时行（事务回滚）',
            ]);
            $service = new CodingPlanRatioService;
            $creditAccount = new CodingPlanAccount(['vendor' => '__selftest__', 'billing_mode' => CodingPlanAccount::BILLING_MODE_CREDIT]);
            $result = $service->calcUsage($creditAccount, 'td-full-day', 1000, 0, 1, 0);
            $check('calcUsage：窗口命中 units 减半（0.69 → 0.345）', abs($result['units'] - 0.345) < 1e-9);
            $check('calcUsage：time_window 返回命中窗口名', ($result['time_window']['name'] ?? null) === '全天5折自检');
            $result = $service->calcUsage($creditAccount, 'td-plain', 1000, 0, 1, 0);
            $check('calcUsage：未配置窗口 units 原价（0.69）', abs($result['units'] - 0.69) < 1e-9 && $result['time_window'] === null);
            $perRequestAccount = new CodingPlanAccount(['vendor' => '__selftest__', 'billing_mode' => CodingPlanAccount::BILLING_MODE_PER_REQUEST]);
            $result = $service->calcUsage($perRequestAccount, 'td-full-day', 0, 0, 3, 0);
            $check('calcUsage：非积分计费（按次）不受折扣影响', abs($result['units'] - 3.0) < 1e-9 && $result['time_window'] === null);
        } finally {
            DB::rollBack();
            Cache::forget('coding_plan_ratios:__selftest__');
        }

        $this->newLine();
        if ($fail > 0) {
            $this->error("自检未通过：{$fail} 项断言失败（通过 {$pass} 项）");

            return self::FAILURE;
        }
        $this->info("✅ 分时段折扣自检全部通过（{$pass} 项断言）");

        return self::SUCCESS;
    }
}
