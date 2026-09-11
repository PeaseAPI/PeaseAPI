<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Coding Plan 模型折算比率表（积分制核心）
 *
 * 将某厂商下不同模型的用量折算为供应商原生单位：
 *  - match_type=exact：模型名全等匹配
 *  - match_type=prefix：模型名前缀匹配（前缀最长者优先）
 *  - cost_mode=per_request：每次请求消耗 unit_cost 个单位
 *  - cost_mode=per_1k_tokens：每 1000 token 消耗 unit_cost 个单位
 *  - cost_mode=per_token_parts：分段折算（输入/缓存命中/输出三段独立千 token 系数）
 *
 * 分时段折扣：time_discounts 窗口数组按计费时刻自动命中（智谱非高峰 5 折、
 * DeepSeek 空闲减半等官方口径），未配置/未命中 = 原价。
 *
 * 解析不到匹配时回退：unit_cost=1、per_request。
 */
class CodingPlanModelRatio extends Model
{
    public const MATCH_EXACT = 'exact';

    public const MATCH_PREFIX = 'prefix';

    public const COST_PER_REQUEST = 'per_request';

    public const COST_PER_1K_TOKENS = 'per_1k_tokens';

    /** 计费口径：分段折算（输入/缓存命中/输出三段独立千 token 系数，如智谱 GLM、阿里云 Credits） */
    public const COST_PER_TOKEN_PARTS = 'per_token_parts';

    /**
     * 时段折扣判定的固定时区：官方折扣窗口均为北京时间口径
     * （智谱/DeepSeek/阿里云官方文档均按 Asia/Shanghai 描述时段），
     * 判定时显式换算，与 APP_TIMEZONE / 服务器时区解耦。
     */
    public const DISCOUNT_TIMEZONE = 'Asia/Shanghai';

    protected $table = 'coding_plan_model_ratios';

    public $timestamps = false;

    protected $fillable = [
        'vendor',
        'model',
        'match_type',
        'cost_mode',
        'unit_cost',
        'input_rate',
        'cached_rate',
        'output_rate',
        'time_discounts',
        'status',
        'sort',
        'remark',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'unit_cost' => 'float',
        'input_rate' => 'float',
        'cached_rate' => 'float',
        'output_rate' => 'float',
        'time_discounts' => 'array',
        'status' => 'integer',
        'sort' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    /**
     * 是否为分段折算口径（units 由三段千 token 系数决定，unit_cost 不参与）。
     */
    public function usesSplitRates(): bool
    {
        return $this->cost_mode === self::COST_PER_TOKEN_PARTS;
    }

    /**
     * 是否配置了时段折扣窗口（配置了即按计费时刻自动命中折扣）。
     */
    public function hasTimeDiscounts(): bool
    {
        return is_array($this->time_discounts) && $this->time_discounts !== [];
    }

    /**
     * 计算时刻命中的时段折扣。
     *
     * 窗口为官方北京时间口径：$at 允许任意时区（缺省取当前时刻），
     * 内部统一换算到 Asia/Shanghai 后取周几与分钟判定。
     *
     * @param  CarbonInterface|null  $at  计费时刻（缺省当前时间；引擎计费语义=请求完成时刻）
     * @return array{0: float, 1: array|null} [折扣乘数, 命中的窗口规则]；未配置/未命中 = [1.0, null]
     */
    public function timeDiscountAt(?CarbonInterface $at = null): array
    {
        if (! $this->hasTimeDiscounts()) {
            return [1.0, null];
        }

        $at = ($at ?? Carbon::now())->copy()->timezone(self::DISCOUNT_TIMEZONE);
        $day = (int) $at->dayOfWeekIso; // ISO 周几：1=周一 … 7=周日
        $minutes = ((int) $at->format('H')) * 60 + (int) $at->format('i');

        foreach ($this->time_discounts as $window) {
            $start = self::parseWindowTime($window['start'] ?? null);
            $end = self::parseWindowTime($window['end'] ?? null);
            if ($start === null || $end === null || $start === $end) {
                continue;
            }
            $days = array_values(array_map('intval', (array) ($window['days'] ?? [])));
            if ($days !== [] && ! in_array($day, $days, true)) {
                continue;
            }
            // end < start 视为跨零点窗口（如 22:00-08:00）
            $hit = $start < $end
                ? ($minutes >= $start && $minutes < $end)
                : ($minutes >= $start || $minutes < $end);
            if ($hit) {
                return [(float) $window['discount'], $window];
            }
        }

        return [1.0, null];
    }

    /**
     * 规范化时段折扣规则集（迁移预置 / verify-ratios 定价源 / 管理端表单共用）：
     * 结构非法或折扣不在 (0,1) 的条目被丢弃，全部非法返回 null（= 不参与时段折扣）。
     *
     * @param  mixed  $raw  数组或 JSON 字符串
     * @return array<int, array{name: string, days: list<int>, start: string, end: string, discount: float}>|null
     */
    public static function normalizeTimeDiscounts(mixed $raw): ?array
    {
        if (is_string($raw) && $raw !== '') {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return null;
        }

        $windows = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $discount = (float) ($item['discount'] ?? 0);
            if ($discount <= 0.0 || $discount >= 1.0) {
                continue; // 折扣乘数必须位于 (0,1)，防止误配涨价/免费
            }
            $start = self::parseWindowTime($item['start'] ?? null);
            $end = self::parseWindowTime($item['end'] ?? null);
            if ($start === null || $end === null || $start === $end) {
                continue;
            }
            $days = array_values(array_unique(array_filter(
                array_map('intval', (array) ($item['days'] ?? [])),
                fn (int $d) => $d >= 1 && $d <= 7
            )));
            $windows[] = [
                'name' => mb_substr(trim((string) ($item['name'] ?? '时段折扣')), 0, 32),
                'days' => $days === [] ? [1, 2, 3, 4, 5, 6, 7] : $days,
                'start' => sprintf('%02d:%02d', intdiv($start, 60), $start % 60),
                'end' => sprintf('%02d:%02d', intdiv($end, 60), $end % 60),
                'discount' => round($discount, 4),
            ];
        }

        return $windows === [] ? null : $windows;
    }

    /**
     * 解析 "HH:MM" 为当日分钟数（"24:00" → 1440 表示全天截止；非法返回 null）。
     */
    protected static function parseWindowTime(mixed $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
            return null;
        }
        $hours = (int) $m[1];
        $minutes = (int) $m[2];
        if ($minutes > 59 || $hours > 24 || ($hours === 24 && $minutes !== 0)) {
            return null;
        }

        return min($hours * 60 + $minutes, 1440);
    }
}

