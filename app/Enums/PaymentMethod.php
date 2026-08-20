<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case STRIPE = 'stripe';
    case CREEM = 'creem';
    case WAFFO = 'waffo';
    case WAFFO_PANCAKE = 'waffo_pancake';
    case BALANCE = 'balance';

    public function label(): string
    {
        return match ($this) {
            self::STRIPE => 'Stripe',
            self::CREEM => 'Creem',
            self::WAFFO => 'Waffo',
            self::WAFFO_PANCAKE => 'Waffo Pancake',
            self::BALANCE => '余额支付',
        };
    }
}
