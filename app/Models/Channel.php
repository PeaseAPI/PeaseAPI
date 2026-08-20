<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    protected $table = 'channels';

    public $timestamps = false;

    // 1:1 对齐 Go 版 model/channel.go 的所有字段
    protected $fillable = [
        'id', 'type', 'key', 'openai_organization', 'test_model', 'status', 'name',
        'weight', 'created_time', 'test_time', 'response_time', 'base_url', 'other',
        'balance', 'balance_updated_time', 'models', 'group', 'used_quota',
        'model_mapping', 'status_code_mapping', 'priority', 'auto_ban', 'other_info',
        'tag', 'setting', 'param_override', 'header_override', 'remark',
        'channel_info', 'settings',
    ];

    protected $casts = [
        'id' => 'integer',
        'type' => 'integer',
        'status' => 'integer',
        'weight' => 'integer',
        'created_time' => 'integer',
        'test_time' => 'integer',
        'response_time' => 'integer',
        'balance' => 'decimal:4',
        'balance_updated_time' => 'integer',
        'used_quota' => 'integer',
        'priority' => 'integer',
        'auto_ban' => 'integer',
        // JSON / 数组字段自动转换
        'models' => 'array',
        'model_mapping' => 'array',
        'status_code_mapping' => 'array',
        'other_info' => 'array',
        'setting' => 'array',
        'param_override' => 'array',
        'header_override' => 'array',
        'channel_info' => 'array',
        'settings' => 'array',
    ];

    // ===== Scopes（对齐 Go 版查询条件）=====

    public function scopeByIds(Builder $q, array $ids): Builder
    {
        return $q->whereIn('id', $ids);
    }

    public function scopeStatus(Builder $q, int $status): Builder
    {
        return $q->where('status', $status);
    }

    public function scopeEnabled(Builder $q): Builder
    {
        return $q->where('status', 1);
    }

    public function scopeDisabled(Builder $q): Builder
    {
        return $q->where('status', 2);
    }

    public function scopeByGroup(Builder $q, string $group): Builder
    {
        // group 字段为逗号分隔多组
        return $q->whereRaw('FIND_IN_SET(?, `group`)', [$group]);
    }

    public function scopeByType(Builder $q, int $type): Builder
    {
        return $q->where('type', $type);
    }

    public function scopeByTag(Builder $q, string $tag): Builder
    {
        return $q->where('tag', $tag);
    }

    public function scopeSearch(Builder $q, ?string $kw): Builder
    {
        if (empty($kw)) {
            return $q;
        }

        return $q->where(function ($sq) use ($kw) {
            $sq->where('id', $kw)
                ->orWhere('name', 'like', "%{$kw}%")
                ->orWhere('tag', 'like', "%{$kw}%")
                ->orWhere('models', 'like', "%{$kw}%");
        });
    }

    // ===== Accessors / Mutators =====

    // models 读取时若为字符串则自动拆分
    public function getModelsAttribute($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (empty($value)) {
            return [];
        }
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return array_filter(array_map('trim', explode("\n", (string) $value)));
    }

    // ===== Relations =====

    /**
     * abilities 表结构: group, model, channel_id, enabled, priority
     * 一个渠道对应多条 ability 记录（每个 group+model 组合一条）
     */
    public function abilities()
    {
        return $this->hasMany(Ability::class, 'channel_id', 'id');
    }

    public function logs()
    {
        return $this->hasMany(Log::class, 'channel_id');
    }

    // ===== 业务辅助方法（对齐 Go 版 channel.go）=====

    /**
     * 获取渠道支持的所有模型（已去重）
     */
    public function getSupportedModels(): array
    {
        $models = $this->models ?? [];

        return array_values(array_unique($models));
    }

    /**
     * 获取渠道所属分组列表
     */
    public function getGroups(): array
    {
        if (empty($this->group)) {
            return ['default'];
        }

        return array_filter(array_map('trim', explode(',', $this->group)));
    }

    /**
     * 获取模型映射后的目标模型名
     */
    public function getModelMapping(string $model): ?string
    {
        $mapping = $this->model_mapping ?? [];

        return $mapping[$model] ?? null;
    }

    /**
     * 根据状态码映射获取上游错误到本地状态的转换
     */
    public function getMappedStatus(int $upstreamStatus): ?int
    {
        $map = $this->status_code_mapping ?? [];

        return $map[(string) $upstreamStatus] ?? null;
    }

    /**
     * 多 Key 支持：key 字段可能为多行，每行一个 key
     */
    public function getKeys(): array
    {
        if (empty($this->key)) {
            return [];
        }

        return array_filter(array_map('trim', explode("\n", $this->key)));
    }
}
