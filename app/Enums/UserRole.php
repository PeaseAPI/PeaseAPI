<?php

namespace App\Enums;

enum UserRole: int
{
    case ADMIN = 1;
    case USER = 2;
    case GUEST = 3;

    public function label(): string
    {
        return match($this) {
            self::ADMIN => '管理员',
            self::USER => '用户',
            self::GUEST => '访客',
        };
    }

    public function is_admin(): bool
    {
        return $this === self::ADMIN;
    }
}