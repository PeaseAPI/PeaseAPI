<?php

declare(strict_types=1);

namespace App\Enums;

enum SystemTaskStatus: int
{
    case Pending = 1;
    case Running = 2;
    case Done = 3;
    case Failed = 4;

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待处理',
            self::Running => '执行中',
            self::Done => '已完成',
            self::Failed => '失败',
        };
    }
}
