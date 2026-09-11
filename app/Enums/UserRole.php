<?php

namespace App\Enums;

enum UserRole: int
{
    // 对齐 new-api 角色等级（AdminAuth/RootAuth/InstallController 均按此语义）：
    // 普通用户 = 1，管理员 = 10，超级管理员(Root) = 100
    case USER = 1;
    case GUEST = 3;
    case ADMIN = 10;
    case ROOT = 100;

    public function label(): string
    {
        return match ($this) {
            self::USER => '用户',
            self::GUEST => '访客',
            self::ADMIN => '管理员',
            self::ROOT => '超级管理员',
        };
    }

    public function is_admin(): bool
    {
        return $this === self::ADMIN || $this === self::ROOT;
    }
}
