<?php

namespace App\Enums;

enum SubscriptionDurationUnit: string
{
    case YEAR = 'year';
    case MONTH = 'month';
    case DAY = 'day';
    case HOUR = 'hour';
    case CUSTOM = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::YEAR => '年',
            self::MONTH => '月',
            self::DAY => '天',
            self::HOUR => '小时',
            self::CUSTOM => '自定义',
        };
    }
}
