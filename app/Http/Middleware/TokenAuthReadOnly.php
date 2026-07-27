<?php

declare(strict_types=1);

namespace App\Http\Middleware;

/**
 * Token Auth Read-Only - 对标 new-api TokenAuthReadOnly
 *
 * 仅允许只读操作（GET/HEAD/OPTIONS）的 Token 认证
 */
class TokenAuthReadOnly extends TokenAuth
{
    public function handle(\Illuminate\Http\Request $request, \Closure $next)
    {
        $method = strtoupper($request->method());
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => '只读令牌不允许执行写操作',
                    'type' => 'invalid_request_error',
                    'code' => 'read_only_token',
                ],
            ], 403);
        }

        return parent::handle($request, $next);
    }
}