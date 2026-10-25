@extends('layouts.main')

@section('page-title', __('Trial server'))

@section('content')
    <div class="th-page-head">
        <h2 class="th-page-title">{{ __('Try a server for free') }}</h2>
        <p class="th-page-sub">
            {{ trans_choice('It runs for :count hour and costs nothing.|It runs for :count hours and costs nothing.', $durationHours, ['count' => $durationHours]) }}
            @if ($maxExtensions > 0)
                {{ trans_choice('You can extend it :count more time.|You can extend it :count more times.', $maxExtensions, ['count' => $maxExtensions]) }}
            @endif
        </p>
    </div>

    @if ($blockedReason)
        <div class="th-empty">
            <i class="fas fa-lock"></i>
            <h3 class="th-empty-title">{{ __('Not available yet') }}</h3>
            <p class="th-empty-text">{{ $blockedReason }}</p>
            <a href="{{ route('profile.index') }}" class="th-btn th-btn-ghost">
                <i class="fas fa-user"></i>{{ __('Profile') }}
            </a>
        </div>
    @else
        @if (session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0 pl-3">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('trial.store') }}" novalidate
              x-data="trialWizard()" x-init="init()"
              @submit="if (!canSubmit()) { attempted = true; $event.preventDefault(); }">
            @csrf

            <div class="th-steps">
                <div class="th-step" :class="step >= 1 ? 'is-done' : ''">
                    <span class="th-step-no">1</span>
                    <span class="th-step-label">{{ __('Pick a package') }}</span>
                </div>
                <div class="th-step-line"></div>
                <div class="th-step" :class="step >= 2 ? 'is-done' : ''">
                    <span class="th-step-no">2</span>
                    <span class="th-step-label">{{ __('Make it yours') }}</span>
                </div>
            </div>

            {{-- ---------------- Step 1 ---------------- --}}
            <div x-show="step === 1">
                <div class="th-grid">
                    @foreach ($products as $product)
                        <label class="th-plan th-trial-pick"
                               :class="product === '{{ $product->id }}' ? 'is-picked' : ''">
                            <input type="radio" class="th-visually-hidden" name="product"
                                   value="{{ $product->id }}" x-model="product">

                            <h3 class="th-plan-name">{{ $product->name }}</h3>
                            <p class="th-plan-desc">{{ $product->description }}</p>

                            <ul class="th-specs">
                                <li class="th-spec">
                                    <i class="fas fa-microchip"></i>
                                    {{ $product->cpu == 0 ? __('Unlimited') : $product->cpu / 100 }} {{ __('vCores') }}
                                </li>
                                <li class="th-spec">
                                    <i class="fas fa-memory"></i>
                                    {{ $product->memory == 0 ? __('Unlimited') : $product->memory . ' MB' }}
                                </li>
                                <li class="th-spec">
                                    <i class="fas fa-hdd"></i>
                                    {{ $product->disk == 0 ? __('Unlimited') : $product->disk . ' MB' }}
                                </li>
                            </ul>

                            <div class="th-card-foot">
                                <span class="th-plan-note mb-0">
                                    {{ __('Afterwards') }}: {{ $product->display_price }} {{ $credits_display_name }}
                                </span>
                            </div>
                        </label>
                    @endforeach
                </div>

                <div class="th-actions mt-4">
                    <button type="button" class="th-btn th-btn-primary"
                            :disabled="!product" @click="step = 2">
                        {{ __('Next') }}<i class="fas fa-arrow-right" style="font-size:.74rem"></i>
                    </button>
                </div>
            </div>

            {{-- ---------------- Step 2 ---------------- --}}
            <div x-show="step === 2" x-cloak>
                <div class="row">
                    <div class="col-lg-7">
                        <div class="th-card mb-4">
                            <div class="th-card-head">
                                <i class="fas fa-sliders-h"></i>
                                <h3 class="th-card-title">{{ __('Make it yours') }}</h3>
                            </div>
                            <div class="th-card-body">
                                <div class="th-field">
                                    <label class="th-label" for="name">{{ __('Name') }}</label>
                                    <input id="name" class="form-control" name="name" maxlength="191"
                                           placeholder="{{ __('My server') }}" x-model="name"
                                           :class="attempted && !name.trim() ? 'is-invalid' : ''">
                                </div>

                                <div class="th-field">
                                    <label class="th-label" for="egg">{{ __('Software / Games') }}</label>
                                    <select id="egg" class="form-control" name="egg" x-model="egg"
                                            :class="attempted && !egg ? 'is-invalid' : ''">
                                        <option value="">{{ __('Please select software ...') }}</option>
                                        <template x-for="option in eggs()" :key="option.id">
                                            <option :value="option.id" x-text="option.name"></option>
                                        </template>
                                    </select>
                                </div>

                                <template x-if="variables().length > 0">
                                    <div>
                                        <p class="th-label mt-3 mb-2">{{ __('Software options') }}</p>

                                        <template x-for="variable in variables()" :key="variable.env_variable">
                                            <div class="th-field">
                                                <label class="th-label" :for="'var-' + variable.env_variable">
                                                    <span x-text="variable.name"></span>
                                                    <i class="fas fa-info-circle th-hint" x-show="variable.description"
                                                       data-toggle="popover" data-trigger="hover"
                                                       :data-content="variable.description"></i>
                                                </label>

                                                <template x-if="variable.options.length > 0">
                                                    <select class="form-control"
                                                            :id="'var-' + variable.env_variable"
                                                            :name="'variables[' + variable.env_variable + ']'">
                                                        <template x-for="choice in variable.options" :key="choice">
                                                            <option :value="choice" x-text="choice"
                                                                    :selected="choice === variable.default_value"></option>
                                                        </template>
                                                    </select>
                                                </template>

                                                <template x-if="variable.options.length === 0">
                                                    <input type="text" class="form-control"
                                                           :id="'var-' + variable.env_variable"
                                                           :name="'variables[' + variable.env_variable + ']'"
                                                           :value="variable.default_value"
                                                           :placeholder="variable.default_value">
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="th-card th-sticky">
                            <div class="th-card-head">
                                <i class="fas fa-flask"></i>
                                <h3 class="th-card-title">{{ __('Your trial') }}</h3>
                            </div>
                            <div class="th-card-body">
                                <div class="th-summary" style="margin-top:0;padding-top:0;border-top:0">
                                    <div class="th-summary-row">
                                        <span>{{ __('Package') }}</span>
                                        <span x-text="productName()"></span>
                                    </div>
                                    <div class="th-summary-row">
                                        <span>{{ __('Runs for') }}</span>
                                        <span>{{ $durationHours }} h</span>
                                    </div>
                                    <div class="th-summary-total">
                                        <span>{{ __('Total') }}</span>
                                        <strong>{{ __('Free') }}</strong>
                                    </div>
                                </div>

                                <p class="text-muted" style="font-size:.78rem">
                                    @if ($endAction === 'delete')
                                        {{ __('When the time is up the server is removed.') }}
                                    @else
                                        {{ __('When the time is up the server is paused, and buying it later picks up where you left off.') }}
                                    @endif
                                </p>

                                @if ($requiresCaptcha && $recaptchaVersion)
                                    <div class="th-field">
                                        @switch($recaptchaVersion)
                                            @case('v2')
                                                {!! htmlFormSnippet() !!}
                                                @break
                                            @case('v3')
                                                {!! RecaptchaV3::field('recaptchathree') !!}
                                                @break
                                            @case('turnstile')
                                                <x-turnstile-widget theme="dark" size="normal"/>
                                                @break
                                        @endswitch
                                        @error('cf-turnstile-response')
                                            <span class="text-danger"><small><strong>{{ $message }}</strong></small></span>
                                        @enderror
                                        @error('g-recaptcha-response')
                                            <span class="text-danger"><small><strong>{{ $message }}</strong></small></span>
                                        @enderror
                                    </div>
                                @endif

                                <div x-cloak x-show="attempted && !canSubmit()" class="th-required">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <div>
                                        <strong>{{ __('Finish the configuration') }}</strong>
                                        <ul>
                                            <template x-if="!name.trim()">
                                                <li>{{ __('Enter a name for the server') }}</li>
                                            </template>
                                            <template x-if="!egg">
                                                <li>{{ __('Choose the software') }}</li>
                                            </template>
                                        </ul>
                                    </div>
                                </div>

                                <button type="submit" class="th-btn th-btn-primary th-btn-block mt-2">
                                    <i class="fas fa-flask"></i>{{ __('Create trial server') }}
                                </button>

                                <button type="button" class="th-btn th-btn-ghost th-btn-block mt-2"
                                        @click="step = 1">
                                    <i class="fas fa-arrow-left" style="font-size:.74rem"></i>{{ __('Back') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    @endif

    <script>
        function trialWizard() {
            const catalogue = @json($catalogue);

            return {
                step: 1,
                product: @json(old('product', '')),
                egg: @json(old('egg', '')),
                name: @json(old('name', '')),
                attempted: false,

                init() {
                    // Coming back from a rejected submission, pick up where the
                    // customer was rather than starting over.
                    if (this.product) this.step = 2;

                    this.$watch('egg', () => this.$nextTick(() => {
                        $('[data-toggle="popover"]').popover();
                    }));
                },

                eggs() {
                    return catalogue[this.product]?.eggs ?? [];
                },

                productName() {
                    return catalogue[this.product]?.name ?? '—';
                },

                variables() {
                    return this.eggs().find(e => String(e.id) === String(this.egg))?.variables ?? [];
                },

                canSubmit() {
                    return Boolean(this.product) && Boolean(this.egg) && this.name.trim().length > 0;
                },
            };
        }
    </script>
@endsection
