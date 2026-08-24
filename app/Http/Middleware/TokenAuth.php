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
    protected int $maxRequestsPerMinute = 60;

    public function handle(Request $request, Closure $next)
    {
        $key = $this->extractApiKey($request);
        if ($key === null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => __('Missing or invalid authorization header'),
                    'type' => 'invalid_request_error',
                    'code' => 'missing_authorization',
                ],
            ], 401);
        }

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

        $token = Token::where('key', $key)->first();
        if (! $token) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => __('Invalid API key'),
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key',
                ],
            ], 401);
        }

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

        $request->attributes->set('token', $token);
        $request->attributes->set('api_user', $user);
        $request->attributes->set('token_id', $token->id);
        $request->attributes->set('user_id', $user->id);
        $request->attributes->set('user_group', $user->group);
        $request->attributes->set('user_role', $user->role);

        if ($token->accessed_time < time() - 300) {
            $token->updateAccessTime();
        }

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

    protected function extractApiKey(Request $request): ?string
    {
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        $xApiKey = $request->header('x-api-key');
        if ($xApiKey && is_string($xApiKey) && $xApiKey !== '') {
            return $xApiKey;
        }

        return null;
    }
}
