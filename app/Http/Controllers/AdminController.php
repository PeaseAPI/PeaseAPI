<?php

namespace App\Http\Controllers;

use App\Models\Ability;
use App\Models\Channel;
use App\Models\CodingPlanAccount;
use App\Models\CodingPlanUsageLog;
use App\Models\Log;
use App\Models\Token;
use App\Models\User;
use App\Services\OptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

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

        // logs 表为冗余设计（username/token_name/channel_name 落库），无关联可加载
        $recentLogs = Log::query()
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
        // 缓存键 'public_options'：update() 保存后失效（60s 兜底，与 Option 单键缓存同口径）
        $public = Cache::remember('public_options', 60, fn () => OptionService::loadPublic());
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
                'endpoint' => $serverAddress ? $serverAddress.'/v1' : '',
                'description' => 'OpenAI-compatible chat completions API',
                'protocol' => 'OpenAI',
            ],
            [
                'key' => 'anthropic',
                'label' => 'Anthropic Claude',
                'endpoint' => $serverAddress ? $serverAddress.'/v1' : '',
                'description' => 'Anthropic Claude API (compatible endpoint)',
                'protocol' => 'Anthropic',
            ],
            [
                'key' => 'news',
                'label' => 'News',
                'endpoint' => $serverAddress ? $serverAddress.'/news' : '',
                'description' => 'News aggregation API (NewsAPI etc.)',
                'protocol' => 'News',
            ],
            [
                'key' => 'search',
                'label' => 'Search',
                'endpoint' => $serverAddress ? $serverAddress.'/search' : '',
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
     * GET /api/admin/setup-checklist — 管理员设置引导状态
     *
     * 登录后 dashboard 的「设置引导教程」数据源：聚合管理员侧剩余设置步骤的
     * 完成度（启用渠道 / 可用模型），并返回引导是否已被跳过（存 Option）。
     * 一次请求返回全部状态，避免前端多发探测请求。
     */
    public function setupChecklist(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'steps' => [
                    [
                        'key' => 'channel',
                        // 已存在启用状态的渠道：新站点接入上游后才算完成
                        'completed' => Channel::where('status', 1)->exists(),
                    ],
                    [
                        'key' => 'model',
                        // abilities 表有启用模型：渠道启用后由能力表重建生成
                        'completed' => Ability::query()->where('enabled', 1)->exists(),
                    ],
                ],
                // Option::castValue 会把 'true'/'false' 反序列化为布尔，这里统一收敛为 bool
                'dismissed' => (bool) OptionService::get('AdminSetupGuideDismissed', false),
            ],
        ]);
    }

    /**
     * POST /api/admin/setup-checklist/dismiss — 跳过设置引导（不再自动弹出）
     */
    public function dismissSetupGuide(): JsonResponse
    {
        OptionService::set('AdminSetupGuideDismissed', 'true');

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/admin/setup-checklist/reopen — 重新开启设置引导
     */
    public function reopenSetupGuide(): JsonResponse
    {
        OptionService::set('AdminSetupGuideDismissed', 'false');

        return response()->json(['success' => true]);
    }

    /**
     * GET /api/uptime/status — Uptime monitoring status.
     *
     * Returns grouped monitor statuses from Uptime Kuma integration.
     * When not configured, returns an empty list.
     */
    /**
     * Coding Plan 池可观测（P9-4）
     *
     * 消费 P9-2 落库的 coding_plan_usage_logs.meta.route（落选候选与成本明细）
     * 与 P9-3 的账号/渠道 cooldown_until，提供：
     *  ① 账号池概览（按 vendor 聚合：可用/冷却中/耗尽/停用）
     *  ② 冷却倒计时（账号级 + 渠道级，秒级实时）
     *  ③ 近 7 天跨源 failover 统计（落选原因分布、承接 vendor 分布、明细含候选成本）
     */
    public function codingPlan()
    {
        $now = time();
        $since = $now - 7 * 86400;

        // ① 账号池概览（按 vendor 聚合）
        $poolOverview = CodingPlanAccount::query()
            ->selectRaw('vendor, count(*) as total')
            ->selectRaw('sum(case when status = 2 then 1 else 0 end) as exhausted')
            ->selectRaw('sum(case when status = 0 then 1 else 0 end) as disabled')
            ->selectRaw('sum(case when cooldown_until is not null and cooldown_until > ? then 1 else 0 end) as cooling', [$now])
            ->selectRaw('sum(case when status = 1 and (cooldown_until is null or cooldown_until <= ?) then 1 else 0 end) as available', [$now])
            ->groupBy('vendor')
            ->orderBy('vendor')
            ->get();

        // ② 冷却倒计时（账号级 + 渠道级）
        $coolingAccounts = CodingPlanAccount::query()
            ->where('cooldown_until', '>', $now)
            ->orderBy('cooldown_until')
            ->get(['id', 'vendor', 'account_name', 'cooldown_until', 'status']);
        $coolingChannels = Channel::query()
            ->where('cooldown_until', '>', $now)
            ->orderBy('cooldown_until')
            ->get(['id', 'name', 'cooldown_until']);

        // ③ 近 7 天路由决策统计（仅 meta.route 非空的流水）
        $routeStats = [
            'failover_count' => 0,
            'by_reason' => [],
            'by_final_vendor' => [],
            'recent' => [],
        ];

        $routeLogs = CodingPlanUsageLog::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('meta')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->filter(fn ($log) => is_array($log->meta) && isset($log->meta['route']));

        foreach ($routeLogs as $log) {
            $route = $log->meta['route'];
            $routeStats['failover_count']++;

            $finalVendor = (string) ($log->vendor ?? 'unknown');
            $routeStats['by_final_vendor'][$finalVendor] = ($routeStats['by_final_vendor'][$finalVendor] ?? 0) + 1;

            foreach ((array) ($route['attempted'] ?? []) as $att) {
                $reason = (string) ($att['reason'] ?? 'unknown');
                $routeStats['by_reason'][$reason] = ($routeStats['by_reason'][$reason] ?? 0) + 1;
            }

            $routeStats['recent'][] = [
                'time' => (int) $log->created_at,
                'model' => (string) ($log->model ?? '-'),
                'vendor' => $finalVendor,
                'strategy' => (string) ($route['strategy'] ?? '-'),
                'failed_channel_id' => (int) ($route['failed_channel_id'] ?? 0),
                'final_channel_id' => (int) ($route['final_channel_id'] ?? 0),
                'attempted' => array_values((array) ($route['attempted'] ?? [])),
            ];
        }
        $routeStats['recent'] = array_slice($routeStats['recent'], 0, 20);

        return view('admin.coding-plan', compact('poolOverview', 'coolingAccounts', 'coolingChannels', 'routeStats'));
    }

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
