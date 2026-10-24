@extends('layouts.main')

@section('page-title', __('Checkout'))

@section('content')
    @php
        $typeLabel = strtolower($product->type) == 'credits' ? $credits_display_name : $product->type;
        $amount = $product->type == 'Credits'
            ? Currency::formatForDisplay($product->quantity)
            : $product->quantity;
        $icon = strtolower($product->type) == 'credits'
            ? 'fa-coins'
            : (strtolower($product->type) == 'server slots' ? 'fa-server' : 'fa-box');
    @endphp

    <div class="th-page-head d-flex flex-wrap align-items-end justify-content-between" style="gap:1rem">
        <div>
            <h2 class="th-page-title">{{ __('Checkout') }}</h2>
            <p class="th-page-sub">{{ __('One step left. Review your order and pay.') }}</p>
        </div>
        <a href="{{ route('store.index') }}" class="th-btn th-btn-ghost">
            <i class="fas fa-arrow-left" style="font-size:.74rem"></i>{{ __('Back to store') }}
        </a>
    </div>

    <div x-data="couponForm()">
        <form id="payment_form" action="{{ route('payment.pay') }}" method="POST">
            @csrf
            @method('post')

            <div class="row">
                {{-- ---------------- Left: how to pay ---------------- --}}
                <div class="col-lg-7">
                    @if (!$productIsFree)
                        <div class="th-card mb-4">
                            <div class="th-card-head">
                                <i class="fas fa-credit-card"></i>
                                <h3 class="th-card-title">{{ __('Payment Methods') }}</h3>
                            </div>
                            <div class="th-card-body">
                                <input type="hidden" name="payment_method" :value="payment_method"
                                       x-model="payment_method">

                                @forelse ($paymentGateways as $gateway)
                                    @if ($loop->first)
                                        <div class="th-gateways">
                                    @endif

                                    <button type="button" class="th-gateway"
                                            id="{{ $gateway->name }}"
                                            value="{{ $gateway->name }}"
                                            :class="payment_method === '{{ $gateway->name }}' ? 'is-selected' : ''"
                                            :disabled="isFreeAfterCoupon"
                                            @click="payment_method = '{{ $gateway->name }}'; submitted = true;">
                                        <img class="th-gateway-logo" src="{{ $gateway->image }}"
                                             alt="{{ $gateway->name }}"
                                             onerror="this.style.display='none'">
                                        <span class="th-gateway-name">{{ $gateway->name }}</span>
                                        <span class="th-gateway-check"><i class="fas fa-check"></i></span>
                                    </button>

                                    @if ($loop->last)
                                        </div>
                                    @endif
                                @empty
                                    <div class="th-empty" style="padding:2rem 1rem">
                                        <i class="fas fa-credit-card d-block"></i>
                                        <h3 class="th-empty-title">{{ __('No payment method is enabled') }}</h3>
                                        <p class="th-empty-text">
                                            {{ __('Enable and configure a payment gateway before orders can be paid.') }}
                                        </p>
                                        @canany(\App\Constants\PermissionGroups::SETTINGS_PERMISSIONS)
                                            <a href="{{ route('admin.settings.index') }}" class="th-btn th-btn-ghost">
                                                <i class="fas fa-sliders-h"></i>{{ __('Settings') }}
                                            </a>
                                        @endcanany
                                    </div>
                                @endforelse
                            </div>
                        </div>

                        @if ($isCouponsEnabled)
                            <div class="th-card mb-4">
                                <div class="th-card-head">
                                    <i class="fas fa-tags"></i>
                                    <h3 class="th-card-title">{{ __('Coupon') }}</h3>
                                </div>
                                <div class="th-card-body">
                                    <div class="th-coupon">
                                        <input type="text" id="coupon_code" name="coupon_code"
                                               value="{{ old('coupon_code') }}" :value="coupon_code"
                                               class="form-control @error('coupon_code') is-invalid @enderror"
                                               placeholder="{{ __('Enter your coupon here...') }}"
                                               x-on:change.debounce="setCouponCode($event)"
                                               x-model="coupon_code">

                                        <button type="button" id="send_coupon_code" @click="checkCoupon()"
                                                class="th-btn th-btn-primary"
                                                :disabled="!coupon_code.length">
                                            {{ __('Submit') }}
                                        </button>
                                    </div>

                                    <div x-cloak x-show="hasAppliedCoupon" class="th-coupon-applied">
                                        <span class="th-status is-online" x-text="appliedCouponCode"></span>
                                        <button type="button" class="th-btn th-btn-ghost th-btn-sm"
                                                @click="clearCoupon()">
                                            <i class="fas fa-trash-alt"></i>{{ __('Remove coupon') }}
                                        </button>
                                    </div>

                                    @error('coupon_code')
                                        <div class="text-danger mt-2">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        @endif
                    @endif
                </div>

                {{-- ---------------- Right: what you get ---------------- --}}
                <div class="{{ $productIsFree ? 'col-lg-6 offset-lg-3' : 'col-lg-5' }}">
                    <div class="th-card th-sticky">
                        <div class="th-card-head">
                            <i class="fas fa-shopping-cart"></i>
                            <h3 class="th-card-title">{{ __('Order summary') }}</h3>
                        </div>

                        <div class="th-card-body">
                            <div class="th-order-item">
                                <span class="th-stat-icon"><i class="fas {{ $icon }}"></i></span>
                                <div style="min-width:0">
                                    <strong class="d-block">{{ $product->display }}</strong>
                                    <span class="text-muted" style="font-size:.8rem">{{ $typeLabel }}</span>
                                </div>
                                <span class="th-order-qty">{{ $amount }}</span>
                            </div>

                            @if ($product->description)
                                <p class="text-muted mt-2 mb-0" style="font-size:.82rem">
                                    {{ $product->description }}
                                </p>
                            @endif

                            <div class="th-summary">
                                <div class="th-summary-row">
                                    <span>{{ __('Subtotal') }}</span>
                                    <span>{{ Currency::formatToCurrency($product->price, $product->currency_code) }}</span>
                                </div>

                                @if ($taxpercent > 0 && $taxvalue > 0)
                                    <div class="th-summary-row">
                                        <span>{{ __('Tax') }} ({{ $taxpercent }}%)</span>
                                        <span>+ {{ Currency::formatToCurrency($taxvalue, $product->currency_code) }}</span>
                                    </div>
                                @endif

                                <div id="coupon_discount_details" class="th-summary-row is-discount"
                                     style="display: none !important;">
                                    <span>{{ __('Coupon Discount') }}</span>
                                    <span x-text="'- ' + (couponType == 'amount' ? formatToCurrency($currency.format(couponDiscountedValue)) : couponDiscountedValue + '%')"></span>
                                </div>

                                @if ($discountpercent && $discountvalue)
                                    <div class="th-summary-row is-discount">
                                        <span>{{ __('Partner Discount') }} ({{ $discountpercent }}%)</span>
                                        <span>- {{ Currency::formatToCurrency($discountvalue, $product->currency_code) }}</span>
                                    </div>
                                @endif

                                <div class="th-summary-total">
                                    <span>{{ __('Total') }}</span>
                                    <input id="total_price_input" type="hidden" x-model="totalPrice">
                                    <strong x-text="formatToCurrency($currency.format(totalPrice))"></strong>
                                </div>

                                <template x-if="payment_method && !isFreeAfterCoupon">
                                    <div class="th-summary-row">
                                        <span>{{ __('Pay with') }}</span>
                                        <span x-text="payment_method"></span>
                                    </div>
                                </template>
                            </div>

                            <button id="submit_form_button" type="submit"
                                    :disabled="!canSubmitPayment"
                                    :class="!canSubmitPayment ? 'disabled' : ''"
                                    class="th-btn th-btn-primary th-btn-block mt-3">
                                <i class="far fa-credit-card"></i>
                                <span x-cloak x-show="isFreeAfterCoupon">{{ __('Get for free') }}</span>
                                <span x-show="!isFreeAfterCoupon">{{ __('Submit Payment') }}</span>
                            </button>

                            <p class="text-muted text-center mt-3 mb-0" style="font-size:.76rem">
                                <i class="fas fa-lock mr-1"></i>
                                {{ __('Credits land on your account as soon as the payment clears.') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <input type="hidden" name="product_id" value="{{ $product->id }}">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
        </form>
    </div>

    <script>
        function couponForm() {
            return {
                // Get the product id from the url
                productId: window.location.pathname.split('/').pop(),
                productIsFreeFromServer: @json($productIsFree),
                payment_method: null,
                coupon_code: '',
                appliedCouponCode: '',
                submitted: false,
                baseTotalPrice: {{ $total }},
                totalPrice: {{ $total }},
                couponType: null,
                couponDiscountedValue: 0,

                get isFreeAfterCoupon() {
                    return this.productIsFreeFromServer || Number(this.totalPrice) <= 0;
                },

                get canSubmitPayment() {
                    if (this.isFreeAfterCoupon) {
                        return true;
                    }
                    return this.payment_method != null && String(this.payment_method).length > 0;
                },

                get hasAppliedCoupon() {
                    return String(this.appliedCouponCode || '').trim().length > 0;
                },

                setCouponCode(event) {
                    this.coupon_code = event.target.value
                },

                clearCoupon() {
                    this.totalPrice = this.baseTotalPrice
                    this.couponType = null
                    this.couponDiscountedValue = 0
                    this.appliedCouponCode = ''
                    this.coupon_code = ''
                    $('#coupon_discount_details').hide()
                },

                async checkCoupon() {
                    const response = await (fetch(
                        "{{ route('admin.coupon.redeem') }}", {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr(
                                'content')
                        },
                        body: JSON.stringify({
                            couponCode: this.coupon_code,
                            productId: this.productId
                        })
                    }
                    )
                        .then(response => response.json()).catch((error) => {
                            Swal.fire({
                                icon: 'error',
                                title: 'Oops...',
                                text: "{{ __('The coupon code you entered is invalid or cannot be applied to this product.') }}",
                                background: '#212129',
                                color: '#eef1f7',
                            })
                        }))

                    if (!response || !response.isValid || !response.couponCode) {
                        if (response !== undefined) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Oops...',
                                text: response?.error ?? "{{ __('The coupon code you entered is invalid or cannot be applied to this product.') }}",
                                background: '#212129',
                                color: '#eef1f7',
                            })
                        }
                        return
                    }

                    Swal.fire({
                        icon: 'success',
                        text: "{{ __('The coupon was successfully added to your purchase.') }}",
                        background: '#212129',
                        color: '#eef1f7',
                    })

                    this.appliedCouponCode = String(response.couponCode || '').trim()
                    this.calcPriceWithCouponDiscount(response.couponValue, response
                        .couponType)

                    $('#coupon_discount_details').prop('disabled', false).show()
                },

                calcPriceWithCouponDiscount(couponValue, couponType) {
                    let newTotalPrice = this.baseTotalPrice

                    if (couponType === 'percentage') {
                        newTotalPrice = newTotalPrice - (newTotalPrice * couponValue / 100)
                    } else if (couponType === 'amount') {
                        newTotalPrice = newTotalPrice - couponValue
                    }

                    newTotalPrice = Math.max(0, newTotalPrice)

                    this.couponType = couponType
                    this.couponDiscountedValue = couponValue
                    this.totalPrice = newTotalPrice
                    if (Number(this.totalPrice) <= 0) {
                        this.payment_method = null
                    }
                },

                formatToCurrency(amount) {
                    // Match the locale PHP formats with, otherwise the total
                    // reads "€2.00" right under a subtotal of "2,00 €".
                    const lang = "{{ str_replace('_', '-', app()->getLocale()) }}"

                    // format totalPrice to currency
                    return amount.toLocaleString(lang, {
                        style: 'currency',
                        currency: "{{ $product->currency_code }}",
                    })
                },

            }
        }
    </script>
@endsection
