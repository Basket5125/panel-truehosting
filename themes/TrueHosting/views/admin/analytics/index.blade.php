@extends('layouts.main')

@section('page-title', __('Analytics'))

@section('content')
    @php
        $labels = ['7d' => __('7 days'), '30d' => __('30 days'), '90d' => __('90 days'), '12m' => __('12 months')];

        // Percentage change against the preceding span of the same length.
        $delta = function ($now, $before) {
            if ($before == 0) {
                return $now > 0 ? null : 0;
            }
            return round((($now - $before) / abs($before)) * 100, 1);
        };

        $currency = $revenueSeries['currency'];
        $revenueNow = $revenue[$currency] ?? 0;
        $revenueBefore = $revenuePrevious[$currency] ?? 0;
        $revenueDelta = $delta($revenueNow, $revenueBefore);
        $serversDelta = $delta($newServers, $newServersPrevious);
        $usersDelta = $delta($users['new'], $users['newPrevious']);
        $ordersDelta = $delta($paymentsCount, $paymentsCountPrevious);

        $arpu = $users['withServers'] > 0 ? $mrrCredits / $users['withServers'] : 0;
        $churn = $servers['total'] > 0 ? round(($servers['canceled'] / $servers['total']) * 100, 1) : 0;
    @endphp

    <div class="th-page-head d-flex flex-wrap align-items-end justify-content-between" style="gap:1rem">
        <div>
            <h2 class="th-page-title">{{ __('Analytics') }}</h2>
            <p class="th-page-sub">
                {{ $start->format('d.m.Y') }} &ndash; {{ $end->format('d.m.Y') }}
            </p>
        </div>

        <div class="d-flex flex-wrap align-items-center" style="gap:.75rem">
            <a href="{{ route('admin.analytics.index', ['period' => $period, 'hide_staff' => $hideStaff ? 0 : 1]) }}"
               class="th-toggle @if ($hideStaff) is-on @endif"
               title="{{ __('Leaves out accounts holding a role with power :power or above.', ['power' => $staffPower]) }}">
                <i class="fas @if ($hideStaff) fa-check-square @else fa-square @endif"></i>
                <span>{{ __('Without staff') }}</span>
                @if ($hideStaff && $staffCount > 0)
                    <span class="th-nav-badge">{{ $staffCount }}</span>
                @endif
            </a>

            <div class="th-period">
                @foreach ($periods as $p)
                    <a href="{{ route('admin.analytics.index', ['period' => $p, 'hide_staff' => $hideStaff ? 1 : 0]) }}"
                       class="th-period-item @if ($period === $p) active @endif">{{ $labels[$p] }}</a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ---------------- Headline numbers ---------------- --}}
    <div class="th-stats">
        <div class="th-stat">
            <span class="th-stat-icon is-success"><i class="fas fa-money-bill-wave"></i></span>
            <span class="th-stat-body">
                <span class="th-stat-label">{{ __('Revenue') }}</span>
                <span class="th-stat-value">{{ Currency::formatToCurrency($revenueNow, $currency) }}</span>
                <x-analytics-delta :value="$revenueDelta"/>
            </span>
        </div>

        <div class="th-stat">
            <span class="th-stat-icon"><i class="fas fa-receipt"></i></span>
            <span class="th-stat-body">
                <span class="th-stat-label">{{ __('Paid orders') }}</span>
                <span class="th-stat-value">{{ $paymentsCount }}</span>
                <x-analytics-delta :value="$ordersDelta"/>
            </span>
        </div>

        <div class="th-stat">
            <span class="th-stat-icon"><i class="fas fa-server"></i></span>
            <span class="th-stat-body">
                <span class="th-stat-label">{{ __('New servers') }}</span>
                <span class="th-stat-value">{{ $newServers }}</span>
                <x-analytics-delta :value="$serversDelta"/>
            </span>
        </div>

        <div class="th-stat">
            <span class="th-stat-icon"><i class="fas fa-user-plus"></i></span>
            <span class="th-stat-body">
                <span class="th-stat-label">{{ __('New customers') }}</span>
                <span class="th-stat-value">{{ $users['new'] }}</span>
                <x-analytics-delta :value="$usersDelta"/>
            </span>
        </div>
    </div>

    {{-- ---------------- Recurring / forecast ---------------- --}}
    <div class="th-stats">
        <div class="th-stat">
            <span class="th-stat-icon"><i class="fas fa-sync-alt"></i></span>
            <span class="th-stat-body">
                <span class="th-stat-label">{{ __('Monthly recurring') }}</span>
                <span class="th-stat-value">{{ Currency::formatTrimmed($mrrCredits) }}</span>
                <span class="th-stat-note">{{ $credits_display_name }} / {{ __('mo') }}</span>
            </span>
        </div>

        <div class="th-stat">
            <span class="th-stat-icon"><i class="fas fa-chart-line"></i></span>
            <span class="th-stat-body">
                <span class="th-stat-label">{{ __('Revenue estimate') }}</span>
                <span class="th-stat-value">
                    {{ Currency::formatToCurrency($revenueForecast['value'], $currency) }}
                </span>
                <span class="th-stat-note">
                    {{ __('mean of the last :count months', ['count' => $revenueForecast['basis']]) }}
                </span>
            </span>
        </div>

        <div class="th-stat">
            <span class="th-stat-icon is-warning"><i class="fas fa-coins"></i></span>
            <span class="th-stat-body">
                <span class="th-stat-label">{{ __('Credits outstanding') }}</span>
                <span class="th-stat-value">{{ Currency::formatTrimmed($creditsOutstanding) }}</span>
                <span class="th-stat-note">{{ __('already paid for, not yet used') }}</span>
            </span>
        </div>

        <div class="th-stat">
            <span class="th-stat-icon {{ $churn > 10 ? 'is-danger' : '' }}"><i class="fas fa-user-slash"></i></span>
            <span class="th-stat-body">
                <span class="th-stat-label">{{ __('Cancelled servers') }}</span>
                <span class="th-stat-value">{{ $churn }}<sup>%</sup></span>
                <span class="th-stat-note">{{ $servers['canceled'] }} {{ __('of') }} {{ $servers['total'] }}</span>
            </span>
        </div>
    </div>

    {{-- ---------------- Charts ---------------- --}}
    <div class="row">
        <div class="col-lg-8">
            <div class="th-card mb-4">
                <div class="th-card-head">
                    <i class="fas fa-chart-area"></i>
                    <h3 class="th-card-title">{{ __('Revenue') }} ({{ $currency }})</h3>
                </div>
                <div class="th-card-body">
                    <div class="th-chart"><canvas id="revenueChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="th-card mb-4">
                <div class="th-card-head">
                    <i class="fas fa-credit-card"></i>
                    <h3 class="th-card-title">{{ __('By payment method') }}</h3>
                </div>
                <div class="th-card-body">
                    @if (count($revenueByMethod))
                        <div class="th-chart"><canvas id="methodChart"></canvas></div>
                    @else
                        <p class="text-muted mb-0">{{ __('No payments in this period.') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="th-card mb-4">
        <div class="th-card-head">
            <i class="fas fa-server"></i>
            <h3 class="th-card-title">{{ __('Servers created and cancelled') }}</h3>
        </div>
        <div class="th-card-body">
            <div class="th-chart"><canvas id="serverChart"></canvas></div>
        </div>
    </div>

    {{-- ---------------- Breakdown tables ---------------- --}}
    <div class="row">
        <div class="col-lg-6">
            <div class="th-card mb-4">
                <div class="th-card-head">
                    <i class="fas fa-cubes"></i>
                    <h3 class="th-card-title">{{ __('Most used products') }}</h3>
                </div>
                <div class="th-card-body p-0">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>{{ __('Product') }}</th>
                            <th class="text-right">{{ __('Servers') }}</th>
                            <th class="text-right">{{ __('per Month') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($topProducts as $product)
                            <tr>
                                <td>
                                    {{ $product['name'] }}
                                    <span class="d-block text-muted" style="font-size:.74rem">
                                        {{ $product['billing_period'] }}
                                    </span>
                                </td>
                                <td class="text-right">{{ $product['servers'] }}</td>
                                <td class="text-right">{{ Currency::formatTrimmed($product['mrr']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-muted">{{ __('No active servers.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="th-card mb-4">
                <div class="th-card-head">
                    <i class="fas fa-hdd"></i>
                    <h3 class="th-card-title">{{ __('Earnings per node') }}</h3>
                </div>
                <div class="th-card-body p-0">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>{{ __('Node') }}</th>
                            <th class="text-right">{{ __('Servers') }}</th>
                            <th class="text-right">{{ $credits_display_name }} / {{ __('per Month') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($serversByNode as $node)
                            <tr>
                                <td>{{ $node['name'] }}</td>
                                <td class="text-right">{{ $node['servers'] }}</td>
                                <td class="text-right">{{ Currency::formatTrimmed($node['mrr']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-muted">{{ __('No active servers.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="th-card mb-4">
                <div class="th-card-head">
                    <i class="fas fa-shopping-basket"></i>
                    <h3 class="th-card-title">{{ __('Best selling store items') }}</h3>
                </div>
                <div class="th-card-body p-0">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>{{ __('Item') }}</th>
                            <th class="text-right">{{ __('Orders') }}</th>
                            <th class="text-right">{{ __('Revenue') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($topShopProducts as $item)
                            <tr>
                                <td>
                                    {{ $item['name'] }}
                                    <span class="d-block text-muted" style="font-size:.74rem">{{ $item['type'] }}</span>
                                </td>
                                <td class="text-right">{{ $item['orders'] }}</td>
                                <td class="text-right">{{ Currency::formatToCurrency($item['total'], $currency) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-muted">{{ __('No orders in this period.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6">
            <div class="th-card mb-4">
                <div class="th-card-head">
                    <i class="fas fa-crown"></i>
                    <h3 class="th-card-title">{{ __('Top customers') }}</h3>
                </div>
                <div class="th-card-body p-0">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>{{ __('Customer') }}</th>
                            <th class="text-right">{{ __('Orders') }}</th>
                            <th class="text-right">{{ __('Spent') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($topCustomers as $customer)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.users.show', $customer['id']) }}">{{ $customer['name'] }}</a>
                                </td>
                                <td class="text-right">{{ $customer['orders'] }}</td>
                                <td class="text-right">{{ Currency::formatToCurrency($customer['total'], $currency) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-muted">{{ __('No orders in this period.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="th-card mb-4">
                <div class="th-card-head">
                    <i class="fas fa-info-circle"></i>
                    <h3 class="th-card-title">{{ __('At a glance') }}</h3>
                </div>
                <div class="th-card-body p-0">
                    <table class="table">
                        <tbody>
                        <tr>
                            <td>{{ __('Active servers') }}</td>
                            <td class="text-right">{{ $servers['active'] }}</td>
                        </tr>
                        <tr>
                            <td>{{ __('Suspended servers') }}</td>
                            <td class="text-right">{{ $servers['suspended'] }}</td>
                        </tr>
                        <tr>
                            <td>{{ __('Customers with a server') }}</td>
                            <td class="text-right">{{ $users['withServers'] }} / {{ $users['total'] }}</td>
                        </tr>
                        <tr>
                            <td>{{ __('Average per paying customer') }}</td>
                            <td class="text-right">
                                {{ Currency::formatTrimmed((int) round($arpu)) }} {{ $credits_display_name }}
                            </td>
                        </tr>
                        <tr>
                            <td>{{ __('Orders with a coupon') }}</td>
                            <td class="text-right">{{ $couponUses }}</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="{{ asset('plugins/chart.js/Chart.min.js') }}"></script>
    <script>
        (function () {
            if (typeof Chart === 'undefined') return;

            const grid = 'rgba(255,255,255,0.06)';
            const tick = '#8891a5';
            const brand = '#065bf1';
            const brandLight = '#3b7ffa';

            Chart.defaults.global.defaultFontFamily = 'Poppins, sans-serif';
            Chart.defaults.global.defaultFontColor = tick;

            const axes = {
                xAxes: [{gridLines: {color: grid, zeroLineColor: grid}, ticks: {fontColor: tick}}],
                yAxes: [{gridLines: {color: grid, zeroLineColor: grid}, ticks: {fontColor: tick, beginAtZero: true}}]
            };

            const area = document.getElementById('revenueChart');
            if (area) {
                const ctx = area.getContext('2d');
                const fill = ctx.createLinearGradient(0, 0, 0, 260);
                fill.addColorStop(0, 'rgba(6,91,241,0.35)');
                fill.addColorStop(1, 'rgba(6,91,241,0)');

                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: @json($revenueSeries['labels']),
                        datasets: [{
                            label: '{{ __('Revenue') }} ({{ $currency }})',
                            data: @json($revenueSeries['data']),
                            borderColor: brandLight,
                            backgroundColor: fill,
                            borderWidth: 2,
                            pointRadius: 0,
                            pointHoverRadius: 4,
                            lineTension: 0.3,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        legend: {display: false},
                        scales: axes
                    }
                });
            }

            const servers = document.getElementById('serverChart');
            if (servers) {
                new Chart(servers.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: @json($serverSeries['labels']),
                        datasets: [
                            {
                                label: '{{ __('Created') }}',
                                data: @json($serverSeries['created']),
                                backgroundColor: brand
                            },
                            {
                                label: '{{ __('Cancelled') }}',
                                data: @json($serverSeries['lost']),
                                backgroundColor: '#ef4444'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        legend: {display: true, position: 'bottom', labels: {fontColor: tick, boxWidth: 12}},
                        scales: axes
                    }
                });
            }

            const method = document.getElementById('methodChart');
            if (method) {
                new Chart(method.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: @json(array_keys($revenueByMethod)),
                        datasets: [{
                            data: @json(array_map(fn ($v) => round($v / 1000, 2), array_values($revenueByMethod))),
                            backgroundColor: ['#065bf1', '#3b7ffa', '#22c55e', '#f5a524', '#a855f7', '#ef4444'],
                            borderColor: '#1a1a22',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutoutPercentage: 62,
                        legend: {position: 'bottom', labels: {fontColor: tick, boxWidth: 12}}
                    }
                });
            }
        })();
    </script>
@endsection
