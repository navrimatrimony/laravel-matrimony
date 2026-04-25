@extends('layouts.app')

@section('content')
    @if ((! empty($checkoutContext) && is_array($checkoutContext)) || (! empty($revenueSummary) && is_array($revenueSummary)))
        <div class="min-h-screen bg-gray-100 py-10">
            <div class="mx-auto grid max-w-5xl grid-cols-1 gap-6 px-4 md:grid-cols-2">
                {{-- LEFT: coupon --}}
                <div class="rounded-xl bg-white p-6 shadow">
                    @if (! empty($checkoutContext) && is_array($checkoutContext))
                        <h2 class="mb-4 text-lg font-semibold">{{ __('revenue_summary.checkout_coupon_heading') }}</h2>
                        <p class="mb-4 text-sm text-gray-600">{{ __('revenue_summary.checkout_coupon_help') }}</p>

                        <form id="checkout_coupon_form" method="get" action="{{ route('plans.subscribe') }}" class="space-y-3">
                            <input type="hidden" name="plan" value="{{ $checkoutContext['plan'] ?? '' }}">
                            @if (($checkoutContext['plan_term_id'] ?? '') !== '')
                                <input type="hidden" name="plan_term_id" value="{{ $checkoutContext['plan_term_id'] }}">
                            @endif
                            @if (($checkoutContext['plan_price_id'] ?? '') !== '')
                                <input type="hidden" name="plan_price_id" value="{{ $checkoutContext['plan_price_id'] }}">
                            @endif
                            <div>
                                <label for="checkout-coupon" class="mb-1 block text-sm font-medium text-gray-700">{{ __('revenue_summary.checkout_coupon_label') }}</label>
                                <input
                                    id="checkout-coupon"
                                    type="text"
                                    name="coupon"
                                    value="{{ $couponFieldValue ?? '' }}"
                                    autocomplete="off"
                                    maxlength="64"
                                    class="w-full rounded border border-gray-300 px-3 py-2 font-mono text-sm uppercase"
                                    placeholder="{{ __('revenue_summary.checkout_coupon_placeholder') }}"
                                >
                            </div>
                            <button type="submit" class="w-full rounded bg-blue-600 px-4 py-2 text-white md:w-auto">
                                {{ __('revenue_summary.checkout_coupon_apply') }}
                            </button>
                        </form>

                        @if ($errors->has('coupon'))
                            <div class="mt-2 text-sm text-red-500" role="alert">
                                {{ $errors->first('coupon') }}
                            </div>
                        @elseif (! empty($revenueSummary['coupon_code'] ?? null))
                            <div class="mt-2 text-sm text-green-600">
                                {{ __('revenue_summary.checkout_coupon_applied_ok') }}
                            </div>
                        @endif

                        @if (! empty($removeCouponSubscribeParams) && is_array($removeCouponSubscribeParams) && ($couponFieldValue ?? '') !== '')
                            <p class="mt-3 text-center md:text-left">
                                <a href="{{ route('plans.subscribe', $removeCouponSubscribeParams) }}" class="text-sm text-gray-500 underline decoration-gray-400 underline-offset-2 hover:text-gray-700">
                                    {{ __('revenue_summary.checkout_coupon_remove') }}
                                </a>
                            </p>
                        @endif
                    @else
                        <p class="text-center text-sm text-gray-500">
                            {{ __('revenue_summary.checkout_coupon_unavailable') }}
                        </p>
                    @endif
                </div>

                {{-- RIGHT: summary + PayU --}}
                <div class="rounded-xl bg-white p-6 shadow">
                    <p class="mb-4 text-center text-sm font-medium text-gray-600 md:text-left">{{ __('revenue_summary.payu_redirect_note') }}</p>
                    <h2 class="mb-4 text-lg font-semibold">{{ __('revenue_summary.payu_title') }}</h2>

                    @if (! empty($revenueSummary) && is_array($revenueSummary))
                        <div class="mb-4 rounded-lg bg-gray-50 p-4">
                            @include('partials.payu-checkout-summary', [
                                'revenueSummary' => $revenueSummary,
                                'summaryCardClass' => 'mx-0 mb-0 max-w-none border-0 bg-transparent p-0 shadow-none text-left text-sm text-gray-800',
                                'emphasizeTotal' => true,
                            ])
                        </div>
                    @endif

                    @if (! empty($checkoutBestOfferHint))
                        <p class="mb-4 text-center text-xs text-gray-500 md:text-left">{{ __('revenue_summary.checkout_auto_offer_hint') }}</p>
                    @endif

                    <form id="payu_checkout" method="post" action="{{ $action }}" class="hidden">
                        @foreach ($fields as $name => $value)
                            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                        @endforeach
                    </form>

                    <button
                        type="button"
                        id="payu_continue_btn"
                        class="mt-4 w-full rounded bg-green-600 py-3 text-white"
                    >
                        {{ __('revenue_summary.checkout_continue_payment') }}
                    </button>
                </div>
            </div>
        </div>
    @else
        {{-- Legacy (e.g. config plan PayU) — minimal surface --}}
        <div class="min-h-screen bg-gray-100 py-10">
            <div class="mx-auto max-w-lg px-4">
                <div class="rounded-xl bg-white p-6 text-center shadow">
                    <p class="text-sm font-medium text-gray-600">{{ __('revenue_summary.payu_redirect_note') }}</p>
                    <form id="payu_checkout" method="post" action="{{ $action }}" class="mt-6">
                        @foreach ($fields as $name => $value)
                            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                        @endforeach
                        <noscript>
                            <button type="submit" class="w-full rounded bg-green-600 py-3 text-sm font-bold text-white">{{ __('revenue_summary.checkout_continue_payment') }}</button>
                        </noscript>
                    </form>
                    <button
                        type="button"
                        id="payu_continue_btn"
                        class="mt-6 w-full rounded bg-green-600 px-4 py-3 text-base font-semibold text-white shadow hover:bg-green-700"
                    >
                        {{ __('revenue_summary.checkout_continue_payment') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <script>
        (function () {
            var delayMs = {{ (int) ($payuAutoSubmitDelayMs ?? 4000) }};
            var userInteracting = false;
            var payuSubmitted = false;
            var payuForm = document.getElementById('payu_checkout');
            var couponInput = document.getElementById('checkout-coupon');
            var couponForm = document.getElementById('checkout_coupon_form');
            var continueBtn = document.getElementById('payu_continue_btn');
            var timerId = null;

            function submitPayuOnce() {
                if (!payuForm || payuSubmitted) {
                    return;
                }
                payuSubmitted = true;
                if (continueBtn) {
                    continueBtn.disabled = true;
                    continueBtn.classList.add('opacity-60', 'cursor-not-allowed');
                }
                if (timerId) {
                    clearTimeout(timerId);
                }
                payuForm.submit();
            }

            if (couponInput) {
                couponInput.addEventListener('focus', function () { userInteracting = true; });
                couponInput.addEventListener('blur', function () { userInteracting = false; });
            }

            timerId = setTimeout(function () {
                if (!payuForm || payuSubmitted) return;
                if (userInteracting) return;
                submitPayuOnce();
            }, delayMs);

            if (couponForm) {
                couponForm.addEventListener('submit', function () {
                    userInteracting = true;
                    if (timerId) {
                        clearTimeout(timerId);
                    }
                });
            }

            if (continueBtn && payuForm) {
                continueBtn.addEventListener('click', function () {
                    userInteracting = false;
                    submitPayuOnce();
                });
            }
        })();
    </script>
@endsection
