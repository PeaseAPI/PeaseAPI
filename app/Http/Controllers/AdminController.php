<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Log;
use App\Models\Token;
use App\Models\User;
use App\Services\OptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    public function dashboard()
    {
        $user = Auth::user();
        $stats = [
            'total_users' => User::count(),
            'active_users' => User::where('status', 1)->count(),
            'total_channels' => Channel::count(),
            'active_channels' => Channel::where('status', 1)->count(),
            'total_tokens' => Token::count(),
            'active_tokens' => Token::where('status', 1)->count(),
            'total_requests' => User::sum('request_count'),
            'total_used_quota' => User::sum('used_quota'),
        ];

        $recentLogs = Log::with(['user', 'token', 'channel'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $recentUsers = User::orderBy('created_at', 'desc')->limit(5)->get();

        return view('admin.dashboard', compact('user', 'stats', 'recentLogs', 'recentUsers'));
    }

    public function users(Request $request)
    {
        return view('admin.users');
    }

    public function channels()
    {
        return view('admin.channels');
    }

    public function tokens()
    {
        return view('admin.tokens');
    }

    public function abilities()
    {
        return view('admin.abilities');
    }

    public function logs()
    {
        return view('admin.logs');
    }

    public function redemptions()
    {
        return view('admin.redemptions');
    }

    public function options()
    {
        return view('admin.options');
    }

    public function systemSettings()
    {
        return view('admin.system-settings');
    }

    /**
     * GET /api/status — Public system status endpoint.
     */
    public function status(): JsonResponse
    {
        $public = OptionService::loadPublic();
        $data = [];

        // ---- System ----
        $data['version'] = config('app.version', '1.0.0');
        $data['system_name'] = $public['SystemName'] ?? 'Pease API';
        $data['logo'] = $public['SystemLogo'] ?? '';
        $data['footer_html'] = $public['SystemFooter'] ?? '';

        // ---- OAuth / Login ----
        $data['github_oauth'] = $public['GithubOAuthEnabled'] ?? false;
        $data['github_client_id'] = $public['GithubClientId'] ?? '';
        $data['discord_oauth'] = $public['DiscordOAuthEnabled'] ?? false;
        $data['discord_client_id'] = $public['DiscordClientId'] ?? '';
        $data['oidc_enabled'] = $public['OIDCEnabled'] ?? false;
        $data['oidc_authorization_endpoint'] = $public['OIDCAuthorizationEndpoint'] ?? '';
        $data['oidc_client_id'] = $public['OIDCClientId'] ?? '';
        $data['linuxdo_oauth'] = $public['LinuxDOOAuthEnabled'] ?? false;
        $data['linuxdo_client_id'] = $public['LinuxDOClientId'] ?? '';
        $data['telegram_oauth'] = $public['TelegramOAuthEnabled'] ?? false;
        $data['telegram_bot_name'] = $public['TelegramBotName'] ?? '';
        $data['passkey_login'] = $public['PasskeyEnabled'] ?? false;

        // ---- WeChat ----
        $data['wechat_login'] = $public['WeChatAuthEnabled'] ?? false;
        $data['wechat_qrcode'] = '';
        $data['wechat_qr_code'] = '';
        $data['wechat_qrcode_image_url'] = '';
        $data['wechat_qr_code_image_url'] = '';
        $data['wechat_account_qrcode_image_url'] = $public['WeChatAccountQRCode'] ?? '';
        $data['WeChatAccountQRCodeImageURL'] = $public['WeChatAccountQRCode'] ?? '';

        // ---- Turnstile ----
        $data['turnstile_check'] = $public['TurnstileCheckEnabled'] ?? false;
        $data['turnstile_site_key'] = $public['TurnstileSiteKey'] ?? '';

        // ---- Registration / Login toggles ----
        $data['email_verification'] = $public['EmailVerificationEnabled'] ?? false;
        $data['self_use_mode_enabled'] = $public['SelfUseModeEnabled'] ?? false;
        $data['register_enabled'] = $public['RegisterEnabled'] ?? true;
        $data['password_login_enabled'] = $public['PasswordLoginEnabled'] ?? true;
        $data['password_register_enabled'] = $public['PasswordRegisterEnabled'] ?? true;
        $data['oauth_register_enabled'] = $public['OAuthRegisterEnabled'] ?? true;

        // ---- Currency / Quota display ----
        $data['display_in_currency'] = $public['DisplayInCurrencyEnabled'] ?? true;
        $data['display_token_stat_enabled'] = $public['DisplayTokenStatEnabled'] ?? true;
        $data['quota_per_unit'] = $public['QuotaPerUnit'] ?? 500000;
        $data['quota_display_type'] = $public['QuotaDisplayType'] ?? 'USD';
        $data['usd_exchange_rate'] = $public['UsdExchangeRate'] ?? 1;
        $data['custom_currency_symbol'] = $public['CustomCurrencySymbol'] ?? '¤';
        $data['custom_currency_exchange_rate'] = $public['CustomCurrencyExchangeRate'] ?? 1;

        // ---- Flags ----
        $data['demo_site_enabled'] = $public['DemoSiteEnabled'] ?? false;
        $data['user_agreement_enabled'] = $public['UserAgreementEnabled'] ?? false;
        $data['privacy_policy_enabled'] = $public['PrivacyPolicyEnabled'] ?? false;

        // ---- Sidebar / Nav Modules (PascalCase) ----
        $data['SidebarModulesAdmin'] = $public['SidebarModulesAdmin'] ?? '';
        $data['HeaderNavModules'] = $public['HeaderNavModules'] ?? '';

        // ---- Notice (PascalCase) ----
        $data['Notice'] = $public['Notice'] ?? '';

                // ---- API Base URL ----
        $data['server_address'] = $public['ServerAddress'] ?? '';

                // ---- API Protocol Endpoints (auto-generated for user dashboard) ----
        $serverAddress = rtrim($data['server_address'], '/');
        $data['api_protocol_endpoints'] = [
            [
                'key' => 'openai',
                'label' => 'OpenAI Compatible',
                'endpoint' => $serverAddress ? $serverAddress . '/v1' : '',
                'description' => 'OpenAI-compatible chat completions API',
                'protocol' => 'OpenAI',
            ],
            [
                'key' => 'anthropic',
                'label' => 'Anthropic Claude',
                'endpoint' => $serverAddress ? $serverAddress . '/v1' : '',
                'description' => 'Anthropic Claude API (compatible endpoint)',
                'protocol' => 'Anthropic',
            ],
            [
                'key' => 'news',
                'label' => 'News',
                'endpoint' => $serverAddress ? $serverAddress . '/news' : '',
                'description' => 'News aggregation API (NewsAPI etc.)',
                'protocol' => 'News',
            ],
            [
                'key' => 'search',
                'label' => 'Search',
                'endpoint' => $serverAddress ? $serverAddress . '/search' : '',
                'description' => 'Web search API (Tavily, Exa, Brave Search, Google CSE)',
                'protocol' => 'Search',
            ],
        ];

        // ---- Console content settings (snake_case at top level) ----
        $data['api_info_enabled'] = $public['console_setting.api_info_enabled'] ?? true;
        $data['api_info'] = $public['console_setting.api_info'] ?? [];
        $data['announcements_enabled'] = $public['console_setting.announcements_enabled'] ?? true;
        $data['announcements'] = $public['console_setting.announcements'] ?? [];
        $data['faq_enabled'] = $public['console_setting.faq_enabled'] ?? true;
        $data['faq'] = $public['console_setting.faq'] ?? [];
        $data['uptime_kuma_enabled'] = $public['console_setting.uptime_kuma_enabled'] ?? false;
        $data['uptime_kuma_groups'] = $public['console_setting.uptime_kuma_groups'] ?? [];

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $data,
        ]);
    }

    /**
     * GET /api/uptime/status — Uptime monitoring status.
     *
     * Returns grouped monitor statuses from Uptime Kuma integration.
     * When not configured, returns an empty list.
     */
    public function uptimeStatus(): JsonResponse
    {
        $enabled = OptionService::get('console_setting.uptime_kuma_enabled', false);

        if (! $enabled) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        // TODO: Implement actual Uptime Kuma API proxy when the
        // external monitoring service is configured. For now, return
        // empty groups so the frontend renders gracefully.
        return response()->json([
            'success' => true,
            'data' => [],
        ]);
    }
}
