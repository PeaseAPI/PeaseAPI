@extends('layouts.dashboard')
@section('title', __('Performance') . ' - ' . config('app.name'))
@section('content')
<h4 class="mb-4">{{ __('Performance Monitor') }}</h4>

@auth
@if(auth()->user()->isRoot())
@php
$metrics = \App\Models\PerfMetric::orderByDesc('created_at')->limit(50)->get();
$stats = [
    'avg_response_time' => $metrics->avg('response_time'),
    'total_requests' => $metrics->sum('request_count'),
    'error_rate' => $metrics->avg('error_rate'),
    'cpu_avg' => $metrics->avg('cpu_usage'),
    'memory_avg' => $metrics->avg('memory_usage'),
];
@endphp

<div class="row mb-4">
    <div class="col-md-2">
        <div class="card text-center">
            <div class="card-body">
                <h5>{{ number_format($stats['avg_response_time'] ?? 0, 2) }}ms</h5>
                <small class="text-muted">{{ __('Avg Response') }}</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center">
            <div class="card-body">
                <h5>{{ number_format($stats['total_requests'] ?? 0) }}</h5>
                <small class="text-muted">{{ __('Total Requests') }}</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center">
            <div class="card-body">
                <h5>{{ number_format($stats['error_rate'] ?? 0, 2) }}%</h5>
                <small class="text-muted">{{ __('Error Rate') }}</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center">
            <div class="card-body">
                <h5>{{ number_format($stats['cpu_avg'] ?? 0, 1) }}%</h5>
                <small class="text-muted">{{ __('CPU Avg') }}</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center">
            <div class="card-body">
                <h5>{{ number_format($stats['memory_avg'] ?? 0, 1) }}%</h5>
                <small class="text-muted">{{ __('Memory Avg') }}</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center">
            <div class="card-body">
                <h5>{{ $metrics->count() }}</h5>
                <small class="text-muted">{{ __('Data Points') }}</small>
            </div>
        </div>
    </div>
</div>

<h5>{{ __('Recent Metrics') }}</h5>
<div class="table-responsive">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>{{ __('Time') }}</th>
                <th>{{ __('Requests') }}</th>
                <th>{{ __('Response') }}</th>
                <th>{{ __('Errors') }}</th>
                <th>{{ __('CPU') }}</th>
                <th>{{ __('Memory') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($metrics as $m)
            <tr>
                <td>{{ $m->created_at->format('H:i:s') }}</td>
                <td>{{ $m->request_count }}</td>
                <td>{{ number_format($m->response_time, 0) }}ms</td>
                <td>{{ number_format($m->error_rate, 1) }}%</td>
                <td>{{ number_format($m->cpu_usage, 1) }}%</td>
                <td>{{ number_format($m->memory_usage, 1) }}%</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="mt-3 d-flex gap-2">
    <form method="POST" action="{{ route('admin.performance.reset') }}">
        @csrf
        <button type="submit" class="btn btn-warning btn-sm">{{ __('Reset Stats') }}</button>
    </form>
    <form method="POST" action="{{ route('admin.performance.gc') }}">
        @csrf
        <button type="submit" class="btn btn-info btn-sm">{{ __('Force GC') }}</button>
    </form>
    <form method="POST" action="{{ route('admin.performance.clear_cache') }}">
        @csrf
        <button type="submit" class="btn btn-danger btn-sm">{{ __('Clear Cache') }}</button>
    </form>
</div>
@else
<div class="alert alert-danger">{{ __('Access denied. Root only.') }}</div>
@endif
@endauth
@endsection