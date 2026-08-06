<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $doc['title'] }} - {{ $systemName }} 文档</title>
<meta name="description" content="{{ $doc['title'] }} - PeaseAPI 文档">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--primary:#6366f1;--primary-light:#818cf8;--bg-darker:#020617;--bg-card:#1e293b;--text-light:#f1f5f9;--text-muted:#94a3b8;--border:rgba(148,163,184,0.15);--gradient:linear-gradient(135deg,#6366f1 0%,#8b5cf6 50%,#ec4899 100%);--gradient-soft:linear-gradient(135deg,rgba(99,102,241,0.15) 0%,rgba(139,92,246,0.15) 100%)}
html{scroll-behavior:smooth}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;background:var(--bg-darker);color:var(--text-light);line-height:1.6;overflow-x:hidden;min-height:100vh}
a{color:inherit;text-decoration:none}
.bg-decoration{position:fixed;inset:0;z-index:-1;overflow:hidden;pointer-events:none}
.bg-decoration::before{content:'';position:absolute;top:-20%;left:-10%;width:50%;height:50%;border-radius:50%;background:radial-gradient(circle,rgba(99,102,241,0.2) 0%,transparent 70%);filter:blur(80px)}
nav{position:sticky;top:0;z-index:100;background:rgba(15,23,42,0.8);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid var(--border)}
nav .container{display:flex;align-items:center;justify-content:space-between;height:64px;max-width:1400px;margin:0 auto;padding:0 24px}
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
.btn-ghost{background:rgba(255,255,255,0.06);color:var(--text-light);border:1px solid var(--border)}
.btn-ghost:hover{background:rgba(255,255,255,0.1)}
.layout{display:flex;max-width:1400px;margin:0 auto;padding:24px;gap:24px}
.sidebar{width:240px;flex-shrink:0;position:sticky;top:88px;align-self:start;max-height:calc(100vh - 100px);overflow-y:auto}
.sidebar h4{font-size:13px;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:12px;padding:0 12px}
.sidebar ul{list-style:none}
.sidebar li a{display:block;padding:8px 12px;font-size:14px;color:var(--text-muted);border-radius:8px;transition:all 0.2s}
.sidebar li a:hover{color:#fff;background:rgba(255,255,255,0.05)}
.sidebar li a.active{color:#fff;background:rgba(99,102,241,0.15);font-weight:500}
.sidebar .doc-nav-item{display:flex;align-items:center;gap:8px}
.content-area{flex:1;min-width:0}
.doc-header{padding:32px 0 24px;border-bottom:1px solid var(--border);margin-bottom:32px}
.doc-header .breadcrumb{font-size:13px;color:var(--text-muted);margin-bottom:12px}
.doc-header .breadcrumb a{color:var(--primary-light)}
.doc-header .breadcrumb a:hover{text-decoration:underline}
.doc-header h1{font-size:32px;font-weight:800;letter-spacing:-1px;margin-bottom:8px}
.doc-header .subtitle{font-size:15px;color:var(--text-muted)}
.doc-content{background:var(--bg-card);border:1px solid var(--border);border-radius:16px;padding:48px;min-height:400px}
.doc-content.loading{display:flex;align-items:center;justify-content:center}
.doc-content.loading::after{content:'加载中...';color:var(--text-muted);font-size:16px}
.markdown-body{color:var(--text-light);line-height:1.8;font-size:15px}
.markdown-body h1{font-size:28px;font-weight:700;margin:32px 0 16px;padding-bottom:12px;border-bottom:1px solid var(--border)}
.markdown-body h1:first-child{margin-top:0}
.markdown-body h2{font-size:22px;font-weight:700;margin:28px 0 14px;color:#fff}
.markdown-body h3{font-size:18px;font-weight:600;margin:24px 0 12px;color:#fff}
.markdown-body h4{font-size:16px;font-weight:600;margin:20px 0 10px;color:#fff}
.markdown-body p{margin:12px 0}
.markdown-body a{color:var(--primary-light);text-decoration:underline}
.markdown-body ul,.markdown-body ol{margin:12px 0;padding-left:24px}
.markdown-body li{margin:6px 0}
.markdown-body code{font-family:"SF Mono",Monaco,Consolas,monospace;font-size:13px;background:rgba(99,102,241,0.12);color:var(--primary-light);padding:2px 6px;border-radius:4px}
.markdown-body pre{background:#0d1117;border:1px solid var(--border);border-radius:12px;padding:20px;overflow-x:auto;margin:16px 0}
.markdown-body pre code{background:none;color:#e6edf3;padding:0;font-size:13px;line-height:1.6}
.markdown-body blockquote{border-left:3px solid var(--primary);padding:8px 16px;margin:16px 0;background:rgba(99,102,241,0.05);border-radius:0 8px 8px 0;color:var(--text-muted)}
.markdown-body table{width:100%;border-collapse:collapse;margin:16px 0;font-size:14px}
.markdown-body th{background:rgba(99,102,241,0.1);padding:10px 14px;text-align:left;font-weight:600;border:1px solid var(--border)}
.markdown-body td{padding:10px 14px;border:1px solid var(--border)}
.markdown-body tr:nth-child(even) td{background:rgba(15,23,42,0.3)}
.markdown-body hr{border:none;border-top:1px solid var(--border);margin:32px 0}
.markdown-body img{max-width:100%;border-radius:8px;margin:16px 0}
.markdown-body strong{color:#fff;font-weight:600}
.doc-footer{display:flex;justify-content:space-between;align-items:center;margin-top:32px;padding-top:24px;border-top:1px solid var(--border)}
.doc-footer a{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;font-size:14px;border-radius:10px;transition:all 0.2s}
.doc-footer .prev{background:rgba(255,255,255,0.06);border:1px solid var(--border);color:var(--text-light)}
.doc-footer .prev:hover{background:rgba(255,255,255,0.1)}
.doc-footer .next{background:var(--gradient);color:#fff}
.doc-footer .next:hover{transform:translateX(4px)}
footer{padding:32px 0;border-top:1px solid var(--border);background:rgba(15,23,42,0.5)}
.footer-content{text-align:center;color:var(--text-muted);font-size:14px;line-height:1.8}
.footer-content a{color:var(--primary-light)}
@media (max-width:900px){.sidebar{display:none}.layout{padding:16px}.doc-content{padding:24px}}
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

<div class="layout">
    <aside class="sidebar">
        <h4>文档导航</h4>
        <ul>
            @foreach($allDocs as $d)
            <li>
                <a href="/docs/{{ $d['slug'] }}" class="{{ $d['slug'] === $slug ? 'active' : '' }}">
                    <span class="doc-nav-item">{{ $d['icon'] }} {{ $d['title'] }}</span>
                </a>
            </li>
            @endforeach
        </ul>
    </aside>

    <main class="content-area">
        <div class="doc-header">
            <div class="breadcrumb"><a href="/">首页</a> / <a href="/docs">文档</a> / {{ $doc['title'] }}</div>
            <h1>{{ $doc['icon'] }} {{ $doc['title'] }}</h1>
            <div class="subtitle">PeaseAPI {{ $doc['title'] }} 完整文档</div>
        </div>

        <div class="doc-content loading" id="doc-content">
            <div class="markdown-body" id="markdown-body" style="display:none"></div>
        </div>

        <div class="doc-footer">
            <a href="/docs" class="prev">← 返回文档列表</a>
            @php
                $currentIndex = array_search($slug, array_column($allDocs, 'slug'));
                $nextDoc = ($currentIndex !== false && isset($allDocs[$currentIndex + 1])) ? $allDocs[$currentIndex + 1] : null;
            @endphp
            @if($nextDoc)
            <a href="/docs/{{ $nextDoc['slug'] }}" class="next">{{ $nextDoc['title'] }} →</a>
            @endif
        </div>
    </main>
</div>

<footer>
    <div class="footer-content">
        @if(!empty($systemFooter))
            {!! $systemFooter !!}
        @else
            <p>&copy; {{ date('Y') }} {{ $systemName }}. All rights reserved.</p>
            <p><a href="/">首页</a> · <a href="/docs">文档</a> · <a href="/pricing">价格</a> · <a href="/about">关于</a></p>
        @endif
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
<script>
const rawMarkdown = @json($markdownContent);
const contentDiv = document.getElementById('doc-content');
const bodyDiv = document.getElementById('markdown-body');

marked.setOptions({
    breaks: true,
    gfm: true,
});

bodyDiv.innerHTML = marked.parse(rawMarkdown);
contentDiv.classList.remove('loading');
bodyDiv.style.display = 'block';

document.querySelectorAll('pre code').forEach(function(block) {
    const lang = block.className.match(/language-(\w+)/);
    if (lang) {
        block.parentElement.setAttribute('data-lang', lang[1]);
    }
});
</script>
</body>
</html>