@extends('layouts.dashboard')
@section('title', __('Subscriptions') . ' - ' . config('app.name'))
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4>{{ __('My Subscriptions') }}</h4>
    <a href="{{ route('pricing') }}" class="btn btn-primary btn-sm">{{ __('View Plans') }}</a>
</div>

@auth
@php
$activeSubs = \App\Models\Subscription::where('user_id', auth()->id())
    ->where('status', 'active')->orderByDesc('created_at')->get();
@endphp

@if($activeSubs->count() > 0)
<div class="row">
    @foreach($activeSubs as $sub)
    <div class="col-md-6 mb-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="card-title">{{ $sub->plan->name ?? __('Subscription') }}</h5>
                <p class="badge bg-success">{{ __('Active') }}</p>
                <ul class="list-unstyled mt-2">
                    <li><strong>{{ __('Started') }}:</strong> {{ $sub->created_at->format('Y-m-d') }}</li>
                    <li><strong>{{ __('Expires') }}:</strong> {{ $sub->period_end ? date('Y-m-d', $sub->period_end) : __('N/A') }}</li>
                    <li><strong>{{ __('Remaining Quota') }}:</strong> {{ number_format($sub->remain_quota) }}</li>
                </ul>
            </div>
        </div>
    </div>
    @endforeach
</div>
@else
<div class="alert alert-info">
    {{ __('You have no active subscriptions.') }}
    <a href="{{ route('pricing') }}">{{ __('Browse plans') }}</a>
</div>
@endif

<h5 class="mt-4 mb-3">{{ __('Available Plans') }}</h5>
@php
$plans = \App\Models\SubscriptionPlan::where('status', 1)->orderBy('sort')->get();
@endphp
@if($plans->count() > 0)
<div class="row">
    @foreach($plans as $plan)
    <div class="col-md-4 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h6>{{ $plan->name }}</h6>
                <p class="text-primary fw-bold">
                    @if($plan->price > 0) ${{ number_format($plan->price, 2) }} @else {{ __('Free') }} @endif
                </p>
                <p class="text-muted small">{{ $plan->description }}</p>
                <form method="POST" action="{{ route('subscription.subscribe', $plan->id) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100">{{ __('Subscribe') }}</button>
                </form>
            </div>
        </div>
    </div>
    @endforeach
</div>
@endif
@endauth
@endsection