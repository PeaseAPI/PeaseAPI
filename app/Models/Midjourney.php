<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Midjourney 任务模型
 * 
 * 对标源项目: model/midjourney.go
 * 
 * @property int $id
 * @property int $code
 * @property int $user_id
 * @property string $action
 * @property string $mj_id
 * @property string $prompt
 * @property string $prompt_en
 * @property string $description
 * @property string $state
 * @property int $submit_time
 * @property int $start_time
 * @property int $finish_time
 * @property string $image_url
 * @property string $video_url
 * @property string $video_urls
 * @property string $status
 * @property string $progress
 * @property string $fail_reason
 * @property int $channel_id
 * @property int $quota
 * @property string $buttons
 * @property string $properties
 */
class Midjourney extends Model
{
    use HasFactory;

    protected $table = 'midjourneys';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'user_id',
        'action',
        'mj_id',
        'prompt',
        'prompt_en',
        'description',
        'state',
        'submit_time',
        'start_time',
        'finish_time',
        'image_url',
        'video_url',
        'video_urls',
        'status',
        'progress',
        'fail_reason',
        'channel_id',
        'quota',
        'buttons',
        'properties',
    ];

    protected $casts = [
        'code' => 'integer',
        'user_id' => 'integer',
        'submit_time' => 'integer',
        'start_time' => 'integer',
        'finish_time' => 'integer',
        'channel_id' => 'integer',
        'quota' => 'integer',
    ];

    /**
     * 获取用户关联
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 获取渠道关联
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    /**
     * 根据 Midjourney ID 获取任务 (仅通过 mj_id)
     */
    public static function getByOnlyMjId(string $mjId): ?self
    {
        return static::where('mj_id', $mjId)->first();
    }

    /**
     * 根据用户ID和Midjourney ID获取任务
     */
    public static function getByMjId(int $userId, string $mjId): ?self
    {
        return static::where('user_id', $userId)
            ->where('mj_id', $mjId)
            ->first();
    }

    /**
     * 根据用户ID和多个Midjourney ID获取任务列表
     */
    public static function getByMjIds(int $userId, array $mjIds): array
    {
        return static::where('user_id', $userId)
            ->whereIn('mj_id', $mjIds)
            ->get()
            ->all();
    }

    /**
     * 获取所有未完成的任务 (进度不是 100%)
     */
    public static function getAllUnfinishedTasks(): array
    {
        return static::where('progress', '!=', '100%')->get()->all();
    }

    /**
     * 检查是否有未完成的任务
     */
    public static function hasUnfinishedTasks(): bool
    {
        return static::where('progress', '!=', '100%')->exists();
    }

    /**
     * 根据ID获取任务
     */
    public static function getById(int $id): ?self
    {
        return static::find($id);
    }

    /**
     * 更新进度
     */
    public static function updateProgress(int $id, string $progress): bool
    {
        return static::where('id', $id)->update(['progress' => $progress]) > 0;
    }

    /**
     * 批量更新任务
     */
    public static function bulkUpdate(array $mjIds, array $params): int
    {
        return static::whereIn('mj_id', $mjIds)->update($params);
    }

    /**
     * 批量按任务ID更新
     */
    public static function bulkUpdateByTaskIds(array $taskIds, array $params): int
    {
        return static::whereIn('id', $taskIds)->update($params);
    }

    /**
     * 获取用户的所有任务 (分页)
     */
    public static function getAllUserTasks(
        int $userId,
        int $startIdx = 0,
        int $num = 20,
        array $queryParams = []
    ): array {
        $query = static::where('user_id', $userId);

        if (!empty($queryParams['mj_id'])) {
            $query->where('mj_id', $queryParams['mj_id']);
        }

        if (!empty($queryParams['start_timestamp'])) {
            $query->where('submit_time', '>=', (int) $queryParams['start_timestamp']);
        }

        if (!empty($queryParams['end_timestamp'])) {
            $query->where('submit_time', '<=', (int) $queryParams['end_timestamp']);
        }

        return $query->orderBy('id', 'desc')
            ->offset($startIdx)
            ->limit($num)
            ->get()
            ->all();
    }

    /**
     * 获取所有任务 (管理员)
     */
    public static function getAllTasks(
        int $startIdx = 0,
        int $num = 20,
        array $queryParams = []
    ): array {
        $query = static::query();

        if (!empty($queryParams['channel_id'])) {
            $query->where('channel_id', $queryParams['channel_id']);
        }

        if (!empty($queryParams['mj_id'])) {
            $query->where('mj_id', $queryParams['mj_id']);
        }

        if (!empty($queryParams['start_timestamp'])) {
            $query->where('submit_time', '>=', (int) $queryParams['start_timestamp']);
        }

        if (!empty($queryParams['end_timestamp'])) {
            $query->where('submit_time', '<=', (int) $queryParams['end_timestamp']);
        }

        return $query->orderBy('id', 'desc')
            ->offset($startIdx)
            ->limit($num)
            ->get()
            ->all();
    }

    /**
     * 统计用户任务总数
     */
    public static function countUserTasks(int $userId, array $queryParams = []): int
    {
        $query = static::where('user_id', $userId);

        if (!empty($queryParams['mj_id'])) {
            $query->where('mj_id', $queryParams['mj_id']);
        }

        if (!empty($queryParams['start_timestamp'])) {
            $query->where('submit_time', '>=', (int) $queryParams['start_timestamp']);
        }

        if (!empty($queryParams['end_timestamp'])) {
            $query->where('submit_time', '<=', (int) $queryParams['end_timestamp']);
        }

        return $query->count();
    }

    /**
     * 统计所有任务总数 (管理员)
     */
    public static function countAllTasks(array $queryParams = []): int
    {
        $query = static::query();

        if (!empty($queryParams['channel_id'])) {
            $query->where('channel_id', $queryParams['channel_id']);
        }

        if (!empty($queryParams['mj_id'])) {
            $query->where('mj_id', $queryParams['mj_id']);
        }

        if (!empty($queryParams['start_timestamp'])) {
            $query->where('submit_time', '>=', (int) $queryParams['start_timestamp']);
        }

        if (!empty($queryParams['end_timestamp'])) {
            $query->where('submit_time', '<=', (int) $queryParams['end_timestamp']);
        }

        return $query->count();
    }

    /**
     * 带状态条件更新 (CAS 操作)
     */
    public function updateWithStatus(string $fromStatus): bool
    {
        $result = static::where('status', $fromStatus)
            ->where('id', $this->id)
            ->update($this->attributes);

        return $result > 0;
    }

    /**
     * 获取任务查询参数结构
     */
    public static function getTaskQueryParams(array $params = []): array
    {
        return [
            'channel_id' => $params['channel_id'] ?? '',
            'mj_id' => $params['mj_id'] ?? '',
            'start_timestamp' => $params['start_timestamp'] ?? '',
            'end_timestamp' => $params['end_timestamp'] ?? '',
        ];
    }

    /**
     * 获取按钮数组 (JSON 解码)
     */
    public function getButtonsArray(): array
    {
        if (empty($this->buttons)) {
            return [];
        }

        $decoded = json_decode($this->buttons, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 获取视频 URLs 数组 (JSON 解码)
     */
    public function getVideoUrlsArray(): array
    {
        if (empty($this->video_urls)) {
            return [];
        }

        $decoded = json_decode($this->video_urls, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 获取属性对象 (JSON 解码)
     */
    public function getPropertiesObject(): ?object
    {
        if (empty($this->properties)) {
            return null;
        }

        $decoded = json_decode($this->properties);
        return is_object($decoded) ? $decoded : null;
    }

    /**
     * 检查任务是否完成
     */
    public function isFinished(): bool
    {
        return $this->progress === '100%';
    }

    /**
     * 检查任务是否成功
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'SUCCESS';
    }
}