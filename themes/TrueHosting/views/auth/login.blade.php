@extends('layouts.app')

@section('content')
    @php
        $website_settings = app(App\Settings\WebsiteSettings::class);
        $recaptchaVersion = app(App\Settings\GeneralSettings::class)->recaptcha_version;

        // Rotating pitch: a different headline on every visit to the login page.
        $taglines = [
            [
                'title'  => __('Game hosting'),
                'accent' => __('of the next generation'),
                'lead'   => __('Deploy a server in seconds, pay only for what you use and manage everything from one panel.'),
            ],
            [
                'title'  => __('Your server,'),
                'accent' => __('without limits'),
                'lead'   => __('More RAM, more slots, more players. Scale whenever you need to, without reinstalling anything.'),
            ],
            [
                'title'  => __('Launch a server'),
                'accent' => __('in 60 seconds'),
                'lead'   => __('Pick a plan, confirm, play. No activation queue and no waiting for a technician.'),
            ],
            [
                'title'  => __('Minecraft without'),
                'accent' => __('lag or limits'),
                'lead'   => __('NVMe disks, modern CPUs and DDoS protection, so your server holds up on a busy Friday night.'),
            ],
            [
                'title'  => __('Power that'),
                'accent' => __('never lets you down'),
                'lead'   => __('Infrastructure in Poland, 99% uptime and support on Discord that actually answers.'),
            ],
        ];
        $tagline = $taglines[array_rand($taglines)];
    @endphp

    <div class="th-auth">

        {{-- ---------- Left: the pitch ---------- --}}
        <section class="th-auth-pitch">
            <a href="{{ route('welcome') }}" class="th-auth-brand">
                <img class="th-auth-logo"
                     src="{{ \Illuminate\Support\Facades\Storage::disk('public')->exists('icon.png') ? asset('storage/icon.png') : asset('images/ctrlpanel_logo.png') }}"
                     alt="{{ config('app.name') }}">
                <span class="th-auth-brand-name">{{ config('app.name', 'TrueHosting') }}</span>
            </a>

            <div class="th-auth-middle">
                <h2 class="th-auth-title">
                    {{ $tagline['title'] }}<br>
                    <em>{{ $tagline['accent'] }}</em>
                </h2>

                <p class="th-auth-lead">{{ $tagline['lead'] }}</p>

                <ul class="th-auth-points">
                    <li><i class="fas fa-bolt"></i>{{ __('Instant deployment, no waiting for activation') }}</li>
                    <li><i class="fas fa-sliders-h"></i>{{ __('Full control: files, FTP, database and console') }}</li>
                    <li><i class="fas fa-headset"></i>{{ __('Support on Discord, 24/7') }}</li>
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

        {{-- ---------- Right: the form ---------- --}}
        <section class="th-auth-form-wrap">
            <div class="th-auth-card">

                <h1 class="th-auth-card-title">{{ __('Welcome back') }}</h1>
                <p class="th-auth-card-sub">{{ __('Sign in to manage your servers') }}</p>

                @if (session('message'))
                    <div class="alert alert-danger">{{ session('message') }}</div>
                @endif

                <form action="{{ route('login') }}" method="post">
                    @csrf

                    <div class="th-field">
                        <label class="th-label" for="email">{{ __('Email or Username') }}</label>
                        <div class="th-input-wrap">
                            <i class="fas fa-envelope"></i>
                            <input id="email" type="text" name="email" value="{{ old('email') }}"
                                   autocomplete="username" autofocus
                                   class="form-control @error('email') is-invalid @enderror @error('name') is-invalid @enderror"
                                   placeholder="{{ __('your@email.com') }}">
                        </div>
                        @if ($errors->get('email') || $errors->get('name'))
                            <span class="text-danger" role="alert">
                                <small><strong>{{ $errors->first('email') ?: $errors->first('name') }}</strong></small>
                            </span>
                        @endif
                    </div>

                    <div class="th-field">
                        <label class="th-label" for="password">{{ __('Password') }}</label>
                        <div class="th-input-wrap">
                            <i class="fas fa-lock"></i>
                            <input id="password" type="password" name="password" autocomplete="current-password"
                                   class="form-control @error('password') is-invalid @enderror"
                                   placeholder="••••••••">
                        </div>
                        @error('password')
                            <span class="text-danger" role="alert">
                                <small><strong>{{ $message }}</strong></small>
                            </span>
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
                                <span class="text-danger" role="alert">
                                    <small><strong>{{ $message }}</strong></small>
                                </span>
                            @enderror
                        </div>
                    @endif

                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" name="remember" id="remember"
                                   {{ old('remember') ? 'checked' : '' }}>
                            <label class="custom-control-label" for="remember">{{ __('Remember Me') }}</label>
                        </div>

                        @if (Route::has('password.request'))
                            <a href="{{ route('password.request') }}" style="font-size:.84rem">
                                {{ __('Forgot Your Password?') }}
                            </a>
                        @endif
                    </div>

                    <button type="submit" class="th-btn th-btn-primary th-btn-block">
                        {{ __('Sign In') }} <i class="fas fa-arrow-right" style="font-size:.78rem"></i>
                    </button>

                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                </form>

                <div class="th-auth-alt">
                    {{ __("Don't have an account yet?") }}
                    <a href="{{ route('register') }}"><strong>{{ __('Create one for free') }}</strong></a>
                </div>
            </div>
        </section>
    </div>
@endsection
