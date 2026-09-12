<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * 用户侧工单：列表/提交/追回/关闭（页面服务端渲染，动作为 web-api JSON）
 */
class TicketController extends Controller
{
    /** 工单列表 + 提交表单页 */
    public function index(): Response
    {
        $tickets = Ticket::query()
            ->where('user_id', Auth::id())
            ->orderByRaw('field(status, 1, 3, 2, 4), last_reply_at desc, id desc')
            ->limit(100)
            ->get();

        return response()->view('dashboard.tickets', [
            'tickets' => $tickets,
            'categoryMap' => Ticket::CATEGORY_MAP,
            'priorityMap' => Ticket::PRIORITY_MAP,
            'statusMap' => Ticket::STATUS_MAP,
        ]);
    }

    /** 工单会话详情（仅本人） */
    public function show(int $id): Response
    {
        $ticket = Ticket::query()->with('replies.user')->findOrFail($id);
        abort_if((int) $ticket->user_id !== (int) Auth::id(), 404);

        return response()->view('dashboard.ticket-view', [
            'ticket' => $ticket,
            'categoryMap' => Ticket::CATEGORY_MAP,
            'priorityMap' => Ticket::PRIORITY_MAP,
            'statusMap' => Ticket::STATUS_MAP,
        ]);
    }

    /** 提交工单：创建主表 + 首条会话流水 */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|min:5|max:100',
            'category' => 'required|integer|in:'.implode(',', array_keys(Ticket::CATEGORY_MAP)),
            'priority' => 'required|integer|in:'.implode(',', array_keys(Ticket::PRIORITY_MAP)),
            'content' => 'required|string|min:5|max:2000',
        ]);

        $now = time();
        $ticket = Ticket::query()->create([
            'user_id' => (int) Auth::id(),
            'subject' => trim((string) $validated['subject']),
            'category' => (int) $validated['category'],
            'priority' => (int) $validated['priority'],
            'status' => Ticket::STATUS_OPEN,
            'last_reply_at' => $now,
            'created_at' => $now,
        ]);
        TicketReply::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => (int) Auth::id(),
            'is_admin' => false,
            'content' => trim((string) $validated['content']),
            'created_at' => $now,
        ]);

        return response()->json(['success' => true, 'message' => '工单已提交，我们会尽快处理', 'id' => $ticket->id]);
    }

    /** 用户追回复（已关闭工单禁止回复） */
    public function reply(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|min:2|max:2000',
        ]);

        $ticket = Ticket::query()->findOrFail($id);
        abort_if((int) $ticket->user_id !== (int) Auth::id(), 404);

        if ($ticket->isClosed()) {
            return response()->json(['success' => false, 'message' => '工单已关闭，如仍有问题请重新提交']);
        }

        $ticket->update(['status' => Ticket::STATUS_CUSTOMER_REPLY, 'last_reply_at' => time()]);
        TicketReply::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => (int) Auth::id(),
            'is_admin' => false,
            'content' => trim((string) $validated['content']),
            'created_at' => time(),
        ]);

        return response()->json(['success' => true, 'message' => '回复成功']);
    }

    /** 用户关闭工单 */
    public function close(int $id): JsonResponse
    {
        $ticket = Ticket::query()->findOrFail($id);
        abort_if((int) $ticket->user_id !== (int) Auth::id(), 404);

        $ticket->update(['status' => Ticket::STATUS_CLOSED]);

        return response()->json(['success' => true, 'message' => '工单已关闭']);
    }
}
