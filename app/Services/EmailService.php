<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\EmailVerificationMail;
use App\Mail\PasswordResetMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Email Service
 *
 * Handles email verification codes and password reset links.
 * SMTP configuration is read dynamically from the options table,
 * mirroring Go new-api behavior (runtime-configurable SMTP).
 */
class EmailService
{
    private const VERIFICATION_CACHE_PREFIX = 'email_verification:';

    private const PASSWORD_RESET_CACHE_PREFIX = 'password_reset:';

    private const VERIFICATION_TTL = 600;       // 10 minutes

    private const PASSWORD_RESET_TTL = 1800;    // 30 minutes

    /**
     * Send a 6-digit verification code to the given email.
     *
     * @return string The generated code
     */
    public function sendVerificationCode(string $email): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put(self::VERIFICATION_CACHE_PREFIX.$email, $code, self::VERIFICATION_TTL);

        $this->applySmtpConfig();
        Mail::to($email)->queue(new EmailVerificationMail($code));

        return $code;
    }

    /**
     * Verify a previously-sent verification code.
     */
    public function verifyCode(string $email, string $code): bool
    {
        $cached = Cache::get(self::VERIFICATION_CACHE_PREFIX.$email);
        if ($cached === null) {
            return false;
        }
        if (! hash_equals((string) $cached, $code)) {
            return false;
        }
        Cache::forget(self::VERIFICATION_CACHE_PREFIX.$email);

        return true;
    }

    /**
     * Send a password reset link to the given email.
     *
     * @return string The reset token
     */
    public function sendPasswordReset(string $email): string
    {
        $token = Str::random(64);
        Cache::put(self::PASSWORD_RESET_CACHE_PREFIX.$token, $email, self::PASSWORD_RESET_TTL);

        $serverAddress = OptionService::get('ServerAddress', config('app.url', ''));
        $link = rtrim((string) $serverAddress, '/').'/reset?token='.$token;

        $this->applySmtpConfig();
        Mail::to($email)->queue(new PasswordResetMail($link, $email));

        return $token;
    }

    /**
     * Consume a password reset token, returning the email if valid.
     */
    public function consumePasswordResetToken(string $token): ?string
    {
        $email = Cache::get(self::PASSWORD_RESET_CACHE_PREFIX.$token);
        if ($email === null) {
            return null;
        }
        Cache::forget(self::PASSWORD_RESET_CACHE_PREFIX.$token);

        return (string) $email;
    }

    /**
     * Apply SMTP configuration from the options table to Laravel mail config.
     *
     * This enables runtime-configurable SMTP without relying solely on .env.
     */
    public function applySmtpConfig(): void
    {
        $host = OptionService::get('SMTPServer');
        $port = (int) OptionService::get('SMTPPort', 587);
        $username = OptionService::get('SMTPAccount');
        $password = OptionService::get('SMTPToken');
        $from = OptionService::get('SMTPFrom');

        if ($host === '' || $host === null) {
            return; // keep default mailer (log/array from .env)
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'encryption' => $port === 465 ? 'ssl' : 'tls',
            'timeout' => null,
            'local_domain' => parse_url(config('app.url', ''), PHP_URL_HOST),
        ]);

        if ($from !== '' && $from !== null) {
            // SMTPFrom 必须是合法邮箱地址（历史数据可能误填站点名）；
            // 无效时回退到 SMTPAccount（SMTP 登录账号通常即发件地址），
            // 两者皆无效则保留 .env 默认值，避免投出非法 From 头被上游拒收。
            $fromAddress = filter_var($from, FILTER_VALIDATE_EMAIL)
                ? $from
                : (filter_var((string) $username, FILTER_VALIDATE_EMAIL) ? $username : null);

            if ($fromAddress !== null) {
                Config::set('mail.from.address', $fromAddress);
            }

            Config::set('mail.from.name', OptionService::get('SystemName', config('app.name', 'Pease API')));
        }
    }
}
