<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 双因素认证模型
 * 对标 new-api model/two_fa.go
 */
class TwoFA extends Model
{
    protected $table = 'two_fa';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'secret',
        'backup_codes',
        'enabled',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [
        'secret',
        'backup_codes',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'created_at' => 'integer',
        'updated_at' => 'integer',
        'user_id' => 'integer',
        'backup_codes' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}