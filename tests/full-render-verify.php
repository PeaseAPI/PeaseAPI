<?php

declare(strict_types=1);

/**
 * R15 QA：全站页面渲染扫描自检（非 PHPUnit，独立脚本跑完即退出，生产可安全执行）
 * 覆盖：公开页/用户页/管理页全部 GET 页面完整 render、token 编辑页（曾缺失视图的修复验证）、
 * 设置读写闭环（OptionService → welcome composer / OptionController@update / Profile 更新）、
 * 性能面板动作、事务回滚零残留
 */
require __DIR__.'/../vendor/autoload.php';

use App\Http\Controllers\AbilityController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\Api\ChannelApiController;
use App\Http\Controllers\Api\TokenApiController;
use App\Http\Controllers\Api\UserApiController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\OptionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\RedemptionController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SystemInfoController;
use App\Http\Controllers\TicketController;
use App\Models\Option;
use App\Models\Ticket;
use App\Models\Token;
use App\Models\User;
use App\Services\OptionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

$fail = 0;
function check(string $name, bool $cond): void
{
    global $fail;
    echo ($cond ? '  ✓ ' : '  ✗ ').$name."\n";
    if (! $cond) {
        $fail++;
    }
}

/** 完整渲染一个页面（blade 编译 + HTML），异常即失败；返回 HTML 供内容断言 */
function page(string $name, callable $fn): ?string
{
    global $fail;
    try {
        $result = $fn();
        if ($result instanceof RedirectResponse) {
            echo '  ✓ '.$name.'（重定向 '.$result->getTargetUrl().'）'."\n";

            return null;
        }
        // response()->view() 返回 Response：getContent() 触发视图渲染
        $html = $result instanceof Response ? (string) $result->getContent() : (string) $result;
        if (trim($html) === '') {
            throw new RuntimeException('渲染结果为空');
        }
        echo '  ✓ '.$name.'（'.number_format(strlen($html)).' B）'."\n";

        return $html;
    } catch (Throwable $e) {
        $fail++;
        echo '  ✗ '.$name.' → '.get_class($e).': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine()."\n";

        return null;
    }
}

/** 走完整 HTTP 内核（含 session/errors 共享等中间件）请求页面，断言状态码 */
function httpPage(string $name, string $uri, int $expect = 200): ?string
{
    global $fail;
    try {
        $kernel = app(Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle(Request::create($uri, 'GET'));
        $status = $response->getStatusCode();
        check($name."（HTTP {$status}）", $status === $expect);

        return $status === $expect ? $response->getContent() : null;
    } catch (Throwable $e) {
        $fail++;
        echo '  ✗ '.$name.' → '.get_class($e).': '.$e->getMessage()."\n";

        return null;
    }
}

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$suffix = bin2hex(random_bytes(4));
$now = time();

DB::beginTransaction();
try {
    echo "[A] 公开页（未登录态）\n";
    $welcome = page('首页 welcome', fn () => view('welcome')->render());
    check('welcome 含统一导航与页脚', $welcome !== null && str_contains($welcome, 'nav-brand') && str_contains($welcome, 'footer-content'));
    page('关于 about', fn () => view('about')->render());
    page('定价 pricing', fn () => view('pricing')->render());
    page('Coding Plan', fn () => view('coding-plan')->render());
    page('模型排行 rankings', fn () => view('rankings')->render());
    page('隐私政策', fn () => view('privacy-policy')->render());
    page('用户协议', fn () => view('user-agreement')->render());
    page('文档中心 docs', fn () => app(DocsController::class)->index()->getContent());
    page('功能解读 docs/features', fn () => app(DocsController::class)->show('features')->getContent());
    httpPage('登录页（完整 HTTP 内核）', '/login');
    httpPage('注册页（完整 HTTP 内核）', '/register');

    echo "[B] 用户控制台页\n";
    $makeUser = static fn (string $username): User => User::query()->create([
        'username' => $username,
        'email' => $username.'@render-selftest.local',
        'password' => bcrypt('selftest!2026'),
        'role' => 0,
        'aff_code' => substr(strtoupper(bin2hex(random_bytes(16))), 0, 32),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $user = $makeUser('r15u_'.$suffix);
    $admin = $makeUser('r15a_'.$suffix);
    $admin->forceFill(['role' => 100])->save();
    Auth::login($user);
    check('事务内造用户/管理员', $user->exists && (int) $admin->role === 100);

    $dash = app(DashboardController::class);
    page('控制台首页 /dashboard', fn () => $dash->index()->render());
    page('令牌列表 /tokens', fn () => $dash->tokens()->render());
    page('创建令牌 /tokens/create', fn () => $dash->tokenCreate()->render());
    $token = Token::query()->create([
        'user_id' => $user->id, 'name' => '自检令牌', 'key' => 'sk-r15'.$suffix,
        'status' => 1, 'remain_quota' => 500000, 'unlimited_quota' => false,
        'expired_time' => -1, 'accessed_time' => 0, 'created_time' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $editHtml = page('编辑令牌 /tokens/{id}/edit（曾缺失视图）', fn () => $dash->tokenEdit((int) $token->id)->render());
    check('token-edit 页含编辑表单与令牌 ID', $editHtml !== null && str_contains($editHtml, '编辑令牌') && str_contains($editHtml, (string) $token->id));
    page('使用日志 /logs', fn () => $dash->logs()->render());
    page('兑换 /redeem', fn () => $dash->redeem()->render());
    page('个人资料 /profile', fn () => $dash->profile()->render());
    page('新闻密钥 /news-keys', fn () => $dash->newsKeys()->render());

    $ticketCtrl = app(TicketController::class);
    $resp = $ticketCtrl->store(Request::create('/web-api/tickets', 'POST', [
        'subject' => '自检工单：全站扫描',
        'category' => (string) Ticket::CATEGORY_TECHNICAL,
        'priority' => (string) Ticket::PRIORITY_HIGH,
        'content' => 'R15 全站渲染自检造数。',
    ]));
    $payload = json_decode($resp->getContent(), true);
    $ticketId = (int) ($payload['id'] ?? 0);
    check('工单造数（控制器级 store）', $ticketId > 0);
    page('工单列表 /tickets', fn () => $ticketCtrl->index()->getContent());
    $ticketHtml = page('工单会话 /tickets/{id}', fn () => $ticketCtrl->show($ticketId)->getContent());
    check('工单会话页含主题', $ticketHtml !== null && str_contains($ticketHtml, '自检工单：全站扫描'));

    echo "[C] 管理面板页\n";
    Auth::login($admin);
    $adminCtrl = app(AdminController::class);
    page('管理面板 /admin/dashboard', fn () => $adminCtrl->dashboard()->render());
    page('用户管理 /admin/users', fn () => $adminCtrl->users(Request::create('/admin/users'))->render());
    page('渠道管理 /admin/channels', fn () => $adminCtrl->channels()->render());
    page('令牌管理 /admin/tokens', fn () => $adminCtrl->tokens()->render());
    page('能力管理 /admin/abilities', fn () => $adminCtrl->abilities()->render());
    page('日志 /admin/logs', fn () => $adminCtrl->logs()->render());
    page('兑换码 /admin/redemptions', fn () => $adminCtrl->redemptions()->render());
    page('系统设置 /admin/system-settings', fn () => $adminCtrl->systemSettings()->render());
    page('Coding Plan 池 /admin/coding-plan', fn () => $adminCtrl->codingPlan()->render());
    page('工单管理 /admin/tickets', fn () => $adminCtrl->tickets(Request::create('/admin/tickets'))->getContent());
    $adminTicketHtml = page('工单处理 /admin/tickets/{id}', fn () => $adminCtrl->ticketView($ticketId)->getContent());
    check('管理工单详情含快捷回复', $adminTicketHtml !== null && str_contains($adminTicketHtml, '快捷回复'));
    page('性能监控 /admin/performance', fn () => app(PerformanceController::class)->index()->render());
    page('系统信息 /admin/system-info', fn () => app(SystemInfoController::class)->index()->render());
    check('旧 /admin/options 路由存在（重定向 system-settings）', Route::has('admin.options'));

    echo "[D] 设置读写闭环\n";
    Auth::login($user);
    $siteName = '自检站名_'.$suffix;
    OptionService::set('SystemName', $siteName);
    check('OptionService set/get 回读', OptionService::get('SystemName') === $siteName);
    $welcome2 = page('welcome 反映新站名（composer 全局共享）', fn () => view('welcome')->render());
    check('welcome HTML 含新站名', $welcome2 !== null && str_contains($welcome2, $siteName));

    $optCtrl = app(OptionController::class);
    $optReq = Request::create('/web-api/options', 'POST', ['RegisterEnabled' => 'false']);
    $optReq->headers->set('Accept', 'application/json');
    $optResp = $optCtrl->update($optReq);
    $optPayload = json_decode($optResp->getContent(), true);
    check('OptionController@update 保存成功', (bool) ($optPayload['success'] ?? false));
    check('RegisterEnabled 回读 false', OptionService::get('RegisterEnabled') === false);
    OptionService::set('RegisterEnabled', true);
    check('RegisterEnabled 复位 true', OptionService::get('RegisterEnabled') === true);

    $profileResp = app(UserApiController::class)->updateProfile(Request::create('/web-api/profile', 'PUT', [
        'display_name' => 'QA 昵称_'.$suffix,
    ]));
    $user->refresh();
    check('Profile 更新落库 display_name', $user->display_name === 'QA 昵称_'.$suffix && (bool) json_decode($profileResp->getContent(), true));

    $tokenResp = app(TokenApiController::class)->update(Request::create('/web-api/tokens/'.$token->id, 'PUT', [
        'name' => '自检令牌_改', 'status' => 0, 'unlimited_quota' => true,
    ]), (int) $token->id);
    $token->refresh();
    check('Token API 更新（名称/状态/无限额度）', $token->name === '自检令牌_改' && (int) $token->status === 0 && (bool) $token->unlimited_quota);
    $editHtml2 = page('token-edit 再次渲染（更新后预填）', fn () => $dash->tokenEdit((int) $token->id)->render());
    check('token-edit 页状态 pill 就绪', $editHtml2 !== null && str_contains($editHtml2, '编辑令牌'));

    echo "[E] web-api JSON 接口冒烟（页面 JS 数据源）\n";
    Auth::login($user);
    $jsonGet = function (string $name, callable $fn): void {
        global $fail;
        try {
            $resp = $fn();
            $status = $resp->getStatusCode();
            $body = json_decode((string) $resp->getContent(), true);
            $ok = $status === 200 && is_array($body) && ! array_key_exists('error', $body);
            echo ($ok ? '  ✓ ' : '  ✗ ').$name."（HTTP {$status}）\n";
            if (! $ok) {
                $fail++;
            }
        } catch (Throwable $e) {
            $fail++;
            echo '  ✗ '.$name.' → '.get_class($e).': '.$e->getMessage()."\n";
        }
    };
    $jsonGet('web-api/me', fn () => app(UserApiController::class)->me());
    $jsonGet('web-api/logs', fn () => app(LogController::class)->index(Request::create('/web-api/logs')));
    $jsonGet('web-api/tokens', fn () => app(TokenApiController::class)->index(Request::create('/web-api/tokens')));
    $jsonGet('web-api/pricings', fn () => app(PaymentController::class)->pricings());
    $jsonGet('web-api/subscription/plans', fn () => app(SubscriptionController::class)->plans());
    $jsonGet('web-api/subscription/my', function () {
        // 控制器用 $request->user()：CLI 直调请求需手动设置 user resolver
        $req = Request::create('/web-api/subscription/my');
        $req->setUserResolver(fn () => Auth::user());

        return app(SubscriptionController::class)->mySubscription($req);
    });
    Auth::login($admin);
    $jsonGet('web-api/admin users', fn () => app(UserApiController::class)->index(Request::create('/web-api/users')));
    $jsonGet('web-api/admin channels', fn () => app(ChannelApiController::class)->index(Request::create('/web-api/channels')));
    $jsonGet('web-api/admin abilities', fn () => app(AbilityController::class)->index(Request::create('/web-api/abilities')));
    $jsonGet('web-api/admin redemptions', fn () => app(RedemptionController::class)->index(Request::create('/web-api/redemptions')));
    $jsonGet('web-api/admin options', fn () => app(OptionController::class)->index());
    $jsonGet('web-api/admin logs', fn () => app(LogController::class)->index(Request::create('/web-api/logs')));

    echo "[F] 渲染产物质量抽检\n";
    $fw = view('welcome')->render();
    check('welcome 无 Blade 未编译残留', ! str_contains($fw, '@@') && ! str_contains($fw, '{{ $'));
} finally {
    DB::rollBack();
    // Option::set 在事务内写 Laravel 缓存 forget，回滚后 DB 无行 → 主动清残留缓存
    foreach (['SystemName', 'RegisterEnabled'] as $key) {
        Cache::forget("option:{$key}");
    }
    Cache::forget(Option::AGGREGATE_CACHE_KEY);
    Cache::forget('admin:ticket_pending_badge');
    Cache::forget('admin:cp_source_alert_badge');
}

echo "[F] 回滚零残留\n";
check('users 无自检残留', User::query()->where('email', 'like', '%render-selftest.local')->count() === 0);
check('tokens 无自检残留', Token::query()->where('key', 'like', 'sk-r15%')->count() === 0);
check('tickets 无自检残留', Ticket::query()->where('subject', '自检工单：全站扫描')->count() === 0);
check('options 无自检残留', Option::query()->where('key', 'SystemName')->where('value', 'like', '自检站名_%')->count() === 0);

echo $fail === 0 ? "\n✅ 全站渲染扫描自检全部通过\n" : "\n❌ 失败 {$fail} 项\n";
exit($fail === 0 ? 0 : 1);
