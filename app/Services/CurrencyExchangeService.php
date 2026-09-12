<?php

declare(strict_types=1);

namespace App\Services;

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

    /** 管理端写入后清 memo（避免同请求内读到旧值） */
    public static function flushMemo(): void
    {
        self::$memo = [];
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
