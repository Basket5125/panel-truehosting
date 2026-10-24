@extends('layouts.main')

@section('page-title', __('Store'))

@section('content')
    <div class="th-page-head d-flex flex-wrap align-items-end justify-content-between" style="gap:1rem">
        <div>
            <h2 class="th-page-title">{{ __('Store') }}</h2>
            <p class="th-page-sub">
                {{ __('Credits are spent only while a server is running.') }}
            </p>
        </div>
        <button type="button" data-toggle="modal" data-target="#redeemVoucherModal" class="th-btn th-btn-ghost">
            <i class="fas fa-money-check-alt"></i>{{ __('Redeem code') }}
        </button>
    </div>

    @if ($isStoreEnabled && $shopProducts->count() > 0)
        @php
            // Highlight the mid-priced option: it is the one most people should pick.
            $featuredIndex = $shopProducts->count() > 2 ? (int) floor($shopProducts->count() / 2) : -1;
        @endphp

        <div class="th-grid">
            @foreach ($shopProducts as $index => $shopProduct)
                @php
                    $type = strtolower($shopProduct->type);
                    $icon = $type == 'credits' ? 'fa-coins' : ($type == 'server slots' ? 'fa-server' : 'fa-box');
                    $typeLabel = $type == 'credits' ? $credits_display_name : $shopProduct->type;
                @endphp

                <div class="th-plan {{ $index === $featuredIndex ? 'th-plan-featured' : '' }}">
                    @if ($index === $featuredIndex)
                        <span class="th-plan-tag">{{ __('Most popular') }}</span>
                    @endif

                    <span class="th-stat-icon mb-3"><i class="fas {{ $icon }}"></i></span>

                    <h3 class="th-plan-name">{{ $shopProduct->display }}</h3>
                    <p class="th-plan-desc">{{ $typeLabel }}</p>

                    <div class="th-plan-price">
                        <span class="th-plan-amount">
                            {{ Currency::formatToCurrency($shopProduct->price, $shopProduct->currency_code) }}
                        </span>
                    </div>
                    <p class="th-plan-note">{{ __('One-time payment') }}</p>

                    @if ($shopProduct->description)
                        <p class="th-plan-desc">{{ $shopProduct->description }}</p>
                    @endif

                    <div class="th-card-foot">
                        <a href="{{ route('checkout', $shopProduct->id) }}"
                           class="th-btn th-btn-primary th-btn-block @cannot('user.shop.buy') disabled @endcannot">
                            <i class="fas fa-shopping-cart"></i>{{ __('Purchase') }}
                        </a>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="text-muted mt-4" style="font-size:.82rem">
            <i class="fas fa-shield-alt mr-1"></i>
            {{ __('Credits land on your account as soon as the payment clears.') }}
        </p>
    @else
        <div class="th-empty">
            <i class="fas fa-store-slash d-block"></i>
            <h3 class="th-empty-title">
                @if ($shopProducts->count() == 0)
                    {{ __('There are no store shopProducts!') }}
                @else
                    {{ __('The store is not correctly configured!') }}
                @endif
            </h3>
            <p class="th-empty-text">
                {{ __('Check back later or ask us on Discord.') }}
            </p>
            <a href="{{ route('home') }}" class="th-btn th-btn-ghost">{{ __('Back') }}</a>
        </div>
    @endif
@endsection
