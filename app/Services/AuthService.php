<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 认证服务 - 登录、注册、会话管理
 */
class AuthService
{
    /**
     * 用户注册
     */
    public function register(array $data): User
    {
        $user = User::create([
            'username' => $data['username'],
            'email' => $data['email'] ?? '',
            'password' => Hash::make($data['password']),
            'role' => 1,
            'status' => 1,
            'quota' => 0,
            'group' => 'default',
            'aff_code' => Str::random(16),
            'created_time' => time(),
            'accessed_time' => time(),
        ]);

        return $user;
    }

    /**
     * 用户登录
     */
    public function login(string $username, string $password): ?User
    {
        $user = User::where('username', $username)
            ->orWhere('email', $username)
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        if ($user->status !== 1) {
            return null;
        }

        // 更新最后登录时间
        $user->update([
            'last_login_at' => time(),
            'accessed_time' => time(),
        ]);

        return $user;
    }

        /**
     * 创建会话
     */
    public function createSession(User $user, string $ip, string $userAgent, string $loginMethod = 'password'): UserSession
    {
        $session = UserSession::create([
            'user_id' => $user->id,
            'login_method' => $loginMethod,
            'token' => Str::random(64),
            'ip' => $ip,
            'user_agent' => $userAgent,
            'expires_at' => Carbon::now()->addHours(config('pease-api.auth.session_lifetime', 168)),
        ]);

        return $session;
    }

    /**
     * 验证会话token
     */
    public function validateSession(string $token): ?UserSession
    {
        $session = UserSession::where('token', $token)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        return $session;
    }

    /**
     * 刷新会话
     */
    public function refreshSession(UserSession $session): bool
    {
        $session->update([
            'expires_at' => Carbon::now()->addHours(config('pease-api.auth.session_lifetime', 168)),
        ]);

        return true;
    }

    /**
     * 删除会话
     */
    public function deleteSession(string $token): bool
    {
        return UserSession::where('token', $token)->delete() > 0;
    }

    /**
     * 删除用户所有会话
     */
    public function deleteAllUserSessions(int $userId): bool
    {
        return UserSession::where('user_id', $userId)->delete() > 0;
    }

    /**
     * 删除其他会话（保留当前），返回删除的会话数量
     */
    public function deleteOtherSessions(int $userId, string $currentToken): int
    {
        return UserSession::where('user_id', $userId)
            ->where('token', '!=', $currentToken)
            ->delete();
    }

    /**
     * 验证密码
     */
    public function verifyPassword(User $user, string $password): bool
    {
        return Hash::check($password, $user->password);
    }

    /**
     * 更新密码
     */
    public function updatePassword(User $user, string $newPassword): bool
    {
        $user->update(['password' => Hash::make($newPassword)]);

        return true;
    }

    /**
     * 生成密码重置token
     */
    public function generateResetToken(string $email): ?string
    {
        $user = User::where('email', $email)->first();
        if (! $user) {
            return null;
        }

        $token = Str::random(64);
        $user->update(['access_token' => $token]);

        return $token;
    }

    /**
     * 重置密码
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        $user = User::where('access_token', $token)->first();
        if (! $user) {
            return false;
        }

        $user->update([
            'password' => Hash::make($newPassword),
            'access_token' => '',
        ]);

        return true;
    }

    /**
     * 验证邮箱
     */
    public function verifyEmail(User $user): bool
    {
        $user->update(['email_verified_at' => Carbon::now()]);

        return true;
    }

    /**
     * 发送邮箱验证邮件
     */
    public function sendVerificationEmail(User $user): bool
    {
        // 实际实现发送邮件
        return true;
    }
}
