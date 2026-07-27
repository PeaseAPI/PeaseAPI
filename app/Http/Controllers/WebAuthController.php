<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Enums\UserRole;
use App\Services\SmsCodeService;
use App\Services\EmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebAuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }
        return view('auth.login');
    }

    /**
     * 登录：支持密码登录（用户名/邮箱/手机号 + 密码）和短信登录（手机号 + 验证码）。
     */
    public function login(Request $request)
    {
        $loginType = $request->input('login_type', 'password');

        if ($loginType === 'sms') {
            $validated = $request->validate([
                'phone'    => ['required', 'string', 'regex:/^1[3-9]\d{9}$/'],
                'sms_code' => ['required', 'string', 'digits:6'],
            ]);

            $user = User::where('phone', $validated['phone'])->first();
            if (!$user) {
                return back()->withErrors(['phone' => '该手机号尚未注册'])->withInput();
            }

            if (!app(SmsCodeService::class)->verify($validated['phone'], $validated['sms_code'])) {
                return back()->withErrors(['sms_code' => '短信验证码错误或已过期'])->withInput();
            }

            if ($user->status !== 1) {
                return back()->withErrors(['phone' => '账号已被禁用'])->withInput();
            }

            Auth::login($user);
            $user->update(['last_login_at' => time()]);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        }

        // 密码登录
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('username', $credentials['username'])
            ->orWhere('email', $credentials['username'])
            ->orWhere('phone', $credentials['username'])
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return back()->withErrors(['username' => '账号或密码错误'])->withInput();
        }

        if ($user->status !== 1) {
            return back()->withErrors(['username' => '账号已被禁用'])->withInput();
        }

        Auth::login($user);
        $user->update(['last_login_at' => time()]);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function showRegister()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }
        return view('auth.register');
    }

    /**
     * 注册：支持邮箱注册和手机号注册（手机号需短信验证码）。
     */
    public function register(Request $request)
    {
        $registerType = $request->input('register_type', 'email');

        if ($registerType === 'phone') {
            $validated = $request->validate([
                'phone'                => ['required', 'string', 'regex:/^1[3-9]\d{9}$/', Rule::unique('users', 'phone')],
                'sms_code'             => ['required', 'string', 'digits:6'],
                'password'             => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            if (!app(SmsCodeService::class)->verify($validated['phone'], $validated['sms_code'])) {
                return back()->withErrors(['sms_code' => '短信验证码错误或已过期'])->withInput();
            }

            // 手机号注册：自动生成用户名
            $username = 'user_' . Str::random(8);
            while (User::where('username', $username)->exists()) {
                $username = 'user_' . Str::random(8);
            }

            $user = User::create([
                'username'      => $username,
                'phone'         => $validated['phone'],
                'password'      => Hash::make($validated['password']),
                'display_name'  => $username,
                'role'          => UserRole::USER->value,
                'status'        => 1,
                'quota'         => 0,
                'used_quota'    => 0,
                'request_count' => 0,
                'group'         => 'default',
                'aff_code'      => strtoupper(Str::random(8)),
                'created_at'    => time(),
                'last_login_at' => time(),
            ]);

            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->route('dashboard');
        }

        // 邮箱注册
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:32', 'alpha_num', Rule::unique('users', 'username')],
            'email'    => ['required', 'email', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'username'      => $validated['username'],
            'email'         => $validated['email'],
            'password'      => Hash::make($validated['password']),
            'display_name'  => $validated['username'],
            'role'          => UserRole::USER->value,
            'status'        => 1,
            'quota'         => 0,
            'used_quota'    => 0,
            'request_count' => 0,
            'group'         => 'default',
            'aff_code'      => strtoupper(Str::random(8)),
            'created_at'    => time(),
            'last_login_at' => time(),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('home');
    }

    /**
     * 显示找回密码页面。
     */
    public function showReset(Request $request)
    {
        return view('auth.reset', ['request' => $request]);
    }

    /**
     * 处理找回密码：
     * - 邮箱模式：发送重置链接邮件（Laravel Password Broker）
     * - 手机号模式：校验验证码后直接重置密码
     */
    public function reset(Request $request)
    {
        $resetType = $request->input('reset_type', 'email');

        if ($resetType === 'phone') {
            $validated = $request->validate([
                'phone'                => ['required', 'string', 'regex:/^1[3-9]\d{9}$/'],
                'sms_code'             => ['required', 'string', 'digits:6'],
                'password'             => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            $user = User::where('phone', $validated['phone'])->first();
            if (!$user) {
                return back()->withErrors(['phone' => '该手机号尚未注册'])->withInput();
            }

            if (!app(SmsCodeService::class)->verify($validated['phone'], $validated['sms_code'])) {
                return back()->withErrors(['sms_code' => '短信验证码错误或已过期'])->withInput();
            }

            $user->update(['password' => Hash::make($validated['password'])]);

            return redirect()->route('login')->with('status', '密码已重置，请使用新密码登录');
        }

        // 邮箱模式：发送重置链接
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        return $status === Password::RESET_LINK_SENT
            ? back()->with('status', __($status))
            : back()->withErrors(['email' => __($status)])->withInput();
    }

    /**
     * 发送短信验证码（注册/登录/重置密码/修改手机号共用）。
     */
    public function sendSmsCode(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^1[3-9]\d{9}$/'],
            'scene' => ['required', 'string', Rule::in(['register', 'login', 'reset', 'change_phone'])],
        ]);

        $phone = $validated['phone'];
        $scene = $validated['scene'];

        // 场景校验：注册时手机号不应已注册；登录/重置时手机号应已注册
        $exists = User::where('phone', $phone)->exists();
        if ($scene === 'register' && $exists) {
            return response()->json(['success' => false, 'message' => '该手机号已注册']);
        }
        if (in_array($scene, ['login', 'reset'], true) && !$exists) {
            return response()->json(['success' => false, 'message' => '该手机号尚未注册']);
        }
        if ($scene === 'change_phone' && $exists) {
            return response()->json(['success' => false, 'message' => '该手机号已被其他账号绑定']);
        }

        $ip = $request->ip() ?? '';
        $result = app(SmsCodeService::class)->send($phone, $ip);

        return response()->json($result);
    }
}