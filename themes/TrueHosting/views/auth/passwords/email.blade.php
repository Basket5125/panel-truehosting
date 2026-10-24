@extends('layouts.app')

@section('content')
    @php
        $website_settings = app(App\Settings\WebsiteSettings::class);
        $recaptchaVersion = app(App\Settings\GeneralSettings::class)->recaptcha_version;
    @endphp

    <div class="th-auth-center">
        <div class="th-auth-card">
            <a href="{{ route('welcome') }}" class="th-auth-brand mb-4">
                <img class="th-auth-logo"
                     src="{{ \Illuminate\Support\Facades\Storage::disk('public')->exists('icon.png') ? asset('storage/icon.png') : asset('images/ctrlpanel_logo.png') }}"
                     alt="{{ config('app.name') }}">
                <span class="th-auth-brand-name">{{ config('app.name', 'TrueHosting') }}</span>
            </a>

            <h1 class="th-auth-card-title">{{ __('Forgot Your Password?') }}</h1>
            <p class="th-auth-card-sub">
                {{ __('You forgot your password? Here you can easily retrieve a new password.') }}
            </p>

            @if (session('status'))
                <div class="alert alert-success" role="alert">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('password.email') }}">
                @csrf

                <div class="th-field">
                    <label class="th-label" for="email">{{ __('Email') }}</label>
                    <div class="th-input-wrap">
                        <i class="fas fa-envelope"></i>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required
                               autocomplete="email" autofocus
                               class="form-control @error('email') is-invalid @enderror"
                               placeholder="{{ __('your@email.com') }}">
                    </div>
                    @error('email')
                        <span class="text-danger"><small><strong>{{ $message }}</strong></small></span>
                    @enderror
                </div>

                @if ($recaptchaVersion)
                    <div class="th-field">
                        @switch($recaptchaVersion)
                            @case("v2")
                                {!! htmlFormSnippet() !!}
                                @break
                            @case("v3")
                                {!! RecaptchaV3::field('recaptchathree') !!}
                                @break
                            @case("turnstile")
                                <x-turnstile-widget theme="dark" language="en-us" size="normal"/>
                                @error('cf-turnstile-response')
                                    <span class="text-danger"><small><strong>{{ $message }}</strong></small></span>
                                @enderror
                                @break
                        @endswitch
                        @error('g-recaptcha-response')
                            <span class="text-danger"><small><strong>{{ $message }}</strong></small></span>
                        @enderror
                    </div>
                @endif

                <button type="submit" class="th-btn th-btn-primary th-btn-block">
                    {{ __('Request new password') }}
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
