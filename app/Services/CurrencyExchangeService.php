<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CodingPlanVendor;
use App\Models\CurrencyRate;

/**
 * 多货币汇率服务（P7-2 读取侧 / P7-3 将扩展 convert + 汇率快照留痕）
 *
 * 平台基准币种为 CNY。`rate()` 返回「1 单位该币种 = N 人民币」：
 *  1. currency_rates 表有该币种行 → 用表值（管理端维护，source 字段留痕来源）；
 *  2. USD 无行 → 回落平台既有 Option 'UsdExchangeRate'（语义即 1 美元折人民币数）；
 *  3. CNY 恒为 1.0；其余币种无行 → null（调用方应拒绝折算或提示管理员补录）。
 */
class CurrencyExchangeService
{
    /** 平台基准币种（积分/计费主口径） */
    public const BASE_CURRENCY = 'CNY';

    /** USD 无 currency_rates 行时的 Option 回落键 */
    public const USD_FALLBACK_OPTION = 'UsdExchangeRate';

    /** 进程内 memo（单请求生命周期），写入走管理端 CRUD 即时失效 */
    private static array $memo = [];

    /** vendor 官方币种 memo（vendorOfficialCurrency） */
    private static array $vendorCurrencyMemo = [];

    /**
     * 取某币种相对基准 CNY 的汇率（1 单位 = N CNY）；不可折算返回 null
     */
    public static function rate(string $code): ?float
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        if (array_key_exists($code, self::$memo)) {
            return self::$memo[$code];
        }

        $rate = self::resolve($code);
        self::$memo[$code] = $rate;

        return $rate;
    }

    /**
     * 币种是否可折算（有明确汇率或可回落）
     */
    public static function isSupported(string $code): bool
    {
        return self::rate($code) !== null;
    }

    /**
     * quota → 结算币种金额换算（P7-4 余额/账单展示口径）。
     *
     * 平台 quota 与货币的既有口径：QuotaPerUnit quota = 1 美元单位（SubscriptionService::quotaPerUnit 同源）。
     * 流程：quota → USD 金额（÷QuotaPerUnit）→ convert() 折用户币种；随附 fx 快照
     * （结算口径=交易时刻汇率快照，展示层拿到当时所用汇率，事后可对账）。
     *
     * currency=null（用户未设偏好）→ 返回 null，调用方按平台默认展示；
     * 币种不可折算 → 返回 null（调用方拒绝或提示）。
     *
     * 结构：{currency, quota, quota_per_unit, usd_amount, amount, fx:{base,from,to,rates,taken_at}}
     */
    public static function convertQuota(int $quota, ?string $currency): ?array
    {
        $currency = $currency !== null ? strtoupper(trim($currency)) : '';
        if ($currency === '' || $currency === self::BASE_CURRENCY) {
            return null; // 无偏好或即基准：无折算发生
        }

        $usdAmount = $quota / (float) (OptionService::get('QuotaPerUnit', 500000) ?: 500000);
        $amount = self::convert($usdAmount, 'USD', $currency);
        if ($amount === null) {
            return null;
        }

        return [
            'currency' => $currency,
            'quota' => $quota,
            'quota_per_unit' => (float) (OptionService::get('QuotaPerUnit', 500000) ?: 500000),
            'usd_amount' => round($usdAmount, 6),
            'amount' => round($amount, 6),
            'fx' => self::snapshotFor('USD', $currency),
        ];
    }

    /** 管理端写入后清 memo（避免同请求内读到旧值） */
    public static function flushMemo(): void
    {
        self::$memo = [];
        self::$vendorCurrencyMemo = [];
    }

    /**
     * 双向折算：amount（单位 from）→ 单位 to；以基准 CNY 中转。
     * 任一币种不可折算（rate=null）→ 返回 null，调用方应拒绝或提示。
     * 例：convert(7.3, 'CNY', 'USD') 与 convert(1, 'USD', 'CNY') 在 rate(USD)=7.3 时互逆。
     */
    public static function convert(float $amount, string $from, string $to): ?float
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));
        if ($from === '' || $to === '') {
            return null;
        }
        if ($from === $to) {
            return $amount;
        }

        $fromRate = self::rate($from);
        $toRate = self::rate($to);
        if ($fromRate === null || $toRate === null || $toRate == 0.0) {
            return null;
        }

        // from → CNY（×rate(from)），CNY → to（÷rate(to)）
        return $amount * $fromRate / $toRate;
    }

    /**
     * 汇率快照（计费留痕用）：记录本次折算所用汇率与采集时刻。
     * 全部为基准币种时返回 null（无折算发生，无需留痕）；
     * 任一币种不可折算时也返回 null（折算本就不该发生）。
     * 结构：{base, from, to, rates: {FROM: x, TO: y}, taken_at}
     */
    public static function snapshotFor(string $from, ?string $to = null): ?array
    {
        $from = strtoupper(trim($from));
        $to = $to !== null ? strtoupper(trim($to)) : self::BASE_CURRENCY;

        $fromRate = self::rate($from);
        $toRate = self::rate($to);
        if ($fromRate === null || $toRate === null) {
            return null;
        }

        $rates = [];
        if ($from !== self::BASE_CURRENCY) {
            $rates[$from] = $fromRate;
        }
        if ($to !== self::BASE_CURRENCY && $to !== $from) {
            $rates[$to] = $toRate;
        }
        if ($rates === []) {
            return null; // 纯基准币种，无折算
        }

        return [
            'base' => self::BASE_CURRENCY,
            'from' => $from,
            'to' => $to,
            'rates' => $rates,
            'taken_at' => time(),
        ];
    }

    /**
     * 供应商官方计价币种（memo；行缺失/字段空按基准 CNY）
     */
    public static function vendorOfficialCurrency(string $vendorCode): string
    {
        $vendorCode = strtolower(trim($vendorCode));
        if (array_key_exists($vendorCode, self::$vendorCurrencyMemo)) {
            return self::$vendorCurrencyMemo[$vendorCode];
        }

        $currency = CodingPlanVendor::query()->where('code', $vendorCode)->value('currency');
        $currency = is_string($currency) && $currency !== '' ? strtoupper($currency) : self::BASE_CURRENCY;
        self::$vendorCurrencyMemo[$vendorCode] = $currency;

        return $currency;
    }

    private static function resolve(string $code): ?float
    {
        // 基准币种恒为 1
        if ($code === self::BASE_CURRENCY) {
            return 1.0;
        }

        // 表值优先（管理端维护）
        $row = CurrencyRate::query()->where('code', $code)->first();
        if ($row !== null) {
            return (float) $row->rate;
        }

        // USD 回落平台既有汇率 Option
        if ($code === 'USD') {
            $fallback = OptionService::get(self::USD_FALLBACK_OPTION);
            if (is_numeric($fallback) && (float) $fallback > 0) {
                return (float) $fallback;
            }
        }

        return null;
    }
}
