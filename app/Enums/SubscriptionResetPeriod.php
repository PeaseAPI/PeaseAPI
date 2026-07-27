<?php

namespace App\Enums;

enum SubscriptionResetPeriod: string
{
    case NEVER = 'never';
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case CUSTOM = 'custom';

    public function label(): string
    {
        return match($this) {
            self::NEVER => '从不',
            self::DAILY => '每天',
            self::WEEKLY => '每周',
            self::MONTHLY => '每月',
            self::CUSTOM => '自定义',
        };
    }
}