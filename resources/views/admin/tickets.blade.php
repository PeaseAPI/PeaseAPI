@extends('layouts.dashboard')
@section('title', '工单管理')

@section('content')
<div class="mb-6 flex items-center justify-between flex-wrap gap-3">
    <h2 class="text-lg font-semibold">工单管理</h2>
    <div class="flex gap-2 text-sm">
        @foreach(['pending' => '待处理', 'replied' => '已回复', 'closed' => '已关闭', 'all' => '全部'] as $key => $label)
            <a href="/admin/tickets?status={{ $key }}"
               class="px-3 py-1.5 rounded-lg border {{ $statusFilter === $key ? 'bg-primary-600 text-white border-primary-600' : 'bg-white text-gray-600 border-gray-200 hover:border-primary-400' }}">{{ $label }}</a>
        @endforeach
    </div>
</div>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500">
            <tr>
                <th class="px-4 py-3 text-left font-medium">ID</th>
                <th class="px-4 py-3 text-left font-medium">用户</th>
                <th class="px-4 py-3 text-left font-medium">主题</th>
                <th class="px-4 py-3 text-left font-medium">分类</th>
                <th class="px-4 py-3 text-left font-medium">优先级</th>
                <th class="px-4 py-3 text-left font-medium">状态</th>
                <th class="px-4 py-3 text-left font-medium">最近更新</th>
                <th class="px-4 py-3 text-left font-medium">操作</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($tickets as $ticket)
                @php
                    $statusClass = match ($ticket->status) {
                        1 => 'bg-yellow-50 text-yellow-700',
                        2 => 'bg-green-50 text-green-700',
                        3 => 'bg-blue-50 text-blue-700',
                        default => 'bg-gray-100 text-gray-500',
                    };
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-400">#{{ $ticket->id }}</td>
                    <td class="px-4 py-3">{{ $ticket->user?->email ?? ('UID '.$ticket->user_id) }}</td>
                    <td class="px-4 py-3 max-w-xs truncate">{{ $ticket->subject }}</td>
                    <td class="px-4 py-3 text-gray-500">{{ $categoryMap[$ticket->category] }}</td>
                    <td class="px-4 py-3 text-gray-500">{{ $priorityMap[$ticket->priority] }}</td>
                    <td class="px-4 py-3"><span class="px-2 py-0.5 text-xs rounded-full {{ $statusClass }}">{{ $statusMap[$ticket->status] }}</span></td>
                    <td class="px-4 py-3 text-gray-400 text-xs">{{ $ticket->last_reply_at ? date('m-d H:i', $ticket->last_reply_at) : '-' }}</td>
                    <td class="px-4 py-3">
                        <a href="/admin/tickets/{{ $ticket->id }}" class="text-primary-600 hover:underline text-xs font-medium">处理</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-12 text-center text-gray-400 text-sm">暂无工单</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
