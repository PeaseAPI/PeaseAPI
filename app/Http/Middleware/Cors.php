<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS 中间件 - 对标 new-api middleware/cors.go
 */
class Cors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // 获取配置的允许的来源
        $allowedOrigins = config('pease-api.cors.allowed_origins', ['*']);
        $origin = $request->header('Origin', '');

        if (in_array('*', $allowedOrigins, true)) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        } elseif (in_array($origin, $allowedOrigins, true)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        // 预检请求处理
        if ($request->isMethod('OPTIONS')) {
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-API-Key, X-Request-ID');
            $response->headers->set('Access-Control-Max-Age', '86400');
            $response->setStatusCode(200);
        }

        return $response;
    }
}
