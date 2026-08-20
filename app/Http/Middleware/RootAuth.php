<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RootAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return $this->unauthorizedResponse($request, '请先登录');
        }

        $user = auth()->user();

        if ($user->status !== 1) {
            return $this->unauthorizedResponse($request, '账户已被禁用');
        }

        if ($user->role < UserRole::ROOT) {
            return $this->unauthorizedResponse($request, '需要Root权限');
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
                    'code' => 'root_access_required',
                ],
            ], 403);
        }

        return redirect()->route('dashboard')->with('error', $message);
    }
}
