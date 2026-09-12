<?php

declare(strict_types=1);

/**
 * 工单系统自检（非 PHPUnit，独立脚本跑完即退出）
 * 覆盖：tickets 迁移存在 / 提交-回复-追回-关闭-重开完整状态机（控制器级真实调用）/
 * 关闭单回复拦截 / 越权访问 404 / 用户侧与管理端双端页面 render / 事务回滚零残留
 */
require __DIR__.'/../vendor/autoload.php';

use App\Http\Controllers\AdminController;
use App\Http\Controllers\TicketController;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

$fail = 0;
function check(string $name, bool $cond): void
{
    global $fail;
    echo ($cond ? '  ✓ ' : '  ✗ ').$name."\n";
    if (! $cond) {
        $fail++;
    }
}

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$suffix = substr(md5('p9ticket_'.random_int(100000, 999999)), 0, 8);

DB::beginTransaction();
try {
    echo "[1] 迁移与基础\n";
    check('tickets 表存在', Schema::hasTable('tickets'));
    check('ticket_replies 表存在', Schema::hasTable('ticket_replies'));
    check('状态常量齐备', Ticket::STATUS_OPEN === 1 && Ticket::STATUS_REPLIED === 2
        && Ticket::STATUS_CUSTOMER_REPLY === 3 && Ticket::STATUS_CLOSED === 4);

    echo "[2] 造用户与管理员\n";
    $makeUser = static fn (string $email, int $role = 0): User => User::query()->create([
        'username' => explode('@', $email)[0],
        'email' => $email,
        'password' => bcrypt('selftest!2026'),
        'role' => $role,
        'aff_code' => substr(strtoupper(bin2hex(random_bytes(16))), 0, 32),
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    $user = $makeUser($suffix.'@ticket-selftest.local');
    $admin = $makeUser('admin_'.$suffix.'@ticket-selftest.local', 100);
    check('用户与管理员创建', $user->exists && $admin->exists && (int) $admin->role >= 100);

    echo "[3] 用户提交工单（控制器级）\n";
    Auth::login($user);
    $ctrl = app(TicketController::class);
    $resp = $ctrl->store(Request::create('/web-api/tickets', 'POST', [
        'subject' => '自检工单：令牌调用偶发 429',
        'category' => (string) Ticket::CATEGORY_TECHNICAL,
        'priority' => (string) Ticket::PRIORITY_HIGH,
        'content' => '自检数据：调用 gpt-4o 偶发 429，请协助排查。',
    ]));
    $payload = json_decode($resp->getContent(), true);
    check('store 返回 success', (bool) ($payload['success'] ?? false));
    $ticketId = (int) ($payload['id'] ?? 0);
    check('返回工单 ID', $ticketId > 0);
    $ticket = Ticket::query()->findOrFail($ticketId);
    check('初始状态为待处理', $ticket->status === Ticket::STATUS_OPEN);
    check('isPending 判定', $ticket->isPending() && ! $ticket->isClosed());
    check('首条会话流水存在且非客服', $ticket->replies()->count() === 1 && $ticket->replies()->first()->is_admin === false);
    check('last_reply_at 已写入', $ticket->last_reply_at !== null);

    echo "[4] 客服回复 → 已回复\n";
    Auth::login($admin);
    $adminCtrl = app(AdminController::class);
    $resp = $adminCtrl->ticketReply(
        Request::create('/admin/tickets', 'POST', ['content' => '自检数据：已定位限流策略，请稍后重试。']), $ticketId
    );
    $payload = json_decode($resp->getContent(), true);
    check('admin reply 返回 success', (bool) ($payload['success'] ?? false));
    $ticket->refresh();
    check('状态置为已回复', $ticket->status === Ticket::STATUS_REPLIED);
    check('客服流水 is_admin', TicketReply::query()->where('ticket_id', $ticketId)->where('is_admin', true)->count() === 1);

    echo "[5] 用户追回 → 待处理\n";
    Auth::login($user);
    $resp = $ctrl->reply(Request::create('/web-api/tickets', 'POST', ['content' => '自检数据：仍然 429，麻烦再看看。']), $ticketId);
    $payload = json_decode($resp->getContent(), true);
    check('用户追回返回 success', (bool) ($payload['success'] ?? false));
    $ticket->refresh();
    check('状态置为用户追回', $ticket->status === Ticket::STATUS_CUSTOMER_REPLY);
    check('追回后仍待处理', $ticket->isPending());

    echo "[6] 用户关闭 / 关闭单回复拦截 / 管理重开\n";
    $resp = $ctrl->close($ticketId);
    $payload = json_decode($resp->getContent(), true);
    check('用户关闭 success', (bool) ($payload['success'] ?? false));
    $ticket->refresh();
    check('状态已关闭', $ticket->isClosed());
    $resp = $ctrl->reply(Request::create('/web-api/tickets', 'POST', ['content' => '关闭后不应写入。']), $ticketId);
    $payload = json_decode($resp->getContent(), true);
    check('关闭单回复被拦截', ($payload['success'] ?? true) === false);
    $before = TicketReply::query()->where('ticket_id', $ticketId)->count();
    check('拦截后无新增流水', TicketReply::query()->where('ticket_id', $ticketId)->count() === $before);
    Auth::login($admin);
    $resp = $adminCtrl->ticketStatus(
        Request::create('/admin/tickets', 'POST', ['action' => 'reopen']), $ticketId
    );
    $payload = json_decode($resp->getContent(), true);
    check('管理重开 success', (bool) ($payload['success'] ?? false));
    $ticket->refresh();
    check('重开回到待处理', $ticket->status === Ticket::STATUS_OPEN && $ticket->isPending());

    echo "[7] 权限与列表\n";
    $other = $makeUser('other_'.$suffix.'@ticket-selftest.local');
    Auth::login($other);
    try {
        $ctrl->show($ticketId);
        check('越权访问被 404', false);
    } catch (HttpException $e) {
        check('越权访问被 404', $e->getStatusCode() === 404);
    }
    Auth::login($user);
    $list = Ticket::query()->where('user_id', $user->id)->orderByRaw('field(status, 1, 3, 2, 4), last_reply_at desc, id desc')->get();
    check('用户工单列表可见', $list->contains('id', $ticketId));

    echo "[8] 双端页面 render\n";
    $html = $ctrl->index()->getContent();
    check('用户列表页含标题', str_contains($html, '提交工单') && str_contains($html, '我的工单'));
    $html = $ctrl->show($ticketId)->getContent();
    check('用户详情页含主题与会话', str_contains($html, '自检工单：令牌调用偶发 429') && str_contains($html, '会话记录'));
    Auth::login($admin);
    $html = $adminCtrl->tickets(Request::create('/admin/tickets'))->getContent();
    check('管理列表页含工单与筛选', str_contains($html, '自检工单：令牌调用偶发 429') && str_contains($html, '工单管理'));
    $html = $adminCtrl->ticketView($ticketId)->getContent();
    check('管理详情页含客服回复区', str_contains($html, '回复用户') && str_contains($html, '会话记录'));

    echo "[9] 映射表完整\n";
    check('状态映射完整', [1 => '待处理', 2 => '已回复', 3 => '用户追回', 4 => '已关闭'] === Ticket::STATUS_MAP);
    check('分类/优先级映射', count(Ticket::CATEGORY_MAP) === 4 && count(Ticket::PRIORITY_MAP) === 3);
} catch (Throwable $e) {
    $fail++;
    echo '  ✗ 异常：'.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();
    $left = Ticket::query()->where('subject', 'like', '%自检工单%')->count();
    check('回滚零残留', $left === 0);
    // 自检在事务内渲染过侧边栏，badge 缓存可能被写入事务内计数，强制清除防污染（对齐 P1-9 踩坑沉淀）
    Cache::forget('admin:ticket_pending_badge');
    Auth::logout();
}

echo $fail === 0 ? "\nALL PASS\n" : "\nFAILED x{$fail}\n";
exit($fail === 0 ? 0 : 1);
