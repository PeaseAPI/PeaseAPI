<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 工单会话流水：is_admin 区分客服与用户发言；工单首条内容同走本表保证会话完整
 */
class TicketReply extends Model
{
    protected $table = 'ticket_replies';

    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'is_admin',
        'content',
        'created_at',
    ];

    protected $casts = [
        'ticket_id' => 'integer',
        'user_id' => 'integer',
        'is_admin' => 'boolean',
        'created_at' => 'integer',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
