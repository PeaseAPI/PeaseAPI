<?php

use App\Http\Controllers\AbilityController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\CheckinController;
use App\Http\Controllers\CodingPlanController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MidjourneyController;
use App\Http\Controllers\ModelController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\OAuthController;
use App\Http\Controllers\OptionController;
use App\Http\Controllers\RedemptionController;
use App\Http\Controllers\RelayController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SunoController;
use App\Http\Controllers\SystemInfoController;
use App\Http\Controllers\SystemTaskController;
use App\Http\Controllers\TokenController;
use App\Http\Controllers\TopUpController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserDataController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\WebAuthController;
use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\ApiRateLimit;
use App\Http\Middleware\DecompressRequest;
use App\Http\Middleware\Distributor;
use App\Http\Middleware\ModelRateLimit;
use App\Http\Middleware\RootAuth;
use App\Http\Middleware\Stats;
use App\Http\Middleware\SystemPerformanceCheck;
use App\Http\Middleware\TokenAuth;
use App\Http\Middleware\UserAuth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Complete new-api PHP Implementation
|--------------------------------------------------------------------------
*/

// ============================================
// PUBLIC ROUTES (No Authentication Required)
// ============================================

// Setup & Installation
// [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/setup', [InstallController::class, 'status']);
// [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/setup', [InstallController::class, 'install']);

// System Status
Route::get('/status', [AdminController::class, 'status']);
Route::get('/uptime/status', [AdminController::class, 'uptimeStatus']);

// Models List (requires authentication via token)
Route::get('/models', [ModelController::class, 'list']);

// Public Announcements
Route::get('/notice', [OptionController::class, 'notice']);
Route::get('/user-agreement', [OptionController::class, 'userAgreement']);
Route::get('/privacy-policy', [OptionController::class, 'privacyPolicy']);
Route::get('/about', [OptionController::class, 'about']);
Route::get('/home_page_content', [OptionController::class, 'homePageContent']);
Route::get('/pricing', [OptionController::class, 'pricing']);

// Public Coding Plan deduction offers (vendors + model ratios + last verification)
Route::get('/coding_plan/offers', [CodingPlanController::class, 'publicOffers']);

// Built-in pricing source JSON (CodingPlanCatalog ratios) for vendor pricing_source_url
Route::get('/coding_plan/pricing_source/{code}', [CodingPlanController::class, 'pricingSource']);

// Public promotions for introduce page countdown badges (status=1, not expired; P2-4)
Route::get('/coding_plan/public_promotions', [CodingPlanController::class, 'publicPromotions']);

// Public Subscription Plans
Route::get('/subscription/plans', [SubscriptionController::class, 'plans']);

// Authentication (Public)
Route::post('/user/register', [AuthController::class, 'register']);
Route::post('/user/login', [AuthController::class, 'login']);
Route::post('/user/reset', [AuthController::class, 'resetPassword']);
Route::get('/reset_password', [AuthController::class, 'sendResetLink']);
Route::get('/verification', [AuthController::class, 'sendVerificationEmail']);

// OAuth Routes
Route::post('/oauth/state', [OAuthController::class, 'generateState']);
Route::get('/oauth/github', [OAuthController::class, 'redirectToGithub']);
Route::get('/oauth/github/callback', [OAuthController::class, 'handleGithubCallback']);
Route::get('/oauth/discord', [OAuthController::class, 'redirectToDiscord']);
Route::get('/oauth/discord/callback', [OAuthController::class, 'handleDiscordCallback']);
Route::get('/oauth/oidc', [OAuthController::class, 'redirectToOIDC']);
Route::get('/oauth/oidc/callback', [OAuthController::class, 'handleOIDCCallback']);
Route::get('/oauth/linuxdo', [OAuthController::class, 'redirectToLinuxDO']);
Route::get('/oauth/linuxdo/callback', [OAuthController::class, 'handleLinuxDOCallback']);
Route::get('/oauth/wechat', [OAuthController::class, 'redirectToWeChat']);
Route::get('/oauth/wechat/callback', [OAuthController::class, 'handleWeChatCallback']);
Route::post('/oauth/wechat/bind', [OAuthController::class, 'bindWeChat']);
Route::get('/oauth/telegram/login', [OAuthController::class, 'redirectToTelegram']);
Route::post('/oauth/telegram/bind/start', [OAuthController::class, 'startTelegramBind']);
Route::get('/oauth/telegram/bind/{flow_token}', [OAuthController::class, 'finishTelegramBind']);

// Payment Webhooks (no auth)
Route::post('/stripe/webhook', [TopUpController::class, 'stripeWebhook']);
Route::post('/creem/webhook', [TopUpController::class, 'creemWebhook']);
Route::post('/waffo/webhook', [TopUpController::class, 'waffoWebhook']);
Route::post('/waffo-pancake/webhook/{env}', [TopUpController::class, 'waffoWebhook']);
Route::get('/user/epay/notify', [TopUpController::class, 'epayNotify']);
Route::post('/user/epay/notify', [TopUpController::class, 'epayNotify']);
// 原生微信支付 V3 回调（无登录）
Route::post('/wechat/notify', [TopUpController::class, 'wechatNotify']);
// 原生支付宝异步回调（无登录，支持 GET/POST）
Route::get('/alipay/notify', [TopUpController::class, 'alipayNotify']);
Route::post('/alipay/notify', [TopUpController::class, 'alipayNotify']);

// Security Verification
Route::post('/verify', [AuthController::class, 'verify']);

// ============================================
// Auth endpoints that use cookie-based session validation (not Sanctum)
Route::post('/user/auth/refresh', [AuthController::class, 'refresh']);
Route::post('/user/auth/logout', [AuthController::class, 'logout']);
// 2FA login verification (not behind auth:sanctum — user is not yet authenticated)
Route::post('/user/login/2fa', [AuthController::class, 'verifyTwoFactor']);

// AUTHENTICATED ROUTES (User Session or Token)
// ============================================

Route::middleware(UserAuth::class)->group(function () {
    // User Self Management
    Route::get('/user/self', [UserController::class, 'self']);
    Route::put('/user/self', [UserController::class, 'updateSelf']);
    Route::delete('/user/self', [UserController::class, 'deleteSelf']);
    Route::get('/user/self/groups', [UserController::class, 'groups']);
    Route::put('/user/news-keys', [UserController::class, 'updateNewsKeys']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/user/models', [ModelController::class, 'userModels']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/user/groups', [UserController::class, 'userGroups']);

    // Sessions
    Route::get('/user/sessions', [AuthController::class, 'sessions']);
    Route::delete('/user/sessions/{sid}', [AuthController::class, 'deleteSession']);
    Route::post('/user/sessions/revoke-others', [AuthController::class, 'revokeOtherSessions']);

    // Access Token
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/user/token', [TokenController::class, 'createAccessToken']);

    // Passkey
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/user/passkey', [WebAuthController::class, 'list']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/user/passkey/register/begin', [WebAuthController::class, 'registerBegin']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/user/passkey/register/finish', [WebAuthController::class, 'registerFinish']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/user/passkey/verify/begin', [WebAuthController::class, 'verifyBegin']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/user/passkey/verify/finish', [WebAuthController::class, 'verifyFinish']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::delete('/user/passkey', [WebAuthController::class, 'delete']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/user/passkey/login/begin', [WebAuthController::class, 'loginBegin']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/user/passkey/login/finish', [WebAuthController::class, 'loginFinish']);

    // 2FA (management endpoints — 2fa login verify is outside this group)
    Route::get('/user/2fa/status', [AuthController::class, 'twoFactorStatus']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/user/2fa/setup', [AuthController::class, 'setupTwoFactor']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/user/2fa/enable', [AuthController::class, 'enableTwoFactor']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/user/2fa/disable', [AuthController::class, 'disableTwoFactor']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/user/2fa/backup_codes', [AuthController::class, 'generateBackupCodes']);

    // User Aff
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/user/aff', [UserController::class, 'affiliate']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/user/aff_transfer', [UserController::class, 'affiliateTransfer']);

    // TopUp / Payment
    Route::get('/user/topup/info', [TopUpController::class, 'info']);
    Route::get('/user/topup/self', [TopUpController::class, 'selfList']);
    Route::post('/user/topup', [TopUpController::class, 'epayPay']);
    Route::post('/user/pay', [TopUpController::class, 'epayPay']);
    Route::post('/user/amount', [TopUpController::class, 'calcAmount']);
    Route::post('/user/stripe/pay', [TopUpController::class, 'stripePay']);
    Route::post('/user/stripe/amount', [TopUpController::class, 'stripeAmount']);
    Route::post('/user/creem/pay', [TopUpController::class, 'creemPay']);
    Route::post('/user/waffo/pay', [TopUpController::class, 'waffoPay']);
    Route::post('/user/waffo/amount', [TopUpController::class, 'waffoAmount']);
    Route::post('/user/waffo-pancake/pay', [TopUpController::class, 'waffoPay']);
    Route::post('/user/waffo-pancake/amount', [TopUpController::class, 'waffoAmount']);
    // 原生微信支付 / 支付宝支付下单（需登录）
    Route::post('/user/wechat/pay', [TopUpController::class, 'wechatPay']);
    Route::post('/user/alipay/pay', [TopUpController::class, 'alipayPay']);

    // User Settings
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::put('/user/setting', [UserController::class, 'updateSettings']);

    // Checkin
    Route::get('/user/checkin', [CheckinController::class, 'status']);
    Route::post('/user/checkin', [CheckinController::class, 'checkin']);

    // OAuth Bindings
    Route::get('/user/oauth/bindings', [OAuthController::class, 'listBindings']);
    Route::delete('/user/oauth/bindings/{provider_id}', [OAuthController::class, 'unbind']);

    // Logs
    Route::get('/log/self', [LogController::class, 'selfLogs']);
    Route::get('/log/self/search', [LogController::class, 'searchSelfLogs']);
    Route::get('/log/self/stat', [LogController::class, 'selfStat']);

    // Data/Usage
    Route::get('/data/self', [LogController::class, 'selfData']);
    Route::get('/data/flow/self', [LogController::class, 'selfFlow']);

    // Tokens (self)
    Route::get('/token/', [TokenController::class, 'selfTokens']);
    Route::post('/token/', [TokenController::class, 'store']);
    Route::get('/token/search', [TokenController::class, 'search']);
    Route::put('/token/', [TokenController::class, 'update']); // id 在请求体（SPA 契约）
    Route::get('/token/{id}', [TokenController::class, 'show']);
    Route::put('/token/{id}', [TokenController::class, 'update']);
    Route::delete('/token/{id}', [TokenController::class, 'destroy']);
    Route::delete('/token/{id}/', [TokenController::class, 'destroy']); // SPA 尾斜杠
    Route::post('/token/{id}/key', [TokenController::class, 'revealKey']);
    Route::post('/token/batch', [TokenController::class, 'batchDelete']);
    Route::post('/token/batch/keys', [TokenController::class, 'batchGetKeys']);

    // Subscriptions
    Route::get('/subscription/self', [SubscriptionController::class, 'mySubscription']);
    Route::put('/subscription/self/preference', [SubscriptionController::class, 'updatePreference']);
    Route::post('/subscription/balance/pay', [SubscriptionController::class, 'payWithBalance']);
    Route::post('/subscription/epay/pay', [SubscriptionController::class, 'payWithEpay']);
    Route::post('/subscription/stripe/pay', [SubscriptionController::class, 'payWithStripe']);
    Route::post('/subscription/creem/pay', [SubscriptionController::class, 'payWithCreem']);
    Route::post('/subscription/waffo-pancake/pay', [SubscriptionController::class, 'payWithWaffoPancake']);

    // Midjourney
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/mj/self', [MidjourneyController::class, 'selfTasks']);

    // Tasks
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/task/self', [VideoController::class, 'selfTasks']);

    // Redemptions
    Route::post('/redemption/', [RedemptionController::class, 'redeem']);
    Route::get('/redemption/', [RedemptionController::class, 'myRedemptions']);

    // 邮箱绑定（登录态操作：$request->user() 依赖 UserAuth 写入）
    Route::post('/oauth/email/bind', [OAuthController::class, 'bindEmail']);
});

// ============================================
// ADMIN ROUTES (Admin or Above)
// ============================================

Route::middleware([UserAuth::class, AdminAuth::class])->group(function () {
    // Admin setup guide (onboarding checklist)
    Route::get('/admin/setup-checklist', [AdminController::class, 'setupChecklist']);
    Route::post('/admin/setup-checklist/dismiss', [AdminController::class, 'dismissSetupGuide']);
    Route::post('/admin/setup-checklist/reopen', [AdminController::class, 'reopenSetupGuide']);

    // Users (Admin)
    Route::get('/user/', [UserController::class, 'index']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/user/search', [UserController::class, 'search']);
    Route::get('/user/{id}', [UserController::class, 'show']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/user/', [UserController::class, 'store']);
    Route::put('/user/', [UserController::class, 'update']);
    Route::delete('/user/{id}', [UserController::class, 'destroy']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/user/manage', [UserController::class, 'manage']);
    Route::post('/user/topup/complete', [TopUpController::class, 'adminComplete']);
    Route::get('/user/topup', [TopUpController::class, 'adminList']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::delete('/user/{id}/reset_passkey', [WebAuthController::class, 'adminResetPasskey']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/user/2fa/stats', [AuthController::class, 'twoFactorStats']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::delete('/user/{id}/2fa', [AuthController::class, 'adminDisable2FA']);
    Route::get('/user/{id}/oauth/bindings', [OAuthController::class, 'adminListBindings']);
    Route::delete('/user/{id}/oauth/bindings/{provider_id}', [OAuthController::class, 'adminUnbind']);
    Route::delete('/user/{id}/bindings/{binding_type}', [OAuthController::class, 'adminClearBindings']);

    // Channels (Admin)
    Route::get('/channel/', [ChannelController::class, 'index']);
    Route::get('/channel/search', [ChannelController::class, 'search']);
    Route::get('/channel/models', [ChannelController::class, 'models']);
    Route::get('/channel/models_enabled', [ChannelController::class, 'modelsEnabled']);
    Route::get('/channel/ops', [ChannelController::class, 'ops']);
    Route::get('/channel/{id}', [ChannelController::class, 'show']);
    Route::get('/channel/test', [ChannelController::class, 'testAll']);
    Route::get('/channel/test/{id}', [ChannelController::class, 'test']);
    Route::get('/channel/update_balance', [ChannelController::class, 'updateAllBalances']);
    Route::get('/channel/update_balance/{id}', [ChannelController::class, 'updateBalance']);
    Route::post('/channel/', [ChannelController::class, 'store']);
    Route::put('/channel/', [ChannelController::class, 'update']);
    Route::post('/channel/status/batch', [ChannelController::class, 'batchStatus']);
    Route::post('/channel/{id}/status', [ChannelController::class, 'updateStatus']);
    Route::delete('/channel/disabled', [ChannelController::class, 'deleteDisabled']);
    Route::post('/channel/tag/disabled', [ChannelController::class, 'disableByTag']);
    Route::post('/channel/tag/enabled', [ChannelController::class, 'enableByTag']);
    Route::put('/channel/tag', [ChannelController::class, 'updateTag']);
    Route::delete('/channel/{id}', [ChannelController::class, 'destroy']);
    Route::post('/channel/batch', [ChannelController::class, 'batchDelete']);
    Route::post('/channel/fix', [ChannelController::class, 'fixAbilities']);
    Route::get('/channel/fetch_models/{id}', [ChannelController::class, 'fetchModels']);
    Route::post('/channel/fetch_models', [ChannelController::class, 'batchFetchModels']);
    Route::get('/channel/tag/models', [ChannelController::class, 'tagModels']);
    Route::post('/channel/copy/{id}', [ChannelController::class, 'copy']);
    Route::post('/channel/multi_key/manage', [ChannelController::class, 'multiKeyManage']);
    Route::post('/channel/{id}/key', [ChannelController::class, 'revealKey']);
    Route::post('/channel/{id}/codex/refresh', [ChannelController::class, 'codexRefresh']);
    Route::get('/channel/{id}/codex/usage', [ChannelController::class, 'codexUsage']);
    Route::post('/channel/{id}/codex/usage/reset', [ChannelController::class, 'codexResetUsage']);
    Route::post('/channel/ollama/pull', [ChannelController::class, 'ollamaPull']);
    Route::post('/channel/ollama/pull/stream', [ChannelController::class, 'ollamaPullStream']);
    Route::delete('/channel/ollama/delete', [ChannelController::class, 'ollamaDelete']);
    Route::get('/channel/ollama/version/{id}', [ChannelController::class, 'ollamaVersion']);
    Route::post('/channel/batch/tag', [ChannelController::class, 'batchTag']);
    Route::post('/channel/upstream_updates/apply', [ChannelController::class, 'applyUpstreamUpdate']);
    Route::post('/channel/upstream_updates/apply_all', [ChannelController::class, 'applyAllUpstreamUpdates']);
    Route::post('/channel/upstream_updates/detect', [ChannelController::class, 'detectUpstreamUpdate']);
    Route::post('/channel/upstream_updates/detect_all', [ChannelController::class, 'detectAllUpstreamUpdates']);

    // Abilities (Admin)
    Route::get('/ability/', [AbilityController::class, 'index']);
    Route::post('/ability/', [AbilityController::class, 'store']);
    Route::put('/ability/', [AbilityController::class, 'update']);
    Route::delete('/ability/{id}', [AbilityController::class, 'destroy']);
    Route::post('/ability/batch', [AbilityController::class, 'batchSync']);

    // Logs (Admin)
    Route::get('/log/', [LogController::class, 'index']);
    Route::get('/log/stat', [LogController::class, 'stat']);
    Route::get('/log/search', [LogController::class, 'search']);
    Route::get('/log/token', [LogController::class, 'tokenLogs']);
    Route::get('/log/channel_affinity_usage_cache', [LogController::class, 'affinityCacheStat']);

    // Data/Usage (Admin)
    Route::get('/data/', [UserDataController::class, 'index']);
    Route::get('/data/users', [UserDataController::class, 'users']);
    Route::get('/data/flow', [UserDataController::class, 'flow']);

    // Redemptions (Admin)
    Route::get('/redemption/search', [RedemptionController::class, 'search']);
    Route::get('/redemption/{id}', [RedemptionController::class, 'show']);
    Route::put('/redemption/', [RedemptionController::class, 'update']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::delete('/redemption/invalid', [RedemptionController::class, 'deleteInvalid']);
    Route::delete('/redemption/{id}', [RedemptionController::class, 'destroy']);
    Route::post('/redemption/batch', [RedemptionController::class, 'batchCreate']);

    // Subscriptions (Admin)
    Route::get('/subscription/admin/plans', [SubscriptionController::class, 'allPlans']);
    Route::post('/subscription/admin/plans', [SubscriptionController::class, 'createPlan']);
    Route::put('/subscription/admin/plans/{id}', [SubscriptionController::class, 'updatePlan']);
    Route::patch('/subscription/admin/plans/{id}', [SubscriptionController::class, 'togglePlan']);
    Route::post('/subscription/admin/bind', [SubscriptionController::class, 'bindSubscription']);
    Route::post('/subscription/admin/plans/{id}/subscriptions/reset', [SubscriptionController::class, 'resetPlanSubscriptions']);
    Route::get('/subscription/admin/users/{id}/subscriptions', [SubscriptionController::class, 'userSubscriptions']);
    Route::post('/subscription/admin/users/{id}/subscriptions', [SubscriptionController::class, 'createUserSubscription']);
    Route::post('/subscription/admin/users/{id}/subscriptions/reset', [SubscriptionController::class, 'resetUserSubscription']);
    Route::post('/subscription/admin/user_subscriptions/{id}/invalidate', [SubscriptionController::class, 'invalidateSubscription']);
    Route::delete('/subscription/admin/user_subscriptions/{id}', [SubscriptionController::class, 'deleteSubscription']);

    // Models (Admin)
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/models/', [ModelController::class, 'index']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/models/search', [ModelController::class, 'search']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/models/{id}', [ModelController::class, 'show']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/models/', [ModelController::class, 'store']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::put('/models/', [ModelController::class, 'update']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::delete('/models/{id}', [ModelController::class, 'destroy']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/models/sync_upstream/preview', [ModelController::class, 'syncPreview']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/models/sync_upstream', [ModelController::class, 'syncUpstream']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/models/missing', [ModelController::class, 'missing']);

    // Vendors (Admin)
    Route::get('/vendors/', [VendorController::class, 'index']);
    Route::get('/vendors/search', [VendorController::class, 'search']);
    Route::get('/vendors/{id}', [VendorController::class, 'show']);
    Route::post('/vendors/', [VendorController::class, 'store']);
    Route::put('/vendors/', [VendorController::class, 'update']);
    Route::delete('/vendors/{id}', [VendorController::class, 'destroy']);

    // Deployments (Admin)
    Route::get('/deployments/settings', [DeploymentController::class, 'settings']);
    Route::post('/deployments/settings/test-connection', [DeploymentController::class, 'testConnection']);
    Route::get('/deployments/', [DeploymentController::class, 'index']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/deployments/', [DeploymentController::class, 'store']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::put('/deployments/{id}', [DeploymentController::class, 'update']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::delete('/deployments/{id}', [DeploymentController::class, 'destroy']);

    // Midjourney (Admin)
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/mj/', [MidjourneyController::class, 'index']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/mj/{id}', [MidjourneyController::class, 'show']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/mj/{id}/action', [MidjourneyController::class, 'action']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/mj/{id}/shorten', [MidjourneyController::class, 'shorten']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/mj/{id}/modal', [MidjourneyController::class, 'modal']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/mj/{id}/change', [MidjourneyController::class, 'change']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/mj/{id}/simple-change', [MidjourneyController::class, 'simpleChange']);
    Route::get('/mj/task/{id}/fetch', [MidjourneyController::class, 'fetchTask']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/mj/task/{id}/image-seed', [MidjourneyController::class, 'imageSeed']);
    Route::post('/mj/task/list-by-condition', [MidjourneyController::class, 'listByCondition']);
    Route::post('/mj/insight-face/swap', [MidjourneyController::class, 'insightFaceSwap']);
    Route::post('/mj/submit/upload-discord-images', [MidjourneyController::class, 'uploadDiscordImages']);

    // Tasks (Admin)
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/task/', [VideoController::class, 'index']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/task/{id}', [VideoController::class, 'show']);

    // System Tasks (Admin)
    Route::post('/system-task/log-cleanup', [SystemTaskController::class, 'createLogCleanup']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/system-task/list', [SystemTaskController::class, 'index']);
    Route::get('/system-task/current', [SystemTaskController::class, 'current']);
    Route::get('/system-task/{task_id}', [SystemTaskController::class, 'show']);

    // System Info (Admin)
    Route::get('/system-info/instances', [SystemInfoController::class, 'instances']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::delete('/system-info/stale-instances', [SystemInfoController::class, 'deleteStaleInstances']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::delete('/system-info/instances/{node_name}', [SystemInfoController::class, 'deleteInstance']);

    // Prefill Groups (Admin)
    Route::get('/prefill_group/', [GroupController::class, 'prefillIndex']);
    Route::post('/prefill_group/', [GroupController::class, 'prefillStore']);
    Route::put('/prefill_group/', [GroupController::class, 'prefillUpdate']);
    Route::delete('/prefill_group/{id}', [GroupController::class, 'prefillDestroy']);

    // Groups (Admin)
    Route::get('/group/', [GroupController::class, 'index']);

    // Coding Plan Accounts (Admin)
    Route::get('/coding_plan/accounts', [CodingPlanController::class, 'accounts']);
    Route::post('/coding_plan/accounts', [CodingPlanController::class, 'storeAccount']);
    Route::put('/coding_plan/accounts/{id}', [CodingPlanController::class, 'updateAccount']);
    Route::delete('/coding_plan/accounts/{id}', [CodingPlanController::class, 'destroyAccount']);
    Route::post('/coding_plan/accounts/{id}/reset_usage', [CodingPlanController::class, 'resetUsage']);
    Route::get('/coding_plan/accounts/{id}/usage', [CodingPlanController::class, 'accountUsage']);
    // Coding Plan Vendors (Admin)
    Route::get('/coding_plan/vendors', [CodingPlanController::class, 'vendors']);
    Route::post('/coding_plan/vendors', [CodingPlanController::class, 'storeVendor']);
    Route::put('/coding_plan/vendors/{id}', [CodingPlanController::class, 'updateVendor']);
    Route::delete('/coding_plan/vendors/{id}', [CodingPlanController::class, 'destroyVendor']);
    // Coding Plan Model Ratios (Admin)
    Route::get('/coding_plan/ratios', [CodingPlanController::class, 'ratios']);
    Route::post('/coding_plan/ratios', [CodingPlanController::class, 'storeRatio']);
    Route::put('/coding_plan/ratios/{id}', [CodingPlanController::class, 'updateRatio']);
    Route::delete('/coding_plan/ratios/{id}', [CodingPlanController::class, 'destroyRatio']);
    // P8 上架流：厂商模型清单（聚合官方目录快照）+ 批量启停 + 目录变更一键应用
    Route::get('/coding_plan/vendors/{code}/models', [CodingPlanController::class, 'vendorModels']);
    Route::post('/coding_plan/vendors/{code}/models/batch_status', [CodingPlanController::class, 'batchUpdateModelStatus']);
    Route::post('/coding_plan/catalog_changes/apply', [CodingPlanController::class, 'applyCatalogChanges']);
    // 官方模板目录（预置档位/折算标准一键落地）与定时校对的待确认变更（官方同步页）
    Route::get('/coding_plan/catalog', [CodingPlanController::class, 'catalog']);
    Route::post('/coding_plan/catalog/{code}/apply', [CodingPlanController::class, 'applyCatalog']);
    Route::get('/coding_plan/checks', [CodingPlanController::class, 'checks']);
    Route::post('/coding_plan/checks/ignore', [CodingPlanController::class, 'ignoreCheckChange']);

    // 供应商官方套餐档位（个人版/团队版等，公开介绍页展示，管理端维护）
    Route::get('/coding_plan/tiers', [CodingPlanController::class, 'tiers']);
    Route::post('/coding_plan/tiers', [CodingPlanController::class, 'storeTier']);
    Route::put('/coding_plan/tiers/{id}', [CodingPlanController::class, 'updateTier']);
    Route::delete('/coding_plan/tiers/{id}', [CodingPlanController::class, 'destroyTier']);
    // 汇率配置（P7-2：币种→人民币；USD 无行时回落 Option UsdExchangeRate）
    Route::get('/coding_plan/rates', [CodingPlanController::class, 'rates']);
    Route::post('/coding_plan/rates', [CodingPlanController::class, 'storeRate']);
    Route::delete('/coding_plan/rates/{code}', [CodingPlanController::class, 'destroyRate']);

    // 促销/价格变动/模型退市活动管理（P2-1）
    Route::get('/coding_plan/promotions', [CodingPlanController::class, 'promotions']);
    Route::post('/coding_plan/promotions', [CodingPlanController::class, 'storePromotion']);
    Route::delete('/coding_plan/promotions/{id}', [CodingPlanController::class, 'destroyPromotion']);
    // Coding Plan Plans / Stats (Admin)
    Route::post('/coding_plan/plans/{id}/attach', [CodingPlanController::class, 'attachPlan']);
    Route::post('/coding_plan/plans/{id}/detach', [CodingPlanController::class, 'detachPlan']);
    Route::get('/coding_plan/plans', [CodingPlanController::class, 'plans']);
    Route::get('/coding_plan/stats', [CodingPlanController::class, 'stats']);

});

// ============================================
// ROOT ROUTES (Root Only)
// ============================================

Route::middleware([UserAuth::class, RootAuth::class])->group(function () {
    // Options (Root)
    Route::get('/option/', [OptionController::class, 'index']);
    Route::put('/option/', [OptionController::class, 'update']);
    Route::post('/option/payment_compliance', [OptionController::class, 'paymentCompliance']);
    Route::get('/option/channel_affinity_cache', [OptionController::class, 'affinityCacheStat']);
    Route::delete('/option/channel_affinity_cache', [OptionController::class, 'clearAffinityCache']);
    Route::post('/option/rest_model_ratio', [OptionController::class, 'resetModelRatio']);
    Route::get('/option/waffo-pancake/catalog', [OptionController::class, 'waffoPancakeCatalog']);
    Route::post('/option/waffo-pancake/pair', [OptionController::class, 'waffoPancakePair']);
    Route::post('/option/waffo-pancake/save', [OptionController::class, 'waffoPancakeSave']);
    Route::post('/option/waffo-pancake/subscription-product', [OptionController::class, 'waffoPancakeSubscriptionProduct']);
    Route::get('/option/waffo-pancake/subscription-product-options', [OptionController::class, 'waffoPancakeSubscriptionOptions']);

    // Custom OAuth (Root)
    Route::post('/custom-oauth-provider/discovery', [OAuthController::class, 'discovery']);
    Route::get('/custom-oauth-provider/', [OAuthController::class, 'listCustomProviders']);
    Route::get('/custom-oauth-provider/{id}', [OAuthController::class, 'showCustomProvider']);
    Route::post('/custom-oauth-provider/', [OAuthController::class, 'createCustomProvider']);
    Route::put('/custom-oauth-provider/{id}', [OAuthController::class, 'updateCustomProvider']);
    Route::delete('/custom-oauth-provider/{id}', [OAuthController::class, 'deleteCustomProvider']);

    // Performance (Root)
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/performance/stats', [AdminController::class, 'performanceStats']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::delete('/performance/disk_cache', [AdminController::class, 'clearDiskCache']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/performance/reset_stats', [AdminController::class, 'resetStats']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('/performance/gc', [AdminController::class, 'forceGC']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/performance/logs', [AdminController::class, 'logFiles']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::delete('/performance/logs', [AdminController::class, 'clearLogs']);

    // Ratio Sync (Root)
    Route::get('/ratio_sync/channels', [ChannelController::class, 'ratioSyncChannels']);
    Route::post('/ratio_sync/fetch', [ChannelController::class, 'fetchRatios']);

    // Rankings
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/rankings', [AdminController::class, 'rankings']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/perf-metrics/summary', [AdminController::class, 'perfMetricsSummary']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/perf-metrics', [AdminController::class, 'perfMetrics']);

    // Authz Catalog
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('/authz/catalog', [AdminController::class, 'authzCatalog']);
});

// ============================================
// API RELAY ROUTES (Token Authentication)
// 与 routes/relay.php 对齐：models 无 Distributor；转发类端点经 Distributor 选渠道
// ============================================

$apiRelayMiddleware = [Stats::class, DecompressRequest::class, TokenAuth::class, ApiRateLimit::class];
$apiRelayWithDistributor = [...$apiRelayMiddleware, Distributor::class, ModelRateLimit::class, SystemPerformanceCheck::class];

Route::middleware($apiRelayMiddleware)->prefix('v1')->group(function () {
    // Models
    Route::get('models', [RelayController::class, 'models']);
    Route::get('models/{model}', [RelayController::class, 'model']);
});

Route::middleware($apiRelayWithDistributor)->prefix('v1')->group(function () {
    // Chat Completions
    Route::post('chat/completions', [RelayController::class, 'chat']);
    Route::post('completions', [RelayController::class, 'completions']);
    Route::post('responses', [RelayController::class, 'responses']);
    Route::post('responses/compact', [RelayController::class, 'responsesCompact']);

    // Embeddings
    Route::post('embeddings', [RelayController::class, 'embeddings']);

    // Images
    Route::post('images/generations', [RelayController::class, 'imageGenerations']);
    Route::post('images/edits', [RelayController::class, 'imageEdits']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::post('images/variations', [RelayController::class, 'imageVariations']);

    // Audio
    Route::post('audio/transcriptions', [RelayController::class, 'audioTranscriptions']);
    Route::post('audio/translations', [RelayController::class, 'audioTranslations']);
    Route::post('audio/speech', [RelayController::class, 'audioSpeech']);

    // Other
    Route::post('edits', [RelayController::class, 'edits']);
    Route::post('rerank', [RelayController::class, 'rerank']);
    Route::post('moderations', [RelayController::class, 'moderations']);
});

// Claude API
Route::middleware([TokenAuth::class])->prefix('v1')->group(function () {
    Route::post('messages', [RelayController::class, 'claudeMessages']);
});

// Gemini API
Route::middleware([TokenAuth::class])->prefix('v1beta')->group(function () {
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('models', [RelayController::class, 'geminiModels']);
    // [R15] 未实现蓝图路由（controller 方法缺失，调用即 500）: Route::get('openai/models', [RelayController::class, 'geminiOpenAIModels']);
    Route::post('models/{path}', [RelayController::class, 'geminiRelay'])->where('path', '.*');
});

Route::middleware([TokenAuth::class])->group(function () {
    Route::post('v1/models/{path}', [RelayController::class, 'geminiRelay'])->where('path', '.*');
    Route::post('v1/engines/{model}/embeddings', [RelayController::class, 'geminiEmbeddings']);
});

// Midjourney Routes
Route::middleware([TokenAuth::class])->prefix('mj')->group(function () {
    Route::get('image/{id}', [MidjourneyController::class, 'getImage']);
    Route::post('submit/action', [MidjourneyController::class, 'submitAction']);
    Route::post('submit/shorten', [MidjourneyController::class, 'submitShorten']);
    Route::post('submit/modal', [MidjourneyController::class, 'submitModal']);
    Route::post('submit/imagine', [MidjourneyController::class, 'submitImagine']);
    Route::post('submit/change', [MidjourneyController::class, 'submitChange']);
    Route::post('submit/simple-change', [MidjourneyController::class, 'submitSimpleChange']);
    Route::post('submit/describe', [MidjourneyController::class, 'submitDescribe']);
    Route::post('submit/blend', [MidjourneyController::class, 'submitBlend']);
    Route::post('submit/edits', [MidjourneyController::class, 'submitEdits']);
    Route::post('submit/video', [MidjourneyController::class, 'submitVideo']);
});

// Suno Routes
Route::middleware([TokenAuth::class])->prefix('suno')->group(function () {
    Route::post('submit/{action}', [SunoController::class, 'submit']);
    Route::post('fetch', [SunoController::class, 'fetch']);
    Route::get('fetch/{id}', [SunoController::class, 'fetchOne']);
});

// Video Routes
Route::middleware([TokenAuth::class])->group(function () {
    Route::post('v1/video/generations', [VideoController::class, 'generate']);
    Route::get('v1/video/generations/{task_id}', [VideoController::class, 'getVideo']);
    Route::post('v1/videos/{video_id}/remix', [VideoController::class, 'remix']);
    Route::post('v1/videos', [VideoController::class, 'create']);
    Route::get('v1/videos/{task_id}', [VideoController::class, 'getVideo']);
    Route::get('v1/videos/{task_id}/content', [VideoController::class, 'getVideoContent']);
    Route::post('kling/v1/videos/text2video', [VideoController::class, 'klingText2Video']);
    Route::post('kling/v1/videos/image2video', [VideoController::class, 'klingImage2Video']);
    Route::get('kling/v1/videos/text2video/{task_id}', [VideoController::class, 'getKlingVideo']);
    Route::get('kling/v1/videos/image2video/{task_id}', [VideoController::class, 'getKlingVideo']);
    Route::post('jimeng/', [VideoController::class, 'jimeng']);
});

// Playground
Route::middleware([TokenAuth::class])->prefix('pg')->group(function () {
    Route::post('chat/completions', [RelayController::class, 'playgroundChat']);
});

// Dashboard
Route::middleware(UserAuth::class)->group(function () {
    Route::get('dashboard/billing/subscription', [SubscriptionController::class, 'dashboard']);
    Route::get('v1/dashboard/billing/subscription', [SubscriptionController::class, 'dashboard']);
    Route::get('dashboard/billing/usage', [LogController::class, 'dashboardUsage']);
    Route::get('v1/dashboard/billing/usage', [LogController::class, 'dashboardUsage']);
});

// News / Search Aggregation (requires token auth)
Route::middleware([TokenAuth::class])->prefix('news')->group(function () {
    Route::post('search', [NewsController::class, 'search']);
    Route::get('providers', [NewsController::class, 'providers']);
});

// Search (web / general search, requires token auth)
Route::middleware([TokenAuth::class])->prefix('search')->group(function () {
    Route::post('search', [SearchController::class, 'search']);
    Route::get('providers', [SearchController::class, 'providers']);
});

// Catch-all for any remaining relay routes
Route::middleware([TokenAuth::class])->any('{path}', [RelayController::class, 'catchAll'])->where('path', '.*');
