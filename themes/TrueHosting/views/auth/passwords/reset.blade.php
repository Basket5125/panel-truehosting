@extends('layouts.app')

@section('content')
    <div class="th-auth-center">
        <div class="th-auth-card">
            <a href="{{ route('welcome') }}" class="th-auth-brand mb-4">
                <img class="th-auth-logo"
                     src="{{ \Illuminate\Support\Facades\Storage::disk('public')->exists('icon.png') ? asset('storage/icon.png') : asset('images/ctrlpanel_logo.png') }}"
                     alt="{{ config('app.name') }}">
                <span class="th-auth-brand-name">{{ config('app.name', 'TrueHosting') }}</span>
            </a>

            <h1 class="th-auth-card-title">{{ __('Change password') }}</h1>
            <p class="th-auth-card-sub">{{ __('Pick a new password for your account.') }}</p>

            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div class="th-field">
                    <label class="th-label" for="email">{{ __('Email') }}</label>
                    <div class="th-input-wrap">
                        <i class="fas fa-envelope"></i>
                        <input id="email" type="email" name="email" value="{{ $email ?? old('email') }}" required
                               autocomplete="email" autofocus
                               class="form-control @error('email') is-invalid @enderror"
                               placeholder="{{ __('your@email.com') }}">
                    </div>
                    @error('email')
                        <span class="text-danger"><small><strong>{{ $message }}</strong></small></span>
                    @enderror
                </div>

                <div class="th-field">
                    <label class="th-label" for="password">{{ __('Password') }}</label>
                    <div class="th-input-wrap">
                        <i class="fas fa-lock"></i>
                        <input id="password" type="password" name="password" required autocomplete="new-password"
                               class="form-control @error('password') is-invalid @enderror"
                               placeholder="••••••••">
                    </div>
                    @error('password')
                        <span class="text-danger"><small><strong>{{ $message }}</strong></small></span>
                    @enderror
                </div>

                <div class="th-field">
                    <label class="th-label" for="password_confirmation">{{ __('Retype password') }}</label>
                    <div class="th-input-wrap">
                        <i class="fas fa-lock"></i>
                        <input id="password_confirmation" type="password" name="password_confirmation" required
                               autocomplete="new-password" class="form-control" placeholder="••••••••">
                    </div>
                </div>

                <button type="submit" class="th-btn th-btn-primary th-btn-block">
                    {{ __('Change password') }}
                </button>

                <input type="hidden" name="_token" value="{{ csrf_token() }}">
            </form>

            <div class="th-auth-alt">
                <a href="{{ route('login') }}">{{ __('Back to login') }}</a>
            </div>

            @include('auth.passwords.legal')
        </div>
    </div>
@endsection
