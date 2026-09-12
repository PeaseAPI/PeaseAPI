@extends('layouts.dashboard')
@section('title', '工单支持')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    {{-- 提交工单 --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-headset mr-2 text-primary-600"></i>提交工单</h3>
        <form id="ticketForm" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="md:col-span-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">问题标题</label>
                    <input type="text" name="subject" required minlength="5" maxlength="100"
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-sm"
                        placeholder="简要描述您遇到的问题（5-100 字）">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">问题分类</label>
                    <select name="category" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm">
                        @foreach($categoryMap as $key => $label)
                            <option value="{{ $key }}" {{ $key === 1 ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">优先级</label>
                    <select name="priority" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm">
                        @foreach($priorityMap as $key => $label)
                            <option value="{{ $key }}" {{ $key === 2 ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">问题描述</label>
                    <textarea name="content" required minlength="5" maxlength="2000" rows="5"
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-sm"
                        placeholder="请详细描述问题现象、发生时间、涉及的令牌/模型等信息，便于我们快速定位（5-2000 字）"></textarea>
                </div>
            </div>
            <button type="submit" class="px-6 py-2.5 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 transition">
                <i class="fas fa-paper-plane mr-1"></i>提交工单
            </button>
        </form>
        <div id="ticketResult" class="mt-4 hidden"></div>
    </div>

    {{-- 我的工单 --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-900">我的工单</h3>
        </div>
        @if($tickets->isEmpty())
            <div class="px-6 py-12 text-center text-gray-400 text-sm">
                <i class="fas fa-inbox text-3xl mb-3 block"></i>暂无工单，遇到问题请随时提交
            </div>
        @else
            <div class="divide-y divide-gray-100">
                @foreach($tickets as $ticket)
                    @php
                        $statusClass = match ($ticket->status) {
                            1 => 'bg-yellow-50 text-yellow-700 border-yellow-200',
                            2 => 'bg-green-50 text-green-700 border-green-200',
                            3 => 'bg-blue-50 text-blue-700 border-blue-200',
                            default => 'bg-gray-50 text-gray-500 border-gray-200',
                        };
                    @endphp
                    <a href="/tickets/{{ $ticket->id }}" class="block px-6 py-4 hover:bg-gray-50 transition">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate">{{ $ticket->subject }}</p>
                                <p class="text-xs text-gray-400 mt-1">
                                    {{ $categoryMap[$ticket->category] }} · 优先级：{{ $priorityMap[$ticket->priority] }}
                                    · 最近更新：{{ $ticket->last_reply_at ? date('Y-m-d H:i', $ticket->last_reply_at) : date('Y-m-d H:i', $ticket->created_at) }}
                                </p>
                            </div>
                            <span class="flex-shrink-0 px-2.5 py-1 text-xs font-medium rounded-full border {{ $statusClass }}">{{ $statusMap[$ticket->status] }}</span>
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
document.getElementById('ticketForm').onsubmit = function(e) {
    e.preventDefault();
    const payload = {
        subject: this.subject.value,
        category: Number(this.category.value),
        priority: Number(this.priority.value),
        content: this.content.value,
    };
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
            el.className = 'mt-4 p-4 bg-red-50 text-red-700 rounded-lg text-sm';
            el.textContent = data.message || '提交失败';
        }
    });
};
</script>
@endpush
