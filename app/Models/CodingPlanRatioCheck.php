<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Coding Plan 折算比率校对流水
 *
 * 由 coding-plan:verify-ratios（每 6 小时）写入：
 *  - stale_count：超过核对窗口（CodingPlanRatioStaleDays，默认 7 天）未人工复核的启用比率数
 *  - change_count / changes：配置了 pricing_source_url 的供应商与结构化定价源的
 *    diff 结果（新增模型/单位成本变化/源中消失），只记录不自动改价
 * 公开介绍页（/coding-plan）与 /api/coding_plan/offers 展示每供应商最后核对时间与状态。
 */
class CodingPlanRatioCheck extends Model
{
    /** 供应商未配置定价源 */
    public const SOURCE_NONE = 0;

    /** 定价源拉取成功 */
    public const SOURCE_OK = 1;

    /** 定价源拉取失败 */
    public const SOURCE_FAILED = 2;

    protected $table = 'coding_plan_ratio_checks';

    public $timestamps = false;

    protected $fillable = [
        'vendor',
        'checked_at',
        'stale_count',
        'change_count',
        'source_status',
        'changes',
        'pending_keys',
        'created_at',
    ];

    protected $casts = [
        'checked_at' => 'integer',
        'stale_count' => 'integer',
        'change_count' => 'integer',
        'source_status' => 'integer',
        'created_at' => 'integer',
    ];
}
