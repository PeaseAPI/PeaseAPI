<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Coding Plan 供应商官方套餐档位
 *
 * 记录各厂商的订阅档位（如阿里云 Token Plan 个人版 Lite/Standard/Pro、团队版坐席、
 * 智谱 GLM Coding Plan Lite/Pro/Max 等）的官方价格与额度，供公开介绍页展示与管理端维护。
 * 仅展示 status=1 的档位；price 可为 null（官网价格待核对时）。
 */
class CodingPlanVendorTier extends Model
{
    protected $table = 'coding_plan_vendor_tiers';

    public $timestamps = false;

    protected $fillable = [
        'vendor_code',
        'name',
        'price',
        'price_note',
        'period',
        'quota',
        'quota_unit',
        'quota_note',
        'status',
        'sort',
        'remark',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'price' => 'float',
        'quota' => 'float',
        'status' => 'integer',
        'sort' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];
}
