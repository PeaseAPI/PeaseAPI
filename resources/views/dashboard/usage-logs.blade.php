@extends('layouts.dashboard')
@section('title', __('Usage Logs') . ' - ' . config('app.name'))
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4>{{ __('Usage Logs') }}</h4>
    <form method="GET" class="d-flex gap-2" style="max-width: 400px;">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="{{ __('Search model/keyword') }}" value="{{ request('search') }}">
        <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('Search') }}</button>
    </form>
</div>

@auth
@php
$query = \App\Models\Log::where('user_id', auth()->id());
if ($search = request('search')) {
    $query->where('model_name', 'like', "%{$search}%");
}
$logs = $query->orderByDesc('created_at')->paginate(20);
@endphp

@if($logs->count() > 0)
<div class="table-responsive">
    <table class="table table-striped table-sm">
        <thead>
            <tr>
                <th>{{ __('Time') }}</th>
                <th>{{ __('Model') }}</th>
                <th>{{ __('Tokens') }}</th>
                <th>{{ __('Quota') }}</th>
                <th>{{ __('Channel') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($logs as $log)
            <tr>
                <td>{{ $log->created_at->format('m-d H:i') }}</td>
                <td><code>{{ $log->model_name }}</code></td>
                <td>{{ $log->prompt_tokens }}/{{ $log->completion_tokens }}</td>
                <td>{{ number_format($log->quota) }}</td>
                <td>{{ $log->channel_id }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
{{ $logs->withQueryString()->links() }}
@else
<p class="text-muted">{{ __('No usage logs found.') }}</p>
@endif
@endauth
@endsection