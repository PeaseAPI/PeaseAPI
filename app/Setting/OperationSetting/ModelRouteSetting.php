<?php

declare(strict_types=1);

namespace App\Setting\OperationSetting;

use App\Services\CostRouteService;
use App\Services\OptionService;

/**
 * 模型路由策略设置（P9-1 成本感知路由）
 *
 * Option 键 ModelRouteStrategy：
 *  - static（默认）：原静态调度（priority 降序 + 随机），保护现网行为
 *  - cost_first：同模型多渠道候选按「每 1k tokens 平台成本」升序选择（CostRouteService）
 */
class ModelRouteSetting
{
    /**
     * 当前路由策略（非法配置值回退 static）
     */
    public static function strategy(): string
    {
        $strategy = (string) OptionService::get('ModelRouteStrategy', CostRouteService::STRATEGY_STATIC);

        return in_array($strategy, [CostRouteService::STRATEGY_STATIC, CostRouteService::STRATEGY_COST_FIRST], true)
            ? $strategy
            : CostRouteService::STRATEGY_STATIC;
    }
}
