<?php

namespace App\Enums;

enum ChannelStatus: int
{
    case ENABLED = 1;
    case DISABLED = 2;
    case AUTO_DISABLED = 3;

    public function label(): string
    {
        return match($this) {
            self::ENABLED => "已启用",
            self::DISABLED => "已禁用",
            self::AUTO_DISABLED => "自动禁用",
        };
    }
}
