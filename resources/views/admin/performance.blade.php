@extends('layouts.dashboard')
@section('title', '性能监控')

@section('content')
@php
    // perf_metrics 为分钟级聚合表（bucket_ts 秒级时间戳），无 created_at/响应时间等明细列，
    // 全部指标由聚合列派生：平均延迟 = total_latency_ms / request_count，成功率 = success_count / request_count
    $windowTs = now()->subDays(7)->getTimestamp();
    $agg = \App\Models\PerfMetric::where('bucket_ts', '>=', $windowTs)
        ->selectRaw('COALESCE(SUM(request_count), 0) AS total_requests')
        ->selectRaw('COALESCE(SUM(success_count), 0) AS success_count')
        ->selectRaw('COALESCE(SUM(total_latency_ms), 0) AS total_latency_ms')
        ->selectRaw('COALESCE(SUM(ttft_sum_ms), 0) AS ttft_sum_ms')
        ->selectRaw('COALESCE(SUM(ttft_count), 0) AS ttft_count')
        ->selectRaw('COALESCE(SUM(output_tokens), 0) AS output_tokens')
        ->selectRaw('COUNT(*) AS points')
        ->first();
    $totalRequests = (int) $agg->total_requests;
    $successCount = (int) $agg->success_count;
    $avgLatency = $totalRequests > 0 ? $agg->total_latency_ms / $totalRequests : 0;
    $successRate = $totalRequests > 0 ? $successCount / $totalRequests * 100 : 100;
    $errorRate = max(0, 100 - $successRate);
    $avgTtft = (int) $agg->ttft_count > 0 ? $agg->ttft_sum_ms / $agg->ttft_count : 0;
    $recent = \App\Models\PerfMetric::where('bucket_ts', '>=', $windowTs)
        ->orderByDesc('bucket_ts')->limit(50)->get();
@endphp

<div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-xl font-bold text-gray-900">性能监控</h2>
            <p class="text-sm text-gray-500 mt-0.5">近 7 天聚合指标（分钟级桶），共 {{ number_format((int) $agg->points) }} 个数据点</p>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-6 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="w-10 h-10 bg-orange-100 text-orange-600 rounded-lg flex items-center justify-center mb-3"><i class="fas fa-chart-bar"></i></div>
            <p class="text-xl font-bold text-gray-900">{{ number_format($totalRequests) }}</p>
            <p class="text-xs text-gray-500 mt-1">总请求（7 天）</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="w-10 h-10 bg-sky-100 text-sky-600 rounded-lg flex items-center justify-center mb-3"><i class="fas fa-gauge-high"></i></div>
            <p class="text-xl font-bold text-gray-900">{{ number_format($avgLatency, 0) }}<span class="text-sm font-normal text-gray-400">ms</span></p>
            <p class="text-xs text-gray-500 mt-1">平均总延迟</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="w-10 h-10 bg-emerald-100 text-emerald-600 rounded-lg flex items-center justify-center mb-3"><i class="fas fa-circle-check"></i></div>
            <p class="text-xl font-bold {{ $successRate >= 99 ? 'text-emerald-600' : 'text-gray-900' }}">{{ number_format($successRate, 2) }}%</p>
            <p class="text-xs text-gray-500 mt-1">成功率</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="w-10 h-10 {{ $errorRate > 1 ? 'bg-red-100 text-red-600' : 'bg-gray-100 text-gray-500' }} rounded-lg flex items-center justify-center mb-3"><i class="fas fa-triangle-exclamation"></i></div>
            <p class="text-xl font-bold {{ $errorRate > 1 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($errorRate, 2) }}%</p>
            <p class="text-xs text-gray-500 mt-1">错误率</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="w-10 h-10 bg-indigo-100 text-indigo-600 rounded-lg flex items-center justify-center mb-3"><i class="fas fa-bolt"></i></div>
            <p class="text-xl font-bold text-gray-900">{{ number_format($avgTtft, 0) }}<span class="text-sm font-normal text-gray-400">ms</span></p>
            <p class="text-xs text-gray-500 mt-1">首字延迟 TTFT</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="w-10 h-10 bg-purple-100 text-purple-600 rounded-lg flex items-center justify-center mb-3"><i class="fas fa-database"></i></div>
            <p class="text-xl font-bold text-gray-900">{{ number_format((int) $agg->output_tokens) }}</p>
            <p class="text-xs text-gray-500 mt-1">输出 Tokens</p>
        </div>
    </div>


    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">PHP 运行环境</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-gray-500">PHP 版本</dt><dd class="font-medium text-gray-900">{{ PHP_VERSION }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">SAPI</dt><dd class="font-medium text-gray-900">{{ PHP_SAPI }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">内存占用</dt><dd class="font-medium text-gray-900">{{ number_format(memory_get_usage(true) / 1048576, 1) }} MB</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">内存峰值</dt><dd class="font-medium text-gray-900">{{ number_format(memory_get_peak_usage(true) / 1048576, 1) }} MB</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">OPcache</dt><dd class="font-medium {{ function_exists('opcache_get_status') && opcache_get_status() !== false ? 'text-emerald-600' : 'text-gray-400' }}">{{ function_exists('opcache_get_status') && opcache_get_status() !== false ? '已启用' : '未启用' }}</dd></div>
            </dl>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">维护操作</h3>
            <p class="text-xs text-gray-400 mb-4">以下操作立即生效，请谨慎执行。</p>
            <div class="flex flex-wrap gap-3">
                <button type="button" data-perf-action="{{ route('admin.performance.reset') }}" data-confirm="确认重置全部性能统计？此操作不可恢复。" class="perf-btn px-4 py-2.5 bg-amber-500 text-white rounded-lg hover:bg-amber-600 transition text-sm font-medium disabled:opacity-60"><i class="fas fa-rotate-left mr-1.5"></i>重置统计</button>
                <button type="button" data-perf-action="{{ route('admin.performance.gc') }}" class="perf-btn px-4 py-2.5 bg-sky-500 text-white rounded-lg hover:bg-sky-600 transition text-sm font-medium disabled:opacity-60"><i class="fas fa-recycle mr-1.5"></i>强制 GC</button>
                <button type="button" data-perf-action="{{ route('admin.performance.clear_cache') }}" data-confirm="确认清理应用缓存（含编译视图）？" class="perf-btn px-4 py-2.5 bg-red-500 text-white rounded-lg hover:bg-red-600 transition text-sm font-medium disabled:opacity-60"><i class="fas fa-broom mr-1.5"></i>清理缓存</button>
            </div>
            <p id="perfMsg" class="hidden mt-4 text-sm px-4 py-3 rounded-lg bg-emerald-50 text-emerald-700"></p>
        </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100"><h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">最近聚合桶（Top 50）</h3></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs font-medium text-gray-500 uppercase border-b bg-gray-50">
                    <th class="px-6 py-3">时间</th><th class="px-6 py-3">请求数</th><th class="px-6 py-3">成功</th><th class="px-6 py-3">平均延迟</th><th class="px-6 py-3">TTFT</th><th class="px-6 py-3">输出 Tokens</th>
                </tr></thead>
                <tbody>
                @forelse($recent as $m)
                    @php $req = max(1, (int) $m->request_count); @endphp
                    <tr class="border-b border-gray-100 hover:bg-gray-50">
                        <td class="px-6 py-3 text-gray-500">{{ \Illuminate\Support\Carbon::createFromTimestamp((int) $m->bucket_ts)->format('m-d H:i') }}</td>
                        <td class="px-6 py-3 font-medium">{{ number_format((int) $m->request_count) }}</td>
                        <td class="px-6 py-3">{{ number_format((int) $m->success_count) }}</td>
                        <td class="px-6 py-3">{{ number_format($m->total_latency_ms / $req, 0) }} ms</td>
                        <td class="px-6 py-3">{{ (int) $m->ttft_count > 0 ? number_format($m->ttft_sum_ms / $m->ttft_count, 0).' ms' : '—' }}</td>
                        <td class="px-6 py-3">{{ number_format((int) $m->output_tokens) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-10 text-center text-gray-400"><i class="fas fa-chart-line text-3xl mb-2 block opacity-30"></i>近 7 天暂无性能数据（指标在转发请求时采集）</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

    </div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('.perf-btn').forEach(function(btn) {
    btn.addEventListener('click', async function() {
        if (btn.dataset.confirm && !confirm(btn.dataset.confirm)) return;
        btn.disabled = true;
        try {
            const res = await fetch(btn.dataset.perfAction, { credentials: 'same-origin',
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
            });
            const data = await res.json();
            const msg = document.getElementById('perfMsg');
            msg.textContent = data.message || (data.success ? '操作成功' : '操作失败');
            msg.classList.remove('hidden');
            msg.classList.toggle('bg-emerald-50', data.success !== false);
            msg.classList.toggle('text-emerald-700', data.success !== false);
            msg.classList.toggle('bg-red-50', data.success === false);
            msg.classList.toggle('text-red-700', data.success === false);
        } catch (err) {
            alert('请求出错：' + err.message);
        }
        btn.disabled = false;
    });
});
</script>
@endpush
