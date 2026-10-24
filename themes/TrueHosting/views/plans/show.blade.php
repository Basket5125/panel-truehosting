@extends('layouts.main')

@section('page-title', $plan->name)

@section('content')
    @php
        // Only resources the admin actually priced are configurable — in
        // either currency, so a plan sold for money alone still offers them.
        $fields = [];
        foreach (\App\Models\CustomPlan::RESOURCES as $key => $meta) {
            if (!$plan->offers($key)) { continue; }
            $fields[$key] = [
                'label' => match ($key) {
                    'cpu' => __('CPU'), 'memory' => __('RAM'), 'disk' => __('Disk'),
                    'backups' => __('Backups'), 'databases' => __('MySQL Databases'),
                    default => __('Allocations'),
                },
                'min' => (int) $plan->{$key . '_min'},
                'max' => (int) $plan->{$key . '_max'},
                'step' => (int) ($plan->{$key . '_step'} ?? 1),
                'per' => $meta['per'],
                'unit_price' => (int) $plan->{$meta['price']},
                'money_unit_price' => $plan->moneyUnitPrice($key),
                'suffix' => match ($key) {
                    'cpu' => '%', 'memory', 'disk' => 'MB', default => '',
                },
            ];
        }
    @endphp

    <div class="th-page-head">
        <h2 class="th-page-title">{{ $plan->name }}</h2>
        <p class="th-page-sub">{{ $plan->description ?: __('Pick your resources. The price updates as you go.') }}</p>
    </div>

    <form method="POST" action="{{ route('plans.order', $plan->id) }}"
          x-data="planBuilder()" x-init="recalc(); $watch('mode', () => recalc())">
        @csrf

        <div class="row">
            <div class="col-lg-7">
                <div class="th-card mb-4">
                    <div class="th-card-head"><i class="fas fa-sliders-h"></i>
                        <h3 class="th-card-title">{{ __('Resources') }}</h3>
                    </div>
                    <div class="th-card-body">
                        @foreach ($fields as $key => $f)
                            <div class="th-slider">
                                <div class="th-slider-head">
                                    <label class="th-label mb-0" for="f-{{ $key }}">{{ $f['label'] }}</label>
                                    <output class="th-slider-value">
                                        <span x-text="display('{{ $key }}')"></span>
                                    </output>
                                </div>
                                <input id="f-{{ $key }}" type="range" class="th-range"
                                       name="{{ $key }}"
                                       min="{{ $f['min'] }}" max="{{ $f['max'] }}" step="{{ $f['step'] }}"
                                       x-model.number="res.{{ $key }}" @input="recalc()">
                                <div class="th-slider-scale">
                                    <span>{{ $f['min'] }}{{ $f['suffix'] }}</span>
                                    <span>{{ $f['max'] }}{{ $f['suffix'] }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="th-card mb-4">
                    <div class="th-card-head"><i class="fas fa-gamepad"></i>
                        <h3 class="th-card-title">{{ __('Server') }}</h3>
                    </div>
                    <div class="th-card-body">
                        <div class="th-field">
                            <label class="th-label" for="name">{{ __('Name') }}</label>
                            <input id="name" class="form-control" name="name" required maxlength="191"
                                   value="{{ old('name') }}" placeholder="{{ __('My server') }}">
                        </div>

                        <div class="th-field">
                            <label class="th-label" for="egg">{{ __('Oprogramowanie / Gry') }}</label>
                            <select id="egg" class="form-control" name="egg" required>
                                @forelse ($eggs as $egg)
                                    <option value="{{ $egg->id }}" @selected(old('egg') == $egg->id)>
                                        {{ $egg->nest?->name ? $egg->nest->name . ' · ' : '' }}{{ $egg->name }}
                                    </option>
                                @empty
                                    <option value="" disabled>{{ __('No software available') }}</option>
                                @endforelse
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="th-card th-sticky">
                    <div class="th-card-head"><i class="fas fa-receipt"></i>
                        <h3 class="th-card-title">{{ __('Your configuration') }}</h3>
                    </div>
                    <div class="th-card-body">
                        <div class="th-summary" style="margin-top:0;padding-top:0;border-top:0">
                            <template x-for="line in lines" :key="line.key">
                                <div class="th-summary-row">
                                    <span>
                                        <span x-text="line.label"></span>
                                        <span class="text-muted" x-text="'× ' + line.units"></span>
                                        <template x-if="line.discount > 0">
                                            <span class="th-discount-tag"
                                                  x-text="'-' + Math.round(line.discount * 100) + '%'"></span>
                                        </template>
                                    </span>
                                    <span x-text="amount(line.total)"></span>
                                </div>
                            </template>

                            <div class="th-summary-total">
                                <span>{{ __('Total') }}</span>
                                <strong><span x-text="amount(total)"></span></strong>
                            </div>

                            <p class="text-muted" style="font-size:.78rem">
                                <span x-text="unitLabel()"></span> / {{ $plan->billing_period }}
                            </p>
                        </div>

                        <template x-if="largeOrder">
                            <div class="alert alert-info">
                                {{ __('This size is deployed on our stronger nodes.') }}
                            </div>
                        </template>

                        @if ($plan->allowsCredits() && $plan->allowsDirect())
                            <div class="th-field mt-3">
                                <label class="th-label">{{ __('How do you want to pay?') }}</label>
                                <div class="custom-control custom-radio mb-1">
                                    <input type="radio" class="custom-control-input" id="pm-credits"
                                           name="payment_mode" value="credits" x-model="mode">
                                    <label class="custom-control-label" for="pm-credits">
                                        {{ __('From credits') }}
                                        <span class="text-muted" x-text="'(' + inCredits() + ')'"></span>
                                    </label>
                                </div>
                                <div class="custom-control custom-radio">
                                    <input type="radio" class="custom-control-input" id="pm-direct"
                                           name="payment_mode" value="direct" x-model="mode">
                                    <label class="custom-control-label" for="pm-direct">
                                        {{ __('Pay now') }}
                                        <span class="text-muted" x-text="'(' + inMoney() + ')'"></span>
                                    </label>
                                </div>
                            </div>
                        @elseif ($plan->allowsDirect())
                            <input type="hidden" name="payment_mode" value="direct">
                            <p class="text-muted mt-3 mb-0" style="font-size:.8rem">
                                {{ __('Paid with money each') }} {{ $plan->billing_period }}:
                                <strong><span x-text="inMoney()"></span></strong>
                            </p>
                        @else
                            <input type="hidden" name="payment_mode" value="credits">
                        @endif

                        <button type="submit" class="th-btn th-btn-primary th-btn-block mt-2"
                                @if ($eggs->isEmpty()) disabled @endif>
                            <i class="fas fa-plus"></i>{{ __('Create Server') }}
                        </button>

                        <p class="text-muted text-center mt-3 mb-0" style="font-size:.76rem">
                            {{ __('The final price is confirmed by the server when you order.') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <script>
        function planBuilder() {
            // Mirrors the server-side pricing so the figure moves without a
            // round trip. The order itself is always priced again on the server.
            const fields = @json($fields);
            const discountPerUnit = {{ (float) $plan->discount_per_unit }};
            const discountMax = {{ (float) $plan->discount_max }};
            const largeMemory = {{ $plan->large_order_memory !== null ? (int) $plan->large_order_memory : 'null' }};
            const largeCpu = {{ $plan->large_order_cpu !== null ? (int) $plan->large_order_cpu : 'null' }};
            const locale = '{{ str_replace('_', '-', app()->getLocale()) }}';
            const currency = '{{ $plan->currency_code }}';
            const creditsName = @json($credits_display_name);

            const start = {};
            Object.keys(fields).forEach(k => start[k] = fields[k].min);

            return {
                res: start,
                // Which price list the summary is showing. The server decides
                // again on order; this only drives what the customer reads.
                mode: '{{ $plan->allowsCredits() ? 'credits' : 'direct' }}',
                lines: [],
                total: 0,
                moneyTotal: 0,
                creditTotal: 0,
                largeOrder: false,

                display(key) {
                    const f = fields[key];
                    const v = this.res[key];
                    if (key === 'cpu') return (v / 100).toFixed(1) + ' vCore';
                    if (key === 'memory' || key === 'disk') return (v / 1024).toFixed(1) + ' GB';
                    return v;
                },

                discountFor(units) {
                    if (discountPerUnit <= 0) return 0;
                    return Math.min(Math.max(0, units - 1) * discountPerUnit, discountMax) / 100;
                },

                /** Credit figures are plain numbers, money carries its symbol. */
                amount(value) {
                    return this.mode === 'direct' ? this.asMoney(value) : this.asCredits(value);
                },

                asCredits(value) {
                    return (value / 1000).toLocaleString(locale, {
                        minimumFractionDigits: 2, maximumFractionDigits: 2,
                    });
                },

                asMoney(value) {
                    return (value / 1000).toLocaleString(locale, {
                        style: 'currency', currency: currency,
                    });
                },

                inCredits() {
                    return this.asCredits(this.creditTotal) + ' ' + creditsName;
                },

                inMoney() {
                    return this.asMoney(this.moneyTotal);
                },

                unitLabel() {
                    return this.mode === 'direct' ? currency : creditsName;
                },

                /** Mirrors CustomPlan::breakdown() for one of the two price lists. */
                priceList(priceKey) {
                    const lines = [];
                    let total = 0;

                    Object.keys(fields).forEach(key => {
                        const f = fields[key];
                        const units = this.res[key] / f.per;
                        if (units <= 0) return;

                        const discount = this.discountFor(units);
                        const lineTotal = Math.round(units * f[priceKey] * (1 - discount));

                        lines.push({
                            key: key,
                            label: f.label,
                            units: Number.isInteger(units) ? units : units.toFixed(1),
                            discount: discount,
                            total: lineTotal,
                        });
                        total += lineTotal;
                    });

                    return { lines: lines, total: total };
                },

                recalc() {
                    const credits = this.priceList('unit_price');
                    const money = this.priceList('money_unit_price');

                    this.creditTotal = credits.total;
                    this.moneyTotal = money.total;

                    const active = this.mode === 'direct' ? money : credits;
                    this.lines = active.lines;
                    this.total = active.total;

                    // Mirrors CustomPlan::isLargeOrder(): either threshold is enough.
                    this.largeOrder =
                        (largeMemory !== null && (this.res.memory ?? 0) > largeMemory)
                        || (largeCpu !== null && (this.res.cpu ?? 0) > largeCpu);
                },
            };
        }
    </script>
@endsection
