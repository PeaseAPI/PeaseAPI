<?php

declare(strict_types=1);

namespace App\Enums;

enum SystemTaskType: int
{
    case LogCleanup = 1;

    public function label(): string
    {
        return match ($this) {
            self::LogCleanup => '日志清理',
        };
    }
}
