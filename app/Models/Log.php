<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 日志模型 - 对标 new-api model/log.go
 *
 * 表结构为冗余设计：username/token_name/model_name/channel_name/group 直接落库，
 * 列表/统计均基于本表聚合，不定义 Eloquent 关联。
 */
class Log extends Model
{
    /** 0=未知 1=充值 2=消费 3=管理 4=系统 5=错误 6=退款 7=登录（见 logs 迁移注释） */
    public const TYPE_UNKNOWN = 0;

    public const TYPE_TOPUP = 1;

    public const TYPE_CONSUME = 2;

    public const TYPE_MANAGE = 3;

    public const TYPE_SYSTEM = 4;

    public const TYPE_ERROR = 5;

    public const TYPE_REFUND = 6;

    public const TYPE_LOGIN = 7;

    protected $table = 'logs';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'created_at', 'type', 'content', 'username',
        'token_name', 'model_name', 'quota', 'prompt_tokens',
        'completion_tokens', 'use_time', 'is_stream', 'channel_id',
        'channel_name', 'token_id', 'group', 'ip', 'request_id',
        'upstream_request_id', 'other',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'created_at' => 'integer',
        'type' => 'integer',
        'quota' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'use_time' => 'integer',
        'is_stream' => 'boolean',
        'channel_id' => 'integer',
        'token_id' => 'integer',
    ];
}
