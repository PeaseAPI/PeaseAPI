@extends('layouts.dashboard')
@section('title', '工单处理')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    {{-- 工单信息 --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        @php
            $statusClass = match ($ticket->status) {
                1 => 'bg-yellow-50 text-yellow-700',
                2 => 'bg-green-50 text-green-700',
                3 => 'bg-blue-50 text-blue-700',
                default => 'bg-gray-100 text-gray-500',
            };
        @endphp
        <div class="flex items-start justify-between gap-3 mb-3">
            <div>
                <h3 class="text-lg font-semibold text-gray-900">#{{ $ticket->id }} · {{ $ticket->subject }}</h3>
                <p class="text-xs text-gray-400 mt-1">
                    提交人：{{ $ticket->user?->email ?? ('UID '.$ticket->user_id) }}
                    · {{ $categoryMap[$ticket->category] }} · 优先级：{{ $priorityMap[$ticket->priority] }}
                    · 创建于 {{ date('Y-m-d H:i', $ticket->created_at) }}
                </p>
            </div>
            <span class="flex-shrink-0 px-2.5 py-1 text-xs font-medium rounded-full {{ $statusClass }}">{{ $statusMap[$ticket->status] }}</span>
        </div>
        <div class="flex gap-3">
            <button id="statusBtn" class="text-xs px-3 py-1.5 rounded-lg border border-gray-200 text-gray-600 hover:border-primary-400 transition">
                @if($ticket->isClosed())<i class="fas fa-lock-open mr-1"></i>重开工单 @else<i class="fas fa-lock mr-1"></i>关闭工单 @endif
            </button>
            <a href="/admin/tickets" class="text-xs px-3 py-1.5 rounded-lg border border-gray-200 text-gray-500 hover:border-primary-400 transition">返回列表</a>
        </div>
    </div>

    {{-- 会话记录 --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-900">会话记录</h3>
        </div>
        <div class="px-6 py-6 space-y-4">
            @foreach($ticket->replies as $reply)
                @if($reply->is_admin)
                    <div class="flex gap-3 flex-row-reverse">
                        <div class="w-9 h-9 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-user-shield text-sm"></i>
                        </div>
                        <div class="bg-indigo-50 border border-indigo-100 rounded-xl px-4 py-3 max-w-2xl">
                            <p class="text-xs text-indigo-500 font-medium mb-1 text-right">客服（{{ $reply->user?->email ?? ('UID '.$reply->user_id) }}） · {{ date('m-d H:i', $reply->created_at) }}</p>
                            <p class="text-sm text-gray-800 whitespace-pre-wrap">{{ $reply->content }}</p>
                        </div>
                    </div>
                @else
                    <div class="flex gap-3">
                        <div class="w-9 h-9 rounded-full bg-gray-100 text-gray-600 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-user text-sm"></i>
                        </div>
                        <div class="bg-gray-50 border border-gray-100 rounded-xl px-4 py-3 max-w-2xl">
                            <p class="text-xs text-gray-400 font-medium mb-1">{{ $reply->user?->email ?? ('UID '.$reply->user_id) }} · {{ date('m-d H:i', $reply->created_at) }}</p>
                            <p class="text-sm text-gray-800 whitespace-pre-wrap">{{ $reply->content }}</p>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    {{-- 客服回复 --}}
    @if(! $ticket->isClosed())
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <form id="replyForm" class="space-y-3">
                <textarea name="content" required minlength="2" maxlength="2000" rows="4"
                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-sm"
                    placeholder="输入客服回复内容…（2-2000 字）"></textarea>
                <button type="submit" class="px-6 py-2.5 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 transition">
                    <i class="fas fa-reply mr-1"></i>回复用户
                </button>
            </form>
        </div>
    @endif
    <div id="result" class="hidden max-w-4xl mx-auto"></div>
</div>
@endsection

@push('scripts')
<script>
function showResult(ok, message) {
    const el = document.getElementById('result');
    el.classList.remove('hidden');
    el.className = 'max-w-4xl mx-auto p-4 rounded-lg text-sm ' + (ok ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700');
    el.textContent = message;
}
const replyForm = document.getElementById('replyForm');
if (replyForm) {
    replyForm.onsubmit = function(e) {
        e.preventDefault();
        fetch('/admin/tickets/{{ $ticket->id }}/reply', { credentials: 'same-origin',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
            body: JSON.stringify({ content: this.content.value })
        }).then(res => res.json()).then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                showResult(false, data.message || '回复失败');
            }
        });
    };
}
const statusBtn = document.getElementById('statusBtn');
if (statusBtn) {
    statusBtn.onclick = function() {
        const action = {{ $ticket->isClosed() ? "'reopen'" : "'close'" }};
        if (! confirm(action === 'close' ? '确认关闭该工单？' : '确认重开该工单？')) return;
        fetch('/admin/tickets/{{ $ticket->id }}/status', { credentials: 'same-origin',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
            body: JSON.stringify({ action })
        }).then(res => res.json()).then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                showResult(false, data.message || '操作失败');
            }
        });
    };
}
</script>
@endpush
