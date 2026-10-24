@extends('layouts.main')

@section('page-title', __('Dashboard'))

@section('content')
    @php
        // Map the controller's AdminLTE background class onto this theme's accents.
        $timeLeftAccent = 'is-success';
        if (isset($timeLeft['bg'])) {
            $timeLeftAccent = match ($timeLeft['bg']) {
                'bg-warning' => 'is-warning',
                'bg-danger'  => 'is-danger',
                default      => 'is-success',
            };
        }
        $serverCount = Auth::user()->servers()->count();
        $serverLimit = Auth::user()->server_limit;
        // `suspended` and `canceled` are nullable datetimes, not booleans.
        $activeServers = Auth::user()->servers()->whereNull('suspended')->whereNull('canceled')->count();

        $hour = now()->hour;
        $greeting = $hour < 5 || $hour >= 18
            ? __('Good evening')
            : ($hour < 12 ? __('Good morning') : __('Good afternoon'));
    @endphp

    <div class="th-hero">
        <div>
            <span class="th-hero-eyebrow">{{ config('app.name', 'TrueHosting') }}</span>
            <h2 class="th-page-title">
                {{ $greeting }}, <span class="th-hero-name">{{ Auth::user()->name }}</span>
            </h2>
            <p class="th-hero-status">
                @if ($serverCount === 0)
                    {{ __('No servers on your account yet') }}
                @else
                    {{ __(':active of :total servers active', ['active' => $activeServers, 'total' => $serverCount]) }}
                @endif
                <span class="th-sep">&middot;</span>
                @if ($serverCount > $activeServers)
                    {{ __(':count suspended', ['count' => $serverCount - $activeServers]) }}
                @else
                    {{ __('No issues reported') }}
                @endif
            </p>
        </div>

        <div class="th-hero-clock">
            <span class="th-hero-time">{{ now()->format('H:i') }}</span>
            <span class="th-hero-date">
                {{ ucfirst(now()->locale(app()->getLocale())->translatedFormat('l, j F')) }}
            </span>
        </div>
    </div>

    @if (!file_exists(base_path() . '/install.lock') && Auth::User()->hasRole('Admin'))
        <div class="alert alert-danger">
            <strong>{{ __('The installer is not locked!') }}</strong><br>
            {{ __('please create a file called "install.lock" in your dashboard Root directory. Otherwise no settings will beloaded!') }}
            <a href="/install?step=7" class="btn btn-sm btn-outline-danger ml-2">{{ __('or click here') }}</a>
        </div>
    @endif

    @if (!Auth::user()->hasVerifiedEmail() && Auth::user()->created_at->diffInHours(now(), false) > 1)
        <div class="alert alert-warning">
            <strong>{{ __('You have not yet verified your email address') }}</strong>
            <a href="{{ route('verification.send') }}">{{ __('Click here to resend verification email') }}</a>
        </div>
    @endif

    {{-- ---------------- Stat tiles ---------------- --}}
    <div class="th-stats">
        <div class="th-stat">
            <span class="th-stat-icon"><i class="fas fa-coins"></i></span>
            <span class="th-stat-body">
                <span class="th-stat-label">{{ $general_settings->credits_display_name }}</span>
                <span class="th-stat-value">{{ Currency::formatForDisplay($credits) }}</span>
            </span>
        </div>

        <div class="th-stat">
            <span class="th-stat-icon"><i class="fas fa-server"></i></span>
            <span class="th-stat-body">
                <span class="th-stat-label">{{ __('Servers') }}</span>
                <span class="th-stat-value">{{ $serverCount }}<sup>/ {{ $serverLimit }}</sup></span>
            </span>
        </div>

        <div class="th-stat">
            <span class="th-stat-icon is-warning"><i class="fas fa-chart-line"></i></span>
            <span class="th-stat-body">
                <span class="th-stat-label">{{ __('Usage') }}</span>
                <span class="th-stat-value">
                    {{ Currency::formatForDisplay($usage) }}<sup>/ {{ __('mo') }}</sup>
                </span>
            </span>
        </div>

        @if ($credits > 10 && $usage > 0 && isset($timeLeft))
            <div class="th-stat">
                <span class="th-stat-icon {{ $timeLeftAccent }}"><i class="fas fa-hourglass-half"></i></span>
                <span class="th-stat-body">
                    <span class="th-stat-label">{{ $timeLeft['message'] }}</span>
                    <span class="th-stat-value">
                        {{ $timeLeft['value'] }}<sup>{{ $timeLeft['unit'] }}</sup>
                    </span>
                </span>
            </div>
        @endif
    </div>

    {{-- ---------------- Quick actions ---------------- --}}
    <div class="th-actions">
        <a href="{{ route('servers.create') }}" class="th-btn th-btn-primary">
            <i class="fas fa-plus"></i> {{ __('Create Server') }}
        </a>
        @if (config('app.env') == 'local' || $general_settings->store_enabled)
            <a href="{{ route('store.index') }}" class="th-btn th-btn-ghost">
                <i class="fas fa-shopping-cart"></i> {{ __('Buy credits') }}
            </a>
        @endif
        <a href="javascript:void(0)" data-toggle="modal" data-target="#redeemVoucherModal"
           class="th-btn th-btn-ghost">
            <i class="fas fa-money-check-alt"></i> {{ __('Redeem code') }}
        </a>
        @if (app(App\Settings\TicketSettings::class)->enabled)
            <a href="{{ route('ticket.index') }}" class="th-btn th-btn-ghost">
                <i class="fas fa-headset"></i> {{ __('Support Ticket') }}
            </a>
        @endif
        <a href="{{ route('servers.index') }}" class="th-btn th-btn-ghost ml-auto">
            {{ __('Servers') }} <i class="fas fa-arrow-right" style="font-size:.72rem"></i>
        </a>
    </div>

    {{-- ---------------- Credit forecast ---------------- --}}
    @if ($usage > 0)
        <div class="th-card mb-4">
            <div class="th-card-head">
                <i class="fas fa-chart-area"></i>
                <h3 class="th-card-title">{{ __('Credit balance forecast') }}</h3>
                <span class="th-card-actions text-muted" style="font-size:.78rem">
                    {{ __('at the current burn rate of :amount / month', [
                        'amount' => Currency::formatForDisplay($usage) . ' ' . $general_settings->credits_display_name,
                    ]) }}
                </span>
            </div>
            <div class="th-card-body">
                <div class="th-chart" style="height:220px"><canvas id="creditChart"></canvas></div>
            </div>
        </div>
    @endif

    <div class="row">
        {{-- ---------------- Left column ---------------- --}}
        <div class="col-lg-6">

            @if ($website_settings->motd_enabled && !empty($website_settings->motd_message))
                <div class="th-card mb-4">
                    <div class="th-card-head">
                        <i class="fas fa-bullhorn"></i>
                        <h3 class="th-card-title">{{ __('Message of the day') }}</h3>
                    </div>
                    <div class="th-card-body">
                        {!! $website_settings->motd_message !!}
                    </div>
                </div>
            @endif

            @if ($website_settings->useful_links_enabled)
                <div class="th-card mb-4">
                    <div class="th-card-head">
                        <i class="fas fa-link"></i>
                        <h3 class="th-card-title">{{ __('Useful Links') }}</h3>
                    </div>
                    <div class="th-card-body">
                        @forelse ($useful_links_dashboard as $useful_link)
                            <a href="{{ $useful_link->link }}" target="__blank"
                               class="d-flex align-items-start mb-3" style="gap:.75rem">
                                <span class="th-stat-icon" style="width:38px;height:38px;font-size:.9rem">
                                    <i class="{{ $useful_link->icon }}"></i>
                                </span>
                                <span>
                                    <strong class="d-block">{{ $useful_link->title }}</strong>
                                    <span class="text-muted" style="font-size:.84rem">
                                        {!! $useful_link->description !!}
                                    </span>
                                </span>
                            </a>
                        @empty
                            <span class="text-muted">{{ __('No useful links available') }}</span>
                        @endforelse
                    </div>
                </div>
            @endif

            @if ($referral_settings->enabled)
                <div class="th-card mb-4">
                    <div class="th-card-head">
                        <i class="fas fa-handshake"></i>
                        <h3 class="th-card-title">{{ __('Partner program') }}</h3>
                    </div>
                    <div class="th-card-body">
                        @if (Auth::user()->can('user.referral'))
                            <p class="th-label">{{ __('Your referral URL') }}</p>
                            <div class="d-flex align-items-center mb-3" style="gap:.5rem">
                                <input id="RefLinkInput" class="form-control" readonly
                                       value="{{ route('register') . '?ref=' . Auth::user()->referral_code }}">
                                <button class="th-btn th-btn-primary" onclick="thCopyRef()" type="button"
                                        title="{{ __('Click to copy') }}">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>

                            <div class="th-specs mb-2">
                                <span class="th-spec">
                                    <i class="fas fa-user-check"></i>
                                    {{ __('Number of referred users:') }} <strong>{{ $numberOfReferrals }}</strong>
                                </span>
                            </div>

                            <table class="table mt-3">
                                <thead>
                                <tr>
                                    @if ($partnerDiscount)
                                        <th>{{ __('Your discount') }}</th>
                                        <th>{{ __('Discount for your new users') }}</th>
                                    @endif
                                    @if (!$partnerDiscount && in_array($referral_settings->mode, ['sign-up', 'both']))
                                        <th>{{ __('Reward per registered user') }}</th>
                                    @endif
                                    @if ($partnerDiscount)
                                        <th>{{ __('Reward per registered user') }}</th>
                                    @endif
                                    @if ($partnerDiscount || in_array($referral_settings->mode, ['commission', 'both']))
                                        <th>{{ __('New user payment commision') }}</th>
                                    @endif
                                </tr>
                                </thead>
                                <tbody>
                                <tr>
                                    @if ($partnerDiscount)
                                        <td>{{ $partnerDiscount->partner_discount }}%</td>
                                        <td>{{ $partnerDiscount->registered_user_discount }}%</td>
                                        <td>
                                            {{ Currency::formatForDisplay($referral_settings->reward) }}
                                            {{ $general_settings->credits_display_name }}
                                        </td>
                                        <td>
                                            {{ $partnerDiscount->referral_system_commission == -1
                                                ? $referral_settings->percentage
                                                : $partnerDiscount->referral_system_commission }}%
                                        </td>
                                    @else
                                        @if (in_array($referral_settings->mode, ['sign-up', 'both']))
                                            <td>
                                                {{ Currency::formatForDisplay($referral_settings->reward) }}
                                                {{ $general_settings->credits_display_name }}
                                            </td>
                                        @endif
                                        @if (in_array($referral_settings->mode, ['commission', 'both']))
                                            <td>{{ $referral_settings->percentage }}%</td>
                                        @endif
                                    @endif
                                </tr>
                                </tbody>
                            </table>
                        @else
                            <div class="alert alert-warning mb-0">
                                <i class="mr-2 fa fa-user-check"></i>
                                {{ __('Make a purchase to reveal your referral-URL') }}
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        {{-- ---------------- Right column ---------------- --}}
        <div class="col-lg-6">
            <div class="th-card mb-4">
                <div class="th-card-head">
                    <i class="fas fa-history"></i>
                    <h3 class="th-card-title">{{ __('Activity Logs') }}</h3>
                </div>
                <div class="th-card-body">
                    @php
                        $logs = Auth::user()->actions()->take(8)->orderBy('created_at', 'desc')->get();
                    @endphp

                    @forelse ($logs as $log)
                        @php
                            $properties = json_decode($log->properties, true);
                            $icon = match (true) {
                                str_starts_with($log->description, 'created')  => ['fa-plus', '#4ade80'],
                                str_starts_with($log->description, 'redeemed') => ['fa-money-check-alt', '#4ade80'],
                                str_starts_with($log->description, 'deleted')  => ['fa-times', '#f87171'],
                                str_starts_with($log->description, 'gained')   => ['fa-money-bill', '#4ade80'],
                                str_starts_with($log->description, 'updated')  => ['fa-pen', '#3b7ffa'],
                                default                                        => ['fa-circle', '#8891a5'],
                            };
                            $subject = explode('\\', $log->subject_type);
                            $subject = $subject[2] ?? ($subject[count($subject) - 1] ?? '');
                        @endphp

                        <div class="d-flex align-items-start mb-3" style="gap:.7rem">
                            <i class="fas {{ $icon[0] }}"
                               style="margin-top:.45rem;font-size:.7rem;color:{{ $icon[1] }}"></i>
                            <div style="min-width:0;flex:1 1 auto">
                                <div class="d-flex justify-content-between" style="gap:.5rem">
                                    <strong style="font-size:.88rem">
                                        {{ $subject }} {{ ucfirst($log->description) }}
                                    </strong>
                                    <small class="text-muted" style="white-space:nowrap">
                                        {{ $log->created_at->diffForHumans() }}
                                    </small>
                                </div>

                                @php
                                    $detailSet = match (true) {
                                        $log->description === 'created' && isset($properties['attributes']) => $properties['attributes'],
                                        $log->description === 'deleted' && isset($properties['old']) => $properties['old'],
                                        default => null,
                                    };
                                @endphp

                                @if ($log->description === 'updated' && isset($properties['attributes'], $properties['old']))
                                    <details>
                                        <summary class="text-muted" style="font-size:.8rem;cursor:pointer">
                                            {{ __('Details') }}
                                        </summary>
                                        <ul class="text-muted mb-0" style="font-size:.8rem">
                                            @foreach ($properties['attributes'] as $attribute => $newValue)
                                                @if (array_key_exists($attribute, $properties['old']) && !is_null($newValue))
                                                    <li>
                                                        <strong>{{ ucfirst($attribute) }}:</strong>
                                                        {{ in_array($attribute, ['created_at', 'updated_at'])
                                                            ? \Carbon\Carbon::parse($properties['old'][$attribute])->toDayDateTimeString() . ' → ' . \Carbon\Carbon::parse($newValue)->toDayDateTimeString()
                                                            : $properties['old'][$attribute] . ' → ' . $newValue }}
                                                    </li>
                                                @endif
                                            @endforeach
                                        </ul>
                                    </details>
                                @elseif ($detailSet)
                                    <details>
                                        <summary class="text-muted" style="font-size:.8rem;cursor:pointer">
                                            {{ __('Details') }}
                                        </summary>
                                        <ul class="text-muted mb-0" style="font-size:.8rem">
                                            @foreach ($detailSet as $attribute => $value)
                                                @if (!is_null($value))
                                                    <li>
                                                        <strong>{{ ucfirst($attribute) }}:</strong>
                                                        {{ in_array($attribute, ['created_at', 'updated_at'])
                                                            ? \Carbon\Carbon::parse($value)->toDayDateTimeString()
                                                            : $value }}
                                                    </li>
                                                @endif
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif
                            </div>
                        </div>
                    @empty
                        <span class="text-muted">{{ __('No activity logs available') }}</span>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <script src="{{ asset('plugins/chart.js/Chart.min.js') }}"></script>
    <script>
        (function () {
            const canvas = document.getElementById('creditChart');
            if (!canvas || typeof Chart === 'undefined') return;

            // Straight-line projection: today's balance minus the current
            // monthly burn, for the next six months.
            const balance = {{ (float) $credits }} / 1000;
            const burn = {{ (float) $usage }} / 1000;

            const labels = [];
            const data = [];
            const now = new Date();
            for (let i = 0; i <= 6; i++) {
                const d = new Date(now.getFullYear(), now.getMonth() + i, 1);
                labels.push(d.toLocaleDateString(document.documentElement.lang || 'pl', {month: 'short'}));
                data.push(Math.max(0, +(balance - burn * i).toFixed(2)));
            }

            const ctx = canvas.getContext('2d');
            const fill = ctx.createLinearGradient(0, 0, 0, 200);
            fill.addColorStop(0, 'rgba(6,91,241,0.32)');
            fill.addColorStop(1, 'rgba(6,91,241,0)');

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: '{{ $general_settings->credits_display_name }}',
                        data: data,
                        borderColor: '#3b7ffa',
                        backgroundColor: fill,
                        borderWidth: 2,
                        pointRadius: 2,
                        lineTension: 0.25,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: {display: false},
                    scales: {
                        xAxes: [{gridLines: {color: 'rgba(255,255,255,0.06)'}, ticks: {fontColor: '#8891a5'}}],
                        yAxes: [{gridLines: {color: 'rgba(255,255,255,0.06)'}, ticks: {fontColor: '#8891a5', beginAtZero: true}}]
                    }
                }
            });
        })();
    </script>

    <script>
        function thCopyRef() {
            const input = document.getElementById('RefLinkInput');
            if (!input) return;

            const done = () => Swal.fire({
                icon: 'success',
                title: '{{ __('URL copied to clipboard') }}',
                position: 'top-end',
                showConfirmButton: false,
                background: '#212129',
                color: '#eef1f7',
                toast: true,
                timer: 1600,
                timerProgressBar: true,
            });

            if (navigator.clipboard) {
                navigator.clipboard.writeText(input.value).then(done);
            } else {
                // Fallback for browsers without the async clipboard API.
                input.select();
                document.execCommand('copy');
                done();
            }
        }
    </script>
@endsection
