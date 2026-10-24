@extends('layouts.main')

@section('page-title', $plan->exists ? __('Edit plan') : __('Create plan'))

@section('content')
    @php
        $periods = ['hourly','daily','weekly','monthly','quarterly','half-annually','annually'];
        $val = fn ($field, $default = null) => old($field, $plan->exists ? $plan->{$field} : $default);
        $money = fn ($field, $default = 0) => old($field, $plan->exists ? Currency::formatForForm($plan->{$field}) : $default);
    @endphp

    <div class="th-page-head">
        <h2 class="th-page-title">{{ $plan->exists ? __('Edit plan') : __('Create plan') }}</h2>
        <p class="th-page-sub">{{ __('Set what one unit costs. The customer picks how many.') }}</p>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0 pl-3">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ $plan->exists ? route('admin.customplans.update', $plan->id) : route('admin.customplans.store') }}">
        @csrf
        @if ($plan->exists) @method('PATCH') @endif

        <div class="row">
            <div class="col-lg-6">
                <div class="th-card mb-4">
                    <div class="th-card-head"><i class="fas fa-cube"></i>
                        <h3 class="th-card-title">{{ __('Basics') }}</h3>
                    </div>
                    <div class="th-card-body">
                        <div class="th-field">
                            <label class="th-label" for="name">{{ __('Name') }}</label>
                            <input id="name" class="form-control" name="name" value="{{ $val('name') }}" required>
                        </div>
                        <div class="th-field">
                            <label class="th-label" for="description">{{ __('Description') }}</label>
                            <input id="description" class="form-control" name="description" value="{{ $val('description') }}">
                        </div>
                        <div class="th-field">
                            <label class="th-label" for="billing_period">{{ __('Billing Period') }}</label>
                            <select id="billing_period" class="form-control" name="billing_period">
                                @foreach ($periods as $period)
                                    <option value="{{ $period }}"
                                        @selected($val('billing_period', 'monthly') === $period)>{{ $period }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="disabled" name="disabled" value="1"
                                   @checked($val('disabled', false))>
                            <label class="custom-control-label" for="disabled">{{ __('Disabled') }}</label>
                        </div>
                    </div>
                </div>

                <div class="th-card mb-4">
                    <div class="th-card-head"><i class="fas fa-coins"></i>
                        <h3 class="th-card-title">{{ __('Price per unit') }}</h3>
                    </div>
                    <div class="th-card-body">
                        <div class="th-pricegrid">
                            <span></span>
                            <span class="th-pricegrid-head">{{ $credits_display_name }}</span>
                            <span class="th-pricegrid-head" id="moneyColumnHead">
                                {{ strtoupper($val('currency_code', 'EUR')) }}
                            </span>

                            @foreach ([
                                'cpu' => __('per vCPU') . ' (100%)',
                                'memory' => __('per GB RAM'),
                                'disk' => __('per GB disk'),
                                'backup' => __('per backup'),
                                'database' => __('per database'),
                                'allocation' => __('per port'),
                            ] as $key => $label)
                                <label class="th-label mb-0" for="price_{{ $key }}">{{ $label }}</label>
                                <input id="price_{{ $key }}" class="form-control" name="price_{{ $key }}"
                                       type="number" step="0.01" min="0"
                                       value="{{ $money('price_' . $key, '0.00') }}" required>
                                <input id="money_price_{{ $key }}" class="form-control" name="money_price_{{ $key }}"
                                       type="number" step="0.01" min="0"
                                       value="{{ $money('money_price_' . $key, '0.00') }}" required>
                            @endforeach
                        </div>
                        <p class="text-muted mb-0 mt-3" style="font-size:.8rem">
                            {{ __('A resource priced at 0 in both columns is hidden from the configurator. The customer is charged from the column matching the payment they picked.') }}
                        </p>
                    </div>
                </div>

                <div class="th-card mb-4">
                    <div class="th-card-head"><i class="far fa-credit-card"></i>
                        <h3 class="th-card-title">{{ __('How it is paid') }}</h3>
                    </div>
                    <div class="th-card-body">
                        <div class="th-field">
                            <label class="th-label" for="payment_modes">{{ __('Allowed payment') }}</label>
                            <select id="payment_modes" class="form-control" name="payment_modes">
                                @foreach ([
                                    'credits' => __('Credits only'),
                                    'direct' => __('Direct payment only'),
                                    'both' => __('Customer chooses'),
                                ] as $value => $label)
                                    <option value="{{ $value }}" @selected($val('payment_modes', 'credits') === $value)>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="th-field">
                                    <label class="th-label" for="credit_rate">
                                        {{ __('Money per 1 credit') }}
                                        <i class="fas fa-info-circle th-hint"
                                           data-toggle="popover" data-trigger="hover"
                                           data-content="{{ __('Only used by plans with no money price list: the money total is then the credit total times this rate. Leave it at 0 once you have filled the currency column above.') }}"></i>
                                    </label>
                                    <input id="credit_rate" class="form-control" type="number" step="0.0001" min="0"
                                           name="credit_rate" value="{{ $val('credit_rate', 0) }}">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="th-field">
                                    <label class="th-label" for="currency_code">{{ __('Currency') }}</label>
                                    <input id="currency_code" class="form-control" maxlength="3" name="currency_code"
                                           value="{{ $val('currency_code', 'EUR') }}" required
                                           oninput="document.getElementById('moneyColumnHead').textContent = this.value.toUpperCase()">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="th-field">
                                    <label class="th-label" for="grace_days">{{ __('Grace days') }}</label>
                                    <input id="grace_days" class="form-control" type="number" min="0" max="60"
                                           name="grace_days" value="{{ $val('grace_days', 3) }}" required>
                                </div>
                            </div>
                        </div>
                        <p class="text-muted mb-0" style="font-size:.8rem">
                            {{ __('Direct payments are charged from the currency column of the price list. After the paid period ends, the server keeps running for the grace days, then it is suspended.') }}
                        </p>
                    </div>
                </div>

                <div class="th-card mb-4">
                    <div class="th-card-head"><i class="fas fa-percent"></i>
                        <h3 class="th-card-title">{{ __('Volume discount') }}</h3>
                    </div>
                    <div class="th-card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="th-field">
                                    <label class="th-label" for="discount_per_unit">{{ __('% cheaper per extra unit') }}</label>
                                    <input id="discount_per_unit" class="form-control" name="discount_per_unit"
                                           type="number" step="0.1" min="0" max="100"
                                           value="{{ $val('discount_per_unit', 0) }}" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="th-field">
                                    <label class="th-label" for="discount_max">{{ __('Maximum discount %') }}</label>
                                    <input id="discount_max" class="form-control" name="discount_max"
                                           type="number" step="0.1" min="0" max="100"
                                           value="{{ $val('discount_max', 0) }}" required>
                                </div>
                            </div>
                        </div>
                        <p class="text-muted mb-0" style="font-size:.8rem">
                            {{ __('The first unit is always full price. Each further unit of the same resource lowers that resource\'s price, up to the cap.') }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="th-card mb-4">
                    <div class="th-card-head"><i class="fas fa-sliders-h"></i>
                        <h3 class="th-card-title">{{ __('What the customer may choose') }}</h3>
                    </div>
                    <div class="th-card-body p-0">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Resource') }}</th>
                                <th>{{ __('Min') }}</th>
                                <th>{{ __('Max') }}</th>
                                <th>{{ __('Step') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ([
                                ['cpu', __('CPU') . ' (%)', true],
                                ['memory', __('RAM') . ' (MB)', true],
                                ['disk', __('Disk') . ' (MB)', true],
                                ['backups', __('Backups'), false],
                                ['databases', __('MySQL Databases'), false],
                                ['allocations', __('Allocations'), false],
                            ] as [$key, $label, $hasStep])
                                <tr>
                                    <td>{{ $label }}</td>
                                    <td><input class="form-control" type="number" name="{{ $key }}_min"
                                               value="{{ $val($key . '_min') }}" required></td>
                                    <td><input class="form-control" type="number" name="{{ $key }}_max"
                                               value="{{ $val($key . '_max') }}" required></td>
                                    <td>
                                        @if ($hasStep)
                                            <input class="form-control" type="number" name="{{ $key }}_step"
                                                   value="{{ $val($key . '_step') }}" required>
                                        @else
                                            <span class="text-muted">1</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="th-card mb-4">
                    <div class="th-card-head"><i class="fas fa-server"></i>
                        <h3 class="th-card-title">{{ __('Where it is deployed') }}</h3>
                    </div>
                    <div class="th-card-body">
                        <div class="th-field">
                            <label class="th-label" for="large_order_memory">
                                {{ __('Large order above (MB RAM)') }}
                            </label>
                            <input id="large_order_memory" class="form-control" type="number" min="1"
                                   name="large_order_memory" value="{{ $val('large_order_memory') }}"
                                   placeholder="{{ __('empty = no limit') }}">
                            <small class="text-muted">
                                {{ __('Above this, only nodes ticked as "takes large orders" are used.') }}
                            </small>
                        </div>

                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Node') }}</th>
                                <th style="width:110px">{{ __('Priority') }}</th>
                                <th style="width:120px">{{ __('Large orders') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($nodes as $node)
                                @php($pivot = $selectedNodes[$node->id] ?? null)
                                <tr>
                                    <td>
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input"
                                                   id="node{{ $node->id }}" name="nodes[]" value="{{ $node->id }}"
                                                   @checked($pivot)>
                                            <label class="custom-control-label" for="node{{ $node->id }}">
                                                {{ $node->name }}
                                            </label>
                                        </div>
                                    </td>
                                    <td>
                                        <input class="form-control" type="number"
                                               name="node_priority[{{ $node->id }}]"
                                               value="{{ $pivot?->pivot->priority ?? 0 }}">
                                    </td>
                                    <td>
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input"
                                                   id="large{{ $node->id }}"
                                                   name="node_allow_large[{{ $node->id }}]" value="1"
                                                   @checked($pivot?->pivot->allow_large ?? true)>
                                            <label class="custom-control-label" for="large{{ $node->id }}"></label>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted">{{ __('Sync your nodes first.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                        <small class="text-muted">
                            {{ __('Lower priority is tried first. If a node is full or unreachable, the next one is used.') }}
                        </small>
                    </div>
                </div>

                <div class="th-card mb-4">
                    <div class="th-card-head"><i class="fas fa-gamepad"></i>
                        <h3 class="th-card-title">{{ __('Available software') }}</h3>
                    </div>
                    <div class="th-card-body" style="max-height:320px;overflow-y:auto">
                        @forelse ($eggs as $egg)
                            <div class="custom-control custom-checkbox mb-1">
                                <input type="checkbox" class="custom-control-input" id="egg{{ $egg->id }}"
                                       name="eggs[]" value="{{ $egg->id }}"
                                       @checked(in_array($egg->id, $selectedEggs))>
                                <label class="custom-control-label" for="egg{{ $egg->id }}">
                                    {{ $egg->name }}
                                    <span class="text-muted">{{ $egg->nest?->name }}</span>
                                </label>
                            </div>
                        @empty
                            <span class="text-muted">{{ __('Sync your eggs first.') }}</span>
                        @endforelse
                    </div>
                </div>

                <div class="th-card mb-4">
                    <div class="th-card-head"><i class="fas fa-cogs"></i>
                        <h3 class="th-card-title">{{ __('Fixed values') }}</h3>
                    </div>
                    <div class="th-card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="th-field">
                                    <label class="th-label" for="swap">{{ __('Swap') }}</label>
                                    <input id="swap" class="form-control" type="number" name="swap"
                                           value="{{ $val('swap', 0) }}" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="th-field">
                                    <label class="th-label" for="io">{{ __('IO') }}</label>
                                    <input id="io" class="form-control" type="number" name="io"
                                           value="{{ $val('io', 500) }}" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="th-field">
                                    <label class="th-label" for="minimum_credits">{{ __('Minimum credits') }}</label>
                                    <input id="minimum_credits" class="form-control" type="number" step="0.01"
                                           name="minimum_credits"
                                           value="{{ old('minimum_credits', $plan->exists && $plan->minimum_credits != -1 ? Currency::formatForForm($plan->minimum_credits) : -1) }}"
                                           required>
                                </div>
                            </div>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="oom_killer" name="oom_killer"
                                   value="1" @checked($val('oom_killer', false))>
                            <label class="custom-control-label" for="oom_killer">{{ __('OOM Killer') }}</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end" style="gap:.5rem">
            <a href="{{ route('admin.customplans.index') }}" class="th-btn th-btn-ghost">{{ __('Back') }}</a>
            <button type="submit" class="th-btn th-btn-primary">{{ __('Save changes') }}</button>
        </div>
    </form>
@endsection
