@extends('layouts.app')
@section('title', '登录')
@section('content')
<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-indigo-50 flex items-center justify-center py-12 px-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="flex items-center justify-center space-x-2 mb-6">
                @if(!empty($systemLogo))
                    <img src="{{ $systemLogo }}" alt="logo" class="w-10 h-10 rounded-lg object-cover">
                @else
                    <div class="w-10 h-10 bg-primary-600 rounded-lg flex items-center justify-center">
                        <span class="text-white font-bold text-lg">{{ mb_substr($systemName ?? 'P', 0, 1) }}</span>
                    </div>
                @endif
                <span class="text-2xl font-bold text-gray-900">{{ $systemName ?? 'Pease API' }}</span>
            </div>
            <h2 class="text-2xl font-bold text-gray-900">登录您的账号</h2>
            <p class="text-gray-500 mt-2">欢迎回来，请输入您的凭据</p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
            {{-- 登录方式切换 --}}
            <div class="flex border-b border-gray-200 mb-6">
                <button type="button" id="tab-password" onclick="switchTab('password')"
                    class="flex-1 pb-3 text-sm font-medium border-b-2 border-primary-600 text-primary-600 transition">密码登录</button>
                <button type="button" id="tab-sms" onclick="switchTab('sms')"
                    class="flex-1 pb-3 text-sm font-medium border-b-2 border-transparent text-gray-500 transition">短信登录</button>
            </div>

            @if($errors->any())
            <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm">
                {{ $errors->first() }}
            </div>
            @endif

            {{-- 密码登录表单 --}}
            <form method="POST" action="{{ route('login') }}" id="form-password">
                @csrf
                <input type="hidden" name="login_type" value="password">
                <div class="space-y-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">用户名 / 邮箱 / 手机号</label>
                        <input type="text" name="username" value="{{ old('username') }}"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition text-sm"
                            placeholder="请输入用户名、邮箱或手机号" required autofocus>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">密码</label>
                        <input type="password" name="password"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition text-sm"
                            placeholder="请输入密码" required>
                    </div>
                </div>
                <button type="submit" class="w-full mt-6 bg-primary-600 text-white py-2.5 rounded-lg font-medium hover:bg-primary-700 transition shadow-sm">
                    登 录
                </button>
            </form>

            {{-- 短信登录表单 --}}
            <form method="POST" action="{{ route('login') }}" id="form-sms" class="hidden">
                @csrf
                <input type="hidden" name="login_type" value="sms">
                <div class="space-y-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">手机号</label>
                        <input type="text" name="phone" value="{{ old('phone') }}" pattern="^1[3-9]\d{9}$"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition text-sm"
                            placeholder="请输入手机号" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">短信验证码</label>
                        <div class="flex space-x-2">
                            <input type="text" name="sms_code" maxlength="6" pattern="\d{6}"
                                class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition text-sm"
                                placeholder="请输入 6 位验证码" required>
                            <button type="button" id="btn-send-login" onclick="sendSms('login')"
                                class="px-4 py-2.5 border border-primary-600 text-primary-600 rounded-lg text-sm font-medium hover:bg-primary-50 transition whitespace-nowrap">
                                获取验证码
                            </button>
                        </div>
                    </div>
                </div>
                <button type="submit" class="w-full mt-6 bg-primary-600 text-white py-2.5 rounded-lg font-medium hover:bg-primary-700 transition shadow-sm">
                    登 录
                </button>
            </form>

            <div class="mt-6 flex items-center justify-between text-center">
                <span class="text-sm text-gray-500">还没有账号？</span>
                <div class="flex items-center space-x-3">
                    <a href="{{ route('register') }}" class="text-sm text-primary-600 hover:text-primary-700 font-medium">立即注册</a>
                    <span class="text-gray-300">|</span>
                    <a href="{{ route('password.request') }}" class="text-sm text-primary-600 hover:text-primary-700 font-medium">找回密码</a>
                </div>
            </div>
        </div>

        <div class="mt-6 text-center">
            <a href="{{ route('home') }}" class="text-sm text-gray-500 hover:text-gray-700">
                <i class="fas fa-arrow-left mr-1"></i>返回首页
            </a>
        </div>
    </div>
</div>

<script>
function switchTab(type) {
    const pwdTab = document.getElementById('tab-password');
    const smsTab = document.getElementById('tab-sms');
    const pwdForm = document.getElementById('form-password');
    const smsForm = document.getElementById('form-sms');
    if (type === 'password') {
        pwdTab.classList.add('border-primary-600', 'text-primary-600');
        pwdTab.classList.remove('border-transparent', 'text-gray-500');
        smsTab.classList.remove('border-primary-600', 'text-primary-600');
        smsTab.classList.add('border-transparent', 'text-gray-500');
        pwdForm.classList.remove('hidden');
        smsForm.classList.add('hidden');
    } else {
        smsTab.classList.add('border-primary-600', 'text-primary-600');
        smsTab.classList.remove('border-transparent', 'text-gray-500');
        pwdTab.classList.remove('border-primary-600', 'text-primary-600');
        pwdTab.classList.add('border-transparent', 'text-gray-500');
        smsForm.classList.remove('hidden');
        pwdForm.classList.add('hidden');
    }
}

let countdown = 0;
function sendSms(scene) {
    const phoneInput = document.querySelector('#form-sms input[name="phone"]');
    const phone = phoneInput ? phoneInput.value.trim() : '';
    if (!/^1[3-9]\d{9}$/.test(phone)) {
        alert('请输入正确的手机号');
        return;
    }
    fetch('{{ route("sms.send") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json'
        },
        body: JSON.stringify({ phone: phone, scene: scene })
    }).then(r => r.json()).then(data => {
        if (data.success) {
            startCountdown('btn-send-login');
        } else {
            alert(data.message || '发送失败');
        }
    }).catch(() => alert('网络错误，请重试'));
}

function startCountdown(btnId) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    countdown = 60;
    btn.disabled = true;
    btn.classList.add('opacity-50', 'cursor-not-allowed');
    const timer = setInterval(() => {
        if (countdown <= 0) {
            clearInterval(timer);
            btn.disabled = false;
            btn.innerText = '获取验证码';
            btn.classList.remove('opacity-50', 'cursor-not-allowed');
        } else {
            btn.innerText = countdown + ' 秒后重试';
            countdown--;
        }
    }, 1000);
}
</script>
@endsection