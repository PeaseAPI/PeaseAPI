<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;

/**
 * Token Auth Read-Only - 对标 new-api TokenAuthReadOnly
 *
 * 仅允许只读操作（GET/HEAD/OPTIONS）的 Token 认证
 */
class TokenAuthReadOnly extends TokenAuth
{
    public function handle(Request $request, \Closure $next)
    {
        $method = strtoupper($request->method());
        if (! in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => __('Read-only tokens are not allowed to perform write operations'),
                    'type' => 'invalid_request_error',
                    'code' => 'read_only_token',
                ],
            ], 403);
        }

        return parent::handle($request, $next);
    }
}
