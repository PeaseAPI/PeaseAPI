{{-- 公共页脚（全站统一）：支持管理端 SystemFooter 自定义 HTML，缺省输出版权与全站链接 --}}
<footer>
    <div class="container">
        <div class="footer-content">
            @if(!empty($systemFooter))
                {!! $systemFooter !!}
            @else
                <p>&copy; {{ date('Y') }} {{ $systemName }}. All rights reserved.</p>
                <p><a href="/">首页</a> · <a href="/docs">文档</a> · <a href="/docs/features">功能解读</a> · <a href="/pricing">价格</a> · <a href="/about">关于</a> · <a href="/user-agreement">用户协议</a> · <a href="/privacy-policy">隐私政策</a></p>
            @endif
        </div>
    </div>
</footer>
