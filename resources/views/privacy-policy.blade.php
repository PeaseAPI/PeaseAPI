@php
    $systemName = 'Pease API';
    $systemFooter = '';
    try {
        if (app()->bound('db') && \DB::connection()->getPdo()) {
            $systemName = \App\Services\OptionService::get('SystemName', $systemName);
            $systemFooter = \App\Services\OptionService::get('SystemFooter', '');
        }
    } catch (\Throwable $e) {}
    $content = '';
    try { $content = \App\Services\OptionService::get('PrivacyPolicy', ''); } catch (\Throwable $e) {}
    $updatedAt = '';
    try { $updatedAt = \App\Services\OptionService::get('PrivacyPolicyUpdatedAt', ''); } catch (\Throwable $e) {}
@endphp
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>隐私政策 · {{ $systemName }}</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --bg-darker:#020617;
    --bg-card:rgba(30,41,59,0.6);
    --bg-card-solid:#1e293b;
    --text-light:#f1f5f9;
    --text-muted:#94a3b8;
    --text-dim:#64748b;
    --border:rgba(148,163,184,0.15);
    --border-strong:rgba(148,163,184,0.25);
    --primary:#6366f1;
    --primary-light:#818cf8;
    --primary-glow:rgba(99,102,241,0.35);
    --accent:#06b6d4;
    --success:#10b981;
    --radius:16px;
    --radius-sm:10px;
}
html{scroll-behavior:smooth}
body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
    background:var(--bg-darker);
    color:var(--text-light);
    line-height:1.75;
    min-height:100vh;
    -webkit-font-smoothing:antialiased;
    background-image:
        radial-gradient(ellipse 80% 50% at 50% -20%,rgba(99,102,241,0.15),transparent),
        radial-gradient(ellipse 60% 50% at 100% 100%,rgba(6,182,212,0.08),transparent);
    background-attachment:fixed;
}
a{color:var(--primary-light);text-decoration:none;transition:color .2s}
a:hover{color:#fff}

/* Nav */
.container{max-width:960px;margin:0 auto;padding:0 24px}
nav{
    background:rgba(15,23,42,0.7);
    backdrop-filter:blur(16px);
    -webkit-backdrop-filter:blur(16px);
    border-bottom:1px solid var(--border);
    position:sticky;top:0;z-index:100;
}
nav .container{display:flex;align-items:center;justify-content:space-between;height:64px}
.nav-brand{font-size:18px;font-weight:700;color:#fff;display:flex;align-items:center;gap:8px}
.nav-brand::before{content:"";width:8px;height:8px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--accent));box-shadow:0 0 12px var(--primary-glow)}
.nav-links a{color:var(--text-muted);margin-left:24px;font-size:14px;font-weight:500}
.nav-links a:hover{color:#fff}

/* Hero */
.hero{padding:72px 0 40px;text-align:center}
.hero .eyebrow{
    display:inline-flex;align-items:center;gap:6px;
    padding:6px 14px;border-radius:999px;
    background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.25);
    color:var(--primary-light);font-size:12px;font-weight:600;letter-spacing:.5px;
    margin-bottom:20px;
}
.hero .eyebrow::before{content:"";width:6px;height:6px;border-radius:50%;background:var(--success);box-shadow:0 0 8px var(--success)}
.hero h1{
    font-size:44px;font-weight:800;letter-spacing:-0.02em;
    background:linear-gradient(135deg,#fff 0%,#cbd5e1 60%,#94a3b8 100%);
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
    margin-bottom:16px;
}
.hero p{color:var(--text-muted);font-size:16px;max-width:560px;margin:0 auto}
.hero .meta{
    display:inline-flex;align-items:center;gap:12px;margin-top:24px;
    font-size:13px;color:var(--text-dim);
}
.hero .meta span{display:inline-flex;align-items:center;gap:6px}
.hero .meta svg{width:14px;height:14px;opacity:.7}

/* Article */
.article{
    background:var(--bg-card);
    backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);
    border:1px solid var(--border);
    border-radius:var(--radius);
    padding:48px;
    margin-bottom:48px;
    box-shadow:0 20px 60px -20px rgba(0,0,0,0.5);
}
.article h2{
    font-size:22px;font-weight:700;color:#fff;
    margin:40px 0 16px;padding-bottom:12px;
    border-bottom:1px solid var(--border);
    display:flex;align-items:center;gap:12px;
}
.article h2:first-child{margin-top:0}
.article h2 .num{
    display:inline-flex;align-items:center;justify-content:center;
    width:32px;height:32px;border-radius:8px;
    background:linear-gradient(135deg,var(--primary),var(--accent));
    color:#fff;font-size:14px;font-weight:700;flex-shrink:0;
    box-shadow:0 4px 12px var(--primary-glow);
}
.article h3{font-size:17px;font-weight:600;color:#e2e8f0;margin:24px 0 10px}
.article p{color:var(--text-muted);font-size:15px;margin-bottom:14px}
.article ul,.article ol{color:var(--text-muted);font-size:15px;margin:0 0 16px 4px;padding-left:24px}
.article li{margin-bottom:8px}
.article strong{color:#fff;font-weight:600}
.article a{color:var(--primary-light);text-decoration:underline;text-decoration-color:rgba(129,140,248,0.3);text-underline-offset:3px}
.article a:hover{text-decoration-color:var(--primary-light)}
.article code{
    background:rgba(99,102,241,0.12);color:var(--primary-light);
    padding:2px 8px;border-radius:6px;font-size:13px;
    font-family:"SF Mono",Monaco,Consolas,monospace;
}
.article blockquote{
    border-left:3px solid var(--primary);
    background:rgba(99,102,241,0.06);
    padding:14px 20px;margin:16px 0;border-radius:0 8px 8px 0;
    color:var(--text-muted);font-size:14px;
}

/* Prose 自定义内容 */
.prose{color:var(--text-muted);font-size:15px;line-height:1.8}
.prose h1,.prose h2,.prose h3,.prose h4{color:#fff;font-weight:700;margin:1.5em 0 .6em}
.prose h1{font-size:24px}.prose h2{font-size:20px}.prose h3{font-size:17px}
.prose p{margin-bottom:14px}
.prose ul,.prose ol{padding-left:24px;margin-bottom:14px}
.prose li{margin-bottom:6px}
.prose a{color:var(--primary-light);text-decoration:underline}
.prose strong{color:#fff}
.prose code{background:rgba(99,102,241,0.12);color:var(--primary-light);padding:2px 6px;border-radius:4px;font-size:13px}
.prose blockquote{border-left:3px solid var(--primary);padding-left:16px;margin:14px 0;color:var(--text-dim)}

/* Table of Contents */
.toc{
    background:rgba(15,23,42,0.5);
    border:1px solid var(--border);border-radius:var(--radius-sm);
    padding:20px 24px;margin-bottom:32px;
}
.toc-title{
    font-size:12px;font-weight:600;letter-spacing:1px;text-transform:uppercase;
    color:var(--text-dim);margin-bottom:12px;display:flex;align-items:center;gap:8px;
}
.toc-title::before{content:"";width:16px;height:1px;background:var(--primary)}
.toc ol{list-style:none;padding:0;margin:0;counter-reset:toc}
.toc li{counter-increment:toc;margin-bottom:8px}
.toc li::before{
    content:counter(toc,decimal-leading-zero);
    color:var(--primary);font-weight:700;font-size:13px;margin-right:10px;
    font-family:"SF Mono",Monaco,monospace;
}
.toc a{color:var(--text-muted);font-size:14px}
.toc a:hover{color:#fff}

/* Footer */
footer{
    padding:40px 0;border-top:1px solid var(--border);text-align:center;
    color:var(--text-dim);font-size:14px;
}
footer a{color:var(--text-muted)}

@media (max-width:768px){
    .hero h1{font-size:32px}
    .article{padding:28px 22px}
    .nav-links a{margin-left:16px}
}
</style>
</head>
<body>
<nav><div class="container">
    <a href="/" class="nav-brand">{{ $systemName }}</a>
    <div class="nav-links">
        <a href="/">首页</a>
        <a href="/pricing">价格</a>
        <a href="/about">关于</a>
    </div>
</div></nav>

<section class="hero"><div class="container">
    <span class="eyebrow">PRIVACY POLICY</span>
    <h1>隐私政策</h1>
    <p>我们尊重并保护每一位用户的隐私，本政策详细说明我们如何收集、使用和保护您的个人信息。</p>
    @if(!empty($updatedAt))
    <div class="meta">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="10"/></svg>最后更新：{{ $updatedAt }}</span>
    </div>
    @endif
</div></section>

<section class="container">
    @if($content)
        <article class="article prose">
            {!! $content !!}
        </article>
    @else
        <div class="toc">
            <div class="toc-title">目录</div>
            <ol>
                <li><a href="#sec-1">我们收集的信息</a></li>
                <li><a href="#sec-2">信息使用方式</a></li>
                <li><a href="#sec-3">信息共享与披露</a></li>
                <li><a href="#sec-4">数据安全</a></li>
                <li><a href="#sec-5">Cookie 与同类技术</a></li>
                <li><a href="#sec-6">您的权利</a></li>
                <li><a href="#sec-7">未成年人保护</a></li>
                <li><a href="#sec-8">政策更新</a></li>
                <li><a href="#sec-9">联系我们</a></li>
            </ol>
        </div>

        <article class="article">
            <h2 id="sec-1"><span class="num">1</span>我们收集的信息</h2>
            <p>为了向您提供 AI 模型 API 中转服务，我们会在您使用服务过程中收集以下信息：</p>
            <ul>
                <li><strong>账户信息</strong>：您注册时提交的用户名、邮箱、密码（加密存储）等。</li>
                <li><strong>使用数据</strong>：API 调用记录、Token 用量、消费明细、请求日志（含模型、时间戳、Token 数）。</li>
                <li><strong>支付信息</strong>：充值订单号、支付方式、金额（不存储完整卡号或支付凭证）。</li>
                <li><strong>设备信息</strong>：IP 地址、User-Agent、访问时间，用于安全审计与风控。</li>
            </ul>

            <h2 id="sec-2"><span class="num">2</span>信息使用方式</h2>
            <p>我们收集的信息将用于：</p>
            <ul>
                <li>提供、维护和改进 AI API 中转服务，包括计费、限流与配额管理；</li>
                <li>生成用量统计与分析报表，帮助您了解消费情况；</li>
                <li>检测并防范滥用、欺诈、恶意攻击等安全风险；</li>
                <li>向您发送服务通知、安全提醒及重要政策变更；</li>
                <li>遵守适用的法律法规及监管要求。</li>
            </ul>

            <h2 id="sec-3"><span class="num">3</span>信息共享与披露</h2>
            <p>除以下情形外，我们不会向任何第三方共享或出售您的个人信息：</p>
            <ul>
                <li><strong>服务提供商</strong>：我们可能将请求转发至上游 AI 模型供应商（如 OpenAI、Anthropic 等）以完成 API 调用，仅传输必要的技术参数。</li>
                <li><strong>法律要求</strong>：因遵守法律法规、应政府主管部门要求或为保护用户与公众权益所必需。</li>
                <li><strong>业务变更</strong>：在合并、收购或资产转让时，相关信息将作为资产转移，并继续受本政策约束。</li>
            </ul>

            <h2 id="sec-4"><span class="num">4</span>数据安全</h2>
            <p>我们采取行业标准的安全措施保护您的信息，包括：</p>
            <ul>
                <li>密码使用 bcrypt 算法加盐哈希存储，不以明文形式保存；</li>
                <li>API Key 使用加密存储，传输全程 HTTPS 加密；</li>
                <li>数据库访问受最小权限原则限制，并记录访问日志；</li>
                <li>定期进行安全审计与漏洞扫描。</li>
            </ul>
            <blockquote>尽管我们努力保护您的信息，但互联网传输无法保证 100% 安全。请在使用任何在线服务时保持警惕。</blockquote>

            <h2 id="sec-5"><span class="num">5</span>Cookie 与同类技术</h2>
            <p>我们使用 Cookie 与 LocalStorage 用于会话保持、登录状态识别与匿名流量统计。您可通过浏览器设置管理或清除 Cookie，但部分功能可能因此受限。</p>

            <h2 id="sec-6"><span class="num">6</span>您的权利</h2>
            <p>根据适用法律，您对个人信息享有以下权利：</p>
            <ul>
                <li><strong>访问与导出</strong>：您可以查看并导出账户下的使用数据。</li>
                <li><strong>更正</strong>：您可以修改账户信息以保持准确。</li>
                <li><strong>删除</strong>：您可以申请删除账户及相关数据，但法律法规要求保留的除外。</li>
                <li><strong>撤回同意</strong>：您可以随时撤回对特定数据处理的同意。</li>
            </ul>

            <h2 id="sec-7"><span class="num">7</span>未成年人保护</h2>
            <p>本服务不面向 13 岁以下未成年人。若您是未成年人监护人并发现被监护人使用了本服务，请及时联系我们，我们将删除相关信息。</p>

            <h2 id="sec-8"><span class="num">8</span>政策更新</h2>
            <p>我们可能不时更新本隐私政策。更新后将在本页面公示并修订"最后更新"日期。重大变更时我们将通过站内通知或邮件告知您。</p>

            <h2 id="sec-9"><span class="num">9</span>联系我们</h2>
            <p>如对本隐私政策有任何疑问、建议或投诉，可通过以下方式联系我们：</p>
            <ul>
                <li>站内：<a href="/dashboard">用户中心 → 设置 → 反馈</a></li>
                <li>邮件：发送至平台公告中公布的官方邮箱</li>
            </ul>
            <p>我们将在收到您的请求后 15 个工作日内予以回复。</p>
        </article>
    @endif
</section>

<footer><div class="container">
    @if(!empty($systemFooter)){!! $systemFooter !!}@else<p>&copy; {{ date('Y') }} {{ $systemName }} · 保留所有权利</p>@endif
</div></footer>
</body>
</html>