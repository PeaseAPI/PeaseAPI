<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 订阅计划表
 * 对标 new-api model/subscription_plans.go
 */
class SubscriptionPlan extends Model
{
    protected $table = 'subscription_plans';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'description',
        'price',
        'currency',
        'quota',
        'duration',
        'duration_unit',
        'reset_period',
        'features',
        'status',
        'stripe_price_id',
        'creem_product_id',
        'waffo_product_id',
        'sort',
        // Coding Plan 类型套餐字段
        'plan_type',
        'coding_vendor',
        'coding_submits_per_request',
        'coding_quota',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quota' => 'integer',
        'duration' => 'integer',
        'status' => 'integer',
        'sort' => 'integer',
        'features' => 'array',
        'plan_type' => 'string',
        'coding_submits_per_request' => 'integer',
        'coding_quota' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    /**
     * 是否为 Coding Plan 类型套餐（按提交次数计费）
     */
    public function isCodingPlan(): bool
    {
        return ($this->plan_type ?? 'quota') === 'coding_plan';
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    public function activeSubscriptions()
    {
        return $this->hasMany(Subscription::class, 'plan_id')->where('status', 1);
    }

    /**
     * 判断 subscription_plans 表是否拥有指定列（结果静态缓存）。
     * 兼容从 Go 版导入的旧表结构（可能缺少 status/sort 等列）。
     */
    public static function hasColumnSafe(string $column): bool
    {
        static $cache = [];
        if (isset($cache[$column])) {
            return $cache[$column];
        }
        try {
            return $cache[$column] = \Illuminate\Support\Facades\Schema::hasColumn('subscription_plans', $column);
        } catch (\Throwable $e) {
            // 查询失败时保守返回 false，避免阻断页面
            return $cache[$column] = false;
        }
    }

    /**
     * 获取启用中的订阅计划（容错：旧表无 status/sort 列时回退为全表查询）。
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function getActivePlans()
    {
        $query = static::query();
        if (static::hasColumnSafe('status')) {
            $query->where('status', 1);
        }
        if (static::hasColumnSafe('sort')) {
            $query->orderBy('sort');
        }
        $query->orderBy('id');

        return $query->get();
    }

    /**
     * 根据主键获取启用中的计划（容错）。
     */
    public static function findActiveById(int $planId): ?self
    {
        $query = static::where('id', $planId);
        if (static::hasColumnSafe('status')) {
            $query->where('status', 1);
        }

        return $query->first();
    }
}
