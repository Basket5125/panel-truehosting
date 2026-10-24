@extends('layouts.app')

@section('content')
    @php
        $website_settings = app(App\Settings\WebsiteSettings::class);
        $user_settings = app(App\Settings\UserSettings::class);
        $referral_settings = app(App\Settings\ReferralSettings::class);
        $recaptchaVersion = app(App\Settings\GeneralSettings::class)->recaptcha_version;
    @endphp

    <div class="th-auth">

        <section class="th-auth-pitch">
            <a href="{{ route('welcome') }}" class="th-auth-brand">
                <img class="th-auth-logo"
                     src="{{ \Illuminate\Support\Facades\Storage::disk('public')->exists('icon.png') ? asset('storage/icon.png') : asset('images/ctrlpanel_logo.png') }}"
                     alt="{{ config('app.name') }}">
                <span class="th-auth-brand-name">{{ config('app.name', 'TrueHosting') }}</span>
            </a>

            <div class="th-auth-middle">
                <h2 class="th-auth-title">
                    {{ __('Your server,') }}<br>
                    <em>{{ __('running in a minute') }}</em>
                </h2>

                <p class="th-auth-lead">
                    {{ __('A free account, no commitment. Add credits whenever you want and launch your first server right away.') }}
                </p>

                <ul class="th-auth-points">
                    <li><i class="fas fa-gift"></i>{{ __('Free account, no setup fee') }}</li>
                    <li><i class="fas fa-rocket"></i>{{ __('Minecraft, Discord bots, BeamMP, GTA MTA') }}</li>
                    <li><i class="fas fa-shield-alt"></i>{{ __('DDoS protected infrastructure in Poland') }}</li>
                    <li><i class="fas fa-project-diagram"></i>{{ __('Cancel or upgrade at any time') }}</li>
                </ul>

                <div class="th-auth-stats">
                    <div>
                        <span class="th-auth-stat-value">150+</span>
                        <span class="th-auth-stat-label">{{ __('Happy players') }}</span>
                    </div>
                    <div>
                        <span class="th-auth-stat-value">99%</span>
                        <span class="th-auth-stat-label">{{ __('Uptime') }}</span>
                    </div>
                    <div>
                        <span class="th-auth-stat-value">24/7</span>
                        <span class="th-auth-stat-label">{{ __('Support') }}</span>
                    </div>
                </div>
            </div>

            <div class="th-auth-foot">
                <div class="th-auth-foot-links">
                    <a href="https://truehosting.net" target="_blank">{{ __('Website') }}</a>
                    @if ($website_settings->show_tos)
                        <a target="_blank" href="{{ route('terms', 'tos') }}">{{ __('Terms of Service') }}</a>
                    @endif
                    @if ($website_settings->show_privacy)
                        <a target="_blank" href="{{ route('terms', 'privacy') }}">{{ __('Privacy') }}</a>
                    @endif
                    @if ($website_settings->show_imprint)
                        <a target="_blank" href="{{ route('terms', 'imprint') }}">{{ __('Imprint') }}</a>
                    @endif
                </div>
                &copy; {{ date('Y') }} {{ config('app.name', 'TrueHosting') }}
            </div>
        </section>

        <section class="th-auth-form-wrap">
            <div class="th-auth-card">

                @if (!$user_settings->creation_enabled)
                    <h1 class="th-auth-card-title">{{ __('Registration closed') }}</h1>
                    <p class="th-auth-card-sub">
                        {{ __('The system administrator has blocked the registration of new users') }}
                    </p>
                    <a class="th-btn th-btn-primary th-btn-block" href="{{ route('login') }}">{{ __('Back') }}</a>
                @else
                    <h1 class="th-auth-card-title">{{ __('Create your account') }}</h1>
                    <p class="th-auth-card-sub">{{ __('An email and a password is all it takes.') }}</p>

                    <form method="POST" action="{{ route('register') }}">
                        @csrf

                        @error('ip')
                            <div class="alert alert-danger"><small><strong>{{ $message }}</strong></small></div>
                        @enderror

                        <div class="th-field">
                            <label class="th-label" for="name">{{ __('Username') }}</label>
                            <div class="th-input-wrap">
                                <i class="fas fa-user"></i>
                                <input id="name" type="text" name="name" value="{{ old('name') }}"
                                       autocomplete="username" autofocus
                                       class="form-control @error('name') is-invalid @enderror"
                                       placeholder="{{ __('Username') }}">
                            </div>
                            @error('name')
                                <span class="text-danger"><small><strong>{{ $message }}</strong></small></span>
                            @enderror
                        </div>

                        <div class="th-field">
                            <label class="th-label" for="email">{{ __('Email') }}</label>
                            <div class="th-input-wrap">
                                <i class="fas fa-envelope"></i>
                                <input id="email" type="email" name="email" value="{{ old('email') }}"
                                       autocomplete="email"
                                       class="form-control @error('email') is-invalid @enderror"
                                       placeholder="{{ __('your@email.com') }}">
                            </div>
                            @error('email')
                                <span class="text-danger"><small><strong>{{ $message }}</strong></small></span>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="th-field">
                                    <label class="th-label" for="password">{{ __('Password') }}</label>
                                    <div class="th-input-wrap">
                                        <i class="fas fa-lock"></i>
                                        <input id="password" type="password" name="password"
                                               autocomplete="new-password"
                                               class="form-control @error('password') is-invalid @enderror"
                                               placeholder="••••••••">
                                    </div>
                                    @error('password')
                                        <span class="text-danger"><small><strong>{{ $message }}</strong></small></span>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="th-field">
                                    <label class="th-label" for="password_confirmation">
                                        {{ __('Retype password') }}
                                    </label>
                                    <div class="th-input-wrap">
                                        <i class="fas fa-lock"></i>
                                        <input id="password_confirmation" type="password"
                                               name="password_confirmation" autocomplete="new-password"
                                               class="form-control" placeholder="••••••••">
                                    </div>
                                </div>
                            </div>
                        </div>

                        @if ($referral_settings->enabled)
                            <div class="th-field">
                                <label class="th-label" for="referral_code">
                                    {{ __('Referral code') }}
                                    <span style="font-weight:400;opacity:.6">({{ __('optional') }})</span>
                                </label>
                                <div class="th-input-wrap">
                                    <i class="fas fa-user-check"></i>
                                    <input id="referral_code" type="text" name="referral_code"
                                           value="{{ Request::get('ref') }}" class="form-control"
                                           placeholder="{{ __('Referral code') }}">
                                </div>
                            </div>
                        @endif

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

                        @if ($website_settings->show_tos)
                            <div class="custom-control custom-checkbox mb-3">
                                <input type="checkbox" class="custom-control-input" id="agreeTerms" name="terms"
                                       value="agree">
                                <label class="custom-control-label" for="agreeTerms">
                                    {{ __('I agree to the') }}
                                    <a target="_blank" href="{{ route('terms', 'tos') }}">{{ __('Terms of Service') }}</a>
                                </label>
                                @error('terms')
                                    <span class="text-danger d-block"><small><strong>{{ $message }}</strong></small></span>
                                @enderror
                            </div>
                        @endif

                        <button type="submit" class="th-btn th-btn-primary th-btn-block">
                            {{ __('Register') }} <i class="fas fa-arrow-right" style="font-size:.78rem"></i>
                        </button>

                        <input type="hidden" name="_token" value="{{ csrf_token() }}">
                    </form>

                    <div class="th-auth-alt">
                        {{ __('I already have a membership') }}
                        <a href="{{ route('login') }}"><strong>{{ __('Sign In') }}</strong></a>
                    </div>
                @endif
            </div>
        </section>
    </div>
@endsection
