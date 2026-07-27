<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Enums\UserRole;

class AdminAuth
{
    /**
     * Handle an incoming request.
     * 
     * Role levels:
     * - User: role = 1
     * - Admin: role = 10
     * - Root: role = 100
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return $this->unauthorizedResponse($request, '请先登录');
        }

        $user = auth()->user();

        if ($user->status !== 1) {
            return $this->unauthorizedResponse($request, '账户已被禁用');
        }

        if ($user->role < UserRole::ADMIN) {
            return $this->unauthorizedResponse($request, '需要管理员权限');
        }

        return $next($request);
    }

    private function unauthorizedResponse(Request $request, string $message): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'error' => [
                    'message' => $message,
                    'type' => 'access_denied',
                    'code' => 'admin_access_required'
                ]
            ], 403);
        }

        return redirect()->route('dashboard')->with('error', $message);
    }
}