@extends('layouts.dashboard')
@section('title', '工单详情')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    {{-- 工单信息 --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        @php
            $statusClass = match ($ticket->status) {
                1 => 'bg-yellow-50 text-yellow-700 border-yellow-200',
                2 => 'bg-green-50 text-green-700 border-green-200',
                3 => 'bg-blue-50 text-blue-700 border-blue-200',
                default => 'bg-gray-50 text-gray-500 border-gray-200',
            };
        @endphp
        <div class="flex items-start justify-between gap-3 mb-4">
            <div>
                <h3 class="text-lg font-semibold text-gray-900">{{ $ticket->subject }}</h3>
                <p class="text-xs text-gray-400 mt-1">
                    {{ $categoryMap[$ticket->category] }} · 优先级：{{ $priorityMap[$ticket->priority] }}
                    · 创建于 {{ date('Y-m-d H:i', $ticket->created_at) }}
                </p>
            </div>
            <span class="flex-shrink-0 px-2.5 py-1 text-xs font-medium rounded-full border {{ $statusClass }}">{{ $statusMap[$ticket->status] }}</span>
        </div>
        @if(! $ticket->isClosed())
            <button id="closeBtn" class="text-xs text-gray-500 hover:text-red-600 transition">
                <i class="fas fa-check-circle mr-1"></i>问题已解决，关闭工单
            </button>
        @endif
    </div>

    {{-- 会话记录 --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-900">会话记录</h3>
        </div>
        <div class="px-6 py-6 space-y-4">
            @foreach($ticket->replies as $reply)
                @if($reply->is_admin)
                    <div class="flex gap-3">
                        <div class="w-9 h-9 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-user-shield text-sm"></i>
                        </div>
                        <div class="bg-indigo-50 border border-indigo-100 rounded-xl px-4 py-3 max-w-2xl">
                            <p class="text-xs text-indigo-500 font-medium mb-1">客服 · {{ date('m-d H:i', $reply->created_at) }}</p>
                            <p class="text-sm text-gray-800 whitespace-pre-wrap">{{ $reply->content }}</p>
                        </div>
                    </div>
                @else
                    <div class="flex gap-3 flex-row-reverse">
                        <div class="w-9 h-9 rounded-full bg-primary-100 text-primary-600 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-user text-sm"></i>
                        </div>
                        <div class="bg-primary-50 border border-primary-100 rounded-xl px-4 py-3 max-w-2xl">
                            <p class="text-xs text-primary-500 font-medium mb-1 text-right">我 · {{ date('m-d H:i', $reply->created_at) }}</p>
                            <p class="text-sm text-gray-800 whitespace-pre-wrap">{{ $reply->content }}</p>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    {{-- 回复 --}}
    @if(! $ticket->isClosed())
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <form id="replyForm" class="space-y-3">
                <textarea name="content" required minlength="2" maxlength="2000" rows="4"
                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-sm"
                    placeholder="补充问题信息或追问…（2-2000 字）"></textarea>
                <button type="submit" class="px-6 py-2.5 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 transition">
                    <i class="fas fa-reply mr-1"></i>回复
                </button>
            </form>
        </div>
    @else
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 text-center text-sm text-gray-400">
            工单已关闭，如仍有问题请<a href="/tickets" class="text-primary-600 hover:underline ml-1">重新提交</a>
        </div>
    @endif
    <div id="replyResult" class="hidden max-w-4xl mx-auto"></div>
</div>
@endsection

@push('scripts')
<script>
function showResult(ok, message) {
    const el = document.getElementById('replyResult');
    el.classList.remove('hidden');
    el.className = 'max-w-4xl mx-auto p-4 rounded-lg text-sm ' + (ok ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700');
    el.textContent = message;
}
const replyForm = document.getElementById('replyForm');
if (replyForm) {
    replyForm.onsubmit = function(e) {
        e.preventDefault();
        fetch('/web-api/tickets/{{ $ticket->id }}/reply', { credentials: 'same-origin',
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
const closeBtn = document.getElementById('closeBtn');
if (closeBtn) {
    closeBtn.onclick = function() {
        if (! confirm('确认关闭该工单？')) return;
        fetch('/web-api/tickets/{{ $ticket->id }}/close', { credentials: 'same-origin',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' }
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
