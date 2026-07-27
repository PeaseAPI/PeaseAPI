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
    try { $content = \App\Services\OptionService::get('UserAgreement', ''); } catch (\Throwable $e) {}
    $updatedAt = '';
    try { $updatedAt = \App\Services\OptionService::get('UserAgreementUpdatedAt', ''); } catch (\Throwable $e) {}
@endphp
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>用户协议 · {{ $systemName }}</title>
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
    --warning:#f59e0b;
    --danger:#ef4444;
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
.article blockquote.warning{
    border-left-color:var(--warning);
    background:rgba(245,158,11,0.06);
}
.article blockquote.danger{
    border-left-color:var(--danger);
    background:rgba(239,68,68,0.06);
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
    <span class="eyebrow">TERMS OF SERVICE</span>
    <h1>用户协议</h1>
    <p>请仔细阅读本服务条款。使用本服务即表示您已理解并同意受本协议约束。</p>
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
                <li><a href="#sec-1">服务说明</a></li>
                <li><a href="#sec-2">账户注册与管理</a></li>
                <li><a href="#sec-3">用户行为规范</a></li>
                <li><a href="#sec-4">付费与退款</a></li>
                <li><a href="#sec-5">知识产权</a></li>
                <li><a href="#sec-6">服务变更与终止</a></li>
                <li><a href="#sec-7">免责声明</a></li>
                <li><a href="#sec-8">法律适用</a></li>
                <li><a href="#sec-9">联系我们</a></li>
            </ol>
        </div>

        <article class="article">
            <h2 id="sec-1"><span class="num">1</span>服务说明</h2>
            <p>{{ $systemName }}（以下简称"本平台"或"我们"）提供 AI 模型 API 中转与转发服务，包括但不限于：</p>
            <ul>
                <li>大语言模型（LLM）API 调用转发；</li>
                <li>图像生成、语音、视频等多模态 AI 模型调用；</li>
                <li>API Key 管理、用量统计、配额计费；</li>
                <li>渠道网关、负载均衡、故障切换等技术能力。</li>
            </ul>
            <p>本平台作为中转方，向上游 AI 模型供应商发起请求并将结果返回给用户。上游模型由第三方提供，本平台不保证任何特定模型的持续可用性。</p>

            <h2 id="sec-2"><span class="num">2</span>账户注册与管理</h2>
            <h3>2.1 注册资格</h3>
            <p>您需年满 13 周岁，并具有完全民事行为能力。如您代表实体注册，需声明已获合法授权。</p>
            <h3>2.2 账户安全</h3>
            <ul>
                <li>您需提供真实、准确的注册信息，并及时更新；</li>
                <li>您应妥善保管账户密码与 API Key，因泄露造成的损失由您自行承担；</li>
                <li>如发现账户被盗用或异常使用，请立即通过 <a href="/dashboard/settings">账户设置</a> 修改密码并联系管理员；</li>
                <li>每个用户仅可注册一个账户，禁止批量注册、买卖或共享账户。</li>
            </ul>
            <h3>2.3 API Key</h3>
            <p>您可在用户中心生成多个 API Key 并设置配额限制。请勿将 Key 提交至公开代码仓库或公开渠道。本平台对因 Key 泄露导致的额度消耗不承担责任。</p>

            <h2 id="sec-3"><span class="num">3</span>用户行为规范</h2>
            <p>您在使用本服务时应遵守中华人民共和国及您所在地的法律法规，不得将本服务用于以下用途：</p>
            <ul>
                <li>生成或传播违法、淫秽、暴力、歧视性或侵权内容；</li>
                <li>实施网络攻击、爬虫滥用、DDoS、暴力破解等破坏性行为；</li>
                <li>开发用于欺诈、钓鱼、伪造身份的恶意应用；</li>
                <li>绕过或试图绕过本平台的安全机制、计费系统或访问控制；</li>
                <li>将本服务二次转售或以类似方式提供同类 API 中转服务（除非获得书面授权）。</li>
            </ul>
            <blockquote class="warning">违反上述规范，我们有权立即暂停或终止您的账户，扣除相关额度，并保留追究法律责任的权利。</blockquote>

            <h2 id="sec-4"><span class="num">4</span>付费与退款</h2>
            <h3>4.1 计费方式</h3>
            <p>本平台采用预付费模式。您通过充值获得额度，按实际调用的 Token 数或次数扣费。具体单价以 <a href="/pricing">价格页</a> 为准。</p>
            <h3>4.2 充值与额度</h3>
            <ul>
                <li>充值后额度立即到账，长期有效，不设过期时间；</li>
                <li>支持多种支付方式，具体以钱包页展示为准；</li>
                <li>因上游模型故障导致的失败请求，系统自动退还相应额度。</li>
            </ul>
            <h3>4.3 退款政策</h3>
            <p>已充值费用原则上不予退还。如遇特殊情况（如平台重大违约），可联系客服协商处理。因违反本协议被冻结的账户，剩余额度不予退还。</p>

            <h2 id="sec-5"><span class="num">5</span>知识产权</h2>
            <h3>5.1 平台内容</h3>
            <p>本平台的界面、代码、文档、商标等知识产权归本平台或相关权利人所有，未经授权不得复制、改编或商用。</p>
            <h3>5.2 生成内容</h3>
            <p>您通过本服务生成的 AI 输出内容，其知识产权归属依上游模型供应商的政策确定。本平台仅作为中转方，不对生成内容的权属作任何担保，亦不承担因生成内容引发的侵权责任。</p>
            <h3>5.3 用户输入</h3>
            <p>您应确保提交给本服务的 prompt 和输入内容不侵犯第三方知识产权。因您的输入导致的纠纷，由您自行承担全部责任。</p>

            <h2 id="sec-6"><span class="num">6</span>服务变更与终止</h2>
            <h3>6.1 服务变更</h3>
            <p>我们保留随时变更、暂停或终止部分或全部服务的权利，包括但不限于：</p>
            <ul>
                <li>新增或下线特定 AI 模型；</li>
                <li>调整价格、配额或限流策略；</li>
                <li>维护、升级或重构系统架构。</li>
            </ul>
            <p>重大变更我们将提前通过站内公告或邮件通知。</p>
            <h3>6.2 账户终止</h3>
            <p>您可随时申请注销账户。发生以下情形时，我们有权暂停或终止您的账户：</p>
            <ul>
                <li>违反本协议或相关法律法规；</li>
                <li>长时间未登录或未产生有效调用；</li>
                <li>存在欺诈、盗刷、恶意攻击等行为；</li>
                <li>应监管或司法要求。</li>
            </ul>
            <blockquote class="danger">账户终止后，我们将删除或匿名化处理您的个人信息（法律法规要求保留的除外），剩余额度不予退还。</blockquote>

            <h2 id="sec-7"><span class="num">7</span>免责声明</h2>
            <h3>7.1 服务可用性</h3>
            <p>本服务以"现状"提供，我们不保证服务持续可用、不中断或无错误。因网络故障、上游模型不可用、系统维护等原因导致的服务中断，我们不承担赔偿责任。</p>
            <h3>7.2 生成内容</h3>
            <p>AI 生成的输出可能存在错误、偏见或不准确之处。您应对生成内容进行独立判断，不应将 AI 输出作为专业建议（医疗、法律、金融等）的依据。本平台对生成内容的准确性、完整性不作任何担保。</p>
            <h3>7.3 第三方服务</h3>
            <p>本服务依赖上游 AI 模型供应商及第三方基础设施。因第三方原因导致的服务异常或数据损失，我们将尽力协调但不承担直接责任。</p>
            <h3>7.4 间接损失</h3>
            <p>在适用法律允许的最大范围内，本平台不对因使用或无法使用本服务导致的任何间接、附带、特殊或后果性损失（如利润损失、数据丢失、业务中断）承担责任。</p>

            <h2 id="sec-8"><span class="num">8</span>法律适用</h2>
            <p>本协议的订立、生效、解释与争议解决均适用中华人民共和国法律。因本协议或本服务产生的争议，双方应首先协商解决；协商不成的，任何一方均可向本平台所在地有管辖权的人民法院提起诉讼。</p>

            <h2 id="sec-9"><span class="num">9</span>联系我们</h2>
            <p>如对本协议有任何疑问或建议，可通过以下方式联系我们：</p>
            <ul>
                <li>站内：<a href="/dashboard">用户中心 -> 设置 -> 反馈</a></li>
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
