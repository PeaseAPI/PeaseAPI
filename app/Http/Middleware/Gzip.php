<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Gzip压缩中间件
 */
class Gzip
{
    public function handle(Request $request, Closure $next)
    {
        // TODO: Implement middleware logic
        return $next($request);
    }
}
