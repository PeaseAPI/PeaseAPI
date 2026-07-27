<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 邮箱验证限流中间件
 */
class EmailVerificationRateLimit
{
    public function handle(Request $request, Closure $next)
    {
        // TODO: Implement middleware logic
        return $next($request);
    }
}
