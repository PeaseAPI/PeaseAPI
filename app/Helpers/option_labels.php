<?php

/**
 * Option key display label helpers.
 *
 * Maps an Option key like "SystemName" / "QuotaForNewUser" / "ChatLink2" /
 * "IPRateLimitCount" / "UserUsableGroups" into a human-readable label that
 * matches the original Go new-api web frontend i18n keys:
 *   SystemName        -> "System Name"
 *   QuotaForNewUser   -> "Quota For New User"
 *   ChatLink2         -> "Chat Link 2"
 *   IPRateLimitCount  -> "IP Rate Limit Count"
 *   UserUsableGroups  -> "User Usable Groups"
 *
 * Numbers and consecutive uppercase runs (e.g. "IP", "OIDC", "MJ", "SMTP",
 * "GitHub", "WeChat", "Discord", "Telegram", "LinuxDO", "WaffoPancake",
 * "Creem", "Stripe", "Epay", "MJNotify", "SunoNotify", etc.) are kept as
 * their natural capitalisation.
 */
if (!function_exists('option_label')) {
    /**
     * Convert a camelCase option key into a spaced English label.
     */
    function option_label(string $key): string
    {
        if ($key === '') {
            return $key;
        }
        // Insert a space between a lowercase/digit and an uppercase letter
        // (so "SystemName" -> "System Name" and "QuotaForNewUser" -> "Quota For New User").
        $label = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $key);
        // Insert a space between an uppercase letter followed by an
        // uppercase-then-lowercase sequence (so "IPRateLimit" -> "IP Rate Limit"
        // and "OIDCWellKnown" -> "OIDC Well Known").
        $label = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $label);
        return trim($label);
    }
}

if (!function_exists('option_trans')) {
    /**
     * Translate an option key with fallback.
     *
     * Returns the Chinese label when found, otherwise the spaced English
     * label. The translation is performed via Laravel's __() so it follows
     * the current locale (and falls back to the default locale).
     */
    function option_trans(string $key, array $replace = []): string
    {
        $label = option_label($key);
        $translated = __($label, $replace);
        // If the key wasn't found, Laravel's translator returns the key
        // itself. In that case, expose the spaced English label so the
        // admin UI is at least readable.
        if ($translated === $label) {
            return $label;
        }
        return $translated;
    }
}