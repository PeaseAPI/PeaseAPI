@extends('layouts.dashboard')
@section('title', '工单处理')

@section('content')
@php
    $statusStyles = [
        1 => ['pill' => 'bg-white/20 text-white border-white/30', 'icon' => 'fa-clock'],
        2 => ['pill' => 'bg-white/20 text-white border-white/30', 'icon' => 'fa-comment-dots'],
        3 => ['pill' => 'bg-white/20 text-white border-white/30', 'icon' => 'fa-rotate-left'],
        4 => ['pill' => 'bg-white/20 text-white border-white/30', 'icon' => 'fa-lock'],
    ];
    $priorityMeta = [
        1 => ['pill' => 'bg-gray-100 text-gray-500', 'icon' => 'fa-arrow-down'],
        2 => ['pill' => 'bg-amber-100 text-amber-700', 'icon' => 'fa-equals'],
        3 => ['pill' => 'bg-red-100 text-red-700', 'icon' => 'fa-arrow-up'],
    ];
@endphp
<div class="max-w-4xl mx-auto space-y-6">
    <a href="/admin/tickets" class="inline-flex items-center text-sm text-gray-400 hover:text-primary-600 transition"><i class="fas fa-arrow-left mr-1.5"></i>返回工单列表</a>

    {{-- 工单信息头卡 --}}
    <div class="rounded-2xl bg-gradient-to-r from-indigo-600 via-indigo-600 to-violet-600 px-6 py-5 text-white shadow-lg shadow-indigo-600/10">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex items-center gap-2.5 flex-wrap">
                    <h3 class="text-lg font-semibold">{{ $ticket->subject }}</h3>
                    <span class="flex-shrink-0 px-2.5 py-1 text-xs font-medium rounded-full border {{ $statusStyles[$ticket->status]['pill'] }}">
                        <i class="fas {{ $statusStyles[$ticket->status]['icon'] }} mr-1"></i>{{ $statusMap[$ticket->status] }}
                    </span>
                </div>
                <p class="text-xs text-indigo-100 mt-2 flex items-center flex-wrap gap-x-3 gap-y-1">
                    <span>#{{ $ticket->id }}</span>
                    <span class="px-2 py-0.5 rounded bg-white/15"><i class="far fa-user mr-1"></i>{{ $ticket->user?->email ?? ('UID '.$ticket->user_id) }}</span>
                    <span class="px-2 py-0.5 rounded bg-white/15">{{ $categoryMap[$ticket->category] }}</span>
                    <span class="px-2 py-0.5 rounded bg-white/15"><i class="fas {{ $priorityMeta[$ticket->priority]['icon'] }} mr-1"></i>{{ $priorityMap[$ticket->priority] }}优先</span>
                    <span><i class="far fa-clock mr-0.5"></i>创建于 {{ date('Y-m-d H:i', $ticket->created_at) }}</span>
                </p>
            </div>
            <div class="flex flex-shrink-0 gap-2">
                <button id="statusBtn" class="px-4 py-2 text-xs font-medium rounded-xl bg-white/15 hover:bg-white/25 border border-white/25 backdrop-blur transition">
                    @if($ticket->isClosed())<i class="fas fa-lock-open mr-1"></i>重开工单 @else<i class="fas fa-lock mr-1"></i>关闭工单 @endif
                </button>
            </div>
        </div>
    </div>

    {{-- 会话记录 --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-base font-semibold text-gray-900 flex items-center gap-2"><i class="fas fa-comments text-indigo-500"></i>会话记录</h3>
            <span class="text-xs text-gray-400">{{ $ticket->replies->count() }} 条往来</span>
        </div>
        <div class="px-6 py-6 space-y-5 max-h-[32rem] overflow-y-auto" id="chatBox">
            @foreach($ticket->replies as $reply)
                @php($first = $loop->first)
                @if($reply->is_admin)
                    <div class="flex gap-3 flex-row-reverse">
                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 text-white flex items-center justify-center flex-shrink-0 shadow-sm">
                            <i class="fas fa-user-shield text-xs"></i>
                        </div>
                        <div class="bg-indigo-50/70 border border-indigo-100 rounded-2xl rounded-tr-sm px-4 py-3 max-w-2xl">
                            <p class="text-xs text-indigo-500 font-medium mb-1 text-right flex items-center gap-2 justify-end">
                                客服（{{ $reply->user?->email ?? ('UID '.$reply->user_id) }}）<span class="font-normal opacity-75">{{ date('m-d H:i', $reply->created_at) }}</span>
                            </p>
                            <p class="text-sm text-gray-800 whitespace-pre-wrap leading-relaxed">{{ $reply->content }}</p>
                        </div>
                    </div>
                @else
                    <div class="flex gap-3">
                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 text-white flex items-center justify-center flex-shrink-0 shadow-sm">
                            <i class="fas fa-user text-xs"></i>
                        </div>
                        <div class="bg-primary-50/70 border border-primary-100 rounded-2xl rounded-tl-sm px-4 py-3 max-w-2xl">
                            <p class="text-xs text-primary-500 font-medium mb-1 flex items-center gap-2">
                                {{ $reply->user?->email ?? ('UID '.$reply->user_id) }}
                                @if($first)<span class="px-1.5 py-0.5 rounded bg-primary-100 text-[10px]">工单描述</span>@endif
                                <span class="font-normal opacity-75">{{ date('m-d H:i', $reply->created_at) }}</span>
                            </p>
                            <p class="text-sm text-gray-800 whitespace-pre-wrap leading-relaxed">{{ $reply->content }}</p>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    {{-- 客服回复 --}}
    @if(! $ticket->isClosed())
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-base font-semibold text-gray-900 flex items-center gap-2"><i class="fas fa-reply text-indigo-500"></i>客服回复</h3>
                <span class="text-xs text-gray-400"><span id="replyCount">0</span> / 2000</span>
            </div>
            <div class="flex flex-wrap gap-2 mb-3" id="quickReplies">
                <span class="text-xs text-gray-400 self-center"><i class="fas fa-bolt mr-1 text-amber-400"></i>快捷回复：</span>
                <button type="button" data-text="收到，正在排查处理，请稍候。我们会在解决后第一时间回复您。" class="px-2.5 py-1 text-xs rounded-full bg-gray-100 text-gray-600 hover:bg-primary-50 hover:text-primary-600 transition">正在排查</button>
                <button type="button" data-text="问题已修复，请您验证。如仍有异常请随时回复本工单。" class="px-2.5 py-1 text-xs rounded-full bg-gray-100 text-gray-600 hover:bg-primary-50 hover:text-primary-600 transition">已修复请验证</button>
                <button type="button" data-text="需要更多信息以便定位：请提供使用的令牌、模型名称、大致时间与报错截图/内容。" class="px-2.5 py-1 text-xs rounded-full bg-gray-100 text-gray-600 hover:bg-primary-50 hover:text-primary-600 transition">请补充信息</button>
                <button type="button" data-text="已同步技术团队跟进处理，感谢您的耐心等待。" class="px-2.5 py-1 text-xs rounded-full bg-gray-100 text-gray-600 hover:bg-primary-50 hover:text-primary-600 transition">同步技术团队</button>
            </div>
            <form id="replyForm" class="space-y-3">
                <textarea name="content" id="replyInput" required minlength="2" maxlength="2000" rows="4"
                    class="w-full px-4 py-2.5 border border-gray-200 rounded-xl bg-gray-50/50 focus:bg-white focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500 text-sm transition resize-y"
                    placeholder="输入客服回复内容…（2-2000 字）"></textarea>
                <div class="flex items-center gap-3">
                    <button type="submit" id="replyBtn" class="px-6 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-medium hover:bg-indigo-700 shadow-sm shadow-indigo-600/20 transition disabled:opacity-60 disabled:cursor-not-allowed">
                        <i class="fas fa-paper-plane mr-1.5"></i>回复用户
                    </button>
                    <span class="text-xs text-gray-400">回复后工单状态将置为「已回复」</span>
                </div>
            </form>
        </div>
    @else
        <div class="rounded-xl bg-gradient-to-r from-gray-50 to-gray-100 border border-gray-200 px-5 py-4 text-sm text-gray-500 flex items-center justify-center gap-2">
            <i class="fas fa-lock text-gray-400"></i>该工单已关闭，可在上方「重开工单」恢复跟进
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
const chatBox = document.getElementById('chatBox');
if (chatBox) { chatBox.scrollTop = chatBox.scrollHeight; }
function bindCounter(inputId, counterId) {
    const input = document.getElementById(inputId), counter = document.getElementById(counterId);
    if (! input || ! counter) return;
    const update = () => { counter.textContent = input.value.length; };
    input.addEventListener('input', update);
    update();
}
bindCounter('replyInput', 'replyCount');
document.querySelectorAll('#quickReplies button[data-text]').forEach(btn => {
    btn.onclick = function() {
        const input = document.getElementById('replyInput');
        input.value = this.dataset.text;
        input.dispatchEvent(new Event('input'));
        input.focus();
    };
});
const replyForm = document.getElementById('replyForm');
if (replyForm) {
    replyForm.onsubmit = function(e) {
        e.preventDefault();
        const btn = document.getElementById('replyBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1.5"></i>发送中…';
        fetch('/admin/tickets/{{ $ticket->id }}/reply', { credentials: 'same-origin',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
            body: JSON.stringify({ content: this.content.value })
        }).then(res => res.json()).then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane mr-1.5"></i>回复用户';
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
