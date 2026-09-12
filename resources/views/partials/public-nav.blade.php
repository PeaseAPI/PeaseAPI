{{-- 公共导航（全站统一）：active 由路由名推导；已登录用户显示「控制台」替代登录按钮 --}}
<nav>
    <div class="container">
        <a href="/" class="nav-brand">
            <div class="nav-logo">
                @if($systemLogo)
                    <img src="{{ $systemLogo }}" alt="{{ $systemName }}">
                @elseif(file_exists(public_path('logo.png')))
                    <img src="/logo.png" alt="{{ $systemName }}">
                @else
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                @endif
            </div>
            <span class="nav-name">{{ $systemName }}</span>
        </a>
        <div class="nav-links">
            <a href="/docs" @class(['active' => request()->routeIs('docs.*')])>文档</a>
            <a href="/pricing" @class(['active' => request()->routeIs('pricing')])>价格</a>
            <a href="/about" @class(['active' => request()->routeIs('about')])>关于</a>
            @if(auth()->check())
                <a href="/dashboard" class="btn btn-ghost">控制台</a>
            @elseif($passwordLoginEnabled)
                <a href="/login" class="btn btn-ghost">登录</a>
            @endif
            @if($registerEnabled && ! auth()->check())
                <a href="/register" class="btn btn-primary">免费注册</a>
            @endif
        </div>
    </div>
</nav>
