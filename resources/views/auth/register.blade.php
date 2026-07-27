@extends('layouts.app')
@section('title', '注册')
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
            <h2 class="text-2xl font-bold text-gray-900">创建新账号</h2>
            <p class="text-gray-500 mt-2">注册以开始使用 {{ $systemName ?? 'Pease API' }}</p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
            {{-- 注册方式切换 --}}
            <div class="flex border-b border-gray-200 mb-6">
                <button type="button" id="tab-email" onclick="switchTab('email')"
                    class="flex-1 pb-3 text-sm font-medium border-b-2 border-primary-600 text-primary-600 transition">邮箱注册</button>
                <button type="button" id="tab-phone" onclick="switchTab('phone')"
                    class="flex-1 pb-3 text-sm font-medium border-b-2 border-transparent text-gray-500 transition">手机号注册</button>
            </div>

            @if($errors->any())
            <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm">
                {{ $errors->first() }}
            </div>
            @endif

            {{-- 邮箱注册表单 --}}
            <form method="POST" action="{{ route('register') }}" id="form-email">
                @csrf
                <input type="hidden" name="register_type" value="email">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">用户名</label>
                        <input type="text" name="username" value="{{ old('username') }}"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition text-sm"
                            placeholder="3-32位字母数字" required autofocus>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">邮箱</label>
                        <input type="email" name="email" value="{{ old('email') }}"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition text-sm"
                            placeholder="请输入邮箱地址" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">密码</label>
                        <input type="password" name="password"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition text-sm"
                            placeholder="至少8位字符" required minlength="8">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">确认密码</label>
                        <input type="password" name="password_confirmation"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition text-sm"
                            placeholder="再次输入密码" required>
                    </div>
                </div>
                <button type="submit" class="w-full mt-6 bg-primary-600 text-white py-2.5 rounded-lg font-medium hover:bg-primary-700 transition shadow-sm">
                    注 册
                </button>
            </form>

            {{-- 手机号注册表单 --}}
            <form method="POST" action="{{ route('register') }}" id="form-phone" class="hidden">
                @csrf
                <input type="hidden" name="register_type" value="phone">
                <div class="space-y-4">
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
                            <button type="button" id="btn-send-register" onclick="sendSms('register')"
                                class="px-4 py-2.5 border border-primary-600 text-primary-600 rounded-lg text-sm font-medium hover:bg-primary-50 transition whitespace-nowrap">
                                获取验证码
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">密码</label>
                        <input type="password" name="password"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition text-sm"
                            placeholder="至少8位字符" required minlength="8">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">确认密码</label>
                        <input type="password" name="password_confirmation"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition text-sm"
                            placeholder="再次输入密码" required>
                    </div>
                </div>
                <button type="submit" class="w-full mt-6 bg-primary-600 text-white py-2.5 rounded-lg font-medium hover:bg-primary-700 transition shadow-sm">
                    注 册
                </button>
            </form>

            <div class="mt-6 text-center">
                <span class="text-sm text-gray-500">已有账号？</span>
                <a href="{{ route('login') }}" class="text-sm text-primary-600 hover:text-primary-700 font-medium">立即登录</a>
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
    const emailTab = document.getElementById('tab-email');
    const phoneTab = document.getElementById('tab-phone');
    const emailForm = document.getElementById('form-email');
    const phoneForm = document.getElementById('form-phone');
    if (type === 'email') {
        emailTab.classList.add('border-primary-600', 'text-primary-600');
        emailTab.classList.remove('border-transparent', 'text-gray-500');
        phoneTab.classList.remove('border-primary-600', 'text-primary-600');
        phoneTab.classList.add('border-transparent', 'text-gray-500');
        emailForm.classList.remove('hidden');
        phoneForm.classList.add('hidden');
    } else {
        phoneTab.classList.add('border-primary-600', 'text-primary-600');
        phoneTab.classList.remove('border-transparent', 'text-gray-500');
        emailTab.classList.remove('border-primary-600', 'text-primary-600');
        emailTab.classList.add('border-transparent', 'text-gray-500');
        phoneForm.classList.remove('hidden');
        emailForm.classList.add('hidden');
    }
}

let countdown = 0;
function sendSms(scene) {
    const phoneInput = document.querySelector('#form-phone input[name="phone"]');
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
            startCountdown('btn-send-register');
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