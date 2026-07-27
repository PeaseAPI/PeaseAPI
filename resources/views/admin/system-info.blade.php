@extends('layouts.dashboard')
@section('title', __('System Info') . ' - ' . config('app.name'))
@section('content')
<h4 class="mb-4">{{ __('System Information') }}</h4>

@auth
@if(auth()->user()->isRoot())
@php
$instances = \App\Models\SystemInstance::orderByDesc('last_heartbeat')->limit(20)->get();
@endphp

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h3>{{ $instances->count() }}</h3>
                <small class="text-muted">{{ __('Total Instances') }}</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h3>{{ $instances->where('last_heartbeat', '>', time() - 300)->count() }}</h3>
                <small class="text-muted">{{ __('Online') }}</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h3>{{ $instances->where('last_heartbeat', '<=', time() - 300)->count() }}</h3>
                <small class="text-muted">{{ __('Offline') }}</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h3>{{ \App\Models\User::count() }}</h3>
                <small class="text-muted">{{ __('Total Users') }}</small>
            </div>
        </div>
    </div>
</div>

<h5>{{ __('System Instances') }}</h5>
<div class="table-responsive">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>{{ __('Node') }}</th>
                <th>{{ __('Status') }}</th>
                <th>{{ __('Last Heartbeat') }}</th>
                <th>{{ __('CPU') }}</th>
                <th>{{ __('Memory') }}</th>
                <th>{{ __('Actions') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($instances as $inst)
            <tr>
                <td>{{ $inst->node_name }}</td>
                <td>
                    @if($inst->last_heartbeat > time() - 300)
                    <span class="badge bg-success">{{ __('Online') }}</span>
                    @else
                    <span class="badge bg-secondary">{{ __('Offline') }}</span>
                    @endif
                </td>
                <td>{{ date('Y-m-d H:i:s', $inst->last_heartbeat) }}</td>
                <td>{{ $inst->cpu_usage ?? '-' }}</td>
                <td>{{ $inst->memory_usage ?? '-' }}</td>
                <td>
                    <form method="POST" action="{{ route('admin.system-instances.delete', $inst->node_name) }}" style="display:inline;">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Delete') }}</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

<form method="POST" action="{{ route('admin.system-instances.cleanup') }}" class="mt-3">
    @csrf
    <button type="submit" class="btn btn-warning btn-sm">{{ __('Clean Stale Instances') }}</button>
</form>
@else
<div class="alert alert-danger">{{ __('Access denied. Root only.') }}</div>
@endif
@endauth
@endsection