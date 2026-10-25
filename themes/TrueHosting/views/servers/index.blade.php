@extends('layouts.main')

@section('page-title', __('Servers'))

@section('content')
    @php
        $atLimit = Auth::user()->Servers->count() >= Auth::user()->server_limit;
    @endphp

    <div class="th-page-head d-flex flex-wrap align-items-end justify-content-between" style="gap:1rem">
        <div>
            <h2 class="th-page-title">{{ __('Servers') }}</h2>
            <p class="th-page-sub">
                {{ Auth::user()->Servers->count() }} / {{ Auth::user()->server_limit }}
                {{ __('servers used') }}
            </p>
        </div>

        <div class="d-flex flex-wrap" style="gap:.5rem">
            @if (Auth::user()->Servers->count() > 0 && !empty($phpmyadmin_url))
                <a href="{{ $phpmyadmin_url }}" target="_blank" class="th-btn th-btn-ghost">
                    <i class="fas fa-database"></i>{{ __('Database') }}
                </a>
            @endif

            <a href="{{ route('servers.create') }}"
               class="th-btn th-btn-primary @if ($atLimit) disabled @endif @cannot('user.server.create') disabled @endcannot"
               @if ($atLimit) title="{{ __('Server limit reached!') }}" @endif
               @cannot('user.server.create') title="{{ __('No Permission!') }}" @endcannot>
                <i class="fas fa-plus"></i>{{ __('Create Server') }}
            </a>
        </div>
    </div>

    @if ($servers->count() == 0)
        <div class="th-empty">
            <i class="fas fa-server d-block"></i>
            <h3 class="th-empty-title">{{ __('You have no servers yet') }}</h3>
            <p class="th-empty-text">
                {{ __('Pick a plan and a location. The server starts right after that.') }}
            </p>
            <a href="{{ route('servers.create') }}"
               class="th-btn th-btn-primary @if ($atLimit) disabled @endif">
                <i class="fas fa-plus"></i>{{ __('Create Server') }}
            </a>
        </div>
    @else
        <div class="th-grid" x-data="serverPower()" x-init="start()">
            @foreach ($servers as $server)
                @php
                        $product = $server->product;

                        $nextBilling = null;
                        if (!$server->suspended && $server->last_billed) {
                            $base = \Carbon\Carbon::parse($server->last_billed);
                            $nextBilling = match ($product->billing_period) {
                                'hourly'        => $base->copy()->addHour(),
                                'daily'         => $base->copy()->addDay(),
                                'weekly'        => $base->copy()->addWeek(),
                                'monthly'       => $base->copy()->addMonth(),
                                'quarterly'     => $base->copy()->addMonths(3),
                                'half-annually' => $base->copy()->addMonths(6),
                                'annually'      => $base->copy()->addYear(),
                                default         => null,
                            };
                        }

                        $periodLabel = match ($product->billing_period) {
                            'hourly'        => __('per Hour'),
                            'daily'         => __('per Day'),
                            'weekly'        => __('per Week'),
                            'monthly'       => __('per Month'),
                            'quarterly'     => __('per 3 Months'),
                            'half-annually' => __('per 6 Months'),
                            'annually'      => __('per Year'),
                            default         => '',
                        };
                    @endphp

                    <div class="th-server-card @if ($server->isTrial()) is-trial @endif">

                        <div class="th-server-head">
                            <span class="th-stat-icon" style="width:40px;height:40px;font-size:.95rem">
                                <i class="fas fa-server"></i>
                            </span>
                            <div style="min-width:0;flex:1 1 auto">
                                <h3 class="th-server-name">{{ $server->name }}</h3>
                                <span class="th-server-meta">{{ $server->nest }} &middot; {{ $server->egg }}</span>
                            </div>

                            @php
                                $stateInfo = $server->powerStateInfo();
                            @endphp
                            @if ($server->isTrial())
                                <span class="th-status is-trial">{{ __('Trial') }}</span>
                            @endif
                            {{-- Object syntax, not a string: a string binding only adds
                                 classes and leaves whatever was rendered server side in
                                 place, so a server that finished starting kept its amber
                                 "starting" background while the label already said
                                 "running". Each state is toggled explicitly instead. --}}
                            <span class="th-status {{ $stateInfo['class'] }}"
                                  :class="{
                                      'is-online': cls('{{ $server->id }}') === 'is-online',
                                      'is-pending': cls('{{ $server->id }}') === 'is-pending',
                                      'is-suspended': cls('{{ $server->id }}') === 'is-suspended',
                                  }"
                                  x-text="label('{{ $server->id }}')">{{ $stateInfo['label'] }}</span>
                        </div>

                        @if (!empty($server->address))
                            <div class="th-address" title="{{ __('Server address') }}">
                                <i class="fas fa-network-wired"></i>
                                <code id="addr-{{ $server->id }}">{{ $server->address }}</code>
                                <button type="button" class="th-address-copy"
                                        onclick="thCopyAddress('{{ $server->id }}')"
                                        title="{{ __('Click to copy') }}">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        @endif

                        <ul class="th-specs">
                            <li class="th-spec">
                                <i class="fas fa-microchip"></i>
                                {{ $product->cpu == 0 ? __('Unlimited') : ($product->cpu / 100) }} {{ __('vCores') }}
                            </li>
                            <li class="th-spec">
                                <i class="fas fa-memory"></i>
                                {{ $product->memory == 0 ? __('Unlimited') : $product->memory . ' MB' }}
                            </li>
                            <li class="th-spec">
                                <i class="fas fa-hdd"></i>
                                {{ $product->disk == 0 ? __('Unlimited') : $product->disk . ' MB' }}
                            </li>
                            <li class="th-spec">
                                <i class="fas fa-map-marker-alt"></i>
                                {{ $server->location }}
                            </li>
                            <li class="th-spec"
                                data-toggle="popover" data-trigger="hover" data-html="true"
                                data-content="{{ __('Backups') }}: {{ $product->backups }}<br/>{{ __('MySQL Databases') }}: {{ $product->databases }}<br/>{{ __('Allocations') }}: {{ $product->allocations }}<br/>{{ __('OOM Killer') }}: {{ $product->oom_killer ? __('enabled') : __('disabled') }}<br/>{{ __('Node') }}: {{ $server->node }}"
                                style="cursor:help">
                                <i class="fas fa-info-circle"></i>
                                {{ $product->name }}
                            </li>
                        </ul>

                        {{-- A trial is not billed, so a billing cycle and a monthly figure
                             would only confuse; its own note says what happens instead. --}}
                        <div class="d-flex justify-content-between align-items-end mt-3 pt-3"
                             style="border-top:1px solid var(--glass-border);gap:1rem">
                            <div style="min-width:0">
                                @if ($server->isTrial())
                                    <span class="th-stat-label">{{ __('Costs you') }}</span>
                                    <span style="font-size:.84rem">{{ __('Nothing while the trial runs') }}</span>
                                @elseif ($server->isDirectBilled())
                                    <span class="th-stat-label">{{ __('Paid until') }}</span>
                                    <span style="font-size:.84rem">
                                        {{ $server->paid_until ? \Carbon\Carbon::parse($server->paid_until)->toDayDateTimeString() : '—' }}
                                    </span>
                                @else
                                    <span class="th-stat-label">{{ __('Next Billing Cycle') }}</span>
                                    <span style="font-size:.84rem">
                                        {{ $nextBilling ? $nextBilling->toDayDateTimeString() : '—' }}
                                    </span>
                                @endif
                            </div>
                            <div class="text-right" style="white-space:nowrap">
                                <span class="th-stat-label">
                                    @if ($server->isTrial())
                                        {{ __('Afterwards') }}
                                    @else
                                        {{ $credits_display_name }}
                                    @endif
                                </span>
                                <strong style="font-size:1.02rem">{{ $product->display_price }}</strong>
                                <span class="d-block text-muted" style="font-size:.74rem">{{ $periodLabel }}</span>
                            </div>
                        </div>

                        @if ($server->isDirectBilled())
                            @php
                                $plan = $product->customPlan;
                            @endphp
                            <div class="th-renew">
                                @if ($plan?->allowsCredits())
                                    <form method="POST" action="{{ route('servers.renew', $server->id) }}">
                                        @csrf
                                        <input type="hidden" name="mode" value="credits">
                                        <button type="submit" class="th-btn th-btn-ghost th-btn-sm">
                                            <i class="fas fa-coins"></i>{{ __('Extend from credits') }}
                                        </button>
                                    </form>
                                @endif
                                @if ($plan?->allowsDirect())
                                    @php
                                        // What the next period actually costs, so the button is not
                                        // a blind click through to a checkout.
                                        $renewResources = [
                                            'cpu' => (int) $product->cpu,
                                            'memory' => (int) $product->memory,
                                            'disk' => (int) $product->disk,
                                            'backups' => (int) $product->backups,
                                            'databases' => (int) $product->databases,
                                            'allocations' => (int) $product->allocations,
                                        ];
                                        $renewMoney = $plan->hasMoneyPrices()
                                            ? $plan->moneyPriceFor($renewResources)
                                            : (int) round($product->price * (float) $plan->credit_rate);
                                    @endphp
                                    <form method="POST" action="{{ route('servers.renew', $server->id) }}">
                                        @csrf
                                        <input type="hidden" name="mode" value="direct">
                                        <button type="submit" class="th-btn th-btn-primary th-btn-sm">
                                            <i class="far fa-credit-card"></i>{{ __('Extend and pay') }}
                                            <span style="opacity:.8">
                                                {{ Currency::formatToCurrency($renewMoney, $plan->currency_code) }}
                                            </span>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @endif

                        @if ($server->isTrial())
                            @php
                                $trialEnds = $server->trial_ends_at;
                                $canExtend = $server->trial_extensions < $trial_max_extensions;
                                $trialPrice = $product->display_price;
                            @endphp

                            <div class="th-trial-note">
                                <i class="fas fa-flask"></i>
                                <span>
                                    @if ($trialEnds->isPast())
                                        {{ __('The trial has ended.') }}
                                    @else
                                        {{ __('Trial ends') }} {{ $trialEnds->diffForHumans() }}
                                    @endif
                                </span>
                            </div>

                            <div class="th-trial-actions">
                                <button type="button" class="th-btn th-btn-primary th-btn-sm th-buy-trial"
                                        data-server="{{ $server->id }}"
                                        data-name="{{ $server->name }}"
                                        data-product="{{ $product->name }}"
                                        data-price="{{ (int) round($product->price) }}">
                                    <i class="fas fa-shopping-cart"></i>{{ __('Buy') }}
                                </button>

                                @if ($canExtend)
                                    <button type="button" class="th-btn th-btn-ghost th-btn-sm th-extend-trial"
                                            data-server="{{ $server->id }}">
                                        <i class="fas fa-clock"></i>{{ __('Extend') }}
                                    </button>
                                @endif
                            </div>
                        @endif

                        @if ($power_controls_enabled && $server->canBePowered())
                            <div class="th-power">
                                <button type="button" class="th-btn th-btn-ghost th-btn-sm"
                                        :disabled="busy('{{ $server->id }}')"
                                        @click="send('{{ $server->id }}', 'start')"
                                        title="{{ __('Start') }}">
                                    <i class="fas fa-play"></i>{{ __('Start') }}
                                </button>
                                <button type="button" class="th-btn th-btn-ghost th-btn-sm"
                                        :disabled="busy('{{ $server->id }}')"
                                        @click="send('{{ $server->id }}', 'restart')"
                                        title="{{ __('Restart') }}">
                                    <i class="fas fa-redo"></i>{{ __('Restart') }}
                                </button>
                                <button type="button" class="th-btn th-btn-ghost th-btn-sm"
                                        :disabled="busy('{{ $server->id }}')"
                                        @click="send('{{ $server->id }}', 'stop')"
                                        title="{{ __('Stop') }}">
                                    <i class="fas fa-stop"></i>{{ __('Stop') }}
                                </button>
                            </div>
                        @endif

                        <div class="th-card-foot">
                            <a href="{{ $pterodactyl_url }}/server/{{ $server->identifier }}" target="__blank"
                               class="th-btn th-btn-primary" style="flex:1 1 auto"
                               data-toggle="tooltip" data-placement="bottom" title="{{ __('Manage Server') }}">
                                <i class="fas fa-tools"></i>{{ __('Manage') }}
                            </a>
                            <a href="{{ route('servers.show', ['server' => $server->id]) }}"
                               class="th-btn th-btn-ghost"
                               data-toggle="tooltip" data-placement="bottom" title="{{ __('Server Settings') }}">
                                <i class="fas fa-cog"></i>
                            </a>
                            <button onclick="handleServerCancel('{{ $server->id }}');"
                                    class="th-btn th-btn-ghost"
                                    {{ $server->suspended || $server->canceled ? 'disabled' : '' }}
                                    data-toggle="tooltip" data-placement="bottom" title="{{ __('Cancel Server') }}">
                                <i class="fas fa-ban"></i>
                            </button>
                            <button onclick="handleServerDelete('{{ $server->id }}');"
                                    class="th-btn th-btn-ghost" style="color:#f87171"
                                    data-toggle="tooltip" data-placement="bottom" title="{{ __('Delete Server') }}">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
            @endforeach
        </div>
    @endif

    <script>
        function serverPower() {
            // How often the list asks the panel what each server is doing. Short
            // enough to feel live, long enough not to hammer the game panel with
            // one request per server per tick.
            const INTERVAL = 8000;

            return {
                states: @json($servers->mapWithKeys(fn ($s) => [
                    $s->id => $s->powerStateInfo() + ['can_power' => $s->canBePowered()],
                ])),
                pending: {},
                timer: null,

                start() {
                    this.timer = setInterval(() => this.refresh(), INTERVAL);

                    // A tab nobody is looking at does not need fresh figures,
                    // and catches up the moment it comes back.
                    document.addEventListener('visibilitychange', () => {
                        if (!document.hidden) this.refresh();
                    });
                },

                label(id) {
                    return this.states[id]?.label ?? '';
                },

                cls(id) {
                    return this.states[id]?.class ?? '';
                },

                busy(id) {
                    return this.pending[id] === true;
                },

                async refresh() {
                    if (document.hidden) return;

                    try {
                        const response = await axios.get('{{ route('servers.states') }}');
                        // A server mid-signal keeps the state the click set until
                        // its request comes back, so the pill does not flicker.
                        Object.keys(response.data).forEach(id => {
                            if (!this.busy(id)) this.states[id] = response.data[id];
                        });
                    } catch (e) {
                        // A dropped poll is not worth telling anyone about.
                    }
                },

                async send(id, signal) {
                    if (this.busy(id)) return;

                    this.pending[id] = true;

                    try {
                        const response = await axios.post(
                            '{{ url('servers') }}/' + id + '/power', { signal: signal }
                        );
                        this.states[id] = response.data;
                    } catch (e) {
                        thToast('error', e.response?.data?.error
                            ?? '{{ __('The game panel did not accept that.') }}');
                    } finally {
                        this.pending[id] = false;
                        // Confirm the real state once the panel has had a moment.
                        setTimeout(() => this.refresh(), 2500);
                    }
                },
            };
        }

        // ---------------- Trial: buy, extend, and the first-visit hints -------

        const thTrialBuy = (button) => {
            const price = Number(button.dataset.price);
            const credits = {{ (int) round(Auth::user()->credits) }};
            const after = credits - price;
            const fmt = (value) => (value / 1000).toLocaleString('{{ str_replace('_', '-', app()->getLocale()) }}', {
                minimumFractionDigits: 2, maximumFractionDigits: 2,
            }).replace(/[.,]00$/, '');

            Swal.fire({
                title: '{{ __('Buy this server?') }}',
                html: '<div class="th-buy-rows">'
                    + '<div><span>{{ __('Package') }}</span><span>' + button.dataset.product + '</span></div>'
                    + '<div><span>{{ __('Your balance') }}</span><span>' + fmt(credits) + ' {{ $credits_display_name }}</span></div>'
                    + '<div><span>{{ __('Price') }}</span><span>' + fmt(price) + ' {{ $credits_display_name }}</span></div>'
                    + '<div><span>{{ __('Left afterwards') }}</span><span class="' + (after < 0 ? 'th-buy-short' : '') + '">'
                    + fmt(after) + ' {{ $credits_display_name }}</span></div>'
                    + '</div>',
                showCancelButton: true,
                confirmButtonText: '{{ __('Buy') }}',
                cancelButtonText: '{{ __('Cancel') }}',
                // Nothing to confirm when the balance will not cover it.
                didOpen: () => {
                    if (after < 0) {
                        Swal.getConfirmButton().disabled = true;
                    }
                },
            }).then(async (result) => {
                if (!result.isConfirmed) return;

                try {
                    const response = await fetch('{{ url('servers') }}/' + button.dataset.server + '/trial/buy', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Accept': 'application/json',
                        },
                    });

                    const body = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        thToast('error', body.error ?? '{{ __('Something went wrong, please try again later.') }}');
                        return;
                    }

                    window.location.reload();
                } catch (e) {
                    thToast('error', '{{ __('Something went wrong, please try again later.') }}');
                }
            });
        };

        const thTrialExtend = async (button) => {
            button.disabled = true;

            try {
                const response = await fetch('{{ url('servers') }}/' + button.dataset.server + '/trial/extend', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                });

                const body = await response.json().catch(() => ({}));

                if (!response.ok) {
                    thToast('error', body.error ?? '{{ __('Something went wrong, please try again later.') }}');
                    button.disabled = false;
                    return;
                }

                window.location.reload();
            } catch (e) {
                thToast('error', '{{ __('Something went wrong, please try again later.') }}');
                button.disabled = false;
            }
        };

        @if (session('trial_created'))
            // Shown once, right after the trial was created.
            const thTrialTour = () => Swal.fire({
                icon: 'info',
                title: '{{ __('Your trial server is ready') }}',
                html: @json(__('Click <strong>Manage</strong> to reach the console and the files.')),
                confirmButtonText: '{{ __('Next') }}',
                showCancelButton: true,
                cancelButtonText: '{{ __('Got it') }}',
            }).then((result) => {
                if (!result.isConfirmed) return;

                Swal.fire({
                    icon: 'success',
                    title: '{{ __('Liked it?') }}',
                    html: @json(__('Click <strong>Buy</strong> on the trial server to keep it for good.')),
                    confirmButtonText: '{{ __('Got it') }}',
                });
            });
        @endif

        function thCopyAddress(id) {
            const el = document.getElementById('addr-' + id);
            if (!el) return;

            const done = () => Swal.fire({
                icon: 'success',
                title: '{{ __('Copied') }}',
                position: 'top-end',
                showConfirmButton: false,
                toast: true,
                timer: 1400,
            });

            if (navigator.clipboard) {
                navigator.clipboard.writeText(el.textContent.trim()).then(done);
            } else {
                const r = document.createRange();
                r.selectNode(el);
                window.getSelection().removeAllRanges();
                window.getSelection().addRange(r);
                document.execCommand('copy');
                done();
            }
        }

        const thConfirm = (opts) => Swal.fire(Object.assign({
            icon: 'warning',
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#2a2a33',
            showCancelButton: true,
            reverseButtons: true,
            cancelButtonText: "{{ __('No, abort!') }}",
        }, opts));

        // `fetch` repeats a DELETE or PATCH when it follows a redirect, so these
        // ask for JSON and read the answer instead of reloading blindly.
        const thRequest = async (url, method) => {
            try {
                const response = await fetch(url, {
                    method: method,
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                });

                if (response.ok) {
                    window.location.reload();
                    return;
                }

                const body = await response.json().catch(() => ({}));

                Swal.fire({
                    title: "{{ __('Error') }}",
                    text: body.error ?? "{{ __('Something went wrong, please try again later.') }}",
                    icon: 'error',
                    confirmButtonColor: '#ef4444',
                });
            } catch (e) {
                Swal.fire({
                    title: "{{ __('Error') }}",
                    text: "{{ __('Something went wrong, please try again later.') }}",
                    icon: 'error',
                    confirmButtonColor: '#ef4444',
                });
            }
        };

        const handleServerCancel = (serverId) => {
            thConfirm({
                title: "{{ __('Cancel Server?') }}",
                text: "{{ __('This will cancel your current server to the next billing period. It will get suspended when the current period runs out.') }}",
                confirmButtonText: "{{ __('Yes, cancel it!') }}",
            }).then((result) => {
                if (result.value) {
                    thRequest("{{ route('servers.cancel', '') }}" + '/' + serverId, 'PATCH');
                }
            });
        };

        const handleServerDelete = (serverId) => {
            thConfirm({
                title: "{{ __('Delete Server?') }}",
                html: "{!! __('This is an irreversible action, all files of this server will be removed. <strong>No funds will get refunded</strong>. We recommend deleting the server when server is suspended.') !!}",
                confirmButtonText: "{{ __('Yes, delete it!') }}",
            }).then((result) => {
                if (result.value) {
                    thRequest("{{ route('servers.destroy', '') }}" + '/' + serverId, 'DELETE');
                }
            });
        };

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.th-buy-trial').forEach((button) => {
                button.addEventListener('click', () => thTrialBuy(button));
            });

            document.querySelectorAll('.th-extend-trial').forEach((button) => {
                button.addEventListener('click', () => thTrialExtend(button));
            });

            @if (session('trial_created'))
                thTrialTour();
            @endif

            $('[data-toggle="popover"]').popover();
            $('[data-toggle="tooltip"]').tooltip();
        });
    </script>
@endsection
