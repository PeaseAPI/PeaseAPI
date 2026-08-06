<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>文档中心 - {{ $systemName }}</title>
<meta name="description" content="PeaseAPI 文档中心 - 部署指南、使用手册、API 文档">
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
.nav-logo{width:36px;height:36px;border-radius:10px;overflow:hidden;flex-shrink:0;background:var(--gradient);display:flex;align-items:center;justify-content:center}
.nav-logo img{width:100%;height:100%;object-fit:cover}
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
.hero{padding:80px 0 48px;text-align:center}
.hero-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;margin-bottom:24px;background:var(--gradient-soft);border:1px solid rgba(99,102,241,0.3);border-radius:999px;font-size:13px;color:var(--primary-light)}
.hero h1{font-size:clamp(32px,5vw,52px);font-weight:800;line-height:1.15;letter-spacing:-1.5px;margin-bottom:20px;background:linear-gradient(135deg,#fff 0%,#cbd5e1 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero h1 .grad{background:var(--gradient);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero p{font-size:clamp(15px,2vw,18px);color:var(--text-muted);max-width:640px;margin:0 auto 32px}
.docs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:24px;max-width:900px;margin:0 auto;padding:0 0 60px}
.doc-card{display:block;padding:36px 32px;border-radius:20px;background:var(--bg-card);border:1px solid var(--border);transition:all 0.3s;position:relative;overflow:hidden}
.doc-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--primary),transparent);opacity:0;transition:opacity 0.3s}
.doc-card:hover{transform:translateY(-6px);border-color:rgba(99,102,241,0.3);box-shadow:0 20px 40px rgba(0,0,0,0.3)}
.doc-card:hover::before{opacity:1}
.doc-icon{font-size:48px;margin-bottom:20px;display:block}
.doc-card h3{font-size:22px;font-weight:700;color:#fff;margin-bottom:10px}
.doc-card p{font-size:14px;color:var(--text-muted);line-height:1.7;margin-bottom:20px}
.doc-card .doc-arrow{display:inline-flex;align-items:center;gap:6px;font-size:14px;font-weight:600;color:var(--primary-light);transition:gap 0.2s}
.doc-card:hover .doc-arrow{gap:10px}
.doc-card .doc-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
.doc-tag{padding:4px 10px;font-size:12px;background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.2);border-radius:6px;color:var(--primary-light)}
.info-banner{max-width:900px;margin:0 auto 48px;padding:24px 28px;border-radius:16px;background:var(--gradient-soft);border:1px solid rgba(99,102,241,0.2);display:flex;align-items:center;gap:16px}
.info-banner .icon{font-size:28px;flex-shrink:0}
.info-banner .text{font-size:14px;color:var(--text-light);line-height:1.6}
.info-banner .text strong{color:#fff}
footer{padding:48px 0;border-top:1px solid var(--border);background:rgba(15,23,42,0.5)}
.footer-content{text-align:center;color:var(--text-muted);font-size:14px;line-height:1.8}
.footer-content a{color:var(--primary-light);transition:color 0.2s}
.footer-content a:hover{color:#fff}
@media (max-width:768px){.nav-links a:not(.btn){display:none}.hero{padding:48px 0 32px}.docs-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="bg-decoration"></div>
<nav>
    <div class="container">
        <a href="/" class="nav-brand">
            <div class="nav-logo">
                @if($systemLogo)<img src="{{ $systemLogo }}" alt="{{ $systemName }}">@else<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>@endif
            </div>
            <span class="nav-name">{{ $systemName }}</span>
        </a>
        <div class="nav-links">
            <a href="/docs" class="active">文档</a>
            <a href="/about">关于</a>
            <a href="/pricing">价格</a>
            @if($passwordLoginEnabled)<a href="/login" class="btn btn-ghost">登录</a>@endif
            @if($registerEnabled)<a href="/register" class="btn btn-primary">免费注册</a>@endif
        </div>
    </div>
</nav>

<section class="hero">
    <div class="container">
        <div class="hero-badge"><span>📚 完整产品文档</span></div>
        <h1>文档 <span class="grad">中心</span></h1>
        <p>在这里你可以找到 PeaseAPI 的全部文档，包括多种方式的部署指南和完整的产品使用手册。</p>
    </div>
</section>

<section class="container">
    <div class="info-banner">
        <div class="icon">💡</div>
        <div class="text">
            <strong>PeaseAPI</strong> 是基于 Laravel 11 的 100% PHP 重写版 AI API 中转网关，兼容 OpenAI 接口标准，支持 40+ 主流模型。
            文档涵盖从部署到使用的全部内容，帮助你快速上手。
        </div>
    </div>

    <div class="docs-grid">
        @foreach($docs as $doc)
        <a href="/docs/{{ $doc['slug'] }}" class="doc-card">
            <span class="doc-icon">{{ $doc['icon'] }}</span>
            <h3>{{ $doc['title'] }}</h3>
            <p>{{ $doc['description'] }}</p>
            <span class="doc-arrow">阅读文档 →</span>
            @if($doc['slug'] === 'deployment')
            <div class="doc-meta">
                <span class="doc-tag">独立服务器</span>
                <span class="doc-tag">宝塔面板</span>
                <span class="doc-tag">Docker</span>
                <span class="doc-tag">Nginx</span>
            </div>
            @elseif($doc['slug'] === 'usage-guide')
            <div class="doc-meta">
                <span class="doc-tag">系统设置</span>
                <span class="doc-tag">渠道管理</span>
                <span class="doc-tag">Coding Plan</span>
                <span class="doc-tag">API 调用</span>
            </div>
            @endif
        </a>
        @endforeach
    </div>
</section>

<footer>
    <div class="container">
        <div class="footer-content">
            @if(!empty($systemFooter))
                {!! $systemFooter !!}
            @else
                <p>&copy; {{ date('Y') }} {{ $systemName }}. All rights reserved.</p>
                <p><a href="/">首页</a> · <a href="/docs">文档</a> · <a href="/pricing">价格</a> · <a href="/about">关于</a></p>
            @endif
        </div>
    </div>
</footer>
</body>
</html>