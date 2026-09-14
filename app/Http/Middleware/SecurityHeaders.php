<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // 禁止 MIME 嗅探（防护：浏览器按声明 Content-Type 解析响应体）
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // 禁止第三方站点 iframe 嵌套（防点击劫持）；SAMEORIGIN 放行站内
        // 4 处同源 iframe（about/home/chat/web-preview），故不能用 DENY
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        // 限制 Referrer 泄露：仅同源请求携带完整 URL，跨域只带 origin
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // 关闭站内用不到的敏感浏览器能力
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}
