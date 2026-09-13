@extends('layouts.dashboard')
@section('title', '订阅订单')

@section('content')
@php
    $filters = [
        'all' => ['label' => '全部订单', 'icon' => 'fa-layer-group', 'tone' => 'text-primary-500', 'hint' => '所有订阅订单'],
        'paid' => ['label' => '已支付', 'icon' => 'fa-circle-check', 'tone' => 'text-emerald-500', 'hint' => '已完成履约'],
        'pending' => ['label' => '待支付', 'icon' => 'fa-hourglass-half', 'tone' => 'text-amber-500', 'hint' => '等待支付'],
        'cancelled' => ['label' => '已取消', 'icon' => 'fa-ban', 'tone' => 'text-gray-400', 'hint' => '超时取消（迟到支付仍履约）'],
    ];
    $statusStyles = [
        0 => ['pill' => 'bg-amber-50 text-amber-700 border-amber-200', 'icon' => 'fa-hourglass-half', 'label' => '待支付'],
        1 => ['pill' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'icon' => 'fa-circle-check', 'label' => '已支付'],
        2 => ['pill' => 'bg-gray-100 text-gray-500 border-gray-200', 'icon' => 'fa-ban', 'label' => '已取消'],
    ];
@endphp
<div class="mb-6">
    <h2 class="text-lg font-semibold">订阅订单</h2>
    <p class="text-xs text-gray-400 mt-1">subscription_orders 查询：支付状态、履约周期与流水号（最近 200 条）</p>
</div>
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
    @foreach($filters as $key => $meta)
        <a href="/admin/subscription-orders?status={{ $key }}"
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
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50/80 text-gray-400 text-xs uppercase tracking-wider">
            <tr>
                <th class="px-4 py-3 text-left font-medium">ID</th>
                <th class="px-4 py-3 text-left font-medium">用户</th>
                <th class="px-4 py-3 text-left font-medium">套餐</th>
                <th class="px-4 py-3 text-left font-medium">流水号</th>
                <th class="px-4 py-3 text-left font-medium">金额</th>
                <th class="px-4 py-3 text-left font-medium">状态</th>
                <th class="px-4 py-3 text-left font-medium">支付时间</th>
                <th class="px-4 py-3 text-left font-medium">履约周期</th>
                <th class="px-4 py-3 text-left font-medium">创建时间</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($orders as $order)
                @php($s = $statusStyles[$order->status] ?? $statusStyles[0])
                <tr class="hover:bg-gray-50/70">
                    <td class="px-4 py-3 text-gray-400">#{{ $order->id }}</td>
                    <td class="px-4 py-3">
                        <p class="text-gray-700">{{ $order->user?->email ?? '—' }}</p>
                        <p class="text-xs text-gray-400">UID {{ $order->user_id }}</p>
                    </td>
                    <td class="px-4 py-3 text-gray-700">{{ $order->plan?->name ?? ('#' . $order->plan_id) }}</td>
                    <td class="px-4 py-3">
                        <p class="font-mono text-xs text-gray-600">{{ $order->trade_no }}</p>
                        <p class="text-[11px] text-gray-400">{{ trim(($order->payment_provider ?? '').' '.($order->payment_method ?? '')) ?: '—' }}</p>
                    </td>
                    <td class="px-4 py-3 font-medium text-gray-900 whitespace-nowrap">{{ number_format((float) $order->amount, 2) }} <span class="text-xs text-gray-400">{{ $order->currency ?? 'CNY' }}</span></td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-0.5 text-xs rounded-full border {{ $s['pill'] }}"><i class="fas {{ $s['icon'] }} mr-1"></i>{{ $s['label'] }}</span>
                        @if($order->status === 2 && $order->cancelled_at)
                            <p class="text-[11px] text-gray-400 mt-1">{{ date('m-d H:i', $order->cancelled_at) }} 取消</p>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-500 text-xs whitespace-nowrap">{{ $order->status === 1 && $order->paid_at ? date('m-d H:i', $order->paid_at) : '—' }}</td>
                    <td class="px-4 py-3 text-gray-500 text-xs whitespace-nowrap">{{ $order->period_start && $order->period_end ? date('m-d', $order->period_start).' ~ '.date('m-d', $order->period_end) : '—' }}</td>
                    <td class="px-4 py-3 text-gray-400 text-xs whitespace-nowrap">{{ $order->created_at ? date('Y-m-d H:i', $order->created_at) : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-14 text-center">
                    <div class="w-12 h-12 mx-auto rounded-2xl bg-gray-50 flex items-center justify-center mb-3"><i class="fas fa-inbox text-xl text-gray-300"></i></div>
                    <p class="text-gray-400 text-sm">该筛选下暂无订阅订单</p>
                </td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection