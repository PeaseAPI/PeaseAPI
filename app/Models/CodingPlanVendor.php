<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Coding Plan 供应商元配置
 *
 * 记录各厂商（智谱 GLM、火山方舟、MiniMax、Kimi 等）的默认计费模式、
 * 计数单位与「供应商单位 → 平台积分」的折算汇率。
 * 账号级（coding_plan_accounts）的同名字段可覆盖此默认值。
 */
class CodingPlanVendor extends Model
{
    public const BILLING_MODE_PER_REQUEST = 1;

    public const BILLING_MODE_CREDIT = 2;

    /** 产品类型：订阅制 Coding Plan（包月/包量，按次或资源点折算） */
    public const PLAN_KIND_CODING = 1;

    /** 产品类型：按量 Token Plan（按 token 用量折算扣减） */
    public const PLAN_KIND_TOKEN = 2;

    protected $table = 'coding_plan_vendors';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'logo',
        'billing_mode',
        'plan_kind',
        'unit_name',
        'unit_exchange_rate',
        'docs_url',
        'pricing_source_url',
        'status',
        'sort',
        'remark',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'billing_mode' => 'integer',
        'plan_kind' => 'integer',
        'unit_exchange_rate' => 'float',
        'status' => 'integer',
        'sort' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    /**
     * 产品类型显示名（订阅制 Coding Plan / 按量 Token Plan）
     */
    public function planKindLabel(): string
    {
        return (int) $this->plan_kind === self::PLAN_KIND_TOKEN ? '按量 Token Plan' : '订阅制 Coding Plan';
    }

    public function accounts()
    {
        return $this->hasMany(CodingPlanAccount::class, 'vendor', 'code');
    }

    public function modelRatios()
    {
        return $this->hasMany(CodingPlanModelRatio::class, 'vendor', 'code');
    }

    public function tiers()
    {
        return $this->hasMany(CodingPlanVendorTier::class, 'vendor_code', 'code');
    }
}
