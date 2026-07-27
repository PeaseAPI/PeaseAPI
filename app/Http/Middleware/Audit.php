<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 审计日志中间件
 */
class Audit
{
    public function handle(Request $request, Closure $next)
    {
        // TODO: Implement middleware logic
        return $next($request);
    }
}
