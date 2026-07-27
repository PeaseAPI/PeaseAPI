@extends('layouts.dashboard')
@section('title', '个人信息')

@php
    $serverAddress = (string) \App\Services\OptionService::get('ServerAddress', '');
    if ($serverAddress === '') {
        $serverAddress = (string) config('app.url', '');
    }
    if ($serverAddress === '') {
        $serverAddress = request()->getSchemeAndHttpHost();
    }
    $serverAddress = rtrim($serverAddress, '/');

    $me = auth()->user();
    $avatarUrl = $me && $me->avatar ? \Illuminate\Support\Facades\Storage::url($me->avatar) : '';
    $displayName = $me->display_name ?? $me->username;
    $initial = strtoupper(mb_substr($displayName, 0, 1));
@endphp

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <!-- Avatar -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">头像</h3>
        <div class="flex items-center space-x-6">
            <div id="avatarPreview" class="w-20 h-20 rounded-full overflow-hidden flex items-center justify-center bg-primary-100 text-primary-700 text-2xl font-medium flex-shrink-0">
                @if(!empty($avatarUrl))
                    <img src="{{ $avatarUrl }}" alt="头像" class="w-full h-full object-cover">
                @else
                    {{ $initial }}
                @endif
            </div>
            <div class="flex-1">
                <input type="file" id="avatarInput" accept="image/jpeg,image/png,image/gif,image/webp" class="hidden">
                <button type="button" id="avatarBtn" class="px-4 py-2 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 transition">
                    <i class="fas fa-upload mr-1"></i>选择图片
                </button>
                <p class="text-xs text-gray-400 mt-2">支持 JPG / PNG / GIF / WEBP，最大 2MB</p>
                <p id="avatarMsg" class="text-xs mt-1"></p>
            </div>
        </div>
    </div>

    <!-- Profile Info -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">基本信息</h3>
        <form id="profileForm" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">用户名</label>
                <input type="text" name="username" id="username"
                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">昵称</label>
                <input type="text" name="display_name" id="display_name"
                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">邮箱</label>
                <input type="email" name="email" id="email"
                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 text-sm">
            </div>
            <button type="submit" class="px-6 py-2.5 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 transition">
                保存修改
            </button>
        </form>
    </div>

    <!-- Phone -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">手机号</h3>
        <div class="space-y-4">
            <div class="flex items-center justify-between py-2 border-b border-gray-100">
                <span class="text-sm text-gray-500">当前手机号</span>
                <span class="font-medium text-sm" id="phoneDisplay">-</span>
            </div>
            <form id="phoneForm" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">新手机号</label>
                    <input type="text" name="phone" id="phoneInput" required
                        pattern="^1[3-9]\d{9}$"
                        placeholder="请输入新手机号"
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 text-sm">
                </div>
                <div class="flex space-x-3">
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">短信验证码</label>
                        <input type="text" name="sms_code" id="smsCode" required pattern="\d{6}" maxlength="6"
                            placeholder="6 位验证码"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 text-sm">
                    </div>
                    <div class="flex flex-col justify-end">
                        <button type="button" id="sendCodeBtn"
                            class="px-4 py-2.5 border border-primary-600 text-primary-600 rounded-lg text-sm font-medium hover:bg-primary-50 transition whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed">
                            获取验证码
                        </button>
                    </div>
                </div>
                <p id="phoneMsg" class="text-xs"></p>
                <button type="submit" class="px-6 py-2.5 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 transition">
                    更新手机号
                </button>
            </form>
        </div>
    </div>

    <!-- Change Password -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">修改密码</h3>
        <form id="passwordForm" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">当前密码</label>
                <input type="password" name="current_password" required
                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">新密码</label>
                <input type="password" name="new_password" required minlength="8"
                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">确认新密码</label>
                <input type="password" name="new_password_confirmation" required minlength="8"
                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 text-sm">
            </div>
            <button type="submit" class="px-6 py-2.5 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 transition">
                修改密码
            </button>
        </form>
    </div>

    <!-- API Info -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">API 信息</h3>
        <div class="space-y-3 text-sm">
            <div class="flex items-center justify-between py-2 border-b border-gray-100">
                <span class="text-gray-500">API 地址</span>
                <code class="bg-gray-100 px-3 py-1 rounded text-xs">{{ $serverAddress }}/v1</code>
            </div>
            <div class="flex items-center justify-between py-2 border-b border-gray-100">
                <span class="text-gray-500">账户余额</span>
                <span class="font-medium" id="userBalance">-</span>
            </div>
            <div class="flex items-center justify-between py-2">
                <span class="text-gray-500">已用配额</span>
                <span class="font-medium" id="userUsed">-</span>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const csrfToken = '{{ csrf_token() }}';

function setMsg(id, text, ok) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = text || '';
    el.className = 'text-xs mt-1 ' + (ok ? 'text-green-600' : 'text-red-600');
}

function maskPhone(phone) {
    return phone ? phone.replace(/(\d{3})\d{4}(\d{4})/, '$1****$2') : '未绑定';
}

// 同步顶部导航头像
function syncTopAvatar(avatarUrl, fallbackName) {
    const wrap = document.getElementById('topNavAvatar');
    if (!wrap) return;
    if (avatarUrl) {
        wrap.innerHTML = '<img src="' + avatarUrl + '?_t=' + Date.now() + '" alt="头像" class="w-full h-full object-cover">';
    } else {
        const initial = (fallbackName || '?').charAt(0).toUpperCase();
        wrap.innerHTML = '<span class="w-full h-full bg-primary-100 text-primary-700 flex items-center justify-center text-sm font-medium">' + initial + '</span>';
    }
}

// 同步顶部导航用户名
function syncTopName(name) {
    const el = document.getElementById('topNavUserName');
    if (el && name) el.textContent = name;
}

// 渲染头像预览
function renderAvatarPreview(avatarUrl, fallbackName) {
    const preview = document.getElementById('avatarPreview');
    if (!preview) return;
    if (avatarUrl) {
        preview.innerHTML = '<img src="' + avatarUrl + (avatarUrl.indexOf('?') === -1 ? '?_t=' + Date.now() : '&_t=' + Date.now()) + '" alt="头像" class="w-full h-full object-cover">';
    } else {
        const initial = (fallbackName || '?').charAt(0).toUpperCase();
        preview.innerHTML = '<span class="text-primary-700">' + initial + '</span>';
    }
}

// 加载用户信息
fetch('/web-api/me').then(r => {
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
}).then(d => {
    document.getElementById('username').value = d.username || '';
    document.getElementById('display_name').value = d.display_name || '';
    document.getElementById('email').value = d.email || '';
    document.getElementById('phoneDisplay').textContent = maskPhone(d.phone);
    document.getElementById('userBalance').textContent = (d.quota || 0).toLocaleString();
    document.getElementById('userUsed').textContent = (d.used_quota || 0).toLocaleString();
    const displayName = d.display_name || d.username || '';
    renderAvatarPreview(d.avatar, displayName);
    // 同步顶部导航（防止 session 数据与最新状态不一致）
    syncTopName(displayName);
    syncTopAvatar(d.avatar, displayName);
}).catch(err => {
    setMsg('avatarMsg', '加载用户信息失败：' + err.message, false);
});

// 头像上传（含本地预览）
document.getElementById('avatarBtn').addEventListener('click', () => {
    document.getElementById('avatarInput').click();
});
document.getElementById('avatarInput').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
        setMsg('avatarMsg', '图片大小不能超过 2MB', false);
        return;
    }
    // 本地预览
    const reader = new FileReader();
    reader.onload = e => renderAvatarPreview(e.target.result);
    reader.readAsDataURL(file);

    const formData = new FormData();
    formData.append('avatar', file);
    setMsg('avatarMsg', '上传中...', true);
    fetch('/web-api/avatar', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken },
        body: formData
    }).then(r => {
        if (!r.ok) return r.json().then(d => Promise.reject(d));
        return r.json();
    }).then(d => {
        if (d.avatar) {
            renderAvatarPreview(d.avatar);
            syncTopAvatar(d.avatar);
            setMsg('avatarMsg', d.message || '头像更新成功', true);
            this.value = '';
        } else {
            setMsg('avatarMsg', d.error || d.message || '上传失败', false);
        }
    }).catch(d => {
        setMsg('avatarMsg', (d && (d.error || d.message)) || '上传失败', false);
    });
});

// 基本信息保存
document.getElementById('profileForm').onsubmit = function(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(this));
    fetch('/web-api/profile', {
        method: 'PUT',
        headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    }).then(r => {
        if (!r.ok) return r.json().then(d => Promise.reject(d));
        return r.json();
    }).then(d => {
        alert(d.message || '保存成功');
        const u = d.user || {};
        const name = u.display_name || u.username || data.display_name || data.username;
        syncTopName(name);
    }).catch(d => alert((d && (d.error || d.message)) || '保存失败'));
};

// 发送验证码
let countdown = 0;
let timer = null;
const sendBtn = document.getElementById('sendCodeBtn');
sendBtn.addEventListener('click', function() {
    const phone = document.getElementById('phoneInput').value.trim();
    if (!/^1[3-9]\d{9}$/.test(phone)) {
        setMsg('phoneMsg', '请输入正确的手机号', false);
        return;
    }
    sendBtn.disabled = true;
    setMsg('phoneMsg', '发送中...', true);
    fetch('/sms/send', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
        body: JSON.stringify({ phone: phone, scene: 'change_phone' })
    }).then(r => r.json()).then(d => {
        if (d.success === false) {
            setMsg('phoneMsg', d.message || '发送失败', false);
            sendBtn.disabled = false;
            return;
        }
        setMsg('phoneMsg', d.message || '验证码已发送', true);
        countdown = 60;
        timer = setInterval(() => {
            sendBtn.textContent = countdown + ' 秒后重试';
            countdown--;
            if (countdown < 0) {
                clearInterval(timer);
                sendBtn.disabled = false;
                sendBtn.textContent = '获取验证码';
            }
        }, 1000);
    }).catch(() => {
        setMsg('phoneMsg', '网络错误', false);
        sendBtn.disabled = false;
    });
});

// 更新手机号
document.getElementById('phoneForm').onsubmit = function(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(this));
    setMsg('phoneMsg', '', true);
    fetch('/web-api/phone', {
        method: 'PUT',
        headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    }).then(r => {
        if (!r.ok) return r.json().then(d => Promise.reject(d));
        return r.json();
    }).then(d => {
        if (d.message) {
            setMsg('phoneMsg', d.message, true);
            const newPhone = d.phone || data.phone;
            document.getElementById('phoneDisplay').textContent = maskPhone(newPhone);
            this.reset();
            if (timer) { clearInterval(timer); }
            sendBtn.disabled = false;
            sendBtn.textContent = '获取验证码';
        } else {
            setMsg('phoneMsg', d.error || '更新失败', false);
        }
    }).catch(d => setMsg('phoneMsg', (d && (d.error || d.message)) || '更新失败', false));
};

// 修改密码
document.getElementById('passwordForm').onsubmit = function(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(this));
    if (data.new_password !== data.new_password_confirmation) {
        alert('两次密码不一致');
        return;
    }
    fetch('/web-api/password', {
        method: 'PUT',
        headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    }).then(r => {
        if (!r.ok) return r.json().then(d => Promise.reject(d));
        return r.json();
    }).then(d => {
        alert(d.message || '修改成功');
        this.reset();
    }).catch(d => alert((d && (d.error || d.message)) || '修改失败'));
};
</script>
@endpush
