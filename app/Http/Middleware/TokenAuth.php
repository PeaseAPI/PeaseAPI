<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Token;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class TokenAuth
{
    /**
     * Maximum requests per minute per token
     */
    protected int $maxRequestsPerMinute = 60;

    public function handle(Request $request, Closure $next)
    {
        // 1. Validate Authorization header
        $authHeader = $request->header('Authorization');
        if (! $authHeader || ! str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => __('Missing or invalid authorization header'),
                    'type' => 'invalid_request_error',
                    'code' => 'missing_authorization',
                ],
            ], 401);
        }

        // 2. Extract and validate API key format
        $key = substr($authHeader, 7);
        if (strlen($key) < 20 || strlen($key) > 256) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => __('Invalid API key format'),
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key',
                ],
            ], 401);
        }

        // 3. Find token in database
        $token = Token::where('key', $key)->first();
        if (! $token) {
            // Use generic error message to prevent enumeration
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => __('Invalid API key'),
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key',
                ],
            ], 401);
        }

        // 4. Check if token is active
        if ($token->status !== 1) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => __('API token is disabled'),
                    'type' => 'invalid_request_error',
                    'code' => 'token_disabled',
                ],
            ], 403);
        }

        // 5. Check if token is expired
        if ($token->expired_time > 0 && $token->expired_time < time()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => __('API token has expired'),
                    'type' => 'invalid_request_error',
                    'code' => 'token_expired',
                ],
            ], 403);
        }

        // 6. Check if user exists and is active
        $user = User::find($token->user_id);
        if (! $user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => __('User not found'),
                    'type' => 'invalid_request_error',
                    'code' => 'user_not_found',
                ],
            ], 403);
        }

        if ($user->status !== 1) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => __('User account is disabled'),
                    'type' => 'invalid_request_error',
                    'code' => 'user_disabled',
                ],
            ], 403);
        }

        // 7. Check IP restrictions (if configured)
        if (! $token->isIpAllowed($request->ip())) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => __('IP address not allowed for this token'),
                    'type' => 'invalid_request_error',
                    'code' => 'ip_not_allowed',
                ],
            ], 403);
        }

        // 8. Check if token has available quota (unless unlimited)
        if (! $token->hasAvailableQuota()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => __('Insufficient quota'),
                    'type' => 'insufficient_quota',
                    'code' => 'quota_exceeded',
                ],
            ], 403);
        }

        // 9. Rate limiting per token
        $rateLimitKey = 'token:'.$token->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, $this->maxRequestsPerMinute)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'success' => false,
                'error' => [
                    'message' => 'Rate limit exceeded. Please retry after '.$seconds.' seconds',
                    'type' => 'rate_limit_exceeded',
                    'code' => 'rate_limit_exceeded',
                ],
            ], 429);
        }
        RateLimiter::hit($rateLimitKey, 60);

        // 10. Store token and user in request attributes for later use
        $request->attributes->set('token', $token);
        $request->attributes->set('api_user', $user);
        $request->attributes->set('token_id', $token->id);
        $request->attributes->set('user_id', $user->id);
        $request->attributes->set('user_group', $user->group);
        $request->attributes->set('user_role', $user->role);

        // 11. Update access time (optimized - don't do it on every request)
        // Only update every 5 minutes to reduce database load
        if ($token->accessed_time < time() - 300) {
            $token->updateAccessTime();
        }

        // 12. Check model restrictions if enabled
        $model = $request->input('model') ?? $request->input('messages.0.model') ?? '';
        if (! empty($model) && ! $token->isModelAllowed($model)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => __('Model not allowed for this token'),
                    'type' => 'invalid_request_error',
                    'code' => 'model_not_allowed',
                ],
            ], 403);
        }

        return $next($request);
    }
}
