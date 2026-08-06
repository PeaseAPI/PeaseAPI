@php
    $systemName = 'Pease API';
    $systemLogo = '';
    $systemFooter = '';
    $registerEnabled = true;
    $passwordLoginEnabled = true;
    $topUpRatio = 1;
    try {
        if (app()->bound('db') && \DB::connection()->getPdo()) {
            $systemName = \App\Services\OptionService::get('SystemName', $systemName);
            $systemLogo = \App\Services\OptionService::get('SystemLogo', '');
            $systemFooter = \App\Services\OptionService::get('SystemFooter', '');
            $registerEnabled = (bool) \App\Services\OptionService::get('RegisterEnabled', true);
            $passwordLoginEnabled = (bool) \App\Services\OptionService::get('PasswordLoginEnabled', true);
            $topUpRatio = (float) \App\Services\OptionService::get('TopUpRatio', 1);
        }
    } catch (\Throwable $e) {}

    $presetPlans = [
        ['name' => '入门版', 'price' => '¥9.9', 'period' => '/ 月', 'desc' => '适合个人开发者尝鲜体验', 'quota' => '500,000 Tokens', 'features' => ['GPT-3.5 / Claude Haiku', '标准响应速度', '社区技术支持', '基础数据分析'], 'highlight' => false],
        ['name' => '专业版', 'price' => '¥99', 'period' => '/ 月', 'desc' => '最受欢迎，适合中小团队', 'quota' => '8,000,000 Tokens', 'features' => ['全部 40+ 模型可用', 'GPT-4o / Claude 3.5 / Gemini Pro', '优先响应通道', '高级渠道负载均衡', '邮件技术支持'], 'highlight' => true],
        ['name' => '企业版', 'price' => '¥499', 'period' => '/ 月', 'desc' => '面向企业的高可用方案', 'quota' => '60,000,000 Tokens', 'features' => ['专属渠道与 IP 白名单', '无限团队成员席位', '专属技术支持经理', 'SLA 99.9% 保障', '私有化部署咨询'], 'highlight' => false],
    ];
    $dbPlans = collect();
    try {
        if (app()->bound('db') && \DB::connection()->getPdo() && \Schema::hasTable('subscription_plans')) {
            $dbPlans = \App\Models\SubscriptionPlan::where('status', 1)->orderBy('sort')->orderBy('price')->limit(6)->get();
        }
    } catch (\Throwable $e) {}
@endphp
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>价格 - {{ $systemName }}</title>
<meta name="description" content="{{ $systemName }} 定价方案 - 灵活的订阅套餐与按量计费">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --primary:#6366f1;--primary-light:#818cf8;--accent:#8b5cf6;
    --bg-darker:#020617;--bg-card:#1e293b;
    --text-light:#f1f5f9;--text-muted:#94a3b8;
    --border:rgba(148,163,184,0.15);
    --gradient:linear-gradient(135deg,#6366f1 0%,#8b5cf6 50%,#ec4899 100%);
    --gradient-soft:linear-gradient(135deg,rgba(99,102,241,0.15) 0%,rgba(139,92,246,0.15) 100%);
}
html{scroll-behavior:smooth}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;background:var(--bg-darker);color:var(--text-light);line-height:1.6;overflow-x:hidden;min-height:100vh}
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
.btn-block{display:flex;justify-content:center;width:100%}
.hero{padding:72px 0 40px;text-align:center}
.hero-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;margin-bottom:24px;background:var(--gradient-soft);border:1px solid rgba(99,102,241,0.3);border-radius:999px;font-size:13px;color:var(--primary-light)}
.hero-badge .dot{width:8px;height:8px;border-radius:50%;background:#22c55e;box-shadow:0 0 8px #22c55e}
.hero h1{font-size:clamp(32px,5vw,52px);font-weight:800;line-height:1.15;letter-spacing:-1.5px;margin-bottom:20px;background:linear-gradient(135deg,#fff 0%,#cbd5e1 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero h1 .grad{background:var(--gradient);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero p{font-size:clamp(15px,2vw,18px);color:var(--text-muted);max-width:640px;margin:0 auto 32px}
.plans{padding:0 0 80px}
.plans-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;max-width:1100px;margin:0 auto}
.plan-card{position:relative;padding:36px 28px;border-radius:20px;background:var(--bg-card);border:1px solid var(--border);transition:all 0.3s;display:flex;flex-direction:column}
.plan-card:hover{transform:translateY(-4px);border-color:rgba(99,102,241,0.3)}
.plan-card.featured{border-color:rgba(99,102,241,0.5);background:linear-gradient(180deg,rgba(99,102,241,0.08) 0%,var(--bg-card) 50%);box-shadow:0 20px 50px rgba(99,102,241,0.15)}
.plan-card.featured::before{content:'最受欢迎';position:absolute;top:-12px;left:50%;transform:translateX(-50%);padding:4px 14px;font-size:12px;font-weight:700;background:var(--gradient);color:#fff;border-radius:999px;box-shadow:0 4px 12px rgba(99,102,241,0.4)}
.plan-name{font-size:18px;font-weight:700;color:#fff;margin-bottom:8px}
.plan-desc{font-size:13px;color:var(--text-muted);margin-bottom:24px;min-height:40px}
.plan-price{display:flex;align-items:baseline;gap:4px;margin-bottom:6px}
.plan-price .amount{font-size:42px;font-weight:800;background:linear-gradient(135deg,#fff 0%,#cbd5e1 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.plan-card.featured .plan-price .amount{background:var(--gradient);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.plan-price .period{font-size:14px;color:var(--text-muted)}
.plan-quota{display:inline-block;padding:4px 10px;font-size:12px;background:rgba(99,102,241,0.12);color:var(--primary-light);border-radius:6px;margin-bottom:24px;font-weight:500}
.plan-features{list-style:none;margin-bottom:32px;flex:1}
.plan-features li{display:flex;align-items:flex-start;gap:10px;padding:8px 0;font-size:14px;color:var(--text-muted);border-bottom:1px solid var(--border)}
.plan-features li:last-child{border-bottom:none}
.plan-features li::before{content:'✓';color:#22c55e;font-weight:700;flex-shrink:0}
.payg{padding:60px 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border)}
.payg-inner{display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:center;max-width:1100px;margin:0 auto}
.payg-content h2{font-size:clamp(24px,3vw,32px);font-weight:700;margin-bottom:16px}
.payg-content p{color:var(--text-muted);font-size:15px;margin-bottom:24px}
.payg-features{list-style:none}
.payg-features li{display:flex;align-items:flex-start;gap:10px;padding:6px 0;font-size:14px;color:var(--text-muted)}
.payg-features li::before{content:'✦';color:var(--primary-light);flex-shrink:0}
.payg-card{padding:32px;border-radius:20px;background:var(--bg-card);border:1px solid var(--border);text-align:center}
.payg-card .label{font-size:13px;color:var(--text-muted);margin-bottom:8px}
.payg-card .ratio{font-size:48px;font-weight:800;background:var(--gradient);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:8px}
.payg-card .unit{font-size:14px;color:var(--text-muted);margin-bottom:24px}
.payg-card .example{padding:16px;background:rgba(15,23,42,0.6);border-radius:12px;font-size:13px;color:var(--text-muted);text-align:left;margin-bottom:24px;font-family:"SF Mono",Monaco,Consolas,monospace}
.payg-card .example .hl{color:var(--primary-light)}
.faq{padding:80px 0}
.section-title{text-align:center;margin-bottom:48px}
.section-title h2{font-size:clamp(24px,3vw,32px);font-weight:700;margin-bottom:12px}
.section-title p{color:var(--text-muted);font-size:15px}
.faq-list{max-width:760px;margin:0 auto}
.faq-item{padding:20px 24px;border-radius:12px;background:var(--bg-card);border:1px solid var(--border);margin-bottom:12px}
.faq-item h3{font-size:16px;font-weight:600;color:#fff;margin-bottom:8px}
.faq-item p{font-size:14px;color:var(--text-muted)}
.cta{padding:80px 0;text-align:center}
.cta-box{padding:64px 32px;border-radius:24px;background:var(--gradient-soft);border:1px solid rgba(99,102,241,0.3)}
.cta-box h2{font-size:clamp(28px,4vw,36px);font-weight:700;margin-bottom:16px}
.cta-box p{color:var(--text-muted);font-size:16px;margin-bottom:32px}
.cta-buttons{display:flex;flex-wrap:wrap;justify-content:center;gap:16px}
footer{padding:48px 0;border-top:1px solid var(--border);background:rgba(15,23,42,0.5)}
.footer-content{text-align:center;color:var(--text-muted);font-size:14px;line-height:1.8}
.footer-content a{color:var(--primary-light);transition:color 0.2s}
.footer-content a:hover{color:#fff}
@media (max-width:768px){
    .payg-inner{grid-template-columns:1fr}
    .nav-links a:not(.btn){display:none}
    .hero{padding:48px 0 32px}
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
            <a href="/pricing" class="active">价格</a>
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

<section class="hero">
    <div class="container">
        <div class="hero-badge">
            <span class="dot"></span>
            <span>透明定价 · 无隐藏费用</span>
        </div>
        <h1>选择适合你的 <span class="grad">定价方案</span></h1>
        <p>无论是个人开发者还是大型企业，我们都有适合你的方案。订阅套餐更划算，按量付费更灵活。</p>
    </div>
</section>

<section class="plans">
    <div class="container">
        <div class="plans-grid">
            @foreach($presetPlans as $plan)
            <div class="plan-card @if($plan['highlight']) featured @endif">
                <div class="plan-name">{{ $plan['name'] }}</div>
                <div class="plan-desc">{{ $plan['desc'] }}</div>
                <div class="plan-price">
                    <span class="amount">{{ $plan['price'] }}</span>
                    <span class="period">{{ $plan['period'] }}</span>
                </div>
                <div class="plan-quota">{{ $plan['quota'] }}</div>
                <ul class="plan-features">
                    @foreach($plan['features'] as $feature)
                    <li>{{ $feature }}</li>
                    @endforeach
                </ul>
                @auth
                <a href="/dashboard" class="btn @if($plan['highlight']) btn-primary @else btn-ghost @endif btn-block">选择 {{ $plan['name'] }}</a>
                @else
                <a href="/register" class="btn @if($plan['highlight']) btn-primary @else btn-ghost @endif btn-block">立即注册</a>
                @endauth
            </div>
            @endforeach
        </div>
    </div>
</section>

<section class="payg">
    <div class="container">
        <div class="payg-inner">
            <div class="payg-content">
                <h2>按量付费，灵活自由</h2>
                <p>不想订阅？没关系，我们同样支持按量付费模式。充值余额，按实际使用量扣费，用多少算多少。</p>
                <ul class="payg-features">
                    <li>无最低消费，用多少扣多少</li>
                    <li>支持微信、支付宝、Stripe 充值</li>
                    <li>余额永久有效，不过期</li>
                    <li>实时账单明细，透明可控</li>
                </ul>
            </div>
            <div class="payg-card">
                <div class="label">当前充值比例</div>
                <div class="ratio">1 : {{ number_format($topUpRatio, 2) }}</div>
                <div class="unit">人民币 ¥ ↔ 平台额度</div>
                <div class="example">
                    充值 <span class="hl">¥100.00</span><br>
                    获得 <span class="hl">{{ number_format(100 * $topUpRatio, 2) }}</span> 额度<br>
                    约可调用 GPT-4o <span class="hl">{{ number_format(100 * $topUpRatio / 0.03) }}</span> 次
                </div>
                @auth
                <a href="/dashboard/wallet" class="btn btn-primary btn-block">立即充值</a>
                @else
                <a href="/register" class="btn btn-primary btn-block">注册后充值</a>
                @endauth
            </div>
        </div>
    </div>
</section>

@if($dbPlans->isNotEmpty())
<section class="plans" style="padding-top:60px">
    <div class="container">
        <div class="section-title">
            <h2>更多订阅套餐</h2>
            <p>以下套餐由系统管理员配置</p>
        </div>
        <div class="plans-grid">
            @foreach($dbPlans as $plan)
            <div class="plan-card">
                <div class="plan-name">{{ $plan->name }}</div>
                <div class="plan-desc">{{ $plan->description ?: '自定义套餐' }}</div>
                <div class="plan-price">
                    <span class="amount">¥{{ number_format($plan->price, 2) }}</span>
                    <span class="period">/ {{ $plan->duration_value ?? 1 }} {{ $plan->duration_unit_label ?? '月' }}</span>
                </div>
                @if($plan->quota)
                <div class="plan-quota">{{ number_format($plan->quota) }} Tokens</div>
                @endif
                @auth
                <a href="/dashboard/subscription" class="btn btn-ghost btn-block">订阅此套餐</a>
                @else
                <a href="/register" class="btn btn-ghost btn-block">立即注册</a>
                @endauth
            </div>
            @endforeach
        </div>
    </div>
</section>
@endif

<section class="faq">
    <div class="container">
        <div class="section-title">
            <h2>常见问题</h2>
            <p>关于定价和计费的疑问</p>
        </div>
        <div class="faq-list">
            <div class="faq-item">
                <h3>计费单位是什么？</h3>
                <p>我们按 Token 计费，与 OpenAI 等官方保持一致。不同模型有不同的单价比例，可在控制台查看实时用量与扣费明细。</p>
            </div>
            <div class="faq-item">
                <h3>订阅套餐可以随时取消吗？</h3>
                <p>可以。订阅套餐在当前计费周期内继续有效，到期后不会自动续费。已使用的额度不会丢失。</p>
            </div>
            <div class="faq-item">
                <h3>充值余额会过期吗？</h3>
                <p>不会。充值获得的余额永久有效，可随时用于任何模型的调用，无使用期限限制。</p>
            </div>
            <div class="faq-item">
                <h3>支持哪些支付方式？</h3>
                <p>支持微信支付、支付宝、银行卡（Stripe）、易支付等多种方式。企业用户可联系销售获取对公转账通道。</p>
            </div>
            <div class="faq-item">
                <h3>能否开具发票？</h3>
                <p>企业版套餐支持开具增值税普通发票/专用发票，请在控制台提交开票申请或联系客服处理。</p>
            </div>
        </div>
    </div>
</section>

<section class="cta">
    <div class="container">
        <div class="cta-box">
            <h2>还有疑问？立即开始体验</h2>
            <p>注册即送免费额度，几分钟内即可集成到你的应用中</p>
            <div class="cta-buttons">
                @if($registerEnabled)
                <a href="/register" class="btn btn-primary btn-lg">免费注册</a>
                @endif
                @if($passwordLoginEnabled)
                <a href="/login" class="btn btn-ghost btn-lg">登录控制台</a>
                @endif
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
