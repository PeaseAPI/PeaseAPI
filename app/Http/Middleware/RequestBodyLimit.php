<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request Body Limit Middleware - 对标 new-api middleware/request-body-limit.go
 *
 * 限制请求体大小，防止超大请求
 */
class RequestBodyLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = $this->resolveLimit($request);
        if ($limit <= 0) {
            return $next($request);
        }

        $contentLength = $request->headers->get('Content-Length');
        if ($contentLength !== null && (int) $contentLength > $limit) {
            return $this->deny('请求体超过限制');
        }

        return $next($request);
    }

    protected function resolveLimit(Request $request): int
    {
        $path = $request->path();

        if (str_starts_with($path, 'v1/audio/') || str_starts_with($path, 'v1/images/') || str_starts_with($path, 'mj/')) {
            return (int) config('pease-api.upload.media_limit', 100 * 1024 * 1024);
        }

        return (int) config('pease-api.upload.default_limit', 10 * 1024 * 1024);
    }

    protected function deny(string $message): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
        ], 413);
    }
}