<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CodingPlanAccount;
use App\Models\CodingPlanVendor;
use App\Models\CurrencyRate;
use App\Models\User;
use App\Services\CodingPlanPoolService;
use App\Services\CurrencyExchangeService;
use App\Services\OptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Coding Plan 多货币自检（P7-1 currency 字段 / P7-2 currency_rates / P7-3 折算与快照）。
 *
 * 覆盖：rate() 三级回落（表值 → USD Option 兜底 → null）/ convert() 双向折算与互逆 /
 * snapshotFor() 汇率快照结构 / vendorOfficialCurrency() / recordUsage fx 快照集成。
 * 临时行（currency_rates、vendors、accounts、usage_logs）在数据库事务内写入，
 * 结束后回滚并清 memo，零残留。
 *
 * 用法：php artisan coding-plan:test-currency
 */
class TestCodingPlanCurrency extends Command
{
    protected $signature = 'coding-plan:test-currency';

    protected $description = '多货币体系自检：汇率回落/双向折算/快照/计费留痕（事务内零残留）';

    /** 自检临时厂商（仅为造 account 与汇率场景） */
    private const TEST_VENDOR = '__selftest_cur__';

    public function handle(): int
    {
        $pass = 0;
        $fail = 0;
        $check = function (string $name, bool $ok) use (&$pass, &$fail): void {
            $pass += $ok ? 1 : 0;
            $fail += $ok ? 0 : 1;
            $this->line(($ok ? '  <fg=green>✓</> ' : '  <fg=red>✗</> ').$name);
        };

        $this->info('【A】rate() 三级回落');
        $check('基准 CNY 恒为 1.0', CurrencyExchangeService::rate('CNY') === 1.0);
        $check('小写输入归一（cny → 1.0）', CurrencyExchangeService::rate('cny') === 1.0);
        $check('空串 → null', CurrencyExchangeService::rate('') === null);
        $optionUsd = OptionService::get(CurrencyExchangeService::USD_FALLBACK_OPTION);
        $expectedUsd = is_numeric($optionUsd) && (float) $optionUsd > 0 ? (float) $optionUsd : null;
        $check('USD 无表行 → 回落 Option '.CurrencyExchangeService::USD_FALLBACK_OPTION.'（'.($expectedUsd ?? '无').'）',
            CurrencyExchangeService::rate('USD') === $expectedUsd);
        $check('未知币种 → null', CurrencyExchangeService::rate('XYZ') === null);

        // 表值优先：事务内临时行（回滚零残留）
        DB::beginTransaction();
        try {
            CurrencyRate::create(['code' => 'USD', 'rate' => 7.12, 'source' => 'manual', 'updated_at' => time()]);
            CurrencyExchangeService::flushMemo();
            $check('USD 有表行 → 表值优先（7.12）', CurrencyExchangeService::rate('USD') === 7.12);
            CurrencyRate::where('code', 'USD')->delete();
            CurrencyExchangeService::flushMemo();
            $check('删除表行 → 回落恢复', CurrencyExchangeService::rate('USD') === $expectedUsd);
        } finally {
            DB::rollBack();
            CurrencyExchangeService::flushMemo();
        }

        $this->info('【B】convert() 双向折算（以基准 CNY 中转）');
        if ($expectedUsd !== null) {
            $check('同币种恒等', CurrencyExchangeService::convert(5.0, 'CNY', 'CNY') === 5.0);
            $check('CNY→USD（÷rate）', abs((float) CurrencyExchangeService::convert($expectedUsd, 'CNY', 'USD') - 1.0) < 1e-9);
            $check('USD→CNY（×rate）', abs((float) CurrencyExchangeService::convert(1.0, 'USD', 'CNY') - $expectedUsd) < 1e-9);
            $cnyUsd = CurrencyExchangeService::convert(10.0, 'CNY', 'USD');
            $back = $cnyUsd !== null ? CurrencyExchangeService::convert($cnyUsd, 'USD', 'CNY') : null;
            $check('往返互逆（10 CNY → USD → CNY ≈ 10）', $back !== null && abs($back - 10.0) < 1e-9);
        }
        $check('不可折算币种 → null', CurrencyExchangeService::convert(1.0, 'CNY', 'XYZ') === null && CurrencyExchangeService::convert(1.0, 'XYZ', 'CNY') === null);
        $check('空币种 → null', CurrencyExchangeService::convert(1.0, '', 'USD') === null);

        $this->info('【C】snapshotFor() 汇率快照');
        $check('纯基准币种 → null（无折算无需留痕）', CurrencyExchangeService::snapshotFor('CNY') === null);
        if ($expectedUsd !== null) {
            $snap = CurrencyExchangeService::snapshotFor('USD');
            $check('USD→CNY 快照结构（base/from/to/rates/taken_at）',
                is_array($snap) && $snap['base'] === 'CNY' && $snap['from'] === 'USD' && $snap['to'] === 'CNY'
                && ($snap['rates']['USD'] ?? null) === $expectedUsd && ($snap['taken_at'] ?? 0) > 0);
        }
        $check('不可折算 → null', CurrencyExchangeService::snapshotFor('XYZ') === null);

        $this->info('【D】vendorOfficialCurrency()');
        $check('openai 官方币种 = USD（P7-1 预置）', CurrencyExchangeService::vendorOfficialCurrency('openai') === 'USD');
        $check('zhipu 官方币种 = CNY（默认）', CurrencyExchangeService::vendorOfficialCurrency('zhipu') === 'CNY');
        $check('未知 vendor 回落 CNY', CurrencyExchangeService::vendorOfficialCurrency('no-such-vendor') === 'CNY');

        $this->info('【E】recordUsage fx 快照集成（事务内零残留）');
        $this->runFxIntegration($check, 'USD', 'USD', true);
        $this->runFxIntegration($check, 'CNY', '元', false);

        $this->info('【F】P7-4 结算货币（convertQuota / User::settlementCurrency）');
        $check('convertQuota 无偏好 → null（按平台默认展示）', CurrencyExchangeService::convertQuota(500000, null) === null);
        $check('convertQuota 基准币种 → null（无折算）', CurrencyExchangeService::convertQuota(500000, 'CNY') === null);
        $check('convertQuota 不可折算 → null', CurrencyExchangeService::convertQuota(500000, 'XYZ') === null);
        if ($expectedUsd !== null) {
            $qpu = (float) (OptionService::get('QuotaPerUnit', 500000) ?: 500000);
            $conv = CurrencyExchangeService::convertQuota((int) $qpu, 'usd');
            $check('convertQuota usd 小写归一 + USD 偏好恒等（1 美元单位 → 1.0 USD）',
                is_array($conv) && $conv['currency'] === 'USD' && $conv['usd_amount'] === 1.0
                && ($conv['amount'] ?? null) === $conv['usd_amount'] && is_array($conv['fx']) && $conv['fx']['to'] === 'USD');
            $check('convertQuota USD 快照记录所用汇率（rates.USD = Option 兜底值）',
                ($conv['fx']['rates']['USD'] ?? null) === $expectedUsd);
            $conv2 = CurrencyExchangeService::convertQuota((int) (2 * $qpu), 'USD');
            $check('convertQuota 数值线性（2×quota → 2×amount）',
                is_array($conv2) && abs(($conv2['amount'] ?? 0) - 2.0) < 1e-9);
        }
        $this->runSettlementPreference($check);

        $this->newLine();
        $this->info("✅ 多货币自检全部通过（{$pass} 项断言）");

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * fx 集成场景：事务内造 vendor（指定 currency）+ account → recordUsage →
     * 断言用量 meta 是否自动附 fx 快照（USD 厂商应附、CNY 厂商不应附），回滚零残留。
     */
    private function runFxIntegration(\Closure $check, string $currency, string $unitName, bool $expectFx): void
    {
        DB::beginTransaction();
        try {
            CodingPlanVendor::create([
                'code' => self::TEST_VENDOR,
                'name' => '自检厂商 '.$currency,
                'billing_mode' => CodingPlanVendor::BILLING_MODE_CREDIT,
                'plan_kind' => CodingPlanVendor::PLAN_KIND_TOKEN,
                'unit_name' => $unitName,
                'unit_exchange_rate' => 1,
                'currency' => $currency,
                'status' => 0,
                'sort' => 9999,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
            $account = CodingPlanAccount::create([
                'vendor' => self::TEST_VENDOR,
                'account_name' => 'selftest-'.$currency,
                'channel_id' => 0,
                'billing_mode' => CodingPlanVendor::BILLING_MODE_CREDIT,
                'quota_5h' => 1000,
                'quota_weekly' => 1000,
                'quota_monthly' => 1000,
                'status' => 1,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
            CurrencyExchangeService::flushMemo();

            app(CodingPlanPoolService::class)->recordUsage($account, 1.0, [
                'user_id' => 0,
                'model' => 'selftest-model',
                'meta' => ['ratio' => 1.0],
            ]);

            $log = DB::table('coding_plan_usage_logs')->where('vendor', self::TEST_VENDOR)->first();
            $logMeta = $log !== null ? json_decode((string) $log->meta, true) : null;
            $fx = is_array($logMeta) ? ($logMeta['fx'] ?? null) : null;
            if ($expectFx) {
                $check($currency.' 厂商用量 meta 自动附 fx 快照', is_array($fx) && ($fx['from'] ?? null) === 'USD' && ($fx['base'] ?? null) === 'CNY');
            } else {
                $check($currency.' 厂商无 fx（基准币种不留痕）', is_array($logMeta) && $fx === null);
            }
        } finally {
            DB::rollBack();
            CurrencyExchangeService::flushMemo();
        }
    }

    /**
     * 结算偏好集成（P7-4）：事务内造临时用户行 → User::settlementCurrency() 回落语义 +
     * 偏好变化驱动 convertQuota 切换币种，回滚零残留。
     */
    private function runSettlementPreference(\Closure $check): void
    {
        DB::beginTransaction();
        try {
            $user = User::create([
                'username' => '__selftest_cur__'.time(),
                'password' => 'selftest',
                'email' => '__selftest_cur__'.time().'@invalid.local',
                'aff_code' => 'ST'.time(),
                'quota' => 1000000,
                'created_at' => time(),
            ]);
            $check('未设偏好 → settlementCurrency() 回落 CNY', $user->settlementCurrency() === 'CNY');

            $user->settlement_currency = 'usd';
            $check('偏好小写存储 → settlementCurrency() 归一 USD', $user->settlementCurrency() === 'USD');

            $conv = CurrencyExchangeService::convertQuota((int) $user->quota, $user->settlementCurrency());
            $check('偏好 USD + quota=1e6 → convertQuota 出 USD 金额与 fx 快照',
                is_array($conv) && $conv['currency'] === 'USD' && is_array($conv['fx']) && $conv['fx']['to'] === 'USD');
        } finally {
            DB::rollBack();
        }
    }
}
