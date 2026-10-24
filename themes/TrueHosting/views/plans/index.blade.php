@extends('layouts.main')

@section('page-title', __('Build your server'))

@section('content')
    <div class="th-page-head">
        <h2 class="th-page-title">{{ __('Build your server') }}</h2>
        <p class="th-page-sub">{{ __('Pick a plan and set the resources yourself.') }}</p>
    </div>

    @if ($plans->isEmpty())
        <div class="th-empty">
            <i class="fas fa-sliders-h"></i>
            <h3 class="th-empty-title">{{ __('No configurable plans yet') }}</h3>
            <p class="th-empty-text">{{ __('Check back later or pick one of the ready-made packages.') }}</p>
            <a href="{{ route('servers.create') }}" class="th-btn th-btn-ghost">{{ __('Create Server') }}</a>
        </div>
    @else
        <div class="th-grid">
            @foreach ($plans as $plan)
                <a href="{{ route('plans.show', $plan->id) }}" class="th-plan">
                    <span class="th-stat-icon mb-3"><i class="fas fa-sliders-h"></i></span>
                    <h3 class="th-plan-name">{{ $plan->name }}</h3>
                    <p class="th-plan-desc">{{ $plan->description }}</p>

                    <div class="th-plan-price">
                        <span class="th-plan-amount">{{ Currency::formatForDisplay($plan->price_memory) }}</span>
                        <span class="th-plan-unit">{{ $credits_display_name }} / GB RAM</span>
                    </div>
                    <p class="th-plan-note">
                        {{ __('from') }} {{ $plan->memory_min / 1024 }} GB &middot; {{ $plan->billing_period }}
                    </p>

                    <div class="th-card-foot">
                        <span class="th-btn th-btn-primary th-btn-block">{{ __('Configure') }}</span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
@endsection
