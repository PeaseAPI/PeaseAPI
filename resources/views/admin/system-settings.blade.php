@extends('layouts.dashboard')
@section('title', '系统设置')

@push('head')
<style>
/* ===== 表单控件 ===== */
.field-label{display:block;font-size:.8125rem;font-weight:600;color:#334155;margin-bottom:.375rem;letter-spacing:-.01em}
.field-input,.field-select{width:100%;padding:.5625rem .875rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.875rem;color:#1e293b;transition:border-color .15s,box-shadow .15s;background:#fff}
.field-input:focus,.field-select:focus{outline:0;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.12)}
.field-input::placeholder{color:#94a3b8}
.field-textarea{width:100%;padding:.5625rem .875rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.8125rem;font-family:ui-monospace,"SF Mono",Monaco,monospace;color:#1e293b;transition:border-color .15s,box-shadow .15s;background:#fff;line-height:1.6}
.field-textarea:focus{outline:0;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.12)}
.field-hint{font-size:.75rem;color:#94a3b8;margin-top:.25rem}

/* ===== 开关 ===== */
.toggle{position:relative;display:inline-flex;align-items:center;cursor:pointer;flex-shrink:0}
.toggle input{position:absolute;opacity:0;width:0;height:0}
.toggle-slider{width:40px;height:22px;background:#cbd5e1;border-radius:9999px;transition:background .2s;position:relative}
.toggle-slider:before{content:"";position:absolute;width:18px;height:18px;left:2px;top:2px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.toggle input:checked + .toggle-slider{background:#10b981}
.toggle input:checked + .toggle-slider:before{transform:translateX(18px)}

/* ===== 支付卡片 ===== */
.pay-card{background:#fff;border:1px solid #e2e8f0;border-radius:.75rem;overflow:hidden;transition:border-color .15s,box-shadow .15s}
.pay-card:hover{border-color:#cbd5e1;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.pay-card.is-off .pay-body{opacity:.45;pointer-events:none}
.pay-head{display:flex;align-items:center;justify-content:space-between;padding:.875rem 1.25rem;border-bottom:1px solid #f1f5f9;background:#fafbfc}
.pay-icon{width:40px;height:40px;border-radius:.625rem;display:flex;align-items:center;justify-content:center;font-size:1.125rem;flex-shrink:0}
.pay-body{padding:1.25rem}

/* ===== 二级导航 ===== */
.sub-nav-item{display:flex;align-items:center;gap:.625rem;padding:.5625rem .875rem;border-radius:.5rem;font-size:.8125rem;font-weight:500;color:#64748b;cursor:pointer;transition:all .15s;user-select:none}
.sub-nav-item:hover{background:#f1f5f9;color:#334155}
.sub-nav-item.active{background:#1e293b;color:#fff}
.sub-nav-item.active i{color:#fff}

/* ===== PayMethods 动态行 ===== */
.pm-row{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:.5rem;align-items:end;padding:.75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:.5rem}
.pm-row .pm-del{width:38px;height:38px;display:flex;align-items:center;justify-content:center;border-radius:.5rem;color:#ef4444;cursor:pointer;transition:background .15s;border:1px solid #fecaca;background:#fff}
.pm-row .pm-del:hover{background:#fee2e2}
@media(max-width:640px){.pm-row{grid-template-columns:1fr 1fr;}.pm-row .pm-del{grid-column:span 2}}

/* ===== 保存按钮 ===== */
.btn-save{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;width:100%;padding:.625rem 1rem;background:#1e293b;color:#fff;border-radius:.5rem;font-size:.875rem;font-weight:600;cursor:pointer;transition:background .15s;border:none}
.btn-save:hover{background:#334155}
.btn-save:disabled{opacity:.6;cursor:not-allowed}
</style>
@endpush

@section('content')
@php
$o = [];
try { $o = \App\Services\OptionService::loadAll(); } catch (\Throwable $e) {}
$v = function($k, $d = '') use ($o) {
    $val = $o[$k] ?? $d;
    if (is_array($val)) { return htmlspecialchars(json_encode($val, JSON_UNESCAPED_UNICODE), 3, 'UTF-8'); }
    return htmlspecialchars((string)$val, 3, 'UTF-8');
};
$c = function($k) use ($o) {
    $val = $o[$k] ?? false;
    return in_array($val, [true, '1', 1, 'true'], true) ? 'checked' : '';
};
$payMethods = [];
try { $payMethods = json_decode($o['PayMethods'] ?? '[]', true) ?: []; } catch (\Throwable $e) {}
if (empty($payMethods)) {
    $payMethods = [
        ['name' => '支付宝', 'icon' => 'SiAlipay', 'type' => 'alipay', 'min_topup' => '0'],
        ['name' => '微信支付', 'icon' => 'SiWechat', 'type' => 'wxpay', 'min_topup' => '0'],
    ];
}
@endphp

<div class="flex flex-col md:flex-row gap-6">
    {{-- 二级导航 --}}
    <aside class="md:w-56 flex-shrink-0">
        <div class="bg-white rounded-xl border border-gray-200 p-2.5 md:sticky md:top-20">
            <p class="px-3 py-2 text-xs font-bold text-gray-400 uppercase tracking-wider">设置分组</p>
            <div class="space-y-0.5" id="sub-nav">
                <a class="sub-nav-item active" data-nav="general"><i class="fas fa-cog w-4 text-center"></i>基本设置</a>
                <a class="sub-nav-item" data-nav="register"><i class="fas fa-user-plus w-4 text-center"></i>注册登录</a>
                <a class="sub-nav-item" data-nav="smtp"><i class="fas fa-envelope w-4 text-center"></i>邮件配置</a>
                <a class="sub-nav-item" data-nav="sms"><i class="fas fa-sms w-4 text-center"></i>短信配置</a>
                <a class="sub-nav-item" data-nav="payment"><i class="fas fa-credit-card w-4 text-center"></i>支付配置</a>
                <a class="sub-nav-item" data-nav="topup"><i class="fas fa-wallet w-4 text-center"></i>充值设置</a>
                <a class="sub-nav-item" data-nav="checkin"><i class="fas fa-calendar-check w-4 text-center"></i>签到设置</a>
                <a class="sub-nav-item" data-nav="subscription"><i class="fas fa-star w-4 text-center"></i>订阅设置</a>
                <a class="sub-nav-item" data-nav="ratio"><i class="fas fa-percentage w-4 text-center"></i>倍率设置</a>
            </div>
            <div class="mt-3 pt-3 border-t border-gray-100 px-1">
                <button type="button" id="save-btn" class="btn-save">
                    <i class="fas fa-save"></i><span>保存设置</span>
                </button>
                <p id="save-status" class="mt-2 text-xs text-center text-gray-400"></p>
            </div>
        </div>
    </aside>

    {{-- 内容区 --}}
    <div class="flex-1 min-w-0">
        <div id="alert" class="hidden mb-4 px-4 py-3 rounded-lg text-sm font-medium"></div>
        <form id="settings-form" class="space-y-6">@csrf

        {{-- ========== 基本设置 ========== --}}
        <section data-s="general" class="sec">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="mb-5">
                    <h2 class="text-base font-bold text-gray-900">基本设置</h2>
                    <p class="text-xs text-gray-500 mt-0.5">配置站点基本信息</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="field-label">系统名称</label>
                        <input type="text" name="SystemName" value="{{ $v('SystemName', config('app.name')) }}" class="field-input">
                    </div>
                    <div>
                        <label class="field-label">系统 Logo URL</label>
                        <input type="text" name="SystemLogo" value="{{ $v('SystemLogo') }}" class="field-input" placeholder="https://example.com/logo.png">
                    </div>
                </div>
                <div class="mt-5">
                    <label class="field-label">服务器地址</label>
                    <input type="text" name="ServerAddress" value="{{ $v('ServerAddress') }}" class="field-input" placeholder="https://peaseapi.com">
                </div>
                <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="field-label">首页内容 (HTML)</label>
                        <textarea name="HomePageContent" rows="3" class="field-textarea">{{ $v('HomePageContent') }}</textarea>
                        <p class="field-hint">留空则显示默认首页</p>
                    </div>
                    <div>
                        <label class="field-label">页脚内容 (HTML)</label>
                        <textarea name="SystemFooter" rows="3" class="field-textarea">{{ $v('SystemFooter') }}</textarea>
                        <p class="field-hint">留空则显示默认页脚</p>
                    </div>
                </div>
                <div class="mt-5 grid grid-cols-1 md:grid-cols-3 gap-5">
                    <div><label class="field-label">充值链接</label><input type="text" name="TopUpLink" value="{{ $v('TopUpLink') }}" class="field-input"></div>
                    <div><label class="field-label">聊天链接</label><input type="text" name="ChatLink" value="{{ $v('ChatLink') }}" class="field-input"></div>
                    <div><label class="field-label">文档链接</label><input type="text" name="DocLink" value="{{ $v('DocLink') }}" class="field-input"></div>
                </div>
            </div>
        </section>

        {{-- ========== 注册与登录 ========== --}}
        <section data-s="register" class="sec hidden">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="mb-5">
                    <h2 class="text-base font-bold text-gray-900">注册与登录</h2>
                    <p class="text-xs text-gray-500 mt-0.5">控制用户注册和登录行为</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-5">
                    @foreach(['RegisterEnabled'=>'允许注册','PasswordRegisterEnabled'=>'密码注册','EmailVerificationEnabled'=>'注册需邮箱验证','PasswordLoginEnabled'=>'密码登录','TurnstileCheckEnabled'=>'启用 Turnstile 验证','EmailDomainRestrictionEnabled'=>'邮箱域名限制'] as $k=>$l)
                    <label class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 cursor-pointer hover:bg-gray-50 transition">
                        <input type="checkbox" name="{{ $k }}" value="1" {{ $c($k) }} class="w-4 h-4 text-blue-600 rounded border-gray-300">
                        <span class="text-sm text-gray-700">{{ $l }}</span>
                    </label>
                    @endforeach
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 pt-4 border-t border-gray-100">
                    <div><label class="field-label">Turnstile Site Key</label><input type="text" name="TurnstileSiteKey" value="{{ $v('TurnstileSiteKey') }}" class="field-input"></div>
                    <div><label class="field-label">Turnstile Secret Key</label><input type="password" name="TurnstileSecretKey" value="{{ $v('TurnstileSecretKey') }}" class="field-input"></div>
                </div>
            </div>
        </section>

        {{-- ========== 邮件配置 ========== --}}
        <section data-s="smtp" class="sec hidden">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="mb-5">
                    <h2 class="text-base font-bold text-gray-900">邮件配置 (SMTP)</h2>
                    <p class="text-xs text-gray-500 mt-0.5">配置 SMTP 服务器用于发送验证码和通知邮件</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div><label class="field-label">SMTP 服务器</label><input type="text" name="SMTPServer" value="{{ $v('SMTPServer') }}" class="field-input" placeholder="smtp.gmail.com"></div>
                    <div><label class="field-label">SMTP 端口</label><input type="number" name="SMTPPort" value="{{ $v('SMTPPort', '587') }}" class="field-input" placeholder="587"></div>
                    <div><label class="field-label">SMTP 账号</label><input type="text" name="SMTPAccount" value="{{ $v('SMTPAccount') }}" class="field-input" placeholder="user@example.com"></div>
                    <div><label class="field-label">SMTP 发件人</label><input type="text" name="SMTPFrom" value="{{ $v('SMTPFrom') }}" class="field-input" placeholder="noreply@example.com"></div>
                    <div><label class="field-label">SMTP 密码/Token</label><input type="password" name="SMTPToken" value="{{ $v('SMTPToken') }}" class="field-input"></div>
                </div>
            </div>
        </section>

        {{-- ========== 短信配置 ========== --}}
        <section data-s="sms" class="sec hidden">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="mb-5">
                    <h2 class="text-base font-bold text-gray-900">短信配置 (阿里云)</h2>
                    <p class="text-xs text-gray-500 mt-0.5">配置阿里云短信服务用于发送验证码</p>
                </div>
                <label class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 cursor-pointer hover:bg-gray-50 transition mb-5">
                    <input type="checkbox" name="SmsEnabled" value="1" {{ $c('SmsEnabled') }} class="w-4 h-4 text-blue-600 rounded border-gray-300">
                    <span class="text-sm text-gray-700">启用短信服务</span>
                </label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div><label class="field-label">AccessKey ID</label><input type="text" name="AliyunSmsAccessKeyId" value="{{ $v('AliyunSmsAccessKeyId') }}" class="field-input" placeholder="LTAI..."></div>
                    <div><label class="field-label">AccessKey Secret</label><input type="password" name="AliyunSmsAccessKeySecret" value="{{ $v('AliyunSmsAccessKeySecret') }}" class="field-input"></div>
                    <div><label class="field-label">短信签名</label><input type="text" name="AliyunSmsSignName" value="{{ $v('AliyunSmsSignName') }}" class="field-input" placeholder="PeaseAPI"></div>
                    <div><label class="field-label">验证码模板 Code</label><input type="text" name="AliyunSmsTemplateCode" value="{{ $v('AliyunSmsTemplateCode') }}" class="field-input" placeholder="SMS_123456789"></div>
                    <div><label class="field-label">地域 (Region)</label><input type="text" name="AliyunSmsRegion" value="{{ $v('AliyunSmsRegion', 'cn-hangzhou') }}" class="field-input" placeholder="cn-hangzhou"></div>
                </div>
                <div class="mt-5 pt-4 border-t border-gray-100">
                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">验证码策略</p>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div><label class="field-label">有效期(秒)</label><input type="number" name="SmsCodeTTL" value="{{ $v('SmsCodeTTL', '300') }}" class="field-input"></div>
                        <div><label class="field-label">位数</label><input type="number" name="SmsCodeLength" value="{{ $v('SmsCodeLength', '6') }}" class="field-input"></div>
                        <div><label class="field-label">发送间隔(秒)</label><input type="number" name="SmsSendInterval" value="{{ $v('SmsSendInterval', '60') }}" class="field-input"></div>
                        <div><label class="field-label">每日上限</label><input type="number" name="SmsDailyLimit" value="{{ $v('SmsDailyLimit', '10') }}" class="field-input"></div>
                    </div>
                </div>
                <div class="mt-4 p-3 bg-blue-50 rounded-lg text-xs text-blue-700">
                    <i class="fas fa-info-circle mr-1"></i>模板内容需包含 <code>${code}</code> 变量，例如：您的验证码为 ${code}，5 分钟内有效。
                </div>
            </div>
        </section>

        {{-- ========== 支付配置 ========== --}}
        <section data-s="payment" class="sec hidden">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="mb-5">
                    <h2 class="text-base font-bold text-gray-900">支付配置</h2>
                    <p class="text-xs text-gray-500 mt-0.5">配置各支付渠道的启用状态和密钥</p>
                </div>

                {{-- PayMethods 动态配置（易支付子方式） --}}
                <div class="mb-6">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <label class="field-label" style="margin-bottom:0">支付方式列表 (易支付)</label>
                            <p class="field-hint">配置易支付网关支持的支付方式，用户充值时显示</p>
                        </div>
                        <button type="button" id="pm-add" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100 transition">
                            <i class="fas fa-plus"></i>添加方式
                        </button>
                    </div>
                    <div id="pm-list" class="space-y-2">
                        @foreach($payMethods as $i => $pm)
                        <div class="pm-row">
                            <div><label class="field-label">名称</label><input type="text" class="field-input pm-name" value="{{ htmlspecialchars($pm['name'] ?? '', 3, 'UTF-8') }}" placeholder="支付宝"></div>
                            <div><label class="field-label">图标</label><input type="text" class="field-input pm-icon" value="{{ htmlspecialchars($pm['icon'] ?? '', 3, 'UTF-8') }}" placeholder="SiAlipay"></div>
                            <div><label class="field-label">类型</label>
                                <select class="field-select pm-type">
                                    @foreach(['alipay'=>'支付宝','wxpay'=>'微信支付','qqpay'=>'QQ支付','bank'=>'银行转账','paypal'=>'PayPal'] as $tk=>$tl)
                                    <option value="{{ $tk }}" @selected(($pm['type'] ?? '') === $tk)>{{ $tl }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="pm-del" title="删除"><i class="fas fa-trash-alt"></i></div>
                        </div>
                        @endforeach
                    </div>
                    <input type="hidden" name="PayMethods" id="pm-json" value="{{ htmlspecialchars(json_encode($payMethods, JSON_UNESCAPED_UNICODE), 3, 'UTF-8') }}">
                </div>

                {{-- 支付渠道卡片 --}}
                <div class="space-y-4">

                    {{-- 易支付 --}}
                    <div class="pay-card {{ $c('EpayEnabled') ? '' : 'is-off' }}" data-toggle="EpayEnabled">
                        <div class="pay-head">
                            <div class="flex items-center gap-3">
                                <div class="pay-icon" style="background:#fef3c7;color:#d97706"><i class="fas fa-exchange-alt"></i></div>
                                <div><p class="text-sm font-bold text-gray-900">易支付 (Epay)</p><p class="text-xs text-gray-500">聚合支付网关</p></div>
                            </div>
                            <label class="toggle"><input type="checkbox" name="EpayEnabled" value="1" {{ $c('EpayEnabled') }} data-toggle-card><span class="toggle-slider"></span></label>
                        </div>
                        <div class="pay-body">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div><label class="field-label">商户 ID</label><input type="text" name="EpayId" value="{{ $v('EpayId') }}" class="field-input"></div>
                                <div><label class="field-label">商户密钥</label><input type="password" name="EpayKey" value="{{ $v('EpayKey') }}" class="field-input"></div>
                                <div><label class="field-label">网关地址</label><input type="text" name="EpayAddress" value="{{ $v('EpayAddress') }}" class="field-input" placeholder="https://pay.example.com"></div>
                            </div>
                        </div>
                    </div>

                    {{-- 原生微信支付 --}}
                    <div class="pay-card {{ $c('WechatPayEnabled') ? '' : 'is-off' }}" data-toggle="WechatPayEnabled">
                        <div class="pay-head">
                            <div class="flex items-center gap-3">
                                <div class="pay-icon" style="background:#dcfce7;color:#16a34a"><i class="fab fa-weixin"></i></div>
                                <div><p class="text-sm font-bold text-gray-900">微信支付 V3</p><p class="text-xs text-gray-500">原生接口 · 扫码/H5/JSAPI</p></div>
                            </div>
                            <label class="toggle"><input type="checkbox" name="WechatPayEnabled" value="1" {{ $c('WechatPayEnabled') }} data-toggle-card><span class="toggle-slider"></span></label>
                        </div>
                        <div class="pay-body">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div><label class="field-label">AppId (公众号/小程序)</label><input type="text" name="WechatPayAppId" value="{{ $v('WechatPayAppId') }}" class="field-input"></div>
                                <div><label class="field-label">商户号 MchId</label><input type="text" name="WechatPayMchId" value="{{ $v('WechatPayMchId') }}" class="field-input"></div>
                                <div><label class="field-label">证书序列号</label><input type="text" name="WechatPaySerialNo" value="{{ $v('WechatPaySerialNo') }}" class="field-input"></div>
                                <div><label class="field-label">API V3 密钥</label><input type="password" name="WechatPayApiV3Key" value="{{ $v('WechatPayApiV3Key') }}" class="field-input"></div>
                            </div>
                            <div class="mt-4">
                                <label class="field-label">商户私钥 (PEM)</label>
                                <textarea name="WechatPayPrivateKey" rows="3" class="field-textarea" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----">{{ $v('WechatPayPrivateKey') }}</textarea>
                            </div>
                            <div class="mt-4"><label class="field-label">回调地址 (留空自动生成)</label><input type="text" name="WechatPayNotifyUrl" value="{{ $v('WechatPayNotifyUrl') }}" class="field-input" placeholder="/api/wechat/notify"></div>
                        </div>
                    </div>

                    {{-- 原生支付宝 --}}
                    <div class="pay-card {{ $c('AlipayEnabled') ? '' : 'is-off' }}" data-toggle="AlipayEnabled">
                        <div class="pay-head">
                            <div class="flex items-center gap-3">
                                <div class="pay-icon" style="background:#dbeafe;color:#2563eb"><i class="fab fa-alipay"></i></div>
                                <div><p class="text-sm font-bold text-gray-900">支付宝</p><p class="text-xs text-gray-500">原生接口 · 当面付/手机网站</p></div>
                            </div>
                            <label class="toggle"><input type="checkbox" name="AlipayEnabled" value="1" {{ $c('AlipayEnabled') }} data-toggle-card><span class="toggle-slider"></span></label>
                        </div>
                        <div class="pay-body">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div><label class="field-label">AppId</label><input type="text" name="AlipayAppId" value="{{ $v('AlipayAppId') }}" class="field-input"></div>
                                <div><label class="field-label">运行模式</label>
                                    <select name="AlipayMode" class="field-select">
                                        @foreach(['normal'=>'正式环境','sandbox'=>'沙箱环境'] as $mk=>$ml)
                                        <option value="{{ $mk }}" @selected($v('AlipayMode','normal') === $mk)>{{ $ml }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="mt-4">
                                <label class="field-label">应用私钥</label>
                                <textarea name="AlipayPrivateKey" rows="3" class="field-textarea" placeholder="MIIEvQ...">{{ $v('AlipayPrivateKey') }}</textarea>
                            </div>
                            <div class="mt-4">
                                <label class="field-label">支付宝公钥</label>
                                <textarea name="AlipayAlipayPublicKey" rows="3" class="field-textarea" placeholder="MIIBIj...">{{ $v('AlipayAlipayPublicKey') }}</textarea>
                            </div>
                            <div class="mt-4"><label class="field-label">回调地址 (留空自动生成)</label><input type="text" name="AlipayNotifyUrl" value="{{ $v('AlipayNotifyUrl') }}" class="field-input" placeholder="/api/alipay/notify"></div>
                        </div>
                    </div>

                    {{-- Stripe --}}
                    <div class="pay-card {{ $c('StripeEnabled') ? '' : 'is-off' }}" data-toggle="StripeEnabled">
                        <div class="pay-head">
                            <div class="flex items-center gap-3">
                                <div class="pay-icon" style="background:#f3f4f6;color:#635bff"><i class="fab fa-stripe-s"></i></div>
                                <div><p class="text-sm font-bold text-gray-900">Stripe</p><p class="text-xs text-gray-500">国际信用卡支付</p></div>
                            </div>
                            <label class="toggle"><input type="checkbox" name="StripeEnabled" value="1" {{ $c('StripeEnabled') }} data-toggle-card><span class="toggle-slider"></span></label>
                        </div>
                        <div class="pay-body">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div><label class="field-label">API Keys</label><input type="password" name="StripeApiKeys" value="{{ $v('StripeApiKeys') }}" class="field-input" placeholder="sk_live_..."></div>
                                <div><label class="field-label">Webhook Secret</label><input type="password" name="StripeWebhookSecret" value="{{ $v('StripeWebhookSecret') }}" class="field-input" placeholder="whsec_..."></div>
                                <div><label class="field-label">单价 (美元/额度)</label><input type="number" step="0.01" name="StripeUnitPrice" value="{{ $v('StripeUnitPrice') }}" class="field-input"></div>
                            </div>
                        </div>
                    </div>

                    {{-- Creem --}}
                    <div class="pay-card {{ $c('CreemEnabled') ? '' : 'is-off' }}" data-toggle="CreemEnabled">
                        <div class="pay-head">
                            <div class="flex items-center gap-3">
                                <div class="pay-icon" style="background:#fce7f3;color:#db2777"><i class="fas fa-leaf"></i></div>
                                <div><p class="text-sm font-bold text-gray-900">Creem</p><p class="text-xs text-gray-500">Creem 支付</p></div>
                            </div>
                            <label class="toggle"><input type="checkbox" name="CreemEnabled" value="1" {{ $c('CreemEnabled') }} data-toggle-card><span class="toggle-slider"></span></label>
                        </div>
                        <div class="pay-body">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div><label class="field-label">API Key</label><input type="password" name="CreemApiKey" value="{{ $v('CreemApiKey') }}" class="field-input"></div>
                                <div><label class="field-label">Webhook Secret</label><input type="password" name="CreemWebhookSecret" value="{{ $v('CreemWebhookSecret') }}" class="field-input"></div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </section>

        {{-- ========== 充值设置 ========== --}}
        <section data-s="topup" class="sec hidden">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="mb-5">
                    <h2 class="text-base font-bold text-gray-900">充值设置</h2>
                    <p class="text-xs text-gray-500 mt-0.5">控制充值金额范围和兑换比例</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <div><label class="field-label">最低充值金额</label><input type="number" name="TopUpMinAmount" value="{{ $v('TopUpMinAmount', '1') }}" class="field-input"></div>
                    <div><label class="field-label">最高充值金额</label><input type="number" name="TopUpMaxAmount" value="{{ $v('TopUpMaxAmount', '1000') }}" class="field-input"></div>
                    <div><label class="field-label">充值比例 (元=额度)</label><input type="number" step="0.01" name="TopUpRatio" value="{{ $v('TopUpRatio', '1') }}" class="field-input"></div>
                </div>
                <div class="mt-5">
                    <label class="field-label">充值金额预设 (逗号分隔)</label>
                    <input type="text" name="TopUpAmountPresets" value="{{ $v('TopUpAmountPresets', '10,50,100,500') }}" class="field-input">
                </div>
            </div>
        </section>

        {{-- ========== 签到设置 ========== --}}
        <section data-s="checkin" class="sec hidden">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="mb-5">
                    <h2 class="text-base font-bold text-gray-900">签到设置</h2>
                    <p class="text-xs text-gray-500 mt-0.5">每日签到奖励配置</p>
                </div>
                <label class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 cursor-pointer hover:bg-gray-50 transition mb-5">
                    <input type="checkbox" name="CheckinEnabled" value="1" {{ $c('CheckinEnabled') }} class="w-4 h-4 text-blue-600 rounded border-gray-300">
                    <span class="text-sm text-gray-700">启用每日签到</span>
                </label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div><label class="field-label">签到奖励额度</label><input type="number" name="CheckinReward" value="{{ $v('CheckinReward', '0.1') }}" class="field-input" step="0.01"></div>
                    <div><label class="field-label">连续签到倍率</label><input type="number" step="0.01" name="CheckinStreakBonus" value="{{ $v('CheckinStreakBonus', '0.1') }}" class="field-input"></div>
                </div>
            </div>
        </section>

        {{-- ========== 订阅设置 ========== --}}
        <section data-s="subscription" class="sec hidden">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="mb-5">
                    <h2 class="text-base font-bold text-gray-900">订阅设置</h2>
                    <p class="text-xs text-gray-500 mt-0.5">订阅功能相关配置</p>
                </div>
                <label class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 cursor-pointer hover:bg-gray-50 transition mb-5">
                    <input type="checkbox" name="SubscriptionEnabled" value="1" {{ $c('SubscriptionEnabled') }} class="w-4 h-4 text-blue-600 rounded border-gray-300">
                    <span class="text-sm text-gray-700">启用订阅功能</span>
                </label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div><label class="field-label">订阅说明</label><textarea name="SubscriptionNotice" rows="3" class="field-textarea">{{ $v('SubscriptionNotice') }}</textarea></div>
                    <div><label class="field-label">订阅条款</label><textarea name="SubscriptionTerms" rows="3" class="field-textarea">{{ $v('SubscriptionTerms') }}</textarea></div>
                </div>
            </div>
        </section>

        {{-- ========== 倍率设置 ========== --}}
        <section data-s="ratio" class="sec hidden">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="mb-5">
                    <h2 class="text-base font-bold text-gray-900">倍率设置</h2>
                    <p class="text-xs text-gray-500 mt-0.5">模型计费倍率配置</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                    <div><label class="field-label">模型倍率 (JSON)</label><textarea name="ModelRatio" rows="6" class="field-textarea">{{ $v('ModelRatio') }}</textarea><p class="field-hint">模型名称到倍率的映射</p></div>
                    <div><label class="field-label">补全倍率 (JSON)</label><textarea name="CompletionRatio" rows="6" class="field-textarea">{{ $v('CompletionRatio') }}</textarea><p class="field-hint">补全 Token 倍率</p></div>
                    <div><label class="field-label">分组倍率 (JSON)</label><textarea name="GroupRatio" rows="6" class="field-textarea">{{ $v('GroupRatio') }}</textarea><p class="field-hint">用户分组倍率</p></div>
                    <div><label class="field-label">渠道倍率 (JSON)</label><textarea name="ChannelRatio" rows="6" class="field-textarea">{{ $v('ChannelRatio') }}</textarea></div>
                </div>
                <div class="pt-4 border-t border-gray-100">
                    <button type="button" id="reset-ratio-btn" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-orange-600 bg-orange-50 rounded-lg hover:bg-orange-100 transition">
                        <i class="fas fa-undo"></i>重置为默认倍率
                    </button>
                </div>
            </div>
        </section>

        </form>
    </div>
</div>

<script>
// ===== 导航切换 =====
document.querySelectorAll('.sub-nav-item').forEach(function(item){
    item.addEventListener('click', function(){
        var nav = this.dataset.nav;
        document.querySelectorAll('.sub-nav-item').forEach(function(n){ n.classList.remove('active'); });
        this.classList.add('active');
        document.querySelectorAll('.sec').forEach(function(s){ s.classList.add('hidden'); });
        var target = document.querySelector('.sec[data-s="'+nav+'"]');
        if(target){ target.classList.remove('hidden'); }
    });
});

// ===== 支付卡片开关联动 =====
document.querySelectorAll('[data-toggle-card]').forEach(function(cb){
    cb.addEventListener('change', function(){
        var card = this.closest('.pay-card');
        if(this.checked){ card.classList.remove('is-off'); }
        else { card.classList.add('is-off'); }
    });
});

// ===== PayMethods 动态管理 =====
function serializePM(){
    var list = [];
    document.querySelectorAll('#pm-list .pm-row').forEach(function(row){
        list.push({
            name: row.querySelector('.pm-name').value,
            icon: row.querySelector('.pm-icon').value,
            type: row.querySelector('.pm-type').value
        });
    });
    document.getElementById('pm-json').value = JSON.stringify(list);
}
function addPMRow(){
    var tpl = document.createElement('div');
    tpl.className = 'pm-row';
    tpl.innerHTML = '<div><label class="field-label">名称</label><input type="text" class="field-input pm-name" placeholder="支付宝"></div>'
        + '<div><label class="field-label">图标</label><input type="text" class="field-input pm-icon" placeholder="SiAlipay"></div>'
        + '<div><label class="field-label">类型</label><select class="field-select pm-type">'
        + '<option value="alipay">支付宝</option><option value="wxpay">微信支付</option><option value="qqpay">QQ支付</option><option value="bank">银行转账</option><option value="paypal">PayPal</option>'
        + '</select></div>'
        + '<div class="pm-del" title="删除"><i class="fas fa-trash-alt"></i></div>';
    tpl.querySelector('.pm-del').addEventListener('click', function(){ tpl.remove(); serializePM(); });
    tpl.querySelectorAll('input,select').forEach(function(el){ el.addEventListener('input', serializePM); el.addEventListener('change', serializePM); });
    document.getElementById('pm-list').appendChild(tpl);
    serializePM();
}
document.getElementById('pm-add').addEventListener('click', addPMRow);
document.querySelectorAll('#pm-list .pm-row').forEach(function(row){
    row.querySelector('.pm-del').addEventListener('click', function(){ row.remove(); serializePM(); });
    row.querySelectorAll('input,select').forEach(function(el){ el.addEventListener('input', serializePM); el.addEventListener('change', serializePM); });
});

// ===== 保存 =====
function showAlert(msg, type){
    var el = document.getElementById('alert');
    el.textContent = msg;
    el.className = 'mb-4 px-4 py-3 rounded-lg text-sm font-medium ' + (type === 'error' ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700');
    el.classList.remove('hidden');
    setTimeout(function(){ el.classList.add('hidden'); }, 3000);
}
document.getElementById('save-btn').addEventListener('click', function(){
    var btn = this;
    var form = document.getElementById('settings-form');
    var fd = new FormData(form);
    btn.disabled = true;
    document.getElementById('save-status').textContent = '保存中...';
    fetch('{{ route("admin.options.update") }}', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
    .then(function(r){ return r.json(); })
    .then(function(data){
        btn.disabled = false;
        document.getElementById('save-status').textContent = '';
        if(data.success || data.message === 'success' || data.data){ showAlert('设置已保存', 'ok'); }
        else { showAlert(data.message || '保存失败', 'error'); }
    })
    .catch(function(){
        btn.disabled = false;
        document.getElementById('save-status').textContent = '';
        showAlert('网络错误，请重试', 'error');
    });
});

// ===== 重置倍率 =====
var resetBtn = document.getElementById('reset-ratio-btn');
if(resetBtn){
    resetBtn.addEventListener('click', function(){
        if(!confirm('确定要重置为默认倍率吗？')) return;
        fetch('{{ route("admin.options.resetModelRatio") }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if(data.success || data.data){ showAlert('已重置为默认倍率', 'ok'); setTimeout(function(){ location.reload(); }, 1500); }
            else { showAlert(data.message || '重置失败', 'error'); }
        })
        .catch(function(){ showAlert('网络错误', 'error'); });
    });
}
</script>
@endsection
