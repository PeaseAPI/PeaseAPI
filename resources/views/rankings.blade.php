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

    // 安全获取排行榜数据：优先 Usedata 模型，回退空集合
    $rankings = collect();
    try {
        if (class_exists('\App\Models\Usedata')) {
            $rankings = \App\Models\Usedata::orderByDesc('total_quota')->limit(50)->get();
        }
    } catch (\Throwable $e) {}
@endphp
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>排行榜 - {{ $systemName }}</title>
<meta name="description" content="{{ $systemName }} 用户用量排行榜">
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
.hero{padding:72px 0 32px;text-align:center}
.hero-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;margin-bottom:24px;background:var(--gradient-soft);border:1px solid rgba(99,102,241,0.3);border-radius:999px;font-size:13px;color:var(--primary-light)}
.hero h1{font-size:clamp(32px,5vw,48px);font-weight:800;line-height:1.15;letter-spacing:-1.5px;margin-bottom:16px;background:linear-gradient(135deg,#fff 0%,#cbd5e1 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero h1 .grad{background:var(--gradient);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero p{font-size:16px;color:var(--text-muted);max-width:560px;margin:0 auto}
.section{padding:32px 0 60px}
.rank-card{padding:32px;border-radius:20px;background:var(--bg-card);border:1px solid var(--border);overflow:hidden}
.rank-header{display:grid;grid-template-columns:80px 1fr 160px 140px;gap:16px;padding:0 16px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em}
.rank-row{display:grid;grid-template-columns:80px 1fr 160px 140px;gap:16px;padding:18px 16px;border-bottom:1px solid var(--border);align-items:center;transition:background 0.15s}
.rank-row:hover{background:rgba(99,102,241,0.04)}
.rank-row:last-child{border-bottom:none}
.rank-num{font-size:18px;font-weight:700}
.rank-num.top-1{color:#fbbf24}
.rank-num.top-2{color:#94a3b8}
.rank-num.top-3{color:#fb923c}
.rank-user{display:flex;align-items:center;gap:12px}
.rank-avatar{width:36px;height:36px;border-radius:50%;background:var(--gradient-soft);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:600;color:var(--primary-light);flex-shrink:0}
.rank-name{font-size:15px;font-weight:500}
.rank-quota{font-family:"SF Mono",Monaco,monospace;font-size:15px;font-weight:600;color:var(--primary-light)}
.rank-req{font-family:"SF Mono",Monaco,monospace;font-size:14px;color:var(--text-muted)}
.empty-state{text-align:center;padding:64px 24px}
.empty-state .icon{font-size:48px;margin-bottom:16px;opacity:0.5}
.empty-state h3{font-size:18px;font-weight:600;margin-bottom:8px}
.empty-state p{color:var(--text-muted);font-size:14px}
.podium{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:32px}
.podium-item{text-align:center;padding:24px 16px;border-radius:16px;background:var(--bg-card);border:1px solid var(--border);position:relative}
.podium-item.gold{border-color:rgba(251,191,36,0.4);background:linear-gradient(180deg,rgba(251,191,36,0.08) 0%,var(--bg-card) 100%)}
.podium-item.silver{border-color:rgba(148,163,184,0.4);background:linear-gradient(180deg,rgba(148,163,184,0.08) 0%,var(--bg-card) 100%)}
.podium-item.bronze{border-color:rgba(251,146,60,0.4);background:linear-gradient(180deg,rgba(251,146,60,0.08) 0%,var(--bg-card) 100%)}
.podium-medal{font-size:32px;margin-bottom:8px}
.podium-name{font-size:15px;font-weight:600;margin-bottom:4px}
.podium-quota{font-size:13px;color:var(--text-muted)}
footer{padding:48px 0;border-top:1px solid var(--border);background:rgba(15,23,42,0.5)}
.footer-content{text-align:center;color:var(--text-muted);font-size:14px;line-height:1.8}
.footer-content a{color:var(--primary-light);transition:color 0.2s}
.footer-content a:hover{color:#fff}
@media (max-width:768px){
    .nav-links a:not(.btn){display:none}
    .hero{padding:48px 0 24px}
    .rank-header,.rank-row{grid-template-columns:50px 1fr 100px}
    .rank-req{display:none}
    .rank-header>:nth-child(4){display:none}
}
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
            <a href="/about">关于</a>
            <a href="/pricing">价格</a>
            <a href="/rankings" class="active">排行榜</a>
            @if($passwordLoginEnabled)<a href="/login" class="btn btn-ghost">登录</a>@endif
            @if($registerEnabled)<a href="/register" class="btn btn-primary">免费注册</a>@endif
        </div>
    </div>
</nav>

<section class="hero">
    <div class="container">
        <div class="hero-badge"><span>🏆 用户排行榜</span></div>
        <h1>用量 <span class="grad">排行榜</span></h1>
        <p>看看谁是平台上最活跃的用户。排行榜按 Token 用量统计，实时更新。</p>
    </div>
</section>

<section class="section">
    <div class="container">
        @if($rankings->count() >= 3)
        <div class="podium">
            @php $top3 = $rankings->take(3); @endphp
            @foreach($top3 as $i => $r)
            <div class="podium-item {{ ['gold','silver','bronze'][$i] }}">
                <div class="podium-medal">{{ ['🥇','🥈','🥉'][$i] }}</div>
                <div class="podium-name">{{ $r->username ?? 'Anonymous' }}</div>
                <div class="podium-quota">{{ number_format($r->total_quota ?? 0) }} tokens</div>
            </div>
            @endforeach
        </div>
        @endif

        <div class="rank-card">
            <div class="rank-header">
                <div>排名</div>
                <div>用户</div>
                <div>Token 用量</div>
                <div>请求数</div>
            </div>
            @if($rankings->count() > 0)
                @foreach($rankings as $i => $r)
                <div class="rank-row">
                    <div class="rank-num {{ $i < 3 ? 'top-'.($i+1) : '' }}">#{{ $i + 1 }}</div>
                    <div class="rank-user">
                        <div class="rank-avatar">{{ mb_substr($r->username ?? 'A', 0, 1) }}</div>
                        <div class="rank-name">{{ $r->username ?? 'Anonymous' }}</div>
                    </div>
                    <div class="rank-quota">{{ number_format($r->total_quota ?? 0) }}</div>
                    <div class="rank-req">{{ number_format($r->total_requests ?? 0) }}</div>
                </div>
                @endforeach
            @else
                <div class="empty-state">
                    <div class="icon">📊</div>
                    <h3>暂无排行数据</h3>
                    <p>还没有用户产生用量。开始使用 API 后，排行榜将自动更新。</p>
                </div>
            @endif
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
                <p><a href="/">首页</a> · <a href="/pricing">价格</a> · <a href="/about">关于</a> · <a href="/rankings">排行榜</a></p>
            @endif
        </div>
    </div>
</footer>
</body>
</html>