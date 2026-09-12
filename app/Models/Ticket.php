<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 工单主表：用户在线提交问题，管理端回复/关闭
 *
 * 状态机：
 *  OPEN（新单待处理）→ REPLIED（客服已回复）→ CUSTOMER_REPLY（用户追回，回到待处理）
 *  → 往复 …；任意非关闭态可 CLOSED（用户或管理员关闭），管理员可重开至 OPEN
 */
class Ticket extends Model
{
    public const CATEGORY_GENERAL = 1;

    public const CATEGORY_BILLING = 2;

    public const CATEGORY_TECHNICAL = 3;

    public const CATEGORY_FEATURE = 4;

    public const PRIORITY_LOW = 1;

    public const PRIORITY_NORMAL = 2;

    public const PRIORITY_HIGH = 3;

    public const STATUS_OPEN = 1;

    public const STATUS_REPLIED = 2;

    public const STATUS_CUSTOMER_REPLY = 3;

    public const STATUS_CLOSED = 4;

    /** @var array<int, string> */
    public const CATEGORY_MAP = [
        self::CATEGORY_GENERAL => '综合问题',
        self::CATEGORY_BILLING => '计费账单',
        self::CATEGORY_TECHNICAL => '技术故障',
        self::CATEGORY_FEATURE => '功能建议',
    ];

    /** @var array<int, string> */
    public const PRIORITY_MAP = [
        self::PRIORITY_LOW => '低',
        self::PRIORITY_NORMAL => '普通',
        self::PRIORITY_HIGH => '高',
    ];

    /** @var array<int, string> */
    public const STATUS_MAP = [
        self::STATUS_OPEN => '待处理',
        self::STATUS_REPLIED => '已回复',
        self::STATUS_CUSTOMER_REPLY => '用户追回',
        self::STATUS_CLOSED => '已关闭',
    ];

    protected $table = 'tickets';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'subject',
        'category',
        'priority',
        'status',
        'last_reply_at',
        'created_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'category' => 'integer',
        'priority' => 'integer',
        'status' => 'integer',
        'last_reply_at' => 'integer',
        'created_at' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class)->orderBy('id');
    }

    /** 待处理（新单 + 用户追回）判定，管理端红点口径 */
    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_CUSTOMER_REPLY], true);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
