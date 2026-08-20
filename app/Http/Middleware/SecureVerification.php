<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Secure Verification Middleware - 对标 new-api middleware/secure-verification.go
 *
 * 敏感操作安全验证：要求二次验证（密码/2FA/Passkey）
 */
class SecureVerification
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $this->deny(__('Not logged in'));
        }

        $verifiedAt = session('secure_verified_at', 0);
        $ttl = (int) config('pease-api.security.secure_verification_ttl', 300);

        if (time() - $verifiedAt > $ttl) {
            return $this->requireVerification();
        }

        return $next($request);
    }

    protected function requireVerification(): Response
    {
        return response()->json([
            'success' => false,
            'message' => __('Secure verification required'),
            'data' => [
                'require_verification' => true,
                'methods' => $this->availableMethods(),
            ],
        ], 403);
    }

    protected function availableMethods(): array
    {
        $methods = ['password'];
        if (session('2fa_enabled', false)) {
            $methods[] = '2fa';
        }
        if (session('passkey_enabled', false)) {
            $methods[] = 'passkey';
        }

        return $methods;
    }

    protected function deny(string $message): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
        ], 401);
    }
}
