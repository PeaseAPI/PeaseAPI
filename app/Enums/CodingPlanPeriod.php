<?php

namespace App\Enums;

/**
 * Coding Plan 计费周期类型
 */
enum CodingPlanPeriod: string
{
    case ROLLING_5H = '5h';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::ROLLING_5H => '5小时滚动',
            self::WEEKLY => '每周',
            self::MONTHLY => '每月',
        };
    }

    /**
     * 该周期的重置间隔（秒）
     */
    public function resetSeconds(): int
    {
        return match ($this) {
            self::ROLLING_5H => 5 * 3600,
            self::WEEKLY => 7 * 24 * 3600,
            self::MONTHLY => 30 * 24 * 3600,
        };
    }
}