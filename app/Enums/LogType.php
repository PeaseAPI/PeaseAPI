<?php

namespace App\Enums;

enum LogType: int
{
    case UNKNOWN = 0;
    case TOPUP = 1;
    case CONSUME = 2;
    case MANAGE = 3;
    case SYSTEM = 4;
    case ERROR = 5;
    case REFUND = 6;
    case LOGIN = 7;

    public function label(): string
    {
        return match ($this) {
            self::UNKNOWN => '未知',
            self::TOPUP => '充值',
            self::CONSUME => '消费',
            self::MANAGE => '管理',
            self::SYSTEM => '系统',
            self::ERROR => '错误',
            self::REFUND => '退款',
            self::LOGIN => '登录',
        };
    }
}
