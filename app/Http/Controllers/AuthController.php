<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\TwoFA;
use App\Models\User;
use App\Models\UserSession;
use App\Services\AuthService;
use App\Services\EmailService;
use App\Services\OptionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly OptionService $optionService,
        private readonly EmailService $emailService,
    ) {}

    /**
     * Format a User model as the AuthUser object expected by the frontend.
     *
     * Matches the {@link AuthUser} TypeScript interface which includes
     * `sidebar_modules` (extracted from the user's `setting` JSON) and
     * `permissions` (role-derived capability flags used by the sidebar
     * module overlay).
     */
    private function formatAuthUser(User $user): array
    {
        $setting = is_string($user->setting)
            ? (json_decode($user->setting, true) ?? [])
            : ($user->setting ?? []);

        return [
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'group' => $user->group,
            'quota' => $user->quota,
            'used_quota' => $user->used_quota,
            'request_count' => $user->request_count,
            'aff_code' => $user->aff_code,
            'aff_count' => $user->aff_count ?? 0,
            'aff_quota' => $user->aff_quota ?? 0,
            'aff_history_quota' => $user->aff_history ?? 0,
            'inviter_id' => $user->inviter_id,
            'github_id' => $user->github_id,
            'discord_id' => $user->discord_id,
            'oidc_id' => $user->oidc_id,
            'wechat_id' => $user->wechat_id,
            'telegram_id' => $user->telegram_id,
            'linux_do_id' => $user->linux_do_id,
            'language' => $setting['language'] ?? null,
            'setting' => $user->setting,
            'stripe_customer' => $user->stripe_customer ?? null,
            'sidebar_modules' => $setting['sidebar_modules'] ?? null,
            'permissions' => [
                'sidebar_settings' => $user->role !== 100,
            ],
        ];
    }

    /**
     * Format a UserSession model as the LoginSession object expected by the frontend.
     *
     * Matches the {@link LoginSession} TypeScript interface.
     */
    private function formatLoginSession(UserSession $session, bool $isCurrent = true): array
    {
        $expiresAt = $session->expires_at;
        $createdAt = $session->created_at;
        $updatedAt = $session->updated_at;

        return [
            'sid' => $session->token,
            'current' => $isCurrent,
            'login_method' => $session->login_method ?? 'password',
            'ip' => $session->ip,
            'user_agent' => $session->user_agent,
            'created_at' => $createdAt instanceof \Carbon\Carbon
                ? $createdAt->timestamp
                : (int) $createdAt,
            'last_active_at' => $updatedAt instanceof \Carbon\Carbon
                ? $updatedAt->timestamp
                : (int) ($updatedAt ?? time()),
            'expires_at' => $expiresAt instanceof \Carbon\Carbon
                ? $expiresAt->timestamp
                : (int) $expiresAt,
        ];
    }

    /**
     * Build the full AuthBundle expected by the frontend.
     *
     * Matches the {@link AuthBundle} TypeScript interface:
     *   access_token, token_type, access_expires_at, user, session
     */
    private function formatAuthBundle(User $user, UserSession $session): array
    {
        $expiresAt = $session->expires_at;
        $expiresTimestamp = $expiresAt instanceof \Carbon\Carbon
            ? $expiresAt->timestamp
            : (int) $expiresAt;

        return [
            'access_token' => $session->token,
            'token_type' => 'Bearer',
            'access_expires_at' => $expiresTimestamp,
            'user' => $this->formatAuthUser($user),
            'session' => $this->formatLoginSession($session),
        ];
    }

    /**
     * Create a login response with AuthBundle and session cookie.
     */
    private function loginSuccessResponse(User $user, UserSession $session): JsonResponse
    {
        $lifetime = config('pease-api.auth.session_lifetime', 168) * 60;

        return response()->json([
            'success' => true,
            'message' => __('Login successful'),
            'data' => $this->formatAuthBundle($user, $session),
        ])->cookie('session', $session->token, $lifetime, '/', '', false, true);
    }

    /**
     * 用户注册
     */
    public function register(Request $request): JsonResponse
    {
        // 检查注册开关
        if (! $this->optionService->get('RegisterEnabled', true)) {
            return response()->json(['success' => false, 'message' => __('Registration is disabled')], 403);
        }

        $rules = [
            'username' => 'required|string|min:3|max:32|regex:/^[a-zA-Z0-9_]+$/',
            'password' => 'required|string|min:8|max:64',
        ];

        // 邮箱验证开关
        $emailVerificationEnabled = $this->optionService->get('EmailVerificationEnabled', false);
        $passwordRegisterEnabled = $this->optionService->get('PasswordRegisterEnabled', true);

        if (! $passwordRegisterEnabled) {
            return response()->json(['success' => false, 'message' => __('Password registration is disabled')], 403);
        }

        if ($emailVerificationEnabled) {
            $rules['email'] = 'required|email|unique:users,email';
            $rules['verification_code'] = 'required|string|size:6';
        } else {
            $rules['email'] = 'nullable|email|unique:users,email';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        // 校验邮箱验证码（统一走 EmailService）
        if ($emailVerificationEnabled) {
            if (! $this->emailService->verifyCode($request->input('email'), (string) $request->input('verification_code'))) {
                return response()->json(['success' => false, 'message' => __('Email verification code is invalid or expired')], 422);
            }
        }

        // 邀请码处理
        $inviterId = 0;
        $affCode = $request->input('aff_code');
        if ($affCode) {
            $inviter = User::where('aff_code', $affCode)->first();
            if ($inviter) {
                $inviterId = $inviter->id;
            }
        }

        $defaultQuota = (int) $this->optionService->get('QuotaForNewUser', 0);

        DB::beginTransaction();
        try {
            $user = User::create([
                'username' => $request->input('username'),
                'email' => $request->input('email', ''),
                'password' => Hash::make($request->input('password')),
                'display_name' => $request->input('username'),
                'role' => UserRole::USER->value,
                'status' => 1,
                'quota' => $defaultQuota,
                'used_quota' => 0,
                'request_count' => 0,
                'group' => 'default',
                'aff_code' => strtoupper(Str::random(8)),
                'inviter_id' => $inviterId,
                'created_time' => time(),
                'last_login_at' => time(),
            ]);

            // 邀请奖励
            if ($inviterId > 0) {
                $inviterBonus = (int) $this->optionService->get('QuotaForInviter', 0);
                $inviteeBonus = (int) $this->optionService->get('QuotaForInvitee', 0);
                if ($inviterBonus > 0) {
                    User::where('id', $inviterId)->increment('aff_quota', $inviterBonus);
                }
                if ($inviteeBonus > 0) {
                    $user->increment('quota', $inviteeBonus);
                }
            }

            $session = $this->authService->createSession($user, $request->ip(), $request->userAgent());
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('注册失败: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => __('Registration failed')], 500);
        }

        return response()->json([
            'success' => true,
            'message' => __('Registration successful'),
            'data' => [
                'user' => $user,
                'session_token' => $session->token,
            ],
        ], 201)->cookie('session', $session->token, config('pease-api.auth.session_lifetime', 168) * 60, '/', '', false, true);
    }

    /**
     * 用户登录
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $username = $request->input('username');
        $password = $request->input('password');

        // 登录限流 (基于 IP + 用户名)
        $rateKey = 'login_attempts:'.$request->ip().':'.md5($username);
        $attempts = (int) Cache::get($rateKey, 0);
        $maxAttempts = (int) $this->optionService->get('MaxLoginAttempts', 5);
        if ($attempts >= $maxAttempts) {
            return response()->json(['success' => false, 'message' => __('Too many login attempts, please try again later')], 429);
        }

        $user = User::where('username', $username)->first();
        if (! $user) {
            $user = User::where('email', $username)->first();
        }

        if (! $user || ! Hash::check($password, $user->password)) {
            Cache::put($rateKey, $attempts + 1, Carbon::now()->addMinutes(15));

            return response()->json(['success' => false, 'message' => __('Invalid username or password')], 401);
        }

        if ($user->status !== 1) {
            return response()->json(['success' => false, 'message' => __('Account is disabled')], 403);
        }

        Cache::forget($rateKey);

        // 检查 2FA
        $twoFA = TwoFA::where('user_id', $user->id)->where('enabled', 1)->first();
        if ($twoFA) {
            // 生成临时登录令牌，用于 2FA 验证
            $pendingToken = Str::random(32);
            Cache::put('2fa_pending:'.$pendingToken, $user->id, Carbon::now()->addMinutes(10));

            return response()->json([
                'success' => true,
                'message' => __('Two-factor authentication required'),
                'data' => ['require_2fa' => true, 'flow_token' => $pendingToken],
            ]);
        }

        $session = $this->authService->createSession($user, $request->ip(), $request->userAgent());
        $user->update(['last_login_at' => time()]);

        return $this->loginSuccessResponse($user, $session);
    }

    /**
     * 2FA 登录验证
     */
    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'flow_token' => 'required|string',
            'code' => 'required|string|size:6',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $userId = Cache::get('2fa_pending:'.$request->input('flow_token'));
        if (! $userId) {
            return response()->json(['success' => false, 'message' => __('Login token has expired, please log in again')], 401);
        }

        $twoFA = TwoFA::where('user_id', $userId)->where('enabled', 1)->first();
        if (! $twoFA) {
            return response()->json(['success' => false, 'message' => __('2FA is not enabled')], 400);
        }

        $totp = TOTP::createFromSecret($twoFA->secret);
        $valid = $totp->verify($request->input('code'));
        if (! $valid) {
            // 检查备份码
            $backupCodes = json_decode($twoFA->backup_codes ?? '[]', true);
            $codeIndex = array_search($request->input('code'), $backupCodes, true);
            if ($codeIndex === false) {
                return response()->json(['success' => false, 'message' => __('Invalid verification code')], 401);
            }
            array_splice($backupCodes, $codeIndex, 1);
            $twoFA->update(['backup_codes' => json_encode($backupCodes)]);
        }

            Cache::forget('2fa_pending:'.$request->input('flow_token'));

        $user = User::findOrFail($userId);
        $session = $this->authService->createSession($user, $request->ip(), $request->userAgent(), '2fa');
        $user->update(['last_login_at' => time()]);

        return $this->loginSuccessResponse($user, $session);
    }

    /**
     * 退出登录
     */
    public function logout(Request $request): JsonResponse
    {
        // Support both cookie and X-Auth-Session header
        $sessionToken = $request->header('X-Auth-Session') ?: $request->cookie('session');
        if ($sessionToken) {
            $this->authService->deleteSession($sessionToken);
        }

        return response()->json(['success' => true, 'message' => __('Logged out')])
            ->cookie('session', '', -1, '/', '', false, true);
    }

    /**
     * 刷新会话
     */
    public function refresh(Request $request): JsonResponse
    {
        // Support both cookie and X-Auth-Session header
        $sessionToken = $request->header('X-Auth-Session') ?: $request->cookie('session');
        if (! $sessionToken) {
            return response()->json(['success' => false, 'message' => __('Not logged in')], 401);
        }
        $session = $this->authService->validateSession($sessionToken);
        if (! $session) {
            return response()->json(['success' => false, 'message' => __('Session has expired')], 401);
        }
        $this->authService->refreshSession($session);

        // Reload session to get updated expires_at
        $session->refresh();

        $user = User::find($session->user_id);
        if (! $user) {
            return response()->json(['success' => false, 'message' => __('User not found')], 401);
        }

        return response()->json([
            'success' => true,
            'message' => __('Session refreshed'),
            'data' => $this->formatAuthBundle($user, $session),
        ]);
    }

    /**
     * 当前用户
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $request->user()]);
    }

    /**
     * 发送密码重置邮件
     */
    public function sendResetLink(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $email = $request->input('email');
        // 限流
        $rateKey = 'reset_email:'.$email;
        if (Cache::has($rateKey)) {
            return response()->json(['success' => false, 'message' => __('Too many requests, please try again later.')], 429);
        }
        Cache::put($rateKey, 1, Carbon::now()->addMinutes(1));

        // 即使邮箱不存在也返回成功，避免用户枚举
        $user = User::where('email', $email)->first();
        if ($user) {
            try {
                $this->emailService->sendPasswordReset($email);
            } catch (\Throwable $e) {
                Log::error('发送重置邮件失败: '.$e->getMessage());
            }
        }

        return response()->json(['success' => true, 'message' => __('If the email exists, a reset email has been sent')]);
    }

    /**
     * 重置密码
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'password' => 'required|string|min:8|max:64',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $email = $this->emailService->consumePasswordResetToken($request->input('token'));
        if (! $email) {
            return response()->json(['success' => false, 'message' => __('Invalid reset token')], 400);
        }
        $user = User::where('email', $email)->first();
        if (! $user) {
            return response()->json(['success' => false, 'message' => __('User does not exist')], 404);
        }
        $user->update(['password' => Hash::make($request->input('password'))]);
        // 撤销该用户所有会话，强制重新登录
        $this->authService->deleteOtherSessions($user->id, '');

        return response()->json(['success' => true, 'message' => '密码已重置']);
    }

    /**
     * 发送邮箱验证码
     */
    public function sendVerificationEmail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $email = $request->input('email');
        if (User::where('email', $email)->exists()) {
            return response()->json(['success' => false, 'message' => __('Email is already registered')], 422);
        }

        $rateKey = 'verify_email:'.$email;
        if (Cache::has($rateKey)) {
            return response()->json(['success' => false, 'message' => __('Too many requests, please try again later.')], 429);
        }
        Cache::put($rateKey, 1, Carbon::now()->addMinutes(1));

        try {
            $this->emailService->sendVerificationCode($email);
        } catch (\Throwable $e) {
            Log::error('发送验证邮件失败: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => __('Failed to send email')], 500);
        }

        return response()->json(['success' => true, 'message' => __('Verification code sent')]);
    }

    /**
     * 统一安全验证
     */
    public function verify(Request $request): JsonResponse
    {
        $allowedTypes = ['email', 'sms', 'totp', 'passkey', 'password'];
        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:'.implode(',', $allowedTypes),
            'target' => 'nullable|string',
            'code' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => __('Not logged in')], 401);
        }

        $type = $request->input('type');
        $code = $request->input('code');
        $target = $request->input('target');

        $verifiedKey = 'secure_verified:'.$user->id.':'.$type;
        $codeKey = 'secure_code:'.$user->id.':'.$type;

        // 验证码校验
        $cachedCode = Cache::get($codeKey);
        if (! $cachedCode || ! hash_equals((string) $cachedCode, (string) $code)) {
            return response()->json(['success' => false, 'message' => __('Verification code is invalid or expired')], 401);
        }
        Cache::forget($codeKey);

        // 标记已验证，5分钟有效
        Cache::put($verifiedKey, true, Carbon::now()->addMinutes(5));

        return response()->json(['success' => true, 'message' => __('Verification passed')]);
    }

    /**
     * 获取登录会话列表
     */
    public function sessions(Request $request): JsonResponse
    {
        $sessions = UserSession::where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get(['id', 'ip', 'user_agent', 'created_at', 'updated_at', 'expires_at'])
            ->map(function ($s) use ($request) {
                $s->is_current = $s->token === $request->cookie('session');

                return $s;
            });

        return response()->json(['success' => true, 'data' => $sessions]);
    }

    /**
     * 删除指定会话
     */
    public function deleteSession(Request $request, int $sid): JsonResponse
    {
        $session = UserSession::where('id', $sid)
            ->where('user_id', $request->user()->id)
            ->first();
        if (! $session) {
            return response()->json(['success' => false, 'message' => __('Session does not exist')], 404);
        }
        $session->delete();

        return response()->json(['success' => true, 'message' => __('Deleted')]);
    }

    /**
     * 撤销其他会话
     */
    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $current = $request->cookie('session');
        $count = $this->authService->deleteOtherSessions($request->user()->id, $current ?? '');

        return response()->json(['success' => true, 'message' => __('Revoked :count sessions', ['count' => $count])]);
    }

    /**
     * 2FA 状态
     */
    public function twoFactorStatus(Request $request): JsonResponse
    {
        $twoFA = TwoFA::where('user_id', $request->user()->id)->first();

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => $twoFA && $twoFA->enabled,
                'backup_codes_remaining' => $twoFA ? count(json_decode($twoFA->backup_codes ?? '[]', true)) : 0,
            ],
        ]);
    }

    /**
     * 2FA 设置（生成 secret 和 QR）
     */
    public function twoFactorSetup(Request $request): JsonResponse
    {
        $secret = Base32::encodeUpper(random_bytes(20));
        $user = $request->user();
        $company = $this->optionService->get('SystemName', config('app.name'));

        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($user->email ?: $user->username);
        $totp->setIssuer($company);
        $qrUrl = $totp->getProvisioningUri();

        // 暂存 secret 待启用
        Cache::put('2fa_setup:'.$user->id, $secret, Carbon::now()->addMinutes(10));

        return response()->json([
            'success' => true,
            'data' => ['secret' => $secret, 'qr_url' => $qrUrl],
        ]);
    }

    /**
     * 2FA 启用
     */
    public function twoFactorEnable(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|size:6',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $user = $request->user();
        $secret = Cache::get('2fa_setup:'.$user->id);
        if (! $secret) {
            return response()->json(['success' => false, 'message' => __('Please call the settings API first')], 400);
        }

        $totp = TOTP::createFromSecret($secret);
        if (! $totp->verify($request->input('code'))) {
            return response()->json(['success' => false, 'message' => __('Invalid verification code')], 401);
        }

        // 生成备份码
        $backupCodes = [];
        for ($i = 0; $i < 10; $i++) {
            $backupCodes[] = strtoupper(Str::random(8));
        }

        TwoFA::updateOrCreate(
            ['user_id' => $user->id],
            ['secret' => $secret, 'enabled' => 1, 'backup_codes' => json_encode($backupCodes)]
        );
        Cache::forget('2fa_setup:'.$user->id);

        return response()->json([
            'success' => true,
            'message' => __('2FA has been enabled'),
            'data' => ['backup_codes' => $backupCodes],
        ]);
    }

    /**
     * 2FA 禁用
     */
    public function twoFactorDisable(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $user = $request->user();
        if (! Hash::check($request->input('password'), $user->password)) {
            return response()->json(['success' => false, 'message' => __('Incorrect password')], 401);
        }
        TwoFA::where('user_id', $user->id)->delete();

        return response()->json(['success' => true, 'message' => __('2FA has been disabled')]);
    }

    /**
     * 重新生成备份码
     */
    public function regenerateBackupCodes(Request $request): JsonResponse
    {
        $user = $request->user();
        $twoFA = TwoFA::where('user_id', $user->id)->where('enabled', 1)->first();
        if (! $twoFA) {
            return response()->json(['success' => false, 'message' => __('2FA is not enabled')], 400);
        }
        $backupCodes = [];
        for ($i = 0; $i < 10; $i++) {
            $backupCodes[] = strtoupper(Str::random(8));
        }
        $twoFA->update(['backup_codes' => json_encode($backupCodes)]);

        return response()->json(['success' => true, 'data' => ['backup_codes' => $backupCodes]]);
    }

    /**
     * 更新用户设置
     */
    public function updateSetting(Request $request): JsonResponse
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'display_name' => 'nullable|string|max:32',
            'email' => 'nullable|email|unique:users,email,'.$user->id,
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }
        $user->update($request->only(['display_name', 'email']));

        return response()->json(['success' => true, 'message' => __('Settings updated')]);
    }

    /**
     * 获取用户分组
     */
    public function groups(Request $request): JsonResponse
    {
        $user = $request->user();
        $groups = [$user->group => '默认分组'];
        $usableGroups = $this->optionService->get('UserUsableGroups', '');
        if ($usableGroups) {
            $parsed = json_decode($usableGroups, true);
            if (is_array($parsed)) {
                $groups = array_merge($groups, $parsed);
            }
        }

        return response()->json(['success' => true, 'data' => $groups]);
    }
}
