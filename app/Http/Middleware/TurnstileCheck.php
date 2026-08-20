<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turnstile Check Middleware - 对标 new-api middleware/turnstile-check.go
 *
 * Cloudflare Turnstile 人机验证
 */
class TurnstileCheck
{
    protected array $exemptedPaths = [
        'api/status',
        'api/notice',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldVerify($request)) {
            return $next($request);
        }

        $token = $request->header('X-Turnstile-Token') ?: $request->input('turnstile_token');
        if (empty($token)) {
            return $this->deny('缺少 Turnstile 验证令牌');
        }

        if (! $this->verify($token, $request->ip())) {
            return $this->deny('Turnstile 验证失败');
        }

        return $next($request);
    }

    protected function shouldVerify(Request $request): bool
    {
        if (! config('pease-api.security.turnstile_enabled', false)) {
            return false;
        }

        if (in_array($request->path(), $this->exemptedPaths, true)) {
            return false;
        }

        return true;
    }

    protected function verify(string $token, string $ip): bool
    {
        $secret = config('pease-api.security.turnstile_secret_key', '');
        if (empty($secret)) {
            return false;
        }

        try {
            $response = Http::timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]);

            return $response->json('success', false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function deny(string $message): Response
    {
        return response()->json([
            'success' => false,
            'error' => [
                'message' => $message,
                'type' => 'turnstile_error',
                'code' => 'turnstile_failed',
            ],
        ], 403);
    }
}
