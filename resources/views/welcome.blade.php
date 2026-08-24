@php
    $systemName = 'Pease API';
    $systemLogo = '';
    $homePageContent = '';
    $systemFooter = '';
    $registerEnabled = true;
    $passwordLoginEnabled = true;
    try {
        if (app()->bound('db') && \DB::connection()->getPdo()) {
            $systemName = \App\Services\OptionService::get('SystemName', $systemName);
            $systemLogo = \App\Services\OptionService::get('SystemLogo', '');
            $homePageContent = \App\Services\OptionService::get('HomePageContent', '');
            $systemFooter = \App\Services\OptionService::get('SystemFooter', '');
            $registerEnabled = (bool) \App\Services\OptionService::get('RegisterEnabled', true);
            $passwordLoginEnabled = (bool) \App\Services\OptionService::get('PasswordLoginEnabled', true);
            // 服务器地址：优先读取系统设置，回退到 app.url，再回退到当前请求来源
            $serverAddress = (string) \App\Services\OptionService::get('ServerAddress', '');
            if ($serverAddress === '') {
                $serverAddress = (string) config('app.url', '');
            }
            if ($serverAddress === '') {
                $serverAddress = request()->getSchemeAndHttpHost();
            }
            $serverAddress = rtrim($serverAddress, '/');
        }
    } catch (\Throwable $e) {
    }
@endphp
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $systemName }} - AI API 中转网关</title>
    <meta name="description" content="强大的 AI API 中转网关，提供 OpenAI、Claude、Gemini、Midjourney 等 40+ 主流模型的统一访问接口">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        :root{
            --primary:#6366f1;--primary-dark:#4f46e5;--primary-light:#818cf8;--accent:#8b5cf6;
            --bg-dark:#0f172a;--bg-darker:#020617;--bg-card:#1e293b;
            --text-light:#f1f5f9;--text-muted:#94a3b8;
            --border:rgba(148,163,184,0.15);
            --gradient:linear-gradient(135deg,#6366f1 0%,#8b5cf6 50%,#ec4899 100%);
            --gradient-soft:linear-gradient(135deg,rgba(99,102,241,0.15) 0%,rgba(139,92,246,0.15) 100%);
        }
        html{scroll-behavior:smooth}
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei","Helvetica Neue",Helvetica,Arial,sans-serif;background:var(--bg-darker);color:var(--text-light);line-height:1.6;overflow-x:hidden;min-height:100vh}
        a{color:inherit;text-decoration:none}
        .container{max-width:1200px;margin:0 auto;padding:0 24px}
        .bg-decoration{position:fixed;inset:0;z-index:-1;overflow:hidden;pointer-events:none}
        .bg-decoration::before{content:'';position:absolute;top:-20%;left:-10%;width:60%;height:60%;border-radius:50%;background:radial-gradient(circle,rgba(99,102,241,0.25) 0%,transparent 70%);filter:blur(80px)}
        .bg-decoration::after{content:'';position:absolute;bottom:-20%;right:-10%;width:50%;height:50%;border-radius:50%;background:radial-gradient(circle,rgba(236,72,153,0.2) 0%,transparent 70%);filter:blur(80px)}
        nav{position:sticky;top:0;z-index:100;background:rgba(15,23,42,0.8);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid var(--border)}
        nav .container{display:flex;align-items:center;justify-content:space-between;height:64px}
        .nav-brand{display:flex;align-items:center;gap:12px}
        .nav-logo{width:36px;height:36px;border-radius:10px;overflow:hidden;flex-shrink:0}
        .nav-logo img{width:100%;height:100%;object-fit:cover}
        .nav-name{font-size:18px;font-weight:700;color:#fff;letter-spacing:-0.5px}
        .nav-links{display:flex;align-items:center;gap:8px}
        .nav-links a{padding:8px 16px;font-size:14px;color:var(--text-muted);transition:color 0.2s,background 0.2s;border-radius:8px}
        .nav-links a:hover{color:#fff;background:rgba(255,255,255,0.05)}
        .btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;font-size:14px;font-weight:600;border-radius:10px;transition:all 0.2s;cursor:pointer;border:none;outline:none}
        .btn-primary{background:var(--gradient);color:#fff;box-shadow:0 4px 14px rgba(99,102,241,0.4)}
        .btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(99,102,241,0.5)}
        .btn-ghost{background:rgba(255,255,255,0.06);color:var(--text-light);border:1px solid var(--border)}
        .btn-ghost:hover{background:rgba(255,255,255,0.1)}
        .hero{padding:80px 0 60px;text-align:center;position:relative}
        .hero-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;margin-bottom:28px;background:var(--gradient-soft);border:1px solid rgba(99,102,241,0.3);border-radius:999px;font-size:13px;color:var(--primary-light)}
        .hero-badge .dot{width:8px;height:8px;border-radius:50%;background:#22c55e;box-shadow:0 0 8px #22c55e}
        .hero-logo{width:88px;height:88px;margin:0 auto 32px;border-radius:24px;overflow:hidden;box-shadow:0 20px 50px rgba(99,102,241,0.5)}
        .hero-logo img{width:100%;height:100%;object-fit:cover}
        .hero h1{font-size:clamp(36px,6vw,64px);font-weight:800;line-height:1.1;letter-spacing:-2px;margin-bottom:24px;background:linear-gradient(135deg,#fff 0%,#cbd5e1 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
        .hero h1 .grad{background:var(--gradient);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
        .hero p{font-size:clamp(16px,2vw,20px);color:var(--text-muted);max-width:680px;margin:0 auto 40px;line-height:1.7}
        .hero-cta{display:flex;flex-wrap:wrap;justify-content:center;gap:16px;margin-bottom:56px}
        .btn-lg{padding:14px 32px;font-size:16px;border-radius:12px}
        .model-cloud{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;max-width:800px;margin:0 auto}
        .model-tag{padding:6px 14px;font-size:13px;font-weight:500;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:999px;color:var(--text-muted);transition:all 0.2s}
        .model-tag:hover{color:#fff;border-color:var(--primary);background:rgba(99,102,241,0.1)}
        .features{padding:80px 0}
        .section-title{text-align:center;margin-bottom:56px}
        .section-title h2{font-size:clamp(28px,4vw,40px);font-weight:700;margin-bottom:16px;letter-spacing:-1px}
        .section-title p{color:var(--text-muted);font-size:16px}
        .features-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:24px}
        .feature-card{padding:32px;border-radius:16px;background:var(--bg-card);border:1px solid var(--border);transition:all 0.3s;position:relative;overflow:hidden}
        .feature-card::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,var(--primary),transparent);opacity:0;transition:opacity 0.3s}
        .feature-card:hover{transform:translateY(-4px);border-color:rgba(99,102,241,0.3)}
        .feature-card:hover::before{opacity:1}
        .feature-icon{width:48px;height:48px;border-radius:12px;margin-bottom:20px;display:flex;align-items:center;justify-content:center}
        .feature-icon svg{width:24px;height:24px}
        .icon-indigo{background:rgba(99,102,241,0.15);color:#818cf8}
        .icon-green{background:rgba(34,197,94,0.15);color:#4ade80}
        .icon-purple{background:rgba(139,92,246,0.15);color:#a78bfa}
        .icon-pink{background:rgba(236,72,153,0.15);color:#f472b6}
        .feature-card h3{font-size:18px;font-weight:700;margin-bottom:8px;color:#fff}
        .feature-card p{color:var(--text-muted);font-size:14px;line-height:1.6}
        .stats{padding:60px 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border)}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:32px;text-align:center}
        .stat-num{font-size:36px;font-weight:800;background:var(--gradient);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:8px}
        .stat-label{color:var(--text-muted);font-size:14px}
        .code-section{padding:80px 0}
        .code-wrap{max-width:800px;margin:0 auto;border-radius:16px;overflow:hidden;border:1px solid var(--border);background:#0d1117}
        .code-header{display:flex;align-items:center;gap:8px;padding:12px 16px;background:#161b22;border-bottom:1px solid var(--border)}
        .code-dot{width:12px;height:12px;border-radius:50%}
        .code-dot.red{background:#ff5f56}.code-dot.yellow{background:#ffbd2e}.code-dot.green{background:#27c93f}
        .code-title{margin-left:8px;font-size:13px;color:var(--text-muted)}
        .code-body{padding:24px;font-family:"SF Mono",Monaco,"Cascadia Code","Roboto Mono",Consolas,monospace;font-size:14px;line-height:1.8;overflow-x:auto}
        .code-body .kw{color:#ff7b72}.code-body .str{color:#a5d6ff}.code-body .com{color:#8b949e}.code-body .fn{color:#d2a8ff}.code-body .var{color:#79c0ff}
        .cta{padding:80px 0;text-align:center}
        .cta-box{padding:64px 32px;border-radius:24px;background:var(--gradient-soft);border:1px solid rgba(99,102,241,0.3);position:relative;overflow:hidden}
        .cta-box h2{font-size:clamp(28px,4vw,36px);font-weight:700;margin-bottom:16px}
        .cta-box p{color:var(--text-muted);font-size:16px;margin-bottom:32px}
        footer{padding:48px 0;border-top:1px solid var(--border);background:rgba(15,23,42,0.5)}
        .footer-content{text-align:center;color:var(--text-muted);font-size:14px;line-height:1.8}
        .footer-content a{color:var(--primary-light);transition:color 0.2s}
        .footer-content a:hover{color:#fff}
        @media (max-width:640px){
            .nav-links a:not(.btn){display:none}
            .hero{padding:48px 0 40px}
            .features{padding:48px 0}
            .code-section{padding:48px 0}
        }
    </style>
</head>
<body>
    <div class="bg-decoration"></div>
    <nav>
        <div class="container">
            <a href="/" class="nav-brand">
                <div class="nav-logo">
                    <img src="{{ $systemLogo ?: '/logo.png' }}" alt="{{ $systemName }}">
                </div>
                <span class="nav-name">{{ $systemName }}</span>
            </a>
            <div class="nav-links">
                <a href="/docs">文档</a>
                <a href="/pricing">价格</a>
                <a href="/about">关于</a>
                @if($passwordLoginEnabled)
                <a href="/login" class="btn btn-ghost">登录</a>
                @endif
                @if($registerEnabled)
                <a href="/register" class="btn btn-primary">免费注册</a>
                @endif
            </div>
        </div>
    </nav>

    @if(!empty($homePageContent))
        <section class="container" style="padding:60px 24px">
            <div style="max-width:800px;margin:0 auto;color:var(--text-light);line-height:1.8">{!! $homePageContent !!}</div>
        </section>
    @else
    <section class="hero">
        <div class="container">
            <div class="hero-badge">
                <span class="dot"></span>
                <span>40+ 主流 AI 模型 · 统一 API 接口</span>
            </div>
            <div class="hero-logo">
                <img src="{{ $systemLogo ?: '/logo.png' }}" alt="{{ $systemName }}">
            </div>
            <h1>强大的 AI API <span class="grad">中转网关</span></h1>
            <p>提供 OpenAI、Claude、Gemini、Midjourney 等 40+ 主流模型的统一访问接口，支持 Token 计费、渠道管理、负载均衡，让 AI 开发更简单高效。</p>
            <div class="hero-cta">
                @if($registerEnabled)
                <a href="/register" class="btn btn-primary btn-lg">免费注册
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                </a>
                @endif
                @if($passwordLoginEnabled)
                <a href="/login" class="btn btn-ghost btn-lg">登录控制台</a>
                @endif
            </div>
            <div class="model-cloud">
                <span class="model-tag">GPT-4o</span>
                <span class="model-tag">Claude 3.5 Sonnet</span>
                <span class="model-tag">Gemini 1.5 Pro</span>
                <span class="model-tag">Midjourney</span>
                <span class="model-tag">DeepSeek</span>
                <span class="model-tag">DALL·E 3</span>
                <span class="model-tag">Suno</span>
                <span class="model-tag">Llama 3</span>
                <span class="model-tag">Qwen</span>
                <span class="model-tag">+31 更多</span>
            </div>
        </div>
    </section>

    <section class="features">
        <div class="container">
            <div class="section-title">
                <h2>为什么选择 {{ $systemName }}</h2>
                <p>专为开发者和企业打造的 AI API 中转平台</p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon icon-indigo">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h3>安全可靠</h3>
                    <p>企业级安全保障，支持 Token 权限管理、调用频率限制、敏感内容过滤，确保 API 调用安全可控。</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon icon-green">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/></svg>
                    </div>
                    <h3>多模型支持</h3>
                    <p>支持 GPT、Claude、Gemini、Midjourney、Suno 等 40+ 主流模型，统一接口，一个 Key 访问所有模型。</p>
                </div>
                                <div class="feature-card">
                    <div class="feature-icon icon-purple">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <h3>灵活计费</h3>
                    <p>按 Token 精确计费，支持多种支付方式充值，实时统计用量明细，让成本透明可控。</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon icon-pink">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h3>高性能</h3>
                    <p>智能渠道负载均衡与故障自动切换，毫秒级响应，99.9% 可用性保障，轻松应对高并发场景。</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon icon-indigo">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
                    </div>
                    <h3>新闻搜索聚合</h3>
                    <p>聚合 Google CSE、NewsAPI、Tavily、Exa 四大搜索源，统一 API 接口，支持渠道路由与配额计费，轻松构建新闻搜索应用。</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon icon-green">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                    </div>
                    <h3>Coding Plan 账号池</h3>
                    <p>Claude Code / Cursor 等编程订阅账号池化管理，5h/周/月滚动窗口配额，自动切换与优先级调度。</p>
                </div>
            </div>
        </div>
    </section>

    <section class="stats">
        <div class="container">
            <div class="stats-grid">
                <div><div class="stat-num">40+</div><div class="stat-label">AI 模型</div></div>
                <div><div class="stat-num">99.9%</div><div class="stat-label">服务可用性</div></div>
                <div><div class="stat-num">毫秒级</div><div class="stat-label">响应速度</div></div>
                <div><div class="stat-num">7×24</div><div class="stat-label">技术支持</div></div>
            </div>
        </div>
    </section>

    <section class="code-section">
        <div class="container">
            <div class="section-title">
                <h2>简单易用的 API</h2>
                <p>兼容 OpenAI 接口标准，零成本切换</p>
            </div>
            <div class="code-wrap">
                <div class="code-header">
                    <span class="code-dot red"></span>
                    <span class="code-dot yellow"></span>
                    <span class="code-dot green"></span>
                    <span class="code-title">quick-start.py</span>
                </div>
                <div class="code-body"><span class="kw">from</span> openai <span class="kw">import</span> OpenAI

client = OpenAI(
    api_key=<span class="str">"sk-your-token"</span>,
    base_url=<span class="str">"{{ $serverAddress }}/v1"</span>
)

response = client.chat.completions.create(
    model=<span class="str">"gpt-4o"</span>,
    messages=[{<span class="str">"role"</span>: <span class="str">"user"</span>, <span class="str">"content"</span>: <span class="str">"Hello!"</span>}]
)

<span class="com"># 同样支持 Claude / Gemini / DeepSeek 等模型</span>
<span class="kw">print</span>(response.choices[<span class="var">0</span>].message.content)</div>
                        </div>
        </div>
    </section>

    <section class="code-section">
        <div class="container">
            <div class="section-title">
                <h2>新闻搜索 API</h2>
                <p>聚合四大搜索源，一行代码获取全球新闻</p>
            </div>
            <div class="code-wrap">
                <div class="code-header">
                    <span class="code-dot red"></span>
                    <span class="code-dot yellow"></span>
                    <span class="code-dot green"></span>
                    <span class="code-title">news-search.py</span>
                </div>
                <div class="code-body"><span class="kw">import</span> requests

<span class="com"># 搜索新闻 — 自动路由到 Google CSE / NewsAPI / Tavily / Exa</span>
response = requests.post(
    <span class="str">"{{ $serverAddress }}/news/search"</span>,
    headers={<span class="str">"Authorization"</span>: <span class="str">"Bearer sk-your-token"</span>},
    json={<span class="str">"query"</span>: <span class="str">"AI 最新进展"</span>, <span class="str">"limit"</span>: <span class="var">5</span>}
)

<span class="kw">for</span> article <span class="kw">in</span> response.json()[<span class="str">"results"</span>]:
    <span class="kw">print</span>(article[<span class="str">"title"</span>], article[<span class="str">"url"</span>])

<span class="com"># 查看可用的搜索源</span>
providers = requests.get(
    <span class="str">"{{ $serverAddress }}/news/providers"</span>,
    headers={<span class="str">"Authorization"</span>: <span class="str">"Bearer sk-your-token"</span>}
)
<span class="kw">print</span>(providers.json())</div>
            </div>
        </div>
    </section>

    <section class="cta">
        <div class="container">
            <div class="cta-box">
                <h2>立即开始你的 AI 之旅</h2>
                <p>注册即送免费额度，几分钟内即可集成到你的应用中</p>
                <div class="hero-cta">
                    @if($registerEnabled)
                    <a href="/register" class="btn btn-primary btn-lg">免费注册</a>
                    @endif
                    @if($passwordLoginEnabled)
                    <a href="/login" class="btn btn-ghost btn-lg">登录</a>
                    @endif
                </div>
            </div>
        </div>
    </section>
    @endif

    <footer>
        <div class="container">
            <div class="footer-content">
                @if(!empty($systemFooter))
                    {!! $systemFooter !!}
                @else
                    <p>&copy; {{ date('Y') }} {{ $systemName }}. All rights reserved.</p>
                    <p><a href="/docs">文档</a> · <a href="/pricing">价格</a> · <a href="/about">关于</a> · <a href="/user-agreement">用户协议</a> · <a href="/privacy-policy">隐私政策</a></p>
                @endif
            </div>
        </div>
    </footer>
</body>
</html>
