<?php

namespace App\Services;

use App\Models\Option;
use Illuminate\Support\Facades\Cache;

/**
 * Option (System Configuration) Service
 *
 * Centralizes 200+ configuration items with defaults, categories, and validation.
 * Mirrors Go new-api model/option.go behavior.
 */
class OptionService
{
    /**
     * All known option keys grouped by category.
     * Values here are PHP defaults used when DB row missing.
     */
    public const DEFAULTS = [
        // ---------- General / System ----------
        'SystemName' => 'Pease API',
        'SystemLogo' => '',
        'SystemFooter' => '',
        'HomePageContent' => '',
        'About' => '',
        'Theme' => 'default',
        'Language' => 'zh-CN',
        'ServerAddress' => '',
        'BackendName' => 'Pease API Backend',
        'HomePageLink' => '',
        'ChatLink' => '',
        'ChatLink2' => '',
        'ChatLink3' => '',
        'ChatLink4' => '',
        'TopUpLink' => '',
        'DocLink' => '',
        'FriendLinks' => [],

        // ---------- Registration / Login ----------
        'RegisterEnabled' => true,
        'PasswordRegisterEnabled' => true,
        'EmailVerificationEnabled' => false,
        'TurnstileCheckEnabled' => false,
        'TurnstileSiteKey' => '',
        'TurnstileSecretKey' => '',
        'EmailDomainRestrictionEnabled' => false,
        'EmailDomainWhitelist' => '',
        'EmailDomainRestriction' => '',
        'PasswordLoginEnabled' => true,
        'PasswordStrengthEnabled' => false,
        'PasswordMinLength' => 8,
        'PasswordMaxLength' => 64,
        'GithubOAuthEnabled' => false,
        'GithubClientId' => '',
        'GithubClientSecret' => '',
        'GithubOrganization' => '',
        'DiscordOAuthEnabled' => false,
        'DiscordClientId' => '',
        'DiscordClientSecret' => '',
        'DiscordGuildId' => '',
        'WeChatAuthEnabled' => false,
        'WeChatServerAddress' => '',
        'WeChatAccountQRCode' => '',
        'WeChatServerToken' => '',
        'TelegramOAuthEnabled' => false,
        'TelegramBotToken' => '',
        'TelegramBotName' => '',
        'OIDCEnabled' => false,
        'OIDCClientId' => '',
        'OIDCClientSecret' => '',
        'OIDCWellKnown' => '',
        'OIDCAuthorizationEndpoint' => '',
        'OIDCTokenEndpoint' => '',
        'OIDCUserInfoEndpoint' => '',
        'OIDCScopes' => 'openid profile email',
        'OIDCDisplayName' => 'OIDC',
        'OIDCGroupMapping' => '',
        'LinuxDOOAuthEnabled' => false,
        'LinuxDOClientId' => '',
        'LinuxDOClientSecret' => '',
        'OAuthRedirectURI' => '',
        'OAuthStateTTL' => 600,

        // ---------- Phone / SMS ----------
        'PhoneLoginEnabled' => false,
        'PhoneRegisterEnabled' => false,
        'PhoneVerificationEnabled' => false,
        'PhonePasswordResetEnabled' => false,
        'SmsEnabled' => false,
        'SmsProvider' => 'aliyun',          // aliyun
        'AliyunSmsAccessKeyId' => '',
        'AliyunSmsAccessKeySecret' => '',
        'AliyunSmsSignName' => '',
        'AliyunSmsTemplateCode' => '',      // 验证码模板 CODE
        'AliyunSmsRegion' => 'cn-hangzhou',
        'SmsCodeTTL' => 300,                // 验证码有效期（秒）
        'SmsCodeLength' => 6,               // 验证码位数
        'SmsSendInterval' => 60,            // 同一号码发送间隔（秒）
        'SmsDailyLimit' => 10,              // 同一号码每日上限
        'SmsIpHourLimit' => 5,              // 同一 IP 每小时上限

        // ---------- SMTP / Email ----------
        'SMTPServer' => '',
        'SMTPPort' => 587,
        'SMTPAccount' => '',
        'SMTPFrom' => '',
        'SMTPToken' => '',
        'SMTPFromName' => 'Pease API',

        // ---------- Operations ----------
        'QuotaForNewUser' => 0,
        'QuotaForInviter' => 0,
        'QuotaForInvitee' => 0,
        'QuotaRemindThreshold' => 1000,
        'PreConsumedQuota' => 500,
        'SelfUseModeEnabled' => false,
        'DemoSiteEnabled' => false,
        'UserDefaultGroup' => 'default',
        'UserUsableGroups' => 'default',
        'AutomaticDisableChannelEnabled' => false,
        'AutomaticEnableChannelEnabled' => false,
        'ChannelDisableThreshold' => 5,
        'ChannelTestTimeout' => 30,
        'LogConsumeEnabled' => true,
        'LogNotConsumeEnabled' => false,
        'DisplayInCurrencyEnabled' => true,
        'DisplayTokenStatEnabled' => true,
        'RetryTimes' => 0,
        'BillingPromptRatio' => 0.0,
        'GroupModelRatioEnabled' => false,
        'ModelRatioSetEnable' => false,
        'AutomaticModelRatioEnabled' => false,
        'AutoGroupRatioEnabled' => false,
        'AutoGroupRatio' => [],

        // ---------- Pricing / Ratios ----------
        'ModelRatio' => [],
        'GroupRatio' => ['default' => 1],
        'CompletionRatio' => [],
        'ModelPrice' => [],
        'CacheRatio' => [],

        // ---------- User Agreement ----------
        'UserAgreement' => '',
        'UserAgreementUpdatedAt' => '',
        'PrivacyPolicy' => '',
        'PrivacyPolicyUpdatedAt' => '',

        // ---------- Check-in ----------
        'CheckinEnabled' => false,
        'CheckinQuota' => 1000,
        'CheckinMaxContinuous' => 7,
        'CheckinStreakEnabled' => false,
        'CheckinStreakRules' => [],
        'CheckinStreakResetHour' => 0,

        // ---------- Subscription ----------
        'SubscriptionEnabled' => false,
        'SubscriptionResetDay' => 1,

        // ---------- Redemption ----------
        'RedemptionEnabled' => true,

        // ---------- Payment ----------
        // 支付方式配置（独立配置项）
        'PayMethod1Enabled' => '1',
        'PayMethod1Name' => '支付宝',
        'PayMethod1Type' => 'alipay',
        'PayMethod1Icon' => 'SiAlipay',
        'PayMethod1MinTopup' => '',
        'PayMethod2Enabled' => '1',
        'PayMethod2Name' => '微信支付',
        'PayMethod2Type' => 'wxpay',
        'PayMethod2Icon' => 'SiWechat',
        'PayMethod2MinTopup' => '',
        'PayMethod3Enabled' => '0',
        'PayMethod3Name' => '',
        'PayMethod3Type' => '',
        'PayMethod3Icon' => '',
        'PayMethod3MinTopup' => '',
        'PayMethod4Enabled' => '0',
        'PayMethod4Name' => '',
        'PayMethod4Type' => '',
        'PayMethod4Icon' => '',
        'PayMethod4MinTopup' => '',
        // 兼容旧版 JSON 格式
        'PayMethods' => '[{"name":"支付宝","icon":"SiAlipay","type":"alipay"},{"name":"微信","icon":"SiWechat","type":"wxpay"}]',
        'StripeEnabled' => false,
        'StripeApiKeys' => '',
        'StripeWebhookSecret' => '',
        'StripeUnitPrice' => 0.1,
        'StripeMinAmount' => 1,
        'StripeMaxAmount' => 1000,
        'EpayEnabled' => false,
        'EpayId' => '',
        'EpayKey' => '',
        'EpayAddress' => '',
        'EpayMinAmount' => 1,
        'EpayMaxAmount' => 1000,
        'CreemEnabled' => false,
        'CreemApiKey' => '',
        'CreemWebhookSecret' => '',
        'CreemMinAmount' => 1,
        'CreemMaxAmount' => 1000,
        'WaffoEnabled' => false,
        'WaffoMerchantId' => '',
        'WaffoApiKey' => '',
        'WaffoMinAmount' => 1,
        'WaffoMaxAmount' => 1000,
        'WaffoPancakeEnabled' => false,
        'WaffoPancakeMerchantId' => '',
        'WaffoPancakeApiKey' => '',
        'WaffoPancakeWebhookSecret' => '',
        'TopUpMinAmount' => 1,
        'TopUpMaxAmount' => 1000,
        'TopUpRatio' => 1.0,
        'PaymentComplianceAcknowledged' => false,
        'PaymentComplianceAcknowledgedAt' => 0,

        // ---------- 原生微信支付 V3 ----------
        'WechatPayEnabled' => false,
        'WechatPayAppId' => '',           // 公众号/小程序 AppId
        'WechatPayMchId' => '',           // 商户号
        'WechatPayApiV3Key' => '',        // API V3 密钥
        'WechatPaySerialNo' => '',        // 商户证书序列号
        'WechatPayPrivateKey' => '',      // 商户私钥(PEM)
        'WechatPayNotifyUrl' => '',       // 回调地址(留空则自动生成)

        // ---------- 原生支付宝 ----------
        'AlipayEnabled' => false,
        'AlipayAppId' => '',
        'AlipayPrivateKey' => '',         // 应用私钥
        'AlipayAlipayPublicKey' => '',    // 支付宝公钥
        'AlipayAppPublicKey' => '',       // 应用公钥(可选)
        'AlipayMode' => 'normal',         // normal|sandbox
        'AlipayNotifyUrl' => '',

        // ---------- Channel Affinity ----------
        'ChannelAffinityEnabled' => false,
        'ChannelAffinityExpireMinutes' => 60,

        // ---------- Monitor ----------
        'PerformanceMetricEnabled' => false,
        'MetricDisplayThreshold' => 1000,
        'MaxRetryTimes' => 3,
        'PerfMetricMaxAge' => 7,
        'PerfMetricMaxCount' => 10000,

        // ---------- Midjourney / Suno / Task ----------
        'MJNotify' => false,
        'MJNotifyChannel' => '',
        'SunoNotify' => false,
        'SunoNotifyChannel' => '',
        'TaskNotify' => false,
        'TaskNotifyChannel' => '',
        'MjDefaultChannel' => 0,
        'MjDefaultAvatar' => '',
        'MjMode' => 'fast',
        'MjType' => '',
        'SunoAutoPlay' => false,
        'SunoDefaultChannel' => 0,

        // ---------- Security / Session / 2FA / Passkey ----------
        'SessionSecret' => '',
        'SessionCookieSameSite' => 'lax',
        'SessionCookieSecure' => false,
        'SecureVerificationEnabled' => false,
        'SecureVerificationTimeout' => 300,
        'TwoFAEnabled' => false,
        'TwoFARequired' => false,
        'PasskeyEnabled' => false,
        'PasskeyRPID' => '',
        'PasskeyROrigins' => [],
        'IPRateLimitEnabled' => false,
        'IPRateLimitCount' => 60,
        'IPRateLimitDuration' => 60,

        // ---------- Log / Data ----------
        'LogDataRetentionDays' => 30,
        'LogCleanEnabled' => false,
        'LogCleanIntervalDays' => 7,
        'UsedataEnabled' => false,

        // ---------- Tiered Billing ----------
        'TieredBillingEnabled' => false,
        'TieredBillingRules' => [],

        // ---------- Misc ----------
        'NotifyRootEnabled' => false,
        'NotifyRootThreshold' => 10000,
        'SensitiveWordEnabled' => false,
        'SensitiveWords' => '',
        'RateLimitEnabled' => false,
        'GlobalApiRateLimit' => 180,
        'GlobalWebRateLimit' => 60,
        'ModelRateLimitEnabled' => false,
        'ModelRateLimitDuration' => 60,
        'ModelRateLimitCount' => 60,
        'SearchRateLimit' => 30,
        'CriticalRateLimit' => 3,
        'EmailVerificationRateLimit' => 3,
        'RequestBodyLimit' => 10485760,
        'GzipEnabled' => true,
        'DecompressRequestEnabled' => false,
        'AuditLogEnabled' => false,
        'StatsEnabled' => true,
        'RequestIdEnabled' => true,
        'RetryWithOtherChannelEnabled' => true,
        'CrossGroupRetryEnabled' => true,
        'AutoGroupSetting' => '',
        'UserUsableGroupSetting' => '',
    ];

    /**
     * Keys that should be stored as JSON arrays/objects.
     */
    public const JSON_KEYS = [
        'ModelRatio', 'GroupRatio', 'CompletionRatio', 'ModelPrice', 'CacheRatio',
        'EmailDomainWhitelist', 'EmailDomainRestriction', 'UserUsableGroups',
        'FriendLinks', 'AutoGroupRatio', 'CheckinStreakRules', 'PasskeyROrigins',
        'TieredBillingRules',
    ];

    /**
     * Keys that should be stored as boolean.
     */
    public const BOOL_KEYS = [
        // 支付方式启用
        'PayMethod1Enabled', 'PayMethod2Enabled', 'PayMethod3Enabled', 'PayMethod4Enabled',
        // 注册登录
        'RegisterEnabled', 'PasswordRegisterEnabled', 'EmailVerificationEnabled',
        'PhoneLoginEnabled', 'PhoneRegisterEnabled', 'PhoneVerificationEnabled',
        'PhonePasswordResetEnabled', 'SmsEnabled',
        'TurnstileCheckEnabled', 'EmailDomainRestrictionEnabled', 'PasswordLoginEnabled',
        'PasswordStrengthEnabled', 'GithubOAuthEnabled', 'DiscordOAuthEnabled',
        'WeChatAuthEnabled', 'TelegramOAuthEnabled', 'OIDCEnabled', 'LinuxDOOAuthEnabled',
        'SelfUseModeEnabled', 'DemoSiteEnabled', 'AutomaticDisableChannelEnabled',
        'AutomaticEnableChannelEnabled', 'LogConsumeEnabled', 'LogNotConsumeEnabled',
        'DisplayInCurrencyEnabled', 'DisplayTokenStatEnabled', 'CheckinEnabled',
        'SubscriptionEnabled', 'RedemptionEnabled', 'StripeEnabled', 'EpayEnabled',
        'CreemEnabled', 'WaffoEnabled', 'WaffoPancakeEnabled', 'ChannelAffinityEnabled',
        'WechatPayEnabled', 'AlipayEnabled',
        'PerformanceMetricEnabled', 'NotifyRootEnabled', 'SensitiveWordEnabled',
        'RateLimitEnabled', 'ModelRateLimitEnabled', 'GzipEnabled',
        'DecompressRequestEnabled', 'AuditLogEnabled', 'StatsEnabled',
        'RequestIdEnabled', 'RetryWithOtherChannelEnabled', 'CrossGroupRetryEnabled',
        'GroupModelRatioEnabled', 'ModelRatioSetEnable', 'AutomaticModelRatioEnabled',
        'AutoGroupRatioEnabled', 'CheckinStreakEnabled', 'LogCleanEnabled',
        'UsedataEnabled', 'TieredBillingEnabled', 'IPRateLimitEnabled',
        'PasskeyEnabled', 'TwoFAEnabled', 'TwoFARequired',
        'SecureVerificationEnabled', 'SessionCookieSecure', 'PaymentComplianceAcknowledged',
        'MJNotify', 'SunoNotify', 'TaskNotify', 'SunoAutoPlay',
    ];

    /**
     * Keys that should be integers.
     */
    public const INT_KEYS = [
        'SMTPPort', 'QuotaForNewUser', 'QuotaForInviter', 'QuotaForInvitee',
        'QuotaRemindThreshold', 'PreConsumedQuota', 'ChannelDisableThreshold',
        'ChannelTestTimeout', 'RetryTimes', 'CheckinQuota', 'CheckinMaxContinuous',
        'SubscriptionResetDay', 'ChannelAffinityExpireMinutes', 'MetricDisplayThreshold',
        'MaxRetryTimes', 'NotifyRootThreshold', 'GlobalApiRateLimit', 'GlobalWebRateLimit',
        'ModelRateLimitDuration', 'ModelRateLimitCount', 'SearchRateLimit',
        'CriticalRateLimit', 'EmailVerificationRateLimit', 'RequestBodyLimit',
        'OAuthStateTTL', 'PasswordMinLength', 'PasswordMaxLength',
        'StripeMinAmount', 'StripeMaxAmount', 'EpayMinAmount', 'EpayMaxAmount',
        'CreemMinAmount', 'CreemMaxAmount', 'WaffoMinAmount', 'WaffoMaxAmount',
        'TopUpMinAmount', 'TopUpMaxAmount', 'PerfMetricMaxAge', 'PerfMetricMaxCount',
        'MjDefaultChannel', 'SunoDefaultChannel', 'SecureVerificationTimeout',
        'IPRateLimitCount', 'IPRateLimitDuration', 'LogDataRetentionDays',
        'LogCleanIntervalDays', 'CheckinStreakResetHour',
        'PaymentComplianceAcknowledgedAt',
        'SmsCodeTTL', 'SmsCodeLength', 'SmsSendInterval', 'SmsDailyLimit', 'SmsIpHourLimit',
    ];

    /**
     * Keys that should be floats.
     */
    public const FLOAT_KEYS = [
        'BillingPromptRatio', 'StripeUnitPrice', 'TopUpRatio',
    ];

    /**
     * Secret keys that require Root + SecureVerification to view/set.
     */
    public const SECRET_KEYS = [
        'GithubClientSecret', 'DiscordClientSecret', 'OIDCClientSecret',
        'LinuxDOClientSecret', 'SMTPToken', 'StripeApiKeys', 'StripeWebhookSecret',
        'EpayKey', 'CreemApiKey', 'CreemWebhookSecret', 'WaffoApiKey',
        'TelegramBotToken', 'TurnstileSecretKey',
        'WechatPayApiV3Key', 'WechatPayPrivateKey',
        'AlipayPrivateKey', 'AlipayAlipayPublicKey',
        'AliyunSmsAccessKeySecret',
    ];

    /**
     * Get a single option value (with default fallback).
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $default = $default ?? self::DEFAULTS[$key] ?? null;
        $value = Option::get($key, null);
        if ($value === null) {
            return $default;
        }

        return self::cast($key, $value);
    }

    /**
     * Get multiple option values.
     */
    public static function getMany(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = self::get($key);
        }

        return $result;
    }

    /**
     * Set an option value (with type casting and cache invalidation).
     */
    public static function set(string $key, mixed $value): void
    {
        $value = self::cast($key, $value);
        Option::set($key, $value);
    }

    /**
     * Set multiple options at once.
     */
    public static function setMany(array $options): void
    {
        foreach ($options as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            self::set($key, $value);
        }
    }

    /**
     * Load all options merged with defaults (for frontend consumption).
     */
    public static function loadAll(): array
    {
        $stored = Option::loadAll();
        $merged = self::DEFAULTS;
        foreach ($stored as $key => $value) {
            $merged[$key] = self::cast($key, $value);
        }

        return $merged;
    }

    /**
     * Load only the keys that are safe to expose publicly (no secrets).
     */
    public static function loadPublic(): array
    {
        $all = self::loadAll();
        // Remove secrets and internal-only keys
        $hidden = array_merge(self::SECRET_KEYS, ['BackendName']);
        foreach ($hidden as $key) {
            unset($all[$key]);
        }

        return $all;
    }

    /**
     * Cast a value according to its key type.
     */
    public static function cast(string $key, mixed $value): mixed
    {
        if (in_array($key, self::BOOL_KEYS, true)) {
            if (is_bool($value)) {
                return $value;
            }
            if (is_string($value)) {
                return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
            }

            return (bool) $value;
        }
        if (in_array($key, self::INT_KEYS, true)) {
            return (int) $value;
        }
        if (in_array($key, self::FLOAT_KEYS, true)) {
            return (float) $value;
        }
        if (in_array($key, self::JSON_KEYS, true)) {
            if (is_array($value)) {
                return $value;
            }
            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }

            return self::DEFAULTS[$key] ?? [];
        }

        return $value;
    }

    /**
     * Check if a key is a secret (requires elevated permission).
     */
    public static function isSecret(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }

    /**
     * Check if a key is a known option.
     */
    public static function isKnown(string $key): bool
    {
        return array_key_exists($key, self::DEFAULTS);
    }

    /**
     * Clear all option cache.
     */
    public static function clearCache(): void
    {
        Option::clearCache();
    }
}
