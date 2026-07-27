<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 导航头中间件
 */
class HeaderNav
{
    public function handle(Request $request, Closure $next)
    {
        // TODO: Implement middleware logic
        return $next($request);
    }
}
