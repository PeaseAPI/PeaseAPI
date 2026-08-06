@php
    $systemName = 'Pease API';
    $systemFooter = '';
    $registerEnabled = true;
    $passwordLoginEnabled = true;
    try {
        if (app()->bound('db') && \DB::connection()->getPdo()) {
            $systemName = \App\Services\OptionService::get('SystemName', $systemName);
            $systemFooter = \App\Services\OptionService::get('SystemFooter', '');
            $registerEnabled = (bool) \App\Services\OptionService::get('RegisterEnabled', true);
            $passwordLoginEnabled = (bool) \App\Services\OptionService::get('PasswordLoginEnabled', true);
        }
    } catch (\Throwable $e) {}
    $version = config('app.version', '1.0.0');
@endphp
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>关于 - {{ $systemName }}</title>
<meta name="description" content="了解 {{ $systemName }} - 统一的 AI API 网关，支持 40+ 主流模型供应商">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--primary:#6366f1;--primary-light:#818cf8;--bg-darker:#020617;--bg-card:#1e293b;--text-light:#f1f5f9;--text-muted:#94a3b8;--border:rgba(148,163,184,0.15);--gradient:linear-gradient(135deg,#6366f1 0%,#8b5cf6 50%,#ec4899 100%);--gradient-soft:linear-gradient(135deg,rgba(99,102,241,0.15) 0%,rgba(139,92,246,0.15) 100%)}
html{scroll-behavior:smooth}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;background:var(--bg-darker);color:var(--text-light);line-height:1.6;overflow-x:hidden;min-height:100vh}
a{color:inherit;text-decoration:none}
.container{max-width:1100px;margin:0 auto;padding:0 24px}
.bg-decoration{position:fixed;inset:0;z-index:-1;overflow:hidden;pointer-events:none}
.bg-decoration::before{content:'';position:absolute;top:-20%;left:-10%;width:60%;height:60%;border-radius:50%;background:radial-gradient(circle,rgba(99,102,241,0.25) 0%,transparent 70%);filter:blur(80px)}
.bg-decoration::after{content:'';position:absolute;bottom:-20%;right:-10%;width:50%;height:50%;border-radius:50%;background:radial-gradient(circle,rgba(236,72,153,0.2) 0%,transparent 70%);filter:blur(80px)}
nav{position:sticky;top:0;z-index:100;background:rgba(15,23,42,0.8);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid var(--border)}
nav .container{display:flex;align-items:center;justify-content:space-between;height:64px}
.nav-brand{display:flex;align-items:center;gap:12px}
.nav-logo{width:36px;height:36px;border-radius:10px;background:var(--gradient);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(99,102,241,0.4)}
.nav-logo svg{width:20px;height:20px;color:#fff}
.nav-name{font-size:18px;font-weight:700;color:#fff}
.nav-links{display:flex;align-items:center;gap:8px}
.nav-links a{padding:8px 16px;font-size:14px;color:var(--text-muted);transition:all 0.2s;border-radius:8px}
.nav-links a:hover{color:#fff;background:rgba(255,255,255,0.05)}
.nav-links a.active{color:#fff;background:rgba(99,102,241,0.15)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;font-size:14px;font-weight:600;border-radius:10px;transition:all 0.2s;cursor:pointer;border:none;outline:none}
.btn-primary{background:var(--gradient);color:#fff;box-shadow:0 4px 14px rgba(99,102,241,0.4)}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(99,102,241,0.5)}
.btn-ghost{background:rgba(255,255,255,0.06);color:var(--text-light);border:1px solid var(--border)}
.btn-ghost:hover{background:rgba(255,255,255,0.1)}
.btn-lg{padding:14px 32px;font-size:16px;border-radius:12px}
.hero{padding:80px 0 48px;text-align:center}
.hero-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;margin-bottom:24px;background:var(--gradient-soft);border:1px solid rgba(99,102,241,0.3);border-radius:999px;font-size:13px;color:var(--primary-light)}
.hero h1{font-size:clamp(32px,5vw,52px);font-weight:800;line-height:1.15;letter-spacing:-1.5px;margin-bottom:20px;background:linear-gradient(135deg,#fff 0%,#cbd5e1 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero h1 .grad{background:var(--gradient);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero p{font-size:clamp(15px,2vw,18px);color:var(--text-muted);max-width:640px;margin:0 auto 32px}
.version-tag{display:inline-block;padding:4px 12px;font-size:12px;background:rgba(34,197,94,0.12);color:#22c55e;border:1px solid rgba(34,197,94,0.3);border-radius:6px;margin-top:16px;font-weight:500}
.section{padding:60px 0}
.section-title{text-align:center;margin-bottom:48px}
.section-title h2{font-size:clamp(24px,3vw,32px);font-weight:700;margin-bottom:12px}
.section-title p{color:var(--text-muted);font-size:15px}
.features-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:24px}
.feature-card{padding:32px 28px;border-radius:16px;background:var(--bg-card);border:1px solid var(--border);transition:all 0.3s}
.feature-card:hover{transform:translateY(-4px);border-color:rgba(99,102,241,0.3)}
.feature-icon{width:48px;height:48px;border-radius:12px;background:var(--gradient-soft);display:flex;align-items:center;justify-content:center;margin-bottom:20px;font-size:24px}
.feature-card h3{font-size:18px;font-weight:600;color:#fff;margin-bottom:10px}
.feature-card p{font-size:14px;color:var(--text-muted)}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:24px;max-width:800px;margin:0 auto}
.stat{text-align:center;padding:28px 16px;border-radius:16px;background:var(--bg-card);border:1px solid var(--border)}
.stat .num{font-size:36px;font-weight:800;background:var(--gradient);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:4px}
.stat .label{font-size:13px;color:var(--text-muted)}
.tech-stack{max-width:800px;margin:0 auto;padding:40px;border-radius:20px;background:var(--bg-card);border:1px solid var(--border)}
.tech-stack h3{font-size:18px;font-weight:600;margin-bottom:20px;text-align:center}
.tech-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px}
.tech-item{display:flex;align-items:center;gap:12px;padding:14px 16px;background:rgba(15,23,42,0.5);border-radius:10px;border:1px solid var(--border)}
.tech-item .dot{width:10px;height:10px;border-radius:50%;background:var(--gradient);flex-shrink:0}
.tech-item .name{font-size:14px;font-weight:500}
.tech-item .ver{font-size:12px;color:var(--text-muted);margin-left:auto}
.links-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;max-width:800px;margin:0 auto}
.link-card{display:flex;align-items:center;gap:14px;padding:18px 20px;border-radius:12px;background:var(--bg-card);border:1px solid var(--border);transition:all 0.2s}
.link-card:hover{transform:translateX(4px);border-color:rgba(99,102,241,0.3);background:rgba(99,102,241,0.05)}
.link-card .arrow{margin-left:auto;color:var(--text-muted);transition:transform 0.2s}
.link-card:hover .arrow{transform:translateX(4px);color:var(--primary-light)}
.link-card .label{font-size:15px;font-weight:500}
.link-card .desc{font-size:12px;color:var(--text-muted)}
.cta{padding:80px 0;text-align:center}
.cta-box{padding:64px 32px;border-radius:24px;background:var(--gradient-soft);border:1px solid rgba(99,102,241,0.3)}
.cta-box h2{font-size:clamp(24px,3vw,32px);font-weight:700;margin-bottom:16px}
.cta-box p{color:var(--text-muted);font-size:16px;margin-bottom:32px}
.cta-buttons{display:flex;flex-wrap:wrap;justify-content:center;gap:16px}
footer{padding:48px 0;border-top:1px solid var(--border);background:rgba(15,23,42,0.5)}
.footer-content{text-align:center;color:var(--text-muted);font-size:14px;line-height:1.8}
.footer-content a{color:var(--primary-light);transition:color 0.2s}
.footer-content a:hover{color:#fff}
@media (max-width:768px){.nav-links a:not(.btn){display:none}.hero{padding:48px 0 32px}}
</style>
</head>
<body>
<div class="bg-decoration"></div>
<nav>
    <div class="container">
        <a href="/" class="nav-brand">
            <div class="nav-logo"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></div>
            <span class="nav-name">{{ $systemName }}</span>
        </a>
        <div class="nav-links">
            <a href="/docs">文档</a>
            <a href="/about" class="active">关于</a>
            <a href="/pricing">价格</a>
            @if($passwordLoginEnabled)<a href="/login" class="btn btn-ghost">登录</a>@endif
            @if($registerEnabled)<a href="/register" class="btn btn-primary">免费注册</a>@endif
        </div>
    </div>
</nav>

<section class="hero">
    <div class="container">
        <div class="hero-badge"><span>🚀 统一 AI API 网关</span></div>
        <h1>关于 <span class="grad">{{ $systemName }}</span></h1>
        <p>{{ $systemName }} 是一个统一的 AI API 网关，支持 OpenAI、Claude、Gemini、Midjourney 等 40+ 主流模型供应商，让你用一个接口访问所有 AI 能力。</p>
        <div class="version-tag">当前版本 v{{ $version }}</div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-title"><h2>核心特性</h2><p>为什么选择 {{ $systemName }}</p></div>
        <div class="features-grid">
            <div class="feature-card"><div class="feature-icon">🔌</div><h3>统一接口</h3><p>兼容 OpenAI API 格式，一行代码切换 40+ 模型供应商，无需修改业务逻辑。</p></div>
            <div class="feature-card"><div class="feature-icon">⚡</div><h3>高性能转发</h3><p>基于 PHP 8.3 + Laravel 11 构建，流式响应零延迟，支持并发与负载均衡。</p></div>
            <div class="feature-card"><div class="feature-icon">🔐</div><h3>安全可靠</h3><p>完善的鉴权、限流、配额管理，支持多租户与团队协作，数据隔离安全。</p></div>
            <div class="feature-card"><div class="feature-icon">📊</div><h3>用量分析</h3><p>实时统计 Token 消耗与费用明细，可视化日志查询，精准成本控制。</p></div>
            <div class="feature-card"><div class="feature-icon">🎨</div><h3>多模态支持</h3><p>文本、图像、音频、视频全覆盖，支持 Midjourney、Suno、Sora 等创作模型。</p></div>
            <div class="feature-card"><div class="feature-icon">💰</div><h3>灵活计费</h3><p>支持按量付费与订阅套餐，多种支付方式，企业级开票与对公转账。</p></div>
        </div>
    </div>
</section>

<section class="section" style="padding-top:0">
    <div class="container">
        <div class="section-title"><h2>平台数据</h2><p>持续增长中的生态</p></div>
        <div class="stats">
            <div class="stat"><div class="num">40+</div><div class="label">模型供应商</div></div>
            <div class="stat"><div class="num">100+</div><div class="label">可用模型</div></div>
            <div class="stat"><div class="num">99.9%</div><div class="label">服务可用性</div></div>
            <div class="stat"><div class="num">24/7</div><div class="label">技术支持</div></div>
        </div>
    </div>
</section>

<section class="section" style="padding-top:0">
    <div class="container">
        <div class="tech-stack">
            <h3>🛠️ 技术栈</h3>
            <div class="tech-list">
                <div class="tech-item"><span class="dot"></span><span class="name">PHP</span><span class="ver">8.3</span></div>
                <div class="tech-item"><span class="dot"></span><span class="name">Laravel</span><span class="ver">11.x</span></div>
                <div class="tech-item"><span class="dot"></span><span class="name">MySQL</span><span class="ver">8.0+</span></div>
                <div class="tech-item"><span class="dot"></span><span class="name">Redis</span><span class="ver">7+</span></div>
                <div class="tech-item"><span class="dot"></span><span class="name">Blade</span><span class="ver">模板引擎</span></div>
                <div class="tech-item"><span class="dot"></span><span class="name">Tailwind CSS</span><span class="ver">3.x</span></div>
            </div>
        </div>
    </div>
</section>

<section class="section" style="padding-top:0">
    <div class="container">
        <div class="section-title"><h2>快速导航</h2><p>探索更多内容</p></div>
        <div class="links-grid">
            <a href="/" class="link-card"><span><div class="label">🏠 首页</div><div class="desc">返回主页</div></span><span class="arrow">→</span></a>
            <a href="/pricing" class="link-card"><span><div class="label">💳 价格</div><div class="desc">查看定价方案</div></span><span class="arrow">→</span></a>
            <a href="/rankings" class="link-card"><span><div class="label">🏆 排行榜</div><div class="desc">用户排行</div></span><span class="arrow">→</span></a>
            <a href="/user-agreement" class="link-card"><span><div class="label">📋 用户协议</div><div class="desc">服务条款</div></span><span class="arrow">→</span></a>
            <a href="/privacy-policy" class="link-card"><span><div class="label">🔒 隐私政策</div><div class="desc">数据处理说明</div></span><span class="arrow">→</span></a>
        </div>
    </div>
</section>

<section class="cta">
    <div class="container">
        <div class="cta-box">
            <h2>准备好开始了吗？</h2>
            <p>注册即送免费额度，几分钟内即可集成到你的应用中</p>
            <div class="cta-buttons">
                @if($registerEnabled)<a href="/register" class="btn btn-primary btn-lg">免费注册</a>@endif
                @if($passwordLoginEnabled)<a href="/login" class="btn btn-ghost btn-lg">登录控制台</a>@endif
            </div>
        </div>
    </div>
</section>

<footer>
    <div class="container">
        <div class="footer-content">
            @if(!empty($systemFooter))
                {!! $systemFooter !!}
            @else
                <p>&copy; {{ date('Y') }} {{ $systemName }}. All rights reserved.</p>
                <p><a href="/">首页</a> · <a href="/docs">文档</a> · <a href="/pricing">价格</a> · <a href="/about">关于</a> · <a href="/user-agreement">用户协议</a> · <a href="/privacy-policy">隐私政策</a></p>
            @endif
        </div>
    </div>
</footer>
</body>
</html>
