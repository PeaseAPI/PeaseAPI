@extends('layouts.dashboard')
@section('title', '工单详情')

@section('content')
@php
    $statusStyles = [
        1 => ['pill' => 'bg-white/20 text-white border-white/30', 'icon' => 'fa-clock'],
        2 => ['pill' => 'bg-white/20 text-white border-white/30', 'icon' => 'fa-comment-dots'],
        3 => ['pill' => 'bg-white/20 text-white border-white/30', 'icon' => 'fa-rotate-left'],
        4 => ['pill' => 'bg-white/20 text-white border-white/30', 'icon' => 'fa-lock'],
    ];
    $priorityMeta = [1 => ['label' => '低', 'color' => 'bg-gray-300'], 2 => ['label' => '中', 'color' => 'bg-amber-400'], 3 => ['label' => '高', 'color' => 'bg-red-400']];
@endphp
<div class="max-w-4xl mx-auto space-y-6">
    <a href="/tickets" class="inline-flex items-center text-sm text-gray-400 hover:text-primary-600 transition"><i class="fas fa-arrow-left mr-1.5"></i>返回我的工单</a>

    {{-- 工单信息头卡 --}}
    <div class="rounded-2xl bg-gradient-to-r from-primary-600 via-primary-600 to-indigo-600 px-6 py-5 text-white shadow-lg shadow-primary-600/10">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex items-center gap-2.5 flex-wrap">
                    <h3 class="text-lg font-semibold">{{ $ticket->subject }}</h3>
                    <span class="flex-shrink-0 px-2.5 py-1 text-xs font-medium rounded-full border {{ $statusStyles[$ticket->status]['pill'] }}">
                        <i class="fas {{ $statusStyles[$ticket->status]['icon'] }} mr-1"></i>{{ $statusMap[$ticket->status] }}
                    </span>
                </div>
                <p class="text-xs text-primary-100 mt-2 flex items-center flex-wrap gap-x-3 gap-y-1">
                    <span>#{{ $ticket->id }}</span>
                    <span class="px-2 py-0.5 rounded bg-white/15">{{ $categoryMap[$ticket->category] }}</span>
                    <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full {{ $priorityMeta[$ticket->priority]['color'] }}"></span>{{ $priorityMap[$ticket->priority] }}优先</span>
                    <span><i class="far fa-clock mr-0.5"></i>创建于 {{ date('Y-m-d H:i', $ticket->created_at) }}</span>
                </p>
            </div>
            @if(! $ticket->isClosed())
                <button id="closeBtn" class="flex-shrink-0 px-4 py-2 text-xs font-medium rounded-xl bg-white/15 hover:bg-white/25 border border-white/25 backdrop-blur transition">
                    <i class="fas fa-check-circle mr-1"></i>问题已解决，关闭工单
                </button>
            @endif
        </div>
    </div>

    {{-- 会话记录 --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-base font-semibold text-gray-900 flex items-center gap-2"><i class="fas fa-comments text-primary-600"></i>会话记录</h3>
            <span class="text-xs text-gray-400">{{ $ticket->replies->count() }} 条往来</span>
        </div>
        <div class="px-6 py-6 space-y-5 max-h-[32rem] overflow-y-auto" id="chatBox">
            @foreach($ticket->replies as $reply)
                @php($first = $loop->first)
                @if($reply->is_admin)
                    <div class="flex gap-3">
                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 text-white flex items-center justify-center flex-shrink-0 shadow-sm">
                            <i class="fas fa-user-shield text-xs"></i>
                        </div>
                        <div class="bg-indigo-50/70 border border-indigo-100 rounded-2xl rounded-tl-sm px-4 py-3 max-w-2xl">
                            <p class="text-xs text-indigo-500 font-medium mb-1 flex items-center gap-2">
                                PeaseAPI 客服@if($first)<span class="px-1.5 py-0.5 rounded bg-indigo-100 text-[10px]">工单描述</span>@endif
                                <span class="font-normal opacity-75">{{ date('m-d H:i', $reply->created_at) }}</span>
                            </p>
                            <p class="text-sm text-gray-800 whitespace-pre-wrap leading-relaxed">{{ $reply->content }}</p>
                        </div>
                    </div>
                @else
                    <div class="flex gap-3 flex-row-reverse">
                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 text-white flex items-center justify-center flex-shrink-0 shadow-sm">
                            <i class="fas fa-user text-xs"></i>
                        </div>
                        <div class="bg-primary-50/70 border border-primary-100 rounded-2xl rounded-tr-sm px-4 py-3 max-w-2xl">
                            <p class="text-xs text-primary-500 font-medium mb-1 text-right flex items-center gap-2 justify-end">
                                @if($first)<span class="px-1.5 py-0.5 rounded bg-primary-100 text-[10px]">工单描述</span>@endif
                                我<span class="font-normal opacity-75">{{ date('m-d H:i', $reply->created_at) }}</span>
                            </p>
                            <p class="text-sm text-gray-800 whitespace-pre-wrap leading-relaxed">{{ $reply->content }}</p>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    {{-- 回复 --}}
    @if(! $ticket->isClosed())
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-base font-semibold text-gray-900 flex items-center gap-2"><i class="fas fa-reply text-primary-600"></i>补充回复</h3>
                <span class="text-xs text-gray-400"><span id="replyCount">0</span> / 2000</span>
            </div>
            <form id="replyForm" class="space-y-3">
                <textarea name="content" id="replyInput" required minlength="2" maxlength="2000" rows="4"
                    class="w-full px-4 py-2.5 border border-gray-200 rounded-xl bg-gray-50/50 focus:bg-white focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500 text-sm transition resize-y"
                    placeholder="补充问题信息或追问…（2-2000 字）"></textarea>
                <div class="flex items-center gap-3">
                    <button type="submit" id="replyBtn" class="px-6 py-2.5 bg-primary-600 text-white rounded-xl text-sm font-medium hover:bg-primary-700 shadow-sm shadow-primary-600/20 transition disabled:opacity-60 disabled:cursor-not-allowed">
                        <i class="fas fa-paper-plane mr-1.5"></i>回复
                    </button>
                    <span class="text-xs text-gray-400">回复后工单将保持跟进状态</span>
                </div>
            </form>
        </div>
    @else
        <div class="rounded-xl bg-gradient-to-r from-gray-50 to-gray-100 border border-gray-200 px-5 py-4 text-sm text-gray-500 flex items-center justify-center gap-2">
            <i class="fas fa-lock text-gray-400"></i>该工单已关闭
            <a href="/tickets" class="text-primary-600 hover:underline ml-1">如有新问题请重新提交</a>
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
const replyForm = document.getElementById('replyForm');
if (replyForm) {
    replyForm.onsubmit = function(e) {
        e.preventDefault();
        const btn = document.getElementById('replyBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1.5"></i>发送中…';
        fetch('/web-api/tickets/{{ $ticket->id }}/reply', { credentials: 'same-origin',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
            body: JSON.stringify({ content: this.content.value })
        }).then(res => res.json()).then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane mr-1.5"></i>回复';
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
