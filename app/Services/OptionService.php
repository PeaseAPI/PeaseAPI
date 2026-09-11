<?php

namespace App\Services;

use App\Models\Option;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        'Footer' => '',
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
        // Coding Plan：是否要求用户持有对应厂商订阅才能使用（默认关闭，向后兼容）
        'CodingPlanRequireSubscription' => false,

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
        // 易支付网关地址（前端设置页保存键为 PayAddress，EpayUrl 为后端别名，见 ALIASES）
        'PayAddress' => '',
        // 按量计费单价：充值金额 = 额度 × Price（默认 7.3 元 / 美元额度）
        'Price' => 7.3,
        'TopUpEnabled' => true,
        // 各网关单份额度单价（美元），未配置时的兜底值
        'CreemPrice' => 0.01,
        'WaffoPrice' => 0.01,
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
        'StopOnSensitiveEnabled' => false,
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

        // ---------- Sidebar / Nav Modules ----------
        'SidebarModulesAdmin' => '',
        'HeaderNavModules' => '',

        // ---------- Notice ----------
        'Notice' => '',

        // ---------- Console Content Settings (console_setting.*) ----------
        'console_setting.api_info_enabled' => true,
        'console_setting.api_info' => [],
        'console_setting.announcements_enabled' => true,
        'console_setting.announcements' => [],
        'console_setting.faq_enabled' => true,
        'console_setting.faq' => [],
        'console_setting.uptime_kuma_enabled' => false,
        'console_setting.uptime_kuma_groups' => [],

        // ---------- Currency / Quota Display ----------
        'QuotaPerUnit' => 500000,
        'QuotaDisplayType' => 'USD',
        'UsdExchangeRate' => 1,
        'CustomCurrencySymbol' => '¤',
        'CustomCurrencyExchangeRate' => 1,

        // ---------- OAuth Register ----------
        'OAuthRegisterEnabled' => true,
        'UserAgreementEnabled' => false,
        'PrivacyPolicyEnabled' => false,

        // ---------- 前端存量设置（new-api 命名，后端暂未消费，仅持久化回显） ----------
        'EmailAliasRestrictionEnabled' => false,
        'LinuxDOMinimumTrustLevel' => 0,
        'SMTPSSLEnabled' => false,
        'SMTPStartTLSEnabled' => false,
        'SMTPInsecureSkipVerify' => false,
        'SMTPForceAuthLogin' => false,
        'WorkerUrl' => '',
        'WorkerValidKey' => '',
        'WorkerAllowHttpImageRequestEnabled' => false,
        'CheckSensitiveOnPromptEnabled' => false,
        'ModelRequestRateLimitSuccessCount' => 10,
        'ModelRequestRateLimitGroup' => '',
        'AutomaticDisableKeywords' => '',
        'AutomaticDisableStatusCodes' => '',
        'AutomaticRetryStatusCodes' => '',
        'DataExportEnabled' => false,
        'DataExportInterval' => 5,
        'DataExportDefaultTime' => 'hour',
        'DrawingEnabled' => false,
        'MjAccountFilterEnabled' => false,
        'MjActionCheckSuccessEnabled' => false,
        'MjForwardUrlEnabled' => false,
        'MjModeClearEnabled' => false,
        'CreemTestMode' => false,
        'CreemProducts' => [],
        'CustomCallbackAddress' => '',
        // 聊天快捷入口（new-api 兼容，JSON 数组或字符串）
        'Chats' => '[]',
        // Stripe 结账配置
        'StripePriceId' => '',
        'StripePromotionCodesEnabled' => false,
        'WaffoCurrency' => 'USD',
        'WaffoNotifyUrl' => '',
        'WaffoReturnUrl' => '',
        'WaffoPayMethods' => '',
        'WaffoPrivateKey' => '',
        'WaffoPublicCert' => '',
        'WaffoSandbox' => false,
        'WaffoSandboxApiKey' => '',
        'WaffoSandboxPrivateKey' => '',
        'WaffoSandboxPublicCert' => '',
        'checkin_setting.max_quota' => 0,
    ];

    /**
     * Keys that should be stored as JSON arrays/objects.
     */
    public const JSON_KEYS = [
        'ModelRatio', 'GroupRatio', 'CompletionRatio', 'ModelPrice', 'CacheRatio',
        'EmailDomainWhitelist', 'EmailDomainRestriction', 'UserUsableGroups',
        'FriendLinks', 'AutoGroupRatio', 'CheckinStreakRules', 'PasskeyROrigins',
        'TieredBillingRules', 'SidebarModulesAdmin', 'HeaderNavModules',
        'console_setting.api_info', 'console_setting.announcements',
        'console_setting.faq', 'console_setting.uptime_kuma_groups',
        'CreemProducts',
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
        'CodingPlanRequireSubscription',
        'CreemEnabled', 'WaffoEnabled', 'WaffoPancakeEnabled', 'ChannelAffinityEnabled',
        'WechatPayEnabled', 'AlipayEnabled',
        'PerformanceMetricEnabled', 'NotifyRootEnabled', 'SensitiveWordEnabled',
        // 前端存量 / 预留开关
        'TopUpEnabled', 'EmailAliasRestrictionEnabled', 'SMTPSSLEnabled',
        'SMTPStartTLSEnabled', 'SMTPInsecureSkipVerify', 'SMTPForceAuthLogin',
        'WorkerAllowHttpImageRequestEnabled', 'CheckSensitiveOnPromptEnabled',
        'StopOnSensitiveEnabled',
        'DataExportEnabled', 'DrawingEnabled', 'MjAccountFilterEnabled',
        'MjActionCheckSuccessEnabled', 'MjForwardUrlEnabled', 'MjModeClearEnabled',
        'CreemTestMode', 'WaffoSandbox', 'StripePromotionCodesEnabled',
        'RateLimitEnabled', 'ModelRateLimitEnabled', 'GzipEnabled',
        'DecompressRequestEnabled', 'AuditLogEnabled', 'StatsEnabled',
        'RequestIdEnabled', 'RetryWithOtherChannelEnabled', 'CrossGroupRetryEnabled',
        'GroupModelRatioEnabled', 'ModelRatioSetEnable', 'AutomaticModelRatioEnabled',
        'AutoGroupRatioEnabled', 'CheckinStreakEnabled', 'LogCleanEnabled',
        'UsedataEnabled', 'TieredBillingEnabled', 'IPRateLimitEnabled',
        'PasskeyEnabled', 'TwoFAEnabled', 'TwoFARequired',
        'SecureVerificationEnabled', 'SessionCookieSecure', 'PaymentComplianceAcknowledged',
        'MJNotify', 'SunoNotify', 'TaskNotify', 'SunoAutoPlay',
        // Console content toggles
        'console_setting.api_info_enabled', 'console_setting.announcements_enabled',
        'console_setting.faq_enabled', 'console_setting.uptime_kuma_enabled',
        // OAuth / Agreement
        'OAuthRegisterEnabled', 'UserAgreementEnabled', 'PrivacyPolicyEnabled',
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
        'QuotaPerUnit',
        // 前端存量 / 预留整数项
        'MinTopUpAmount', 'LinuxDOMinimumTrustLevel',
        'ModelRequestRateLimitSuccessCount', 'DataExportInterval',
        'checkin_setting.max_quota',
    ];

    /**
     * Keys that should be floats.
     */
    public const FLOAT_KEYS = [
        'BillingPromptRatio', 'StripeUnitPrice', 'TopUpRatio',
        'UsdExchangeRate', 'CustomCurrencyExchangeRate',
        'Price', 'CreemPrice', 'WaffoPrice',
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
     * 别名映射：前端（沿用 new-api 命名）保存键 => 后端规范键。
     *
     * 背景：前端控制台源自 new-api，大量设置键与 Laravel 后端命名存在
     * 历史差异；不加映射时 PUT /api/option/ 会被 isKnown() 静默跳过，
     * 设置界面保存无效。读写两端都会在此规范化，数据库只存规范键。
     */
    public const ALIASES = [
        // ---------- 支付 ----------
        'EpayUrl' => 'PayAddress',                  // 易支付网关地址
        'MinTopUp' => 'MinTopUpAmount',             // 最低充值额度
        'StripePrice' => 'StripeUnitPrice',         // Stripe 单份额度单价
        'StripeMinTopUp' => 'StripeMinAmount',
        'WaffoUnitPrice' => 'WaffoPrice',
        'WaffoMinTopUp' => 'WaffoMinAmount',
        'StripeApiSecret' => 'StripeApiKeys',       // Stripe 密钥（后端按单值消费）
        // ---------- 显示 / 汇率 ----------
        'USDExchangeRate' => 'UsdExchangeRate',
        'general_setting.quota_display_type' => 'QuotaDisplayType',
        'general_setting.custom_currency_symbol' => 'CustomCurrencySymbol',
        'general_setting.custom_currency_exchange_rate' => 'CustomCurrencyExchangeRate',
        'general_setting.docs_link' => 'DocLink',
        // ---------- 安全 / 限流 ----------
        'ModelRequestRateLimitEnabled' => 'ModelRateLimitEnabled',
        'ModelRequestRateLimitCount' => 'ModelRateLimitCount',
        'ModelRequestRateLimitDurationMinutes' => 'ModelRateLimitDuration',
        'CheckSensitiveEnabled' => 'SensitiveWordEnabled',
        // ---------- 绘图 ----------
        'MjNotifyEnabled' => 'MJNotify',
        // ---------- OAuth 命名差异 ----------
        'GitHubOAuthEnabled' => 'GithubOAuthEnabled',
        'GitHubClientId' => 'GithubClientId',
        'GitHubClientSecret' => 'GithubClientSecret',
        // ---------- 其它点号组 / 单键 ----------
        'Logo' => 'SystemLogo',
        'legal.user_agreement' => 'UserAgreement',
        'legal.privacy_policy' => 'PrivacyPolicy',
        'checkin_setting.enabled' => 'CheckinEnabled',
        'checkin_setting.min_quota' => 'CheckinQuota',
        'channel_affinity_setting.enabled' => 'ChannelAffinityEnabled',
        'passkey.enabled' => 'PasskeyEnabled',
        'passkey.rp_id' => 'PasskeyRPID',
        'passkey.origins' => 'PasskeyROrigins',
        'perf_metrics_setting.enabled' => 'PerformanceMetricEnabled',
        'perf_metrics_setting.retention_days' => 'PerfMetricMaxAge',
        // OAuth 点号命名（oauth-section 表单风格）→ 规范键
        'discord.enabled' => 'DiscordOAuthEnabled',
        'discord.client_id' => 'DiscordClientId',
        'discord.client_secret' => 'DiscordClientSecret',
        'oidc.enabled' => 'OIDCEnabled',
        'oidc.client_id' => 'OIDCClientId',
        'oidc.client_secret' => 'OIDCClientSecret',
        'oidc.well_known' => 'OIDCWellKnown',
        'oidc.authorization_endpoint' => 'OIDCAuthorizationEndpoint',
        'oidc.token_endpoint' => 'OIDCTokenEndpoint',
        'oidc.user_info_endpoint' => 'OIDCUserInfoEndpoint',
    ];

    /**
     * 扩展键前缀：允许按前缀持久化的点号设置组（无后端消费者时仅存储回显）。
     */
    public const EXTENSION_PREFIXES = [
        'model_setting.', 'model_deployment.', 'fetch_setting.',
        'performance_setting.', 'perf_metrics_setting.', 'payment_setting.',
        'token_setting.', 'console_setting.', 'general_setting.', 'legal.',
        'checkin_setting.', 'passkey.', 'channel_affinity_setting.',
        'claude.', 'gemini.', 'grok.', 'global.', 'monitor_setting.',
        'group_ratio_setting.', 'tool_price_setting.', 'quota_setting.',
        'billing_setting.',
    ];

    /**
     * Resolve an option key to its canonical (stored) name.
     */
    public static function canonicalKey(string $key): string
    {
        return self::ALIASES[$key] ?? $key;
    }

    /**
     * Per-request memo of raw DB values (canonical key => raw|NULL).
     * Avoids repeated cache-store round-trips when the hot path (billing,
     * auth, rate limiting) reads several options within one request.
     */
    private static array $runtimeCache = [];

    /**
     * Get a single option value (with default fallback).
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $key = self::canonicalKey($key);
        if (! array_key_exists($key, self::$runtimeCache)) {
            self::$runtimeCache[$key] = Option::get($key, null);
        }
        $value = self::$runtimeCache[$key];
        if ($value === null) {
            return $default ?? self::DEFAULTS[$key] ?? null;
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
     *
     * JSON_KEYS values must be valid JSON when given as strings — invalid
     * input throws instead of silently persisting an empty default map.
     */
    public static function set(string $key, mixed $value): void
    {
        $key = self::canonicalKey($key);
        if (in_array($key, self::JSON_KEYS, true) && is_string($value) && trim($value) !== '') {
            json_decode($value);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException(
                    "Option [{$key}] expects valid JSON; refusing to store invalid input."
                );
            }
        }
        $value = self::cast($key, $value);
        Option::set($key, $value);
        unset(self::$runtimeCache[$key]);
    }

    /**
     * Set multiple options at once (atomic: no partial writes on failure).
     */
    public static function setMany(array $options): void
    {
        $pairs = [];
        foreach ($options as $key => $value) {
            if (is_string($key) && $key !== '') {
                $pairs[$key] = $value;
            }
        }
        if ($pairs === []) {
            return;
        }
        DB::transaction(function () use ($pairs): void {
            foreach ($pairs as $key => $value) {
                self::set($key, $value);
            }
        });
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
        // 别名键与规范键同步输出，保证前端任一命名都能取到已存值
        foreach (self::ALIASES as $alias => $canonical) {
            if (array_key_exists($canonical, $merged)) {
                $merged[$alias] = $merged[$canonical];
            }
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
        $key = self::canonicalKey($key);
        // 扩展前缀键（model_setting.* 等）：原样存储返回，由前端解析
        foreach (self::EXTENSION_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return $value;
            }
        }
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
        if (array_key_exists($key, self::DEFAULTS) || isset(self::ALIASES[$key])) {
            return true;
        }
        foreach (self::EXTENSION_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clear all option cache.
     *
     * Redis store: SCAN+DEL by prefix (fast path in Option::clearCache()).
     * Other stores (file/database/...): prefix scan is impossible — fall back
     * to forgetting every known key explicitly so install/seed flows never
     * serve stale values for up to a full TTL.
     */
    public static function clearCache(): void
    {
        Option::clearCache();
        self::$runtimeCache = [];
        if (self::cacheDriver() !== 'redis') {
            $keys = array_unique(array_merge(
                array_keys(self::DEFAULTS),
                array_keys(self::ALIASES),
                array_values(self::ALIASES),
            ));
            foreach ($keys as $known) {
                Cache::forget("option:{$known}");
            }
        }
    }

    /**
     * Driver name of the current default cache store.
     */
    private static function cacheDriver(): string
    {
        $store = (string) config('cache.default');

        return (string) config("cache.stores.{$store}.driver", $store);
    }
}
