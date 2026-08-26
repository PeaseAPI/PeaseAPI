<?php

use App\Http\Controllers\AbilityController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\Api\ChannelApiController;
use App\Http\Controllers\Api\TokenApiController;
use App\Http\Controllers\Api\UserApiController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\OptionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\RedemptionController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SystemInfoController;
use App\Http\Controllers\WebAuthController;
use App\Http\Middleware\AdminAuth;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Install route - 如果 install.lock 存在则禁止访问安装页面
Route::get('/install', function () {
    if (InstallController::isInstalled()) {
        return response()->view('errors.install-locked', [], 403);
    }

    return app(InstallController::class)->index();
})->name('install.index');
Route::post('/install', [InstallController::class, 'process'])->name('install.process');
Route::post('/install/migrate', [InstallController::class, 'runMigration'])->name('install.migrate');
Route::get('/install/step3', [InstallController::class, 'step3'])->name('install.step3');

// Public content pages
Route::view('/about', 'about')->name('about');
Route::view('/pricing', 'pricing')->name('pricing');
Route::view('/rankings', 'rankings')->name('rankings');
Route::view('/privacy-policy', 'privacy-policy')->name('privacy-policy');
Route::view('/user-agreement', 'user-agreement')->name('user-agreement');

// Documentation pages
Route::get('/docs', [DocsController::class, 'index'])->name('docs.index');
Route::get('/docs/{slug}', [DocsController::class, 'show'])->name('docs.show');

// Public home page - 检查 public/install.lock 文件
Route::get('/', function () {
    if (! InstallController::isInstalled()) {
        return redirect()->route('install.index');
    }
    // 已登录用户直接进入控制台
    if (Auth::check()) {
        return redirect()->route('dashboard');
    }

    // 未登录用户显示前台首页模板
    return view('welcome');
})->name('home');

// Auth routes
Route::get('/login', [WebAuthController::class, 'showLogin'])->name('login');
Route::post('/login', [WebAuthController::class, 'login']);
Route::get('/register', [WebAuthController::class, 'showRegister'])->name('register');
Route::post('/register', [WebAuthController::class, 'register']);

// 找回密码
Route::get('/reset', [WebAuthController::class, 'showReset'])->name('password.request');
Route::post('/reset', [WebAuthController::class, 'reset'])->name('password.reset');

// 发送短信验证码（注册/登录/重置密码共用）
Route::post('/sms/send', [WebAuthController::class, 'sendSmsCode'])->name('sms.send');

// Support both GET and POST for logout
Route::get('/logout', [WebAuthController::class, 'logout'])->name('logout');
Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout.post');

// Protected routes (auth required)
Route::middleware('auth')->group(function () {
    // User Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/tokens', [DashboardController::class, 'tokens'])->name('tokens');
    Route::get('/tokens/create', [DashboardController::class, 'tokenCreate'])->name('tokens.create');
    Route::get('/tokens/{id}/edit', [DashboardController::class, 'tokenEdit'])->name('tokens.edit');
    Route::get('/logs', [DashboardController::class, 'logs'])->name('user.logs');
    Route::get('/redeem', [DashboardController::class, 'redeem'])->name('redeem');
    Route::get('/profile', [DashboardController::class, 'profile'])->name('profile');
    Route::get('/news-keys', [DashboardController::class, 'newsKeys'])->name('news-keys');
    Route::get('/settings', [DashboardController::class, 'settings'])->name('user.settings');

    // Web API routes (return JSON for frontend JS)
    Route::prefix('web-api')->group(function () {
        // Current user
        Route::get('/me', [UserApiController::class, 'me']);
        Route::put('/profile', [UserApiController::class, 'updateProfile']);
        Route::post('/avatar', [UserApiController::class, 'updateAvatar']);
        Route::put('/phone', [UserApiController::class, 'updatePhone']);
        Route::put('/password', [UserApiController::class, 'updatePassword']);

        // Tokens
        Route::apiResource('tokens', TokenApiController::class);
        Route::post('/tokens/{id}/regenerate', [TokenApiController::class, 'regenerate']);

        // Logs
        Route::get('/logs', [LogController::class, 'index']);

        // Redemption
        Route::post('/redeem', [RedemptionController::class, 'redeem']);

        // Payment
        Route::get('/pricings', [PaymentController::class, 'pricings']);
        Route::post('/payment/checkout', [PaymentController::class, 'createCheckout']);
        Route::get('/payment/history', [PaymentController::class, 'topUpHistory']);

        // Subscription
        Route::get('/subscription/plans', [SubscriptionController::class, 'plans']);
        Route::post('/subscription/subscribe', [SubscriptionController::class, 'subscribe']);
        Route::get('/subscription/my', [SubscriptionController::class, 'mySubscriptions']);
        Route::post('/subscription/{id}/cancel', [SubscriptionController::class, 'cancel']);

        // News API keys (user's own search provider keys)
        Route::put('/news-keys', [UserApiController::class, 'updateNewsKeys']);

        // Admin routes
        Route::middleware(AdminAuth::class)->group(function () {
            // Users management
            Route::apiResource('users', UserApiController::class);
            Route::post('/users/{id}/balance', [UserApiController::class, 'updateBalance']);
            Route::post('/users/{id}/reset-password', [UserApiController::class, 'resetPassword']);

            // Channels management
            Route::apiResource('channels', ChannelApiController::class);
            Route::get('/channels/{id}/health', [ChannelApiController::class, 'health']);
            Route::get('/channels/health/all', [ChannelApiController::class, 'healthAll']);

            // Abilities
            Route::apiResource('abilities', AbilityController::class);

            // Redemptions management
            Route::apiResource('redemptions', RedemptionController::class)->only(['index', 'store']);

            // Options
            Route::get('/options', [OptionController::class, 'index']);
            Route::post('/options', [OptionController::class, 'update'])->name('admin.options.update');
            Route::post('/options/reset-model-ratio', [OptionController::class, 'resetModelRatio'])->name('admin.options.resetModelRatio');
            Route::post('/options/payment-compliance', [OptionController::class, 'paymentCompliance'])->name('admin.options.paymentCompliance');
        });
    });

    // Admin page routes
    Route::prefix('admin')->middleware(AdminAuth::class)->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('admin.dashboard');
        Route::get('/users', [AdminController::class, 'users'])->name('admin.users');
        Route::get('/channels', [AdminController::class, 'channels'])->name('admin.channels');
        Route::get('/tokens', [AdminController::class, 'tokens'])->name('admin.tokens');
        Route::get('/abilities', [AdminController::class, 'abilities'])->name('admin.abilities');
        Route::get('/logs', [AdminController::class, 'logs'])->name('admin.logs');
        Route::get('/redemptions', [AdminController::class, 'redemptions'])->name('admin.redemptions');
        Route::get('/options', function () {
            return redirect()->route('admin.system-settings');
        })->name('admin.options');
        Route::get('/system-settings', [AdminController::class, 'systemSettings'])->name('admin.system-settings');
        Route::get('/system-info', [SystemInfoController::class, 'index'])->name('admin.system-info');
        Route::post('/system-instances/cleanup', [SystemInfoController::class, 'cleanup'])->name('admin.system-instances.cleanup');
        Route::delete('/system-instances/{node_name}', [SystemInfoController::class, 'destroy'])->name('admin.system-instances.delete');
        Route::get('/performance', [PerformanceController::class, 'index'])->name('admin.performance');
        Route::post('/performance/reset', [PerformanceController::class, 'resetStats'])->name('admin.performance.reset');
        Route::post('/performance/gc', [PerformanceController::class, 'forceGc'])->name('admin.performance.gc');
        Route::post('/performance/clear_cache', [PerformanceController::class, 'clearCache'])->name('admin.performance.clear_cache');
    });
});

// Fallback: API requests return JSON 404, web requests redirect to home
Route::fallback(function () {
    // Check if it's an API request that wasn't matched
    if (request()->is('api/*') || request()->is('v1/*') || request()->is('relay/*') || request()->is('mj/*') || request()->is('suno/*') || request()->is('kling/*') || request()->is('jimeng/*')) {
        return response()->json(['success' => false, 'message' => 'API endpoint not found'], 404);
    }

    // For web routes, redirect to home (Laravel Blade frontend)
    return redirect()->route('home');
});
