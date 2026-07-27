<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Token;
use App\Models\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 用户管理服务
 */
class UserService
{
    /**
     * 创建用户
     */
    public function create(array $data): User
    {
        return User::create([
            'username' => $data['username'],
            'email' => $data['email'] ?? '',
            'password' => Hash::make($data['password']),
            'role' => $data['role'] ?? 1,
            'status' => $data['status'] ?? 1,
            'quota' => $data['quota'] ?? 0,
            'group' => $data['group'] ?? 'default',
            'aff_code' => $data['aff_code'] ?? Str::random(16),
            'inviter_id' => $data['inviter_id'] ?? 0,
            'created_time' => time(),
            'accessed_time' => time(),
        ]);
    }

    /**
     * 更新用户
     */
    public function update(User $user, array $data): bool
    {
        $allowedFields = ['email', 'group', 'status', 'quota', 'role'];
        $updateData = [];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }
        
        if (!empty($updateData)) {
            $user->update($updateData);
        }
        
        return true;
    }

    /**
     * 删除用户
     */
    public function delete(User $user): bool
    {
        // 删除相关数据
        Token::where('user_id', $user->id)->delete();
        Log::where('user_id', $user->id)->delete();
        
        $user->delete();
        
        return true;
    }

    /**
     * 搜索用户
     */
    public function search(string $keyword, int $perPage = 20)
    {
        return User::where('username', 'like', "%{$keyword}%")
            ->orWhere('email', 'like', "%{$keyword}%")
            ->paginate($perPage);
    }

    /**
     * 获取用户列表
     */
    public function list(int $perPage = 20, string $orderBy = 'id', string $orderDir = 'desc')
    {
        return User::orderBy($orderBy, $orderDir)->paginate($perPage);
    }

    /**
     * 查找用户
     */
    public function find(int $id): ?User
    {
        return User::find($id);
    }

    /**
     * 通过用户名查找
     */
    public function findByUsername(string $username): ?User
    {
        return User::where('username', $username)->first();
    }

    /**
     * 通过邮箱查找
     */
    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    /**
     * 更新用户配额
     */
    public function updateQuota(User $user, int $amount): bool
    {
        $user->update(['quota' => $amount]);
        return true;
    }

    /**
     * 增加用户配额
     */
    public function addQuota(User $user, int $amount): bool
    {
        $user->increment('quota', $amount);
        return true;
    }

    /**
     * 扣除用户配额
     */
    public function deductQuota(User $user, int $amount): bool
    {
        if ($user->quota < $amount) {
            return false;
        }
        
        $user->decrement('quota', $amount);
        $user->increment('used_quota', $amount);
        
        return true;
    }

    /**
     * 更新用户分组
     */
    public function updateGroup(User $user, string $group): bool
    {
        $user->update(['group' => $group]);
        return true;
    }

    /**
     * 更新用户状态
     */
    public function updateStatus(User $user, int $status): bool
    {
        $user->update(['status' => $status]);
        return true;
    }

    /**
     * 更新用户角色
     */
    public function updateRole(User $user, int $role): bool
    {
        $user->update(['role' => $role]);
        return true;
    }

    /**
     * 获取用户统计信息
     */
    public function getStats(User $user): array
    {
        return [
            'total_tokens' => Token::where('user_id', $user->id)->count(),
            'active_tokens' => Token::where('user_id', $user->id)->where('status', 1)->count(),
            'total_requests' => Log::where('user_id', $user->id)->count(),
            'total_quota' => $user->quota + $user->used_quota,
            'used_quota' => $user->used_quota,
            'remaining_quota' => $user->quota,
        ];
    }

    /**
     * 获取用户邀请码
     */
    public function getAffCode(User $user): string
    {
        if (empty($user->aff_code)) {
            $user->update(['aff_code' => Str::random(16)]);
        }
        
        return $user->aff_code;
    }

    /**
     * 处理邀请关系
     */
    public function processInvite(User $user, string $affCode): bool
    {
        $inviter = User::where('aff_code', $affCode)->first();
        
        if (!$inviter || $inviter->id === $user->id) {
            return false;
        }
        
        $user->update(['inviter_id' => $inviter->id]);
        
        // 给邀请者增加配额奖励
        $inviteBonus = config('pease-api.billing.invite_bonus', 1000);
        $inviter->increment('quota', $inviteBonus);
        
        return true;
    }

    /**
     * 获取用户邀请列表
     */
    public function getInvitedUsers(User $user, int $perPage = 20)
    {
        return User::where('inviter_id', $user->id)
            ->orderBy('created_time', 'desc')
            ->paginate($perPage);
    }

    /**
     * 获取用户邀请人数
     */
    public function getInviteCount(User $user): int
    {
        return User::where('inviter_id', $user->id)->count();
    }

    /**
     * 批量更新用户状态
     */
    public function batchUpdateStatus(array $userIds, int $status): int
    {
        return User::whereIn('id', $userIds)->update(['status' => $status]);
    }

    /**
     * 批量删除用户
     */
    public function batchDelete(array $userIds): int
    {
        // 先删除相关数据
        Token::whereIn('user_id', $userIds)->delete();
        Log::whereIn('user_id', $userIds)->delete();
        
        return User::whereIn('id', $userIds)->delete();
    }

    /**
     * 检查用户名是否存在
     */
    public function usernameExists(string $username): bool
    {
        return User::where('username', $username)->exists();
    }

    /**
     * 检查邮箱是否存在
     */
    public function emailExists(string $email): bool
    {
        return User::where('email', $email)->exists();
    }

    /**
     * 获取用户使用日志
     */
    public function getUsageLogs(User $user, int $perPage = 50)
    {
        return Log::where('user_id', $user->id)
            ->orderBy('created_time', 'desc')
            ->paginate($perPage);
    }
}