<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case REFUNDED = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => '待支付',
            self::COMPLETED => '已完成',
            self::FAILED => '失败',
            self::REFUNDED => '已退款',
        };
    }
}
