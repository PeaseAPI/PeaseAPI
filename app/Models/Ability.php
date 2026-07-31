<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * 渠道能力表
 *
 * 对齐 Go 版 model/ability.go
 * 表结构: id, `group`, model, channel_id, enabled, priority
 * 一个渠道的每个 (group, model) 组合对应一条记录，用于渠道选择算法快速检索
 *
 * 注意：原表使用复合主键 (group, model, channel_id)，但为了适配 apiResource
 * 路由与前端 CRUD，已通过迁移 000190 添加自增 id 作为主键。
 */
class Ability extends Model
{
    protected $table = 'abilities';
    public $timestamps = false;

    protected $fillable = [
        'group',
        'model',
        'channel_id',
        'enabled',
        'priority',
    ];

    protected $casts = [
        'channel_id' => 'integer',
        'enabled'    => 'integer',
        'priority'   => 'integer',
    ];

    // ===== Scopes =====

    public function scopeEnabled(Builder $q): Builder
    {
        return $q->where('enabled', 1);
    }

    public function scopeByGroup(Builder $q, string $group): Builder
    {
        return $q->where('group', $group);
    }

    public function scopeByModel(Builder $q, string $model): Builder
    {
        return $q->where('model', $model);
    }

    public function scopeByChannel(Builder $q, int $channelId): Builder
    {
        return $q->where('channel_id', $channelId);
    }

    // ===== Relations =====

    public function channel()
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }
}