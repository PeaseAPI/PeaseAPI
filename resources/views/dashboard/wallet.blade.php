@extends('layouts.dashboard')
@section('title', '钱包充值')

@section('content')
<div class="max-w-4xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">钱包充值</h1>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- 左侧：充值表单 --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- 余额卡片 --}}
            <div class="bg-gradient-to-r from-blue-500 to-indigo-600 rounded-xl p-6 text-white shadow-lg">
                <p class="text-blue-100 text-sm">当前余额</p>
                <p class="text-3xl font-bold mt-1" id="user-quota">--</p>
                <p class="text-blue-100 text-sm mt-2">已用: <span id="user-used">--</span></p>
            </div>

            {{-- 充值表单 --}}
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">充值额度</h2>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">充值金额（额度）</label>
                    <input type="number" id="amount" min="1" placeholder="请输入充值额度" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                    <p class="text-xs text-gray-400 mt-1">应付金额: ¥<span id="pay-money">0.00</span></p>
                </div>

                {{-- 快捷额度 --}}
                <div class="mb-5">
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="quick-amt px-4 py-1.5 bg-gray-100 hover:bg-blue-50 text-sm rounded-lg" data-amt="10">10</button>
                        <button type="button" class="quick-amt px-4 py-1.5 bg-gray-100 hover:bg-blue-50 text-sm rounded-lg" data-amt="50">50</button>
                        <button type="button" class="quick-amt px-4 py-1.5 bg-gray-100 hover:bg-blue-50 text-sm rounded-lg" data-amt="100">100</button>
                        <button type="button" class="quick-amt px-4 py-1.5 bg-gray-100 hover:bg-blue-50 text-sm rounded-lg" data-amt="500">500</button>
                        <button type="button" class="quick-amt px-4 py-1.5 bg-gray-100 hover:bg-blue-50 text-sm rounded-lg" data-amt="1000">1000</button>
                    </div>
                </div>

                {{-- 支付方式选择（动态渲染） --}}
                <div class="mb-5">
                    <label class="block text-sm font-medium text-gray-700 mb-2">选择支付方式</label>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3" id="pay-methods">
                        {{-- JS 动态填充 --}}
                        <p class="text-gray-400 text-sm col-span-full">加载支付方式中...</p>
                    </div>
                    <p id="pay-methods-empty" class="text-orange-500 text-xs mt-2 hidden">暂无可用支付方式，请联系管理员在后台配置支付参数。</p>
                </div>

                <button type="button" id="pay-btn" class="w-full py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    确认充值
                </button>
            </div>
        </div>

        {{-- 原生微信支付二维码弹窗 --}}
        <div id="wechat-qr-modal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
            <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 text-center">
                <h3 class="text-lg font-semibold text-gray-800 mb-2">微信扫码支付</h3>
                <p class="text-sm text-gray-500 mb-4">请使用微信扫描下方二维码完成支付</p>
                <div id="wechat-qr-img" class="flex justify-center mb-4"></div>
                <p class="text-xs text-gray-400 mb-4">订单号: <span id="wechat-trade-no"></span></p>
                <button type="button" id="wechat-qr-close" class="w-full py-2 border border-gray-300 rounded-lg text-gray-600 hover:bg-gray-50">关闭</button>
            </div>
        </div>

        {{-- 右侧：充值记录 --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">充值记录</h2>
            <div id="history-list" class="space-y-2 text-sm"></div>
        </div>
    </div>
</div>

<script>
const API = window.location.origin + '/api';
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
let selectedPayType = 'alipay';
let selectedGateway = 'epay';
let paymentMethods = [];

// 支付方式图标 SVG 库
const PAY_ICONS = {
    alipay: '<svg class="w-8 h-8 text-blue-600" viewBox="0 0 24 24" fill="currentColor"><path d="M22.5 17.1c-1.2-.4-2.8-1-4.6-1.7.5-1.1.9-2.2 1.2-3.4h-3.3V10h4V9h-4V6.5h-1.7c-.3 0-.3.3-.3.3V9h-4v1h4v1.9H10v1h4.6c-.4 1.4-1 2.7-1.7 3.9-3.4 1.6-5.3 3-5.3 4.3 0 1.4 1.3 2.2 2.8 2.2 2 0 3.5-1 4.9-2.8 2 .7 4.6 1.7 6.2 2.4.2-.5.4-1.1.5-1.6-.2 0-.3 0-.5-.1zM9.3 20.5c-1 0-1.7-.5-1.7-1.2 0-1 1.4-2.1 3.8-3.1.9 1.5 1.3 2.8 1.3 3.7 0 .4-.3 1.3-1.3 1.3-.7 0-1.4.1-2.1-.7z"/></svg>',
    wxpay: '<svg class="w-8 h-8 text-green-600" viewBox="0 0 24 24" fill="currentColor"><path d="M8.5 4C4.9 4 2 6.5 2 9.6c0 1.7.9 3.2 2.4 4.2L3.7 16l2.3-1.2c.8.2 1.6.4 2.5.4h.6c-.1-.5-.2-1-.2-1.5 0-3.3 3-5.7 6.5-5.7h.6C15.3 5.6 12.2 4 8.5 4zm-2 3.3c.5 0 .9.4.9.9s-.4.9-.9.9-.9-.4-.9-.9.4-.9.9-.9zm4.1 0c.5 0 .9.4.9.9s-.4.9-.9.9-.9-.4-.9-.9.4-.9.9-.9zM22 13.8c0-2.6-2.5-4.7-5.5-4.7s-5.5 2.1-5.5 4.7 2.5 4.7 5.5 4.7c.7 0 1.4-.1 2-.3l1.8 1-.5-1.6c1.3-.9 2.2-2.3 2.2-3.8zm-7.3-1.2c-.3 0-.6-.3-.6-.6s.3-.6.6-.6.6.3.6.6-.3.6-.6.6zm3.6 0c-.3 0-.6-.3-.6-.6s.3-.6.6-.6.6.3.6.6-.3.6-.6.6z"/></svg>',
    qqpay: '<svg class="w-8 h-8 text-blue-400" viewBox="0 0 24 24" fill="currentColor"><path d="M12.003 0c-3.314 0-6 2.686-6 6 0 .34.027.673.078.996C4.36 7.59 3 9.13 3 11c0 2.21 1.79 4 4 4 .345 0 .68-.044 1-.126V17c0 2.21 1.79 4 4 4s4-1.79 4-4v-2.126c.32.082.655.126 1 .126 2.21 0 4-1.79 4-4 0-1.87-1.36-3.41-3.078-4.004.051-.323.078-.656.078-.996 0-3.314-2.686-6-6-6z"/></svg>',
    bank: '<svg class="w-8 h-8 text-purple-600" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L2 8v2h20V8L12 2zM4 12v6H2v2h20v-2h-2v-6h-2v6h-3v-6h-2v6h-3v-6H8v6H6v-6H4z"/></svg>',
    stripe: '<svg class="w-8 h-8 text-indigo-600" viewBox="0 0 24 24" fill="currentColor"><path d="M13.479 9.883c-1.626-.604-2.512-1.067-2.512-1.803 0-.622.511-.977 1.423-.977 1.667 0 3.379.642 4.558 1.22l.666-4.111c-.935-.446-2.847-1.177-5.49-1.177-1.87 0-3.425.488-4.536 1.4-1.155.951-1.757 2.348-1.757 4.059 0 3.069 1.873 4.411 5.111 5.504 2.111.665 2.811 1.144 2.811 1.875 0 .722-.622 1.155-1.733 1.155-1.388 0-3.704-.686-5.188-1.685L5.51 18.5c1.289.733 3.685 1.555 6.165 1.555 1.977 0 3.6-.477 4.733-1.388 1.244-.998 1.889-2.466 1.889-4.355 0-3.122-1.911-4.444-5.219-5.429z"/></svg>',
    creem: '<svg class="w-8 h-8 text-gray-700" viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/></svg>',
    custom: '<svg class="w-8 h-8 text-gray-600" viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/></svg>',
    native_wechat: '<svg class="w-8 h-8 text-green-600" viewBox="0 0 24 24" fill="currentColor"><path d="M8.5 4C4.9 4 2 6.5 2 9.6c0 1.7.9 3.2 2.4 4.2L3.7 16l2.3-1.2c.8.2 1.6.4 2.5.4h.6c-.1-.5-.2-1-.2-1.5 0-3.3 3-5.7 6.5-5.7h.6C15.3 5.6 12.2 4 8.5 4zM22 13.8c0-2.6-2.5-4.7-5.5-4.7s-5.5 2.1-5.5 4.7 2.5 4.7 5.5 4.7c.7 0 1.4-.1 2-.3l1.8 1-.5-1.6c1.3-.9 2.2-2.3 2.2-3.8z"/></svg>',
    native_alipay: '<svg class="w-8 h-8 text-blue-600" viewBox="0 0 24 24" fill="currentColor"><path d="M22.5 17.1c-1.2-.4-2.8-1-4.6-1.7.5-1.1.9-2.2 1.2-3.4h-3.3V10h4V9h-4V6.5h-1.7c-.3 0-.3.3-.3.3V9h-4v1h4v1.9H10v1h4.6c-.4 1.4-1 2.7-1.7 3.9-3.4 1.6-5.3 3-5.3 4.3 0 1.4 1.3 2.2 2.8 2.2 2 0 3.5-1 4.9-2.8 2 .7 4.6 1.7 6.2 2.4z"/></svg>',
};

function getIcon(type) {
    return PAY_ICONS[type] || PAY_ICONS.custom;
}

function renderPayMethods(methods) {
    const container = document.getElementById('pay-methods');
    const emptyTip = document.getElementById('pay-methods-empty');
    if (!methods || methods.length === 0) {
        container.innerHTML = '';
        emptyTip.classList.remove('hidden');
        document.getElementById('pay-btn').disabled = true;
        return;
    }
    emptyTip.classList.add('hidden');
    document.getElementById('pay-btn').disabled = false;
    container.innerHTML = methods.map((m, i) => `
        <button type="button" class="pay-btn border-2 ${i===0?'border-blue-500 bg-blue-50':'border-gray-200'} rounded-lg p-4 flex flex-col items-center gap-1" data-type="${m.type}" data-gateway="${m.gateway}">
            ${getIcon(m.type)}
            <span class="text-sm text-gray-700">${m.name}</span>
        </button>
    `).join('');

    // 默认选中第一个
    selectedPayType = methods[0].type;
    selectedGateway = methods[0].gateway;

    document.querySelectorAll('.pay-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.pay-btn').forEach(b => {
                b.classList.remove('border-blue-500','bg-blue-50');
                b.classList.add('border-gray-200');
            });
            this.classList.remove('border-gray-200');
            this.classList.add('border-blue-500','bg-blue-50');
            selectedPayType = this.dataset.type;
            selectedGateway = this.dataset.gateway;
        });
    });
}

async function loadInfo() {
    try {
        const res = await fetch(API + '/user/topup/info', { headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('token')||'') }});
        const data = await res.json();
        if (data.success) {
            document.getElementById('user-quota').textContent = (data.data.quota / 500000).toFixed(2) + ' USD';
            document.getElementById('user-used').textContent = (data.data.used_quota / 500000).toFixed(2) + ' USD';
            paymentMethods = data.data.payment_methods || [];
            renderPayMethods(paymentMethods);
        }
    } catch(e) {
        document.getElementById('pay-methods').innerHTML = '<p class="text-red-500 text-sm col-span-full">支付方式加载失败</p>';
    }
}

async function calcAmount() {
    const amt = parseInt(document.getElementById('amount').value) || 0;
    if (amt <= 0) { document.getElementById('pay-money').textContent = '0.00'; return; }
    try {
        const res = await fetch(API + '/user/amount', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Authorization': 'Bearer ' + (localStorage.getItem('token')||'') },
            body: JSON.stringify({ amount: amt })
        });
        const data = await res.json();
        if (data.success) document.getElementById('pay-money').textContent = data.data.money.toFixed(2);
    } catch(e) {}
}

document.getElementById('amount')?.addEventListener('input', calcAmount);
document.querySelectorAll('.quick-amt').forEach(b => b.addEventListener('click', () => {
    document.getElementById('amount').value = b.dataset.amt;
    calcAmount();
}));

document.getElementById('pay-btn')?.addEventListener('click', async function() {
    const amt = parseInt(document.getElementById('amount').value) || 0;
    if (amt <= 0) { alert('请输入充值金额'); return; }

    // 根据网关选择不同的支付接口
    let url = API + '/user/pay';
    let body = { amount: amt, payment_type: selectedPayType };

    if (selectedGateway === 'stripe') {
        url = API + '/user/stripe/pay';
        body = { amount: amt };
    } else if (selectedGateway === 'creem') {
        url = API + '/user/creem/pay';
        body = { amount: amt };
    } else if (selectedGateway === 'native_wechat') {
        url = API + '/user/wechat/pay';
        body = { amount: amt };
    } else if (selectedGateway === 'native_alipay') {
        url = API + '/user/alipay/pay';
        body = { amount: amt };
    }

    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Authorization': 'Bearer ' + (localStorage.getItem('token')||'') },
            body: JSON.stringify(body)
        });
        const data = await res.json();
        if (data.success) {
            const d = data.data || {};
            if (selectedGateway === 'native_wechat' && d.code_url) {
                // 原生微信支付：显示二维码弹窗
                showWechatQr(d.code_url, d.trade_no || '');
            } else if (selectedGateway === 'native_alipay' && d.pay_url) {
                // 原生支付宝：跳转支付页
                window.location.href = d.pay_url;
            } else if (d.pay_url) {
                // 易支付跳转
                window.location.href = d.pay_url;
            } else if (d.client_secret) {
                // Stripe PaymentIntent
                alert('Stripe 支付已创建，请等待跳转或联系管理员');
                loadHistory();
            } else {
                alert('订单已创建：' + (d.trade_no || ''));
                loadHistory();
            }
        } else {
            alert(data.message || '支付失败');
        }
    } catch(e) {
        alert('网络错误，请稍后重试');
    }
});

// 微信二维码弹窗
function showWechatQr(codeUrl, tradeNo) {
    const modal = document.getElementById('wechat-qr-modal');
    const imgBox = document.getElementById('wechat-qr-img');
    const noBox = document.getElementById('wechat-trade-no');
    // 使用第三方 QR 渲染（服务端 API）或简单 Google Chart API
    imgBox.innerHTML = '<img src="https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' + encodeURIComponent(codeUrl) + '" alt="QR" class="w-60 h-60">';
    noBox.textContent = tradeNo;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    loadHistory();
}

document.getElementById('wechat-qr-close')?.addEventListener('click', function() {
    const modal = document.getElementById('wechat-qr-modal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
});

async function loadHistory() {
    try {
        const res = await fetch(API + '/user/topup/self', { headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('token')||'') }});
        const data = await res.json();
        const list = document.getElementById('history-list');
        if (data.success && data.data.data && data.data.data.length > 0) {
            list.innerHTML = data.data.data.map(r => `
                <div class="flex justify-between py-2 border-b border-gray-100">
                    <div>
                        <p class="text-gray-700">+${r.amount} 额度</p>
                        <p class="text-xs text-gray-400">${r.payment_method || '-'} · ${new Date((r.created_at||0)*1000).toLocaleString()}</p>
                    </div>
                    <span class="text-xs ${r.status===1?'text-green-500':'text-orange-500'}">${r.status===1?'已完成':'待支付'}</span>
                </div>
            `).join('');
        } else {
            list.innerHTML = '<p class="text-gray-400 text-center py-4">暂无充值记录</p>';
        }
    } catch(e) {
        document.getElementById('history-list').innerHTML = '<p class="text-gray-400 text-center py-4">加载失败</p>';
    }
}

loadInfo();
loadHistory();
