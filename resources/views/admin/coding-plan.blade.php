@extends('layouts.dashboard')
@section('title', __('Coding Plan 池可观测') . ' - ' . config('app.name'))
@section('content')
<h4 class="mb-4">Coding Plan 池可观测</h4>

{{-- ① 账号池概览（按供应商聚合） --}}
<div class="card mb-4">
    <div class="card-header bg-white"><strong>账号池概览（按供应商）</strong></div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead><tr><th>Vendor</th><th>账号总数</th><th>可用</th><th>冷却中</th><th>耗尽</th><th>停用</th></tr></thead>
            <tbody>
            @forelse($poolOverview as $row)
                <tr>
                    <td><span class="badge bg-primary">{{ $row->vendor }}</span></td>
                    <td>{{ $row->total }}</td>
                    <td class="text-success fw-bold">{{ $row->available }}</td>
                    <td class="text-warning fw-bold">{{ $row->cooling }}</td>
                    <td class="text-danger fw-bold">{{ $row->exhausted }}</td>
                    <td class="text-muted">{{ $row->disabled }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-3">暂无账号</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- ② 冷却倒计时（账号级 + 渠道级） --}}
<div class="row mb-4">
    <div class="col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-header bg-white"><strong>账号冷却倒计时</strong></div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>Vendor</th><th>账号</th><th>状态</th><th>恢复倒计时</th></tr></thead>
                    <tbody>
                    @forelse($coolingAccounts as $acc)
                        <tr>
                            <td>{{ $acc->vendor }}</td>
                            <td>{{ $acc->account_name }}</td>
                            <td><span class="badge {{ (int) $acc->status === 2 ? 'bg-danger' : 'bg-warning text-dark' }}">{{ (int) $acc->status === 2 ? '耗尽' : '冷却' }}</span></td>
                            <td><span class="cd fw-bold" data-deadline="{{ $acc->cooldown_until }}">—</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">无冷却中账号</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-header bg-white"><strong>渠道冷却倒计时</strong></div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>渠道 ID</th><th>渠道名称</th><th>恢复倒计时</th></tr></thead>
                    <tbody>
                    @forelse($coolingChannels as $ch)
                        <tr>
                            <td>#{{ $ch->id }}</td>
                            <td>{{ $ch->name }}</td>
                            <td><span class="cd fw-bold" data-deadline="{{ $ch->cooldown_until }}">—</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted py-3">无冷却中渠道</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- ③ 路由决策统计（近 7 天） --}}
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card text-center h-100">
            <div class="card-body">
                <h5 class="mb-0">{{ $routeStats['failover_count'] }}</h5>
                <small class="text-muted">近 7 天跨源 failover</small>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-header bg-white"><strong>落选原因 / 承接 Vendor 分布</strong></div>
            <div class="card-body">
                @forelse($routeStats['by_reason'] as $reason => $count)
                    <span class="badge bg-secondary me-2 mb-1">{{ $reason }} × {{ $count }}</span>
                @empty
                    <span class="text-muted">近 7 天无路由决策记录（调度顺畅，无池耗尽触发 failover）</span>
                @endforelse
                @foreach($routeStats['by_final_vendor'] as $vendor => $count)
                    <span class="badge bg-info text-dark me-2 mb-1">→ {{ $vendor }} × {{ $count }}</span>
                @endforeach
            </div>
        </div>
    </div>
</div>

{{-- 最近路由决策明细（含落选候选成本） --}}
<div class="card mb-4">
    <div class="card-header bg-white"><strong>最近路由决策明细（含落选候选成本）</strong></div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead><tr><th>时间</th><th>模型</th><th>策略</th><th>失败渠道</th><th>最终渠道</th><th>承接 Vendor</th><th>落选候选（渠道 / Vendor / 成本 / 原因）</th></tr></thead>
            <tbody>
            @forelse($routeStats['recent'] as $item)
                <tr>
                    <td class="text-nowrap">{{ date('m-d H:i', $item['time']) }}</td>
                    <td class="text-break">{{ $item['model'] }}</td>
                    <td>{{ $item['strategy'] }}</td>
                    <td>#{{ $item['failed_channel_id'] }}</td>
                    <td>#{{ $item['final_channel_id'] }}</td>
                    <td>{{ $item['vendor'] }}</td>
                    <td>
                        @foreach($item['attempted'] as $att)
                            <div class="small mb-1">
                                <span class="fw-bold">#{{ $att['channel_id'] ?? '-' }}</span>
                                {{ $att['vendor'] ?? '—' }}
                                @if(isset($att['cost_per_1k']) && $att['cost_per_1k'] !== null)
                                    <span class="text-success">¥{{ number_format((float) $att['cost_per_1k'], 4) }}/1k</span>
                                @else
                                    <span class="text-muted">成本—</span>
                                @endif
                                <span class="badge bg-light text-dark border">{{ $att['reason'] ?? '-' }}</span>
                            </div>
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-3">近 7 天无跨源 failover（首选源供给顺畅）</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    function renderCountdowns() {
        document.querySelectorAll('.cd').forEach(function (el) {
            var deadline = parseInt(el.getAttribute('data-deadline'), 10) * 1000;
            var remain = Math.max(0, Math.floor((deadline - Date.now()) / 1000));
            if (remain <= 0) {
                el.textContent = '已恢复';
                el.classList.add('text-success');
                return;
            }
            var h = Math.floor(remain / 3600);
            var m = Math.floor((remain % 3600) / 60);
            var s = remain % 60;
            el.textContent = (h > 0 ? h + 'h ' : '') + m + 'm ' + s + 's';
        });
    }
    renderCountdowns();
    setInterval(renderCountdowns, 1000);
})();
</script>
@endsection
