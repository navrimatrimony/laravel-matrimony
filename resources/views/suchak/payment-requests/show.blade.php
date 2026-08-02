@extends('layouts.app')

{{--
    The page a family opens from a WhatsApp link to pay a Suchak.

    Every line here used to carry BOTH languages glued together with a `·` —
    the Marathi phrase, a middot, then its English translation. That is not
    bilingual; it is two half-pages, and neither reader got a clean one. The
    page now answers in the language the reader asked for, from
    `suchak.public_pages.payment_request.*`.

    Amounts stay Latin-digit with Indian grouping in both languages
    (App\Support\MoneyFormat) — that is a product rule, not a language one.
--}}
@php
    use App\Support\LocalizedText;
    use App\Support\MoneyFormat;

    $suchak = $paymentRequest->suchakAccount;

    // Brand line (same source the layout uses for the site name).
    $siteIdentity = app(\App\Services\SiteIdentityService::class);
    $brandName = $siteIdentity->get('site_name', 'Navri Matrimony');

    // Suchak identity (who is asking for money).
    $suchakName = LocalizedText::column($suchak, 'suchak_name');
    $officeName = LocalizedText::column($suchak, 'office_name');
    $isVerified = (bool) ($suchak?->isVerified());

    // Plan + amount.
    $planName = LocalizedText::column($agreement, 'package_name', ['package_name', 'agreement_title']);
    $planDescription = LocalizedText::column($agreement, 'package_description');
    $agreementBody = LocalizedText::column($agreement, 'agreement_body');
    $requestNote = LocalizedText::column($paymentRequest, 'request_note');
    $collectorDisclosure = LocalizedText::column($paymentRequest, 'collector_disclosure');

    $currency = strtoupper($paymentRequest->currency ?? 'INR');
    // Carries its own symbol, Latin digits and Indian grouping — ₹1,00,000,
    // which is what number_format() here used to get wrong. See App\Support\MoneyFormat.
    $amountDisplay = MoneyFormat::amount($paymentRequest->amount_due, $currency);

    // Services the customer is paying for (agreement snapshot deliverables + stages).
    $deliverables = $agreement?->deliverables ?? collect();
    $stages = $agreement?->stages ?? collect();
    $hasServices = $deliverables->isNotEmpty() || $stages->isNotEmpty();

    // Payment identity (Suchak collection only).
    $upiVpa = $paymentIdentity['upi_vpa'] ?? null;
    $qrUrl = $paymentIdentity['payment_qr_url'] ?? null;
    $identityConfigured = (bool) ($paymentIdentity['is_configured'] ?? false);

    // Share-preview (Open Graph) values — override the site-wide default so a
    // WhatsApp link unfurl shows THIS request's scannable UPI QR plus who/how
    // much, instead of the generic homepage image.
    // The candidate's name sits INSIDE the sentence rather than being glued to
    // the front of it. Marathi puts the name before the phrase and English
    // after it, so a placeholder is the only thing that gets both right.
    $ogTitle = trim((!empty($candidateName)
        ? __('suchak.public_pages.payment_request.og_title_for_candidate', ['name' => $candidateName])
        : __('suchak.public_pages.payment_request.og_title'))
        .($amountDisplay !== null ? ' — '.$amountDisplay : ''));
    $ogDescription = trim(($planName !== '' ? $planName.' — ' : '')
        .__('suchak.public_pages.payment_request.og_description'));
    // Share-preview image: prefer THIS request's scannable UPI-intent QR — the
    // qr.png route renders it live from the Suchak's CURRENT UPI VPA, so it works
    // the moment the Suchak configures UPI. If there is no VPA but the Suchak did
    // upload a payment-QR image, use that image URL directly. When neither exists
    // there is genuinely no QR, so emit NO og:image at all (og_image_none) instead
    // of letting the WhatsApp unfurl fall back to the site homepage image.
    $ogImage = null;
    if (!empty($showTrackAIdentity) && !empty($token)) {
        if (!empty($upiVpa)) {
            $ogImage = route('suchak.payment-requests.qr', ['token' => $token]);
        } elseif (!empty($qrUrl)) {
            $ogImage = $qrUrl;
        }
    }
@endphp

@section('og_title', e($ogTitle))
@section('og_description', e($ogDescription))
@if ($ogImage)
    @section('og_image', e($ogImage))
@else
    @section('og_image_none')@endsection
@endif

@section('content')
<div class="mx-auto max-w-xl px-4 py-6 sm:py-8">

    {{-- 1. TRUST HEADER — who is asking for money --}}
    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-3 dark:border-gray-700">
            <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $brandName }}</span>
            @if ($isVerified)
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.3 3.3 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" />
                    </svg>
                    {{ __('suchak.labels.common.verified') }}
                </span>
            @endif
        </div>

        <div class="px-5 py-4">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('suchak.public_pages.payment_request.requested_by') }}</p>
            <h1 class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ $suchakName !== '' ? $suchakName : __('profile.suchak_default_name') }}</h1>
            @if ($officeName !== '')
                <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">{{ $officeName }}</p>
            @endif

            <div class="mt-3 inline-flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 1a5 5 0 0 0-5 5v2H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-1V6a5 5 0 0 0-5-5Zm3 7V6a3 3 0 1 0-6 0v2h6Z" clip-rule="evenodd" />
                </svg>
                {{ __('suchak.public_pages.payment_request.secure_payment') }}
            </div>
        </div>
    </section>

    {{-- 2. CANDIDATE + PLAN + AMOUNT --}}
    <section class="mt-4 rounded-2xl border border-gray-200 bg-white px-5 py-5 dark:border-gray-700 dark:bg-gray-800">
        @if (!empty($candidateName))
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('suchak.public_pages.payment_request.candidate') }}</p>
            <p class="mt-0.5 text-base font-semibold text-gray-900 dark:text-gray-100">{{ $candidateName }}</p>
        @endif

        <p class="{{ !empty($candidateName) ? 'mt-4 ' : '' }}text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('suchak.public_pages.payment_request.plan') }}</p>
        <p class="mt-0.5 text-lg font-bold text-gray-900 dark:text-gray-100">{{ $planName !== '' ? $planName : __('suchak.public_pages.payment_request.plan_fallback') }}</p>
        @if ($planDescription !== '')
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $planDescription }}</p>
        @endif

        <div class="mt-5 rounded-xl bg-gray-50 px-4 py-4 text-center dark:bg-gray-900/60">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('suchak.public_pages.payment_request.amount_to_pay') }}</p>
            @if ($amountDisplay !== null)
                <p class="mt-1 text-4xl font-extrabold tracking-tight text-gray-900 dark:text-gray-100">{{ $amountDisplay }}</p>
            @else
                <p class="mt-1 text-xl font-semibold text-gray-500 dark:text-gray-400">{{ __('suchak.labels.common.to_be_confirmed') }}</p>
            @endif
        </div>

        @if ($requestNote !== '')
            <p class="mt-4 border-t border-gray-100 pt-4 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-200">{{ $requestNote }}</p>
        @endif
    </section>

    {{-- 3. WHAT YOU GET — the services list (these ARE the terms) --}}
    <section class="mt-4 rounded-2xl border border-gray-200 bg-white px-5 py-5 dark:border-gray-700 dark:bg-gray-800">
        {{-- Was an h2 in Marathi with the English translation as a sub-line under it. One heading now, in the reader's language. --}}
        <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">{{ __('suchak.public_pages.payment_request.what_you_get') }}</h2>

        @if ($hasServices)
            <ul class="mt-4 space-y-3">
                @foreach ($deliverables as $deliverable)
                    <li class="flex gap-3">
                        <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-emerald-600 dark:text-emerald-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.7-9.3a1 1 0 0 0-1.4-1.4L9 10.6 7.7 9.3a1 1 0 0 0-1.4 1.4l2 2a1 1 0 0 0 1.4 0l4-4Z" clip-rule="evenodd" />
                        </svg>
                        <div>
                            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ LocalizedText::column($deliverable, 'deliverable_name') }}</div>
                            @php($deliverableDesc = LocalizedText::column($deliverable, 'deliverable_description'))
                            @if ($deliverableDesc !== '')
                                <div class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">{{ $deliverableDesc }}</div>
                            @endif
                        </div>
                    </li>
                @endforeach

                @foreach ($stages as $stage)
                    <li class="flex gap-3">
                        <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-emerald-600 dark:text-emerald-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.7-9.3a1 1 0 0 0-1.4-1.4L9 10.6 7.7 9.3a1 1 0 0 0-1.4 1.4l2 2a1 1 0 0 0 1.4 0l4-4Z" clip-rule="evenodd" />
                        </svg>
                        <div>
                            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ LocalizedText::column($stage, 'stage_name') }}</div>
                            @php($stageDesc = LocalizedText::column($stage, 'stage_description'))
                            @if ($stageDesc !== '')
                                <div class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">{{ $stageDesc }}</div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @elseif ($agreementBody !== '')
            <p class="mt-4 whitespace-pre-line text-sm leading-6 text-gray-700 dark:text-gray-200">{{ $agreementBody }}</p>
        @else
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">{{ __('suchak.public_pages.payment_request.services_confirmed_by_suchak') }}</p>
        @endif

        @if ($hasServices && $agreementBody !== '')
            <p class="mt-4 whitespace-pre-line border-t border-gray-100 pt-4 text-sm leading-6 text-gray-600 dark:border-gray-700 dark:text-gray-300">{{ $agreementBody }}</p>
        @endif
    </section>

    {{-- 4. PAYMENT — one Suchak UPI, three ways to reach it --}}
    @if (!empty($showTrackAIdentity))
        <section class="mt-4 rounded-2xl border border-gray-200 bg-white px-5 py-5 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">{{ __('suchak.public_pages.payment_request.how_to_pay') }}</h2>
            <p class="text-xs font-medium text-gray-400 dark:text-gray-500">{{ __('suchak.public_pages.payment_request.how_to_pay_note') }}</p>

            @if ($identityConfigured)
                @if ($qrUrl)
                    <div class="mt-4 flex flex-col items-center">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('suchak.public_pages.payment_request.scan_qr') }}</p>
                        <img
                            src="{{ $qrUrl }}"
                            alt="{{ __('suchak.public_pages.payment_request.qr_alt') }}"
                            class="mt-3 h-64 w-64 max-w-full rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-600"
                        >
                    </div>
                @endif

                @if ($upiVpa)
                    <div class="mt-5">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('suchak.public_pages.payment_request.or_use_upi') }}</p>
                        <div class="mt-2 flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 dark:border-gray-600 dark:bg-gray-900/60">
                            <span class="min-w-0 flex-1 break-all text-base font-semibold text-gray-900 dark:text-gray-100">{{ $upiVpa }}</span>
                            <button
                                type="button"
                                data-copy-upi="{{ $upiVpa }}"
                                data-copied-text="{{ __('suchak.public_pages.payment_request.copied') }}"
                                class="inline-flex flex-shrink-0 items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1 dark:focus:ring-offset-gray-800"
                            >
                                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path d="M7 3a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V7.8a2 2 0 0 0-.6-1.4l-2.8-2.8A2 2 0 0 0 10.2 3H7Z" />
                                    <path d="M3 7a2 2 0 0 1 2-2v10h7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" />
                                </svg>
                                <span data-copy-label>{{ __('suchak.public_pages.payment_request.copy') }}</span>
                            </button>
                        </div>
                    </div>
                @endif

                <p class="mt-4 rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/60 dark:text-gray-300">
                    {{ __('suchak.public_pages.payment_request.any_upi_app') }}
                </p>
            @else
                <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                    {{ __('suchak.public_pages.payment_request.no_upi_published') }}
                </p>
            @endif
        </section>
    @endif

    {{-- 5. TERMS LINE — reviewing the plan + paying IS the acceptance (no checkbox) --}}
    <section class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 dark:border-emerald-900 dark:bg-emerald-950/30">
        <p class="text-sm leading-6 text-emerald-900 dark:text-emerald-100">
            {{ __('suchak.public_pages.payment_request.paying_accepts_terms') }}
        </p>
    </section>

    {{-- Compliance / anti-fraud disclosure (kept, calmer) --}}
    <div class="mt-4 space-y-1 px-1 text-xs leading-5 text-gray-500 dark:text-gray-400">
        @if ($collectorDisclosure !== '')
            <p>{{ $collectorDisclosure }}</p>
        @endif
        <p>
            @if (empty($showTrackAIdentity))
                {{ __('suchak.public_pages.payment_request.billed_by_platform') }}
            @else
                {{ __('suchak.public_pages.payment_request.suchak_collection_only') }}
            @endif
        </p>
        <p>{{ __('suchak.public_pages.payment_request.report_outside_payment') }}</p>
    </div>
</div>

<script>
(function () {
    var btn = document.querySelector('[data-copy-upi]');
    if (!btn) return;

    function showCopied() {
        var label = btn.querySelector('[data-copy-label]');
        if (!label) return;
        var original = label.textContent;
        label.textContent = btn.getAttribute('data-copied-text') || 'Copied';
        setTimeout(function () { label.textContent = original; }, 1800);
    }

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); showCopied(); } catch (e) {}
        document.body.removeChild(ta);
    }

    btn.addEventListener('click', function () {
        var text = btn.getAttribute('data-copy-upi') || '';
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(showCopied, function () { fallbackCopy(text); });
        } else {
            fallbackCopy(text);
        }
    });
})();
</script>
@endsection
