<?php

namespace App\Services;

use App\Models\Log;
use Illuminate\Database\Eloquent\Builder;

class LogService
{
    public function getLogs(array $filters = [], int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = Log::with(['user', 'token', 'channel', 'ability']);

        if (!empty($filters['user_id'])) $query->where('user_id', $filters['user_id']);
        if (!empty($filters['token_id'])) $query->where('token_id', $filters['token_id']);
        if (!empty($filters['channel_id'])) $query->where('channel_id', $filters['channel_id']);
        if (!empty($filters['type'])) $query->where('type', $filters['type']);
        if (!empty($filters['model'])) $query->where('model', $filters['model']);
        if (!empty($filters['start_time'])) $query->where('created_at', '>=', $filters['start_time']);
        if (!empty($filters['end_time'])) $query->where('created_at', '<=', $filters['end_time']);
        if (!empty($filters['request_id'])) $query->where('request_id', $filters['request_id']);

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function createLog(array $data): Log
    {
        return Log::create($data);
    }

    public function deleteOldLogs(int $days = 30): int
    {
        $cutoff = time() - ($days * 86400);
        return Log::where('created_at', '<', $cutoff)->delete();
    }
}