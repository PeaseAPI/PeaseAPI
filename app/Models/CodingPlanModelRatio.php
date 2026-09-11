<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Coding Plan 模型折算比率表（积分制核心）
 *
 * 将某厂商下不同模型的用量折算为供应商原生单位：
 *  - match_type=exact：模型名全等匹配
 *  - match_type=prefix：模型名前缀匹配（前缀最长者优先）
 *  - cost_mode=per_request：每次请求消耗 unit_cost 个单位
 *  - cost_mode=per_1k_tokens：每 1000 token 消耗 unit_cost 个单位
 *
 * 解析不到匹配时回退：unit_cost=1、per_request。
 */
class CodingPlanModelRatio extends Model
{
    public const MATCH_EXACT = 'exact';

    public const MATCH_PREFIX = 'prefix';

    public const COST_PER_REQUEST = 'per_request';

    public const COST_PER_1K_TOKENS = 'per_1k_tokens';

    /** 计费口径：分段折算（输入/缓存命中/输出三段独立千 token 系数，如智谱 GLM、阿里云 Credits） */
    public const COST_PER_TOKEN_PARTS = 'per_token_parts';

    protected $table = 'coding_plan_model_ratios';

    public $timestamps = false;

    protected $fillable = [
        'vendor',
        'model',
        'match_type',
        'cost_mode',
        'unit_cost',
        'input_rate',
        'cached_rate',
        'output_rate',
        'status',
        'sort',
        'remark',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'unit_cost' => 'float',
        'input_rate' => 'float',
        'cached_rate' => 'float',
        'output_rate' => 'float',
        'status' => 'integer',
        'sort' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    /**
     * 是否为分段折算口径（units 由三段千 token 系数决定，unit_cost 不参与）。
     */
    public function usesSplitRates(): bool
    {
        return $this->cost_mode === self::COST_PER_TOKEN_PARTS;
    }
}
