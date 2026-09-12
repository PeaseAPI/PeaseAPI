<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 汇率配置（币种 → 人民币）
 *
 * 每行记录 1 单位某币种折合多少人民币（基准 CNY）。
 * 无行的币种按 CurrencyExchangeService 的回落规则取值（USD→Option UsdExchangeRate）。
 */
class CurrencyRate extends Model
{
    /** 来源：管理端手工录入 */
    public const SOURCE_MANUAL = 'manual';

    /** 来源：API 自动同步（预留） */
    public const SOURCE_API = 'api';

    protected $table = 'currency_rates';

    public $timestamps = false;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'rate', 'source', 'remark', 'updated_at'];
}
