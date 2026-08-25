<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserSession;
use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 用户认证中间件
 *
 * Validates the session token from either:
 *  - Authorization: Bearer <session_token> header
 *  - X-Auth-Session: <session_token> header
 *  - session cookie
 *
 * On success, sets the authenticated user via Laravel's Auth facade
 * so that $request->user() and Auth::user() work in controllers.
 */
class UserAuth
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        // 1. Try Authorization: Bearer <token> header
        $authHeader = $request->header('Authorization');
        $sessionToken = null;
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $sessionToken = substr($authHeader, 7);
        }

        // 2. Try X-Auth-Session header
        if (! $sessionToken) {
            $sessionToken = $request->header('X-Auth-Session');
        }

        // 3. Try session cookie
        if (! $sessionToken) {
            $sessionToken = $request->cookie('session');
        }

        if (! $sessionToken) {
            return response()->json([
                'success' => false,
                'message' => __('Unauthenticated'),
            ], 401);
        }

        $session = $this->authService->validateSession($sessionToken);
        if (! $session) {
            return response()->json([
                'success' => false,
                'message' => __('Session has expired'),
            ], 401);
        }

        $user = User::find($session->user_id);
        if (! $user || $user->status !== 1) {
            return response()->json([
                'success' => false,
                'message' => __('Unauthenticated'),
            ], 401);
        }

        // Set the authenticated user for Laravel's Auth facade
        Auth::setUser($user);

        // Store session info for controllers that need it
        $request->attributes->set('user_session', $session);

        return $next($request);
    }
}

