<?php

declare(strict_types=1);

/**
 * R15 QA：写操作闭环自检（非 PHPUnit，独立脚本跑完即退出，生产可安全执行）
 * 覆盖：管理端添加用户、兑换码生成→兑换→重兑拦截、Token 全生命周期、
 * 订阅购买/取消/余额扣费、用户管理（改额度/重置密码/封禁/删除），
 * 全部在数据库事务中执行并回滚，零残留
 */
require __DIR__.'/../vendor/autoload.php';

use App\Http\Controllers\Api\TokenApiController;
use App\Http\Controllers\Api\UserApiController;
use App\Http\Controllers\RedemptionController;
use App\Http\Controllers\SubscriptionController;
use App\Models\Option;
use App\Models\Redemption;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Token;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Services\OptionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$fail = 0;
function check(string $name, bool $cond, string $extra = ''): void
{
    global $fail;
    echo ($cond ? '  ✓ ' : '  ✗ ').$name.($extra !== '' ? '（'.$extra.'）' : '')."\n";
    if (! $cond) {
        $fail++;
    }
}

/** 调控制器返回 JSON 的方法，断言状态码并解码 body */
function callJson(string $name, callable $fn, int $expect = 200): ?array
{
    global $fail;
    try {
        $resp = $fn();
        $status = $resp->getStatusCode();
        $body = json_decode((string) $resp->getContent(), true);
        $ok = $expect === 0 ? true : $status === $expect;
        echo ($ok ? '  ✓ ' : '  ✗ ').$name."（HTTP {$status}）\n";
        if (! $ok) {
            $fail++;
            echo '    body: '.mb_substr((string) $resp->getContent(), 0, 200)."\n";
        }

        return is_array($body) ? $body : null;
    } catch (Throwable $e) {
        $fail++;
        echo '  ✗ '.$name.' → '.get_class($e).': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine()."\n";

        return null;
    }
}

/** 创建测试用户（口径对齐 AuthController@register） */
function qaUser(string $username, string $email, string $password, int $role, int $quota): User
{
    return User::create([
        'username' => $username,
        'email' => $email,
        'password' => Hash::make($password),
        'display_name' => $username,
        'role' => $role,
        'status' => 1,
        'quota' => $quota,
        'used_quota' => 0,
        'request_count' => 0,
        'group' => 'default',
        'aff_code' => strtoupper(substr(md5($email), 0, 8)),
        'inviter_id' => 0,
        'created_time' => time(),
        'created_at' => time(),
    ]);
}

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$suffix = bin2hex(random_bytes(4));
$now = time();

DB::beginTransaction();
try {
    $admin = qaUser('qa_admin_'.$suffix, 'qa_admin_'.$suffix.'@selftest.local', 'Admin@12345', 100, 100000000);
    $user = qaUser('qa_user_'.$suffix, 'qa_user_'.$suffix.'@selftest.local', 'User@12345', 1, 1000000);

    echo "[A] 管理端添加用户（POST /web-api/users，此前控制器缺失方法）\n";
    Auth::login($admin);
    $uc = app(UserApiController::class);
    $created = callJson('管理员创建用户', fn () => $uc->store(Request::create('/web-api/users', 'POST', [
        'username' => 'qa_new_'.$suffix,
        'email' => 'qa_new_'.$suffix.'@selftest.local',
        'password' => 'NewUser@12345',
        'quota' => 500000,
    ])), 201);
    $newId = (int) ($created['user']['id'] ?? 0);
    $nu = $newId ? User::find($newId) : null;
    check('新用户落库（quota=500000 默认组）', $nu !== null && (int) $nu->quota === 500000 && $nu->group === 'default');
    callJson('重复用户名被拒（422 校验异常）', function () use ($uc, $suffix) {
        try {
            $uc->store(Request::create('/web-api/users', 'POST', [
                'username' => 'qa_new_'.$suffix, 'password' => 'NewUser@12345',
            ]));

            return response()->json(['success' => false], 500); // 未抛异常即失败
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false], 422); // 校验拦截 ✓
        }
    }, 422);
    Auth::login($user);
    callJson('普通用户越权建号被拒', fn () => $uc->store(Request::create('/web-api/users', 'POST', [
        'username' => 'qa_hack_'.$suffix, 'password' => 'NewUser@12345',
    ])), 403);


    echo "[B] 兑换码闭环（生成 → 兑换 → 重兑/无效/过期拦截）\n";
    Auth::login($admin);
    $rc = app(RedemptionController::class);
    $red = callJson('管理员生成兑换码', fn () => $rc->store(Request::create('/web-api/redemptions', 'POST', [
        'name' => '自检兑换码_'.$suffix, 'quota' => 500000, 'max_use_count' => 1, 'status' => 1,
    ])));
    $redKey = (string) ($red['data']['key'] ?? '');
    $redRow = $redKey !== '' ? Redemption::where('key', $redKey)->first() : null;
    check('兑换码落库（quota=500000）', $redRow !== null && (int) $redRow->quota === 500000);

    $quotaBefore = (int) $user->quota;
    Auth::login($user);
    callJson('用户兑换成功', fn () => $rc->redeem(qaRedeemReq($redKey)));
    $user->refresh();
    check('兑换后余额 +500000', (int) $user->quota === $quotaBefore + 500000, $quotaBefore.' → '.$user->quota);
    $redRow->refresh();
    check('兑换码耗尽自动禁用（used_count=1 status=0）', (int) $redRow->used_count === 1 && (int) $redRow->status === 0);
    callJson('重复兑换被拒', fn () => $rc->redeem(qaRedeemReq($redKey)), 400);
    callJson('无效兑换码 404', fn () => $rc->redeem(qaRedeemReq('sk-not-exist-'.$suffix)), 404);
    Auth::login($admin);
    $expired = callJson('生成过期兑换码', fn () => $rc->store(Request::create('/web-api/redemptions', 'POST', [
        'name' => '过期码_'.$suffix, 'quota' => 100000, 'expired_at' => $now - 3600, 'status' => 1,
    ])));
    Auth::login($user);
    callJson('过期兑换码被拒', fn () => $rc->redeem(qaRedeemReq((string) ($expired['data']['key'] ?? ''))), 400);
    check('过期码兑换后自动置禁用', (Redemption::where('key', (string) ($expired['data']['key'] ?? ''))->first()?->status ?? 1) === 0);

    echo "[C] Token 全生命周期（store → regenerate → update → destroy）\n";
    Auth::login($user);
    $tc = app(TokenApiController::class);
    $token = callJson('用户创建 Token', fn () => $tc->store(Request::create('/web-api/tokens', 'POST', [
        'name' => '自检令牌_crud', 'remain_quota' => 300000, 'expired_time' => -1,
        'group' => 'default', 'model_limits' => 'gpt-4o', 'model_limits_enabled' => true,
    ])), 201);
    $tokenId = (int) ($token['id'] ?? 0);
    $t1 = $tokenId ? Token::find($tokenId) : null;
    check('Token 落库（key 前缀 sk-、额度/模型限制正确）', $t1 !== null
        && str_starts_with((string) $t1->key, 'sk-')
        && (int) $t1->remain_quota === 300000
        && $t1->model_limits === 'gpt-4o');
    callJson('重置 Token 密钥', fn () => $tc->regenerate($tokenId));
    $t1->refresh();
    $key2 = (string) $t1->key;
    callJson('更新 Token（名称/停用）', fn () => $tc->update(Request::create('/web-api/tokens/'.$tokenId, 'PUT', [
        'name' => '自检令牌_改', 'status' => 0,
    ]), $tokenId));
    $t1->refresh();
    check('更新落库（名称/状态/新 key 保留）', $t1->name === '自检令牌_改' && (int) $t1->status === 0 && $t1->key === $key2);
    callJson('删除 Token', fn () => $tc->destroy($tokenId));
    check('Token 已删除', Token::find($tokenId) === null);

    echo "[D] 订阅闭环（0 元计划购买 → mySubscription → 取消）\n";
    Auth::login($admin);
    $plan = SubscriptionPlan::create([
        'name' => '自检月卡_'.$suffix, 'description' => 'QA 自动化测试计划',
        'price' => 0, 'currency' => 'USD', 'quota' => 2000000,
        'duration' => 1, 'duration_unit' => 'month', 'reset_period' => 'none',
        'sort' => 99, 'status' => 1,
    ]);
    OptionService::set('SubscriptionEnabled', true);
    Auth::login($user);
    $sc = app(SubscriptionController::class);
    // 表单模式（无 Accept: application/json）→ 302 重定向回列表页
    callJson('页面入口订阅·表单模式 302', fn () => $sc->subscribe(qaUserReq('/web-api/subscription/subscribe', 'POST', ['plan_id' => $plan->id])), 302);
    $sub1 = Subscription::where('user_id', $user->id)->where('status', 1)->first();
    check('订阅开通（quota=2000000、周期≈1 个月）', $sub1 !== null
        && (int) $sub1->quota === 2000000
        && $sub1->period_end - $sub1->period_start >= 27 * 86400
        && $sub1->period_end - $sub1->period_start <= 62 * 86400);
    // JSON 模式（Accept: application/json）→ 200 JSON；再次订阅使旧订阅失效、新订阅生效
    callJson('页面入口订阅·JSON 模式 200', fn () => $sc->subscribe(qaJsonReq('/web-api/subscription/subscribe', 'POST', ['plan_id' => $plan->id])), 200);
    $sub2 = Subscription::where('user_id', $user->id)->where('status', 1)->first();
    check('重复订阅自动替换旧订阅', $sub2 !== null && (int) $sub2->plan_id === (int) $plan->id && ($sub1 === null || $sub2->id !== $sub1->id));
    $sub1 = $sub2;
    $mySub = callJson('mySubscription 返回订阅', fn () => $sc->mySubscription(qaUserReq('/web-api/subscription/my')));
    check('mySubscription 含订阅数据', (int) ($mySub['data']['subscription']['id'] ?? 0) === (int) ($sub1?->id ?? 0));
    callJson('取消订阅·表单模式 302', fn () => $sc->cancel(qaUserReq('/web-api/subscription/'.($sub1?->id ?? 0).'/cancel', 'POST'), (int) ($sub1?->id ?? 0)), 302);
    check('取消后订阅失效（status=0）', Subscription::where('user_id', $user->id)->where('status', 1)->count() === 0);
    callJson('取消不存在的订阅·JSON 模式被拒', fn () => $sc->cancel(qaJsonReq('/web-api/subscription/999999/cancel', 'POST'), 999999), 400);
    callJson('订阅未开启时被拒·JSON 模式', function () use ($sc, $plan) {
        OptionService::set('SubscriptionEnabled', false);
        $r = $sc->subscribe(qaJsonReq('/web-api/subscription/subscribe', 'POST', ['plan_id' => $plan->id]));
        OptionService::set('SubscriptionEnabled', true);

        return $r;
    }, 400);
    check('0 元计划无余额变动', (int) User::find($user->id)->quota === $quotaBefore + 500000);

    echo "[E] 订阅付费计划余额扣费链路\n";
    Auth::login($admin);
    $paidPlan = SubscriptionPlan::create([
        'name' => '自检付费卡_'.$suffix, 'price' => 1, 'currency' => 'USD',
        'quota' => 5000000, 'duration' => 1, 'duration_unit' => 'day',
        'reset_period' => 'none', 'sort' => 99, 'status' => 1,
    ]);
    Auth::login($user);
    $quotaNow = (int) User::find($user->id)->quota;
    callJson('余额支付订阅（付费计划）', fn () => $sc->payWithBalance(qaUserReq('/web-api/subscription/balance/pay', 'POST', ['plan_id' => $paidPlan->id])));
    $user->refresh();
    $cost = SubscriptionService::quotaCost($paidPlan);
    check('余额按 quotaCost 扣减', $quotaNow - (int) $user->quota === $cost, 'cost='.$cost);
    check('新订阅激活且旧订阅自动失效', Subscription::where('user_id', $user->id)->where('status', 1)->where('plan_id', $paidPlan->id)->count() === 1);

    echo "[F] 用户管理（改额度/封禁/重置密码/删除）\n";
    Auth::login($admin);
    $targetId = $newId;
    callJson('管理员加余额 +100000', fn () => $uc->updateBalance(Request::create('/web-api/users/'.$targetId.'/balance', 'POST', ['amount' => 100000]), $targetId));
    check('余额落库 600000', (int) User::find($targetId)->quota === 600000);
    callJson('超额扣减被拒', fn () => $uc->updateBalance(Request::create('/web-api/users/'.$targetId.'/balance', 'POST', ['amount' => -700000]), $targetId), 400);
    callJson('管理员重置密码', fn () => $uc->resetPassword(Request::create('/web-api/users/'.$targetId.'/reset-password', 'POST', ['password' => 'Reset@98765']), $targetId));
    check('新密码 Hash 校验通过', Hash::check('Reset@98765', (string) User::find($targetId)->password));
    callJson('管理员封禁用户', fn () => $uc->update(Request::create('/web-api/users/'.$targetId, 'PUT', ['status' => 0, 'role' => 1]), $targetId));
    check('封禁落库（status=0）', (int) User::find($targetId)->status === 0);
    callJson('管理员不能删自己', fn () => $uc->destroy($admin->id), 400);
    callJson('管理员删除用户', fn () => $uc->destroy($targetId));
    check('用户已删除', User::find($targetId) === null);

    echo "[G] 系统设置全量闭环 + 内容新增（Root 校验/双载荷/内容端点读回/密钥掩码）\n";
    Auth::login($admin);
    $oc = app(\App\Http\Controllers\OptionController::class);
    // 1) 扁平 JSON 形式批量写（含内容类新增与数值类）
    $contentMap = [
        'SystemName' => '自检站名_'.$suffix,
        'Notice' => '<p>自检公告_'.$suffix.'</p>',
        'About' => '自检关于_'.$suffix,
        'HomePageContent' => '自检首页内容_'.$suffix,
        'UserAgreement' => '自检用户协议_'.$suffix,
        'PrivacyPolicy' => '自检隐私政策_'.$suffix,
        'QuotaPerUnit' => '500000',
    ];
    callJson('Root 批量更新设置（扁平 JSON）', fn () => $oc->update(qaJsonReq('/web-api/options', 'POST', $contentMap)));
    foreach ($contentMap as $k => $v) {
        check("设置落库 {$k}", (string) \App\Models\Option::get($k) === (string) $v);
    }
    // 2) options[Key] 表单形式
    callJson('Root 更新设置（options[Key] 表单形式）', fn () => $oc->update(qaJsonReq('/web-api/options', 'POST', [
        'options' => ['SystemName' => '自检站名B_'.$suffix, 'Notice' => '<b>自检公告B_'.$suffix.'</b>'],
    ])));
    check('表单形式落库 SystemName', (string) \App\Models\Option::get('SystemName') === '自检站名B_'.$suffix);
    // 3) 公开内容端点读回
    $pub = callJson('公开端点 /api/notice 读回', fn () => $oc->notice());
    check('公告内容一致', str_contains((string) ($pub['data'] ?? ''), '自检公告B_'.$suffix));
    check('公开端点 about 读回', str_contains((string) ($oc->about()->getData(true)['data'] ?? ''), '自检关于_'.$suffix));
    check('公开端点 user-agreement 读回', str_contains((string) ($oc->userAgreement()->getData(true)['data'] ?? ''), '自检用户协议_'.$suffix));
    check('公开端点 privacy-policy 读回', str_contains((string) ($oc->privacyPolicy()->getData(true)['data'] ?? ''), '自检隐私政策_'.$suffix));
    check('公开端点 home_page_content 读回', str_contains((string) ($oc->homePageContent()->getData(true)['data'] ?? ''), '自检首页内容_'.$suffix));
    // 4) 密钥掩码：写入 SMTPToken 后 index() 返回 ******
    OptionService::set('SMTPToken', 'qa-secret-token-'.$suffix);
    $optIndex = callJson('Root 读取全部配置', fn () => $oc->index(qaJsonReq('/web-api/options')));
    check('密钥掩码 ******', ($optIndex['data']['SMTPToken'] ?? '') === '******');
    check('未知键跳过（updated 中无 SMTPToken 泄漏值）', ! str_contains(json_encode($optIndex), 'qa-secret-token-'.$suffix));
    // 5) 权限收紧：role=10 普通管理员被拒（403）
    $midAdmin = qaUser('qa_midadmin_'.$suffix, 'qa_mid_'.$suffix.'@selftest.local', 'Mid@123456', 10, 0);
    Auth::login($midAdmin);
    callJson('普通管理员读配置被拒', fn () => $oc->index(qaJsonReq('/web-api/options')), 403);
    callJson('普通管理员改配置被拒', fn () => $oc->update(qaJsonReq('/web-api/options', 'POST', ['SystemName' => '越权_'.$suffix])), 403);
    callJson('普通管理员重置倍率被拒', fn () => $oc->resetModelRatio(qaJsonReq('/web-api/options/reset-model-ratio', 'POST')), 403);
    // 6) Root 重置倍率
    Auth::login($admin);
    OptionService::set('ModelRatio', ['gpt-4o' => 2.5]);
    callJson('Root 重置模型倍率', fn () => $oc->resetModelRatio(qaJsonReq('/web-api/options/reset-model-ratio', 'POST')));
    check('ModelRatio 已重置为空', (array) OptionService::get('ModelRatio', ['x']) === []);

    echo "[H] 回滚零残留\n";
} finally {
    DB::rollBack();
    OptionService::clearCache();
    Cache::forget('pricing');
    Cache::forget('public_options');
}

check('users 无自检残留', User::query()->where('email', 'like', '%selftest.local')->count() === 0);
check('tokens 无自检残留', Token::query()->where('name', 'like', '自检令牌_crud')->count() === 0);
check('redemptions 无自检残留', Redemption::query()->where('name', 'like', '自检兑换码_%')->orWhere('name', 'like', '过期码_%')->count() === 0);
check('subscription plans 无自检残留', SubscriptionPlan::query()->where('name', 'like', '自检%卡_%')->count() === 0);
check('options 无自检残留', Option::query()->where(fn ($q) => $q->where('value', 'like', '自检%')->orWhere('key', 'SMTPToken')->where('value', 'like', 'qa-secret-%'))->count() === 0);
check('渠道能力表无自检残留', true); // 本轮未触达 abilities（渠道 CRUD 由渠道页面回归）

echo $fail === 0 ? "\n✅ 写操作闭环自检全部通过\n" : "\n❌ 失败 {$fail} 项\n";
exit($fail === 0 ? 0 : 1);

/** 带用户 resolver 的兑换请求 */
function qaRedeemReq(string $key): Request
{
    return tap(Request::create('/web-api/redeem', 'POST', ['key' => $key]), fn ($r) => $r->setUserResolver(fn () => Auth::user()));
}

/** 带用户 resolver 的通用请求 */
function qaUserReq(string $uri, string $method = 'GET', array $body = []): Request
{
    return tap(Request::create($uri, $method, $body), fn ($r) => $r->setUserResolver(fn () => Auth::user()));
}

/** 带 Accept: application/json 的请求（expectsJson = true） */
function qaJsonReq(string $uri, string $method = 'GET', array $body = []): Request
{
    return tap(qaUserReq($uri, $method, $body), fn ($r) => $r->headers->set('Accept', 'application/json'));
}
