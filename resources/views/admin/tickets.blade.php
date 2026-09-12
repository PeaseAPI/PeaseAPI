@extends('layouts.dashboard')
@section('title', '工单管理')

@section('content')
@php
    $statusStyles = [
        1 => ['pill' => 'bg-amber-50 text-amber-700 border-amber-200', 'icon' => 'fa-clock', 'bar' => 'border-l-amber-400'],
        2 => ['pill' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'icon' => 'fa-comment-dots', 'bar' => 'border-l-emerald-400'],
        3 => ['pill' => 'bg-sky-50 text-sky-700 border-sky-200', 'icon' => 'fa-rotate-left', 'bar' => 'border-l-sky-400'],
        4 => ['pill' => 'bg-gray-50 text-gray-500 border-gray-200', 'icon' => 'fa-lock', 'bar' => 'border-l-gray-200'],
    ];
    $priorityMeta = [
        1 => ['pill' => 'bg-gray-100 text-gray-500 border-gray-200', 'icon' => 'fa-arrow-down'],
        2 => ['pill' => 'bg-amber-50 text-amber-700 border-amber-200', 'icon' => 'fa-equals'],
        3 => ['pill' => 'bg-red-50 text-red-700 border-red-200', 'icon' => 'fa-arrow-up'],
    ];
    $filters = [
        'pending' => ['label' => '待处理', 'icon' => 'fa-circle-notch', 'tone' => 'text-amber-500', 'hint' => '含用户追回复'],
        'replied' => ['label' => '已回复', 'icon' => 'fa-comment-dots', 'tone' => 'text-emerald-500', 'hint' => '等待用户确认'],
        'closed' => ['label' => '已关闭', 'icon' => 'fa-lock', 'tone' => 'text-gray-400', 'hint' => '已完结工单'],
        'all' => ['label' => '全部', 'icon' => 'fa-layer-group', 'tone' => 'text-primary-500', 'hint' => '所有工单'],
    ];
@endphp
<div class="mb-6">
    <h2 class="text-lg font-semibold">工单管理</h2>
    <p class="text-xs text-gray-400 mt-1">处理用户提交的工单：回复、关闭与重开</p>
</div>
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
    @foreach($filters as $key => $meta)
        <a href="/admin/tickets?status={{ $key }}"
           class="bg-white rounded-xl shadow-sm border p-4 transition hover:shadow-md {{ $statusFilter === $key ? 'border-primary-400 ring-2 ring-primary-500/20' : 'border-gray-100 hover:border-gray-200' }}">
            <div class="flex items-center justify-between">
                <span class="text-xs text-gray-400">{{ $meta['label'] }}</span>
                <i class="fas {{ $meta['icon'] }} {{ $meta['tone'] }}"></i>
            </div>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ $counts[$key] }}</p>
            <p class="text-[11px] text-gray-400 mt-0.5">{{ $meta['hint'] }}</p>
        </a>
    @endforeach
</div>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50/80 text-gray-400 text-xs uppercase tracking-wider">
            <tr>
                <th class="px-4 py-3 text-left font-medium">ID</th>
                <th class="px-4 py-3 text-left font-medium">用户</th>
                <th class="px-4 py-3 text-left font-medium">主题</th>
                <th class="px-4 py-3 text-left font-medium">分类</th>
                <th class="px-4 py-3 text-left font-medium">优先级</th>
                <th class="px-4 py-3 text-left font-medium">状态</th>
                <th class="px-4 py-3 text-left font-medium">最近更新</th>
                <th class="px-4 py-3 text-right font-medium">操作</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($tickets as $ticket)
                @php($s = $statusStyles[$ticket->status])
                <tr class="hover:bg-gray-50/70 border-l-4 {{ $s['bar'] }}">
                    <td class="px-4 py-3 text-gray-400">#{{ $ticket->id }}</td>
                    <td class="px-4 py-3">
                        <p class="text-gray-700">{{ $ticket->user?->email ?? '—' }}</p>
                        <p class="text-xs text-gray-400">UID {{ $ticket->user_id }}</p>
                    </td>
                    <td class="px-4 py-3 max-w-[16rem]">
                        <p class="truncate font-medium text-gray-900">{{ $ticket->subject }}</p>
                        <p class="text-xs text-gray-400"><i class="far fa-comment mr-0.5"></i>{{ $ticket->replies_count }} 条对话</p>
                    </td>
                    <td class="px-4 py-3"><span class="px-2 py-0.5 rounded bg-gray-100 text-gray-500 text-xs">{{ $categoryMap[$ticket->category] }}</span></td>
                    <td class="px-4 py-3"><span class="px-2 py-0.5 text-xs rounded-full border {{ $priorityMeta[$ticket->priority]['pill'] }}"><i class="fas {{ $priorityMeta[$ticket->priority]['icon'] }} mr-1"></i>{{ $priorityMap[$ticket->priority] }}</span></td>
                    <td class="px-4 py-3"><span class="px-2 py-0.5 text-xs rounded-full border {{ $s['pill'] }}"><i class="fas {{ $s['icon'] }} mr-1"></i>{{ $statusMap[$ticket->status] }}</span></td>
                    <td class="px-4 py-3 text-gray-400 text-xs">{{ $ticket->last_reply_at ? date('m-d H:i', $ticket->last_reply_at) : '-' }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="/admin/tickets/{{ $ticket->id }}" class="inline-block px-3 py-1.5 rounded-lg bg-primary-50 text-primary-600 hover:bg-primary-100 text-xs font-medium transition">处理 <i class="fas fa-chevron-right ml-0.5 text-[10px]"></i></a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-14 text-center">
                    <div class="w-12 h-12 mx-auto rounded-2xl bg-gray-50 flex items-center justify-center mb-3"><i class="fas fa-inbox text-xl text-gray-300"></i></div>
                    <p class="text-gray-400 text-sm">该分类下暂无工单</p>
                </td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
