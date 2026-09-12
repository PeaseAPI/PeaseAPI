@extends('layouts.dashboard')
@section('title', '工单支持')

@section('content')
@php
    $statusStyles = [
        1 => ['pill' => 'bg-amber-50 text-amber-700 border-amber-200', 'icon' => 'fa-clock', 'bar' => 'border-l-amber-400'],
        2 => ['pill' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'icon' => 'fa-comment-dots', 'bar' => 'border-l-emerald-400'],
        3 => ['pill' => 'bg-sky-50 text-sky-700 border-sky-200', 'icon' => 'fa-rotate-left', 'bar' => 'border-l-sky-400'],
        4 => ['pill' => 'bg-gray-50 text-gray-500 border-gray-200', 'icon' => 'fa-lock', 'bar' => 'border-l-gray-200'],
    ];
    $priorityMeta = [1 => ['label' => '低', 'color' => 'bg-gray-300'], 2 => ['label' => '中', 'color' => 'bg-amber-400'], 3 => ['label' => '高', 'color' => 'bg-red-400']];
    $pending = $replied = $closed = 0;
    foreach ($tickets as $t) {
        if (in_array($t->status, [1, 3], true)) { $pending++; }
        elseif ($t->status === 2) { $replied++; }
        elseif ($t->status === 4) { $closed++; }
    }
@endphp
<div class="max-w-4xl mx-auto space-y-6">
    {{-- Hero --}}
    <div class="rounded-2xl bg-gradient-to-r from-primary-600 via-primary-600 to-indigo-600 px-6 py-5 text-white shadow-lg shadow-primary-600/10">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-white/15 flex items-center justify-center"><i class="fas fa-headset"></i></span>
                    工单支持
                </h2>
                <p class="text-xs text-primary-100 mt-1.5">技术问题、账单疑问、功能建议都可以在这里提交，我们通常在 24 小时内回复</p>
            </div>
            <div class="flex gap-2 text-xs">
                <span class="px-3 py-1.5 rounded-full bg-white/15 backdrop-blur"><i class="fas fa-circle-notch mr-1 opacity-75"></i>待处理 {{ $pending }}</span>
                <span class="px-3 py-1.5 rounded-full bg-white/15 backdrop-blur"><i class="fas fa-comment-dots mr-1 opacity-75"></i>新回复 {{ $replied }}</span>
                <span class="px-3 py-1.5 rounded-full bg-white/15 backdrop-blur"><i class="fas fa-check mr-1 opacity-75"></i>已关闭 {{ $closed }}</span>
            </div>
        </div>
    </div>

    {{-- 提交工单 --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6" id="ticketFormCard">
        <h3 class="text-base font-semibold text-gray-900 mb-1 flex items-center gap-2"><i class="fas fa-pen-to-square text-primary-600"></i>提交工单</h3>
        <p class="text-xs text-gray-400 mb-5">带 <span class="text-red-400">*</span> 为必填项，提交后可在下方「我的工单」中跟踪进度</p>
        <form id="ticketForm" class="space-y-5">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">问题标题 <span class="text-red-400">*</span></label>
                <input type="text" name="subject" required minlength="5" maxlength="100"
                    class="w-full px-4 py-2.5 border border-gray-200 rounded-xl bg-gray-50/50 focus:bg-white focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500 text-sm transition"
                    placeholder="例如：令牌调用偶发 429 限流（5-100 字）">
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">问题分类 <span class="text-red-400">*</span></label>
                    <select name="category" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl bg-gray-50/50 focus:bg-white focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500 text-sm transition">
                        @foreach($categoryMap as $key => $label)
                            <option value="{{ $key }}" {{ $key === 1 ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">优先级 <span class="text-red-400">*</span></label>
                    <select name="priority" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl bg-gray-50/50 focus:bg-white focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500 text-sm transition">
                        @foreach($priorityMap as $key => $label)
                            <option value="{{ $key }}" {{ $key === 2 ? 'selected' : '' }}>{{ $priorityMeta[$key]['label'] }}优先 · {{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label class="block text-sm font-medium text-gray-700">问题描述 <span class="text-red-400">*</span></label>
                    <span class="text-xs text-gray-400"><span id="contentCount">0</span> / 2000</span>
                </div>
                <textarea name="content" id="ticketContent" required minlength="5" maxlength="2000" rows="5"
                    class="w-full px-4 py-2.5 border border-gray-200 rounded-xl bg-gray-50/50 focus:bg-white focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500 text-sm transition resize-y"
                    placeholder="请描述：问题现象、发生时间、使用的令牌/模型、报错信息等，越详细越有助于快速定位（5-2000 字）"></textarea>
            </div>
            <div class="flex items-center gap-3 pt-1">
                <button type="submit" id="ticketSubmitBtn" class="px-6 py-2.5 bg-primary-600 text-white rounded-xl text-sm font-medium hover:bg-primary-700 shadow-sm shadow-primary-600/20 transition disabled:opacity-60 disabled:cursor-not-allowed">
                    <i class="fas fa-paper-plane mr-1.5"></i>提交工单
                </button>
                <span class="text-xs text-gray-400"><i class="far fa-clock mr-1"></i>响应时间：工作日 24 小时内</span>
            </div>
        </form>
        <div id="ticketResult" class="mt-4 hidden"></div>
    </div>

    {{-- 我的工单 --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-base font-semibold text-gray-900 flex items-center gap-2"><i class="fas fa-list-check text-primary-600"></i>我的工单</h3>
            <span class="text-xs text-gray-400">共 {{ $tickets->count() }} 条</span>
        </div>
        @if($tickets->isEmpty())
            <div class="px-6 py-14 text-center">
                <div class="w-14 h-14 mx-auto rounded-2xl bg-gradient-to-br from-primary-50 to-indigo-50 flex items-center justify-center mb-3">
                    <i class="fas fa-inbox text-2xl text-primary-300"></i>
                </div>
                <p class="text-sm text-gray-400">暂无工单</p>
                <a href="#ticketFormCard" class="inline-block mt-3 px-4 py-2 text-xs font-medium text-primary-600 bg-primary-50 rounded-lg hover:bg-primary-100 transition">提交第一个工单</a>
            </div>
        @else
            <div class="divide-y divide-gray-100">
                @foreach($tickets as $ticket)
                    @php($s = $statusStyles[$ticket->status])
                    <a href="/tickets/{{ $ticket->id }}" class="block px-6 py-4 hover:bg-gray-50/70 transition border-l-4 {{ $s['bar'] }}">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate">{{ $ticket->subject }}</p>
                                    @if($ticket->status === 2)
                                        <span class="flex-shrink-0 px-1.5 py-0.5 text-[10px] font-semibold rounded bg-emerald-100 text-emerald-600 animate-pulse">客服已回复</span>
                                    @endif
                                </div>
                                <p class="text-xs text-gray-400 mt-1.5 flex items-center flex-wrap gap-x-3 gap-y-1">
                                    <span class="px-2 py-0.5 rounded bg-gray-100 text-gray-500">{{ $categoryMap[$ticket->category] }}</span>
                                    <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full {{ $priorityMeta[$ticket->priority]['color'] }}"></span>{{ $priorityMap[$ticket->priority] }}优先</span>
                                    <span><i class="far fa-comment mr-0.5"></i>{{ $ticket->replies_count }} 条对话</span>
                                    <span><i class="far fa-clock mr-0.5"></i>{{ date('m-d H:i', $ticket->last_reply_at ?: $ticket->created_at) }}</span>
                                </p>
                            </div>
                            <span class="flex-shrink-0 px-2.5 py-1 text-xs font-medium rounded-full border {{ $s['pill'] }}"><i class="fas {{ $s['icon'] }} mr-1"></i>{{ $statusMap[$ticket->status] }}</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
function bindCounter(inputId, counterId) {
    const input = document.getElementById(inputId), counter = document.getElementById(counterId);
    if (! input || ! counter) return;
    const update = () => { counter.textContent = input.value.length; };
    input.addEventListener('input', update);
    update();
}
bindCounter('ticketContent', 'contentCount');
document.getElementById('ticketForm').onsubmit = function(e) {
    e.preventDefault();
    const btn = document.getElementById('ticketSubmitBtn');
    const payload = {
        subject: this.subject.value,
        category: Number(this.category.value),
        priority: Number(this.priority.value),
        content: this.content.value,
    };
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1.5"></i>提交中…';
    fetch('/web-api/tickets', { credentials: 'same-origin',
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).then(res => res.json()).then(data => {
        const el = document.getElementById('ticketResult');
        el.classList.remove('hidden');
        if (data.success) {
            el.className = 'mt-4 p-4 bg-green-50 text-green-700 rounded-lg text-sm';
            el.textContent = data.message || '提交成功';
            window.location.href = '/tickets/' + data.id;
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane mr-1.5"></i>提交工单';
            el.className = 'mt-4 p-4 bg-red-50 text-red-700 rounded-lg text-sm';
            el.textContent = data.message || '提交失败';
        }
    });
};
</script>
@endpush
