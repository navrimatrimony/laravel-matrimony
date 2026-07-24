@extends('layouts.app')

@php
    use App\Support\LocalizedText;

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
    $currencySymbol = $currency === 'INR' ? '₹' : $currency . ' ';
    $amountRaw = $paymentRequest->amount_due;
    $amountDisplay = null;
    if ($amountRaw !== null && $amountRaw !== '') {
        $amountFloat = (float) $amountRaw;
        $amountDisplay = fmod($amountFloat, 1.0) === 0.0
            ? number_format($amountFloat, 0)
            : number_format($amountFloat, 2);
    }

    // Services the customer is paying for (agreement snapshot deliverables + stages).
    $deliverables = $agreement?->deliverables ?? collect();
    $stages = $agreement?->stages ?? collect();
    $hasServices = $deliverables->isNotEmpty() || $stages->isNotEmpty();

    // Payment identity (Suchak collection only).
    $upiVpa = $paymentIdentity['upi_vpa'] ?? null;
    $qrUrl = $paymentIdentity['payment_qr_url'] ?? null;
    $identityConfigured = (bool) ($paymentIdentity['is_configured'] ?? false);
@endphp

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
                    पडताळणी झालेली · Verified
                </span>
            @endif
        </div>

        <div class="px-5 py-4">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">पैसे मागणारे सूचक · Requested by</p>
            <h1 class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ $suchakName !== '' ? $suchakName : 'Suchak' }}</h1>
            @if ($officeName !== '')
                <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">{{ $officeName }}</p>
            @endif

            <div class="mt-3 inline-flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 1a5 5 0 0 0-5 5v2H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-1V6a5 5 0 0 0-5-5Zm3 7V6a3 3 0 1 0-6 0v2h6Z" clip-rule="evenodd" />
                </svg>
                सुरक्षित पेमेंट · अधिकृत सूचक
            </div>
        </div>
    </section>

    {{-- 2. CANDIDATE + PLAN + AMOUNT --}}
    <section class="mt-4 rounded-2xl border border-gray-200 bg-white px-5 py-5 dark:border-gray-700 dark:bg-gray-800">
        @if (!empty($candidateName))
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">उमेदवार · Candidate</p>
            <p class="mt-0.5 text-base font-semibold text-gray-900 dark:text-gray-100">{{ $candidateName }}</p>
        @endif

        <p class="{{ !empty($candidateName) ? 'mt-4 ' : '' }}text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">योजना · Plan</p>
        <p class="mt-0.5 text-lg font-bold text-gray-900 dark:text-gray-100">{{ $planName !== '' ? $planName : 'Service plan' }}</p>
        @if ($planDescription !== '')
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $planDescription }}</p>
        @endif

        <div class="mt-5 rounded-xl bg-gray-50 px-4 py-4 text-center dark:bg-gray-900/60">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">भरायची रक्कम · Amount to pay</p>
            @if ($amountDisplay !== null)
                <p class="mt-1 text-4xl font-extrabold tracking-tight text-gray-900 dark:text-gray-100">{{ $currencySymbol }}{{ $amountDisplay }}</p>
            @else
                <p class="mt-1 text-xl font-semibold text-gray-500 dark:text-gray-400">रक्कम निश्चित होणे बाकी · To be confirmed</p>
            @endif
        </div>

        @if ($requestNote !== '')
            <p class="mt-4 border-t border-gray-100 pt-4 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-200">{{ $requestNote }}</p>
        @endif
    </section>

    {{-- 3. WHAT YOU GET — the services list (these ARE the terms) --}}
    <section class="mt-4 rounded-2xl border border-gray-200 bg-white px-5 py-5 dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">यात तुम्हाला मिळेल</h2>
        <p class="text-xs font-medium text-gray-400 dark:text-gray-500">What you get</p>

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
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">या योजनेतील सेवा सूचकांकडून थेट कळवल्या जातील. · The services in this plan will be confirmed directly by the Suchak.</p>
        @endif

        @if ($hasServices && $agreementBody !== '')
            <p class="mt-4 whitespace-pre-line border-t border-gray-100 pt-4 text-sm leading-6 text-gray-600 dark:border-gray-700 dark:text-gray-300">{{ $agreementBody }}</p>
        @endif
    </section>

    {{-- 4. PAYMENT — one Suchak UPI, three ways to reach it --}}
    @if (!empty($showTrackAIdentity))
        <section class="mt-4 rounded-2xl border border-gray-200 bg-white px-5 py-5 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">पैसे कसे भरायचे</h2>
            <p class="text-xs font-medium text-gray-400 dark:text-gray-500">How to pay · directly to this Suchak</p>

            @if ($identityConfigured)
                @if ($qrUrl)
                    <div class="mt-4 flex flex-col items-center">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">QR कोड स्कॅन करा · Scan the QR</p>
                        <img
                            src="{{ $qrUrl }}"
                            alt="Suchak payment QR"
                            class="mt-3 h-64 w-64 max-w-full rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-600"
                        >
                    </div>
                @endif

                @if ($upiVpa)
                    <div class="mt-5">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">किंवा UPI ID वापरा · Or use the UPI ID</p>
                        <div class="mt-2 flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 dark:border-gray-600 dark:bg-gray-900/60">
                            <span class="min-w-0 flex-1 break-all text-base font-semibold text-gray-900 dark:text-gray-100">{{ $upiVpa }}</span>
                            <button
                                type="button"
                                data-copy-upi="{{ $upiVpa }}"
                                data-copied-text="कॉपी झाले ✓"
                                class="inline-flex flex-shrink-0 items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1 dark:focus:ring-offset-gray-800"
                            >
                                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path d="M7 3a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V7.8a2 2 0 0 0-.6-1.4l-2.8-2.8A2 2 0 0 0 10.2 3H7Z" />
                                    <path d="M3 7a2 2 0 0 1 2-2v10h7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" />
                                </svg>
                                <span data-copy-label>कॉपी करा · Copy</span>
                            </button>
                        </div>
                    </div>
                @endif

                <p class="mt-4 rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/60 dark:text-gray-300">
                    तुमच्या कोणत्याही UPI अ‍ॅपमधून (PhonePe, Google Pay, Paytm) वरील QR किंवा UPI ID वापरून पैसे भरता येतील.
                    <span class="mt-1 block text-gray-500 dark:text-gray-400">You can pay using the QR or UPI ID above from any UPI app on your phone.</span>
                </p>
            @else
                <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                    या सूचकांनी अद्याप UPI ID किंवा QR प्रकाशित केलेला नाही. कृपया या पडताळणी झालेल्या संदर्भातूनच सूचकांशी संपर्क साधा किंवा अद्ययावत विनंतीची वाट पाहा.
                    <span class="mt-1 block">This Suchak has not published a UPI ID or payment QR yet. Contact them using this verified request, or wait for an updated request.</span>
                </p>
            @endif
        </section>
    @endif

    {{-- 5. TERMS LINE — reviewing the plan + paying IS the acceptance (no checkbox) --}}
    <section class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 dark:border-emerald-900 dark:bg-emerald-950/30">
        <p class="text-sm leading-6 text-emerald-900 dark:text-emerald-100">
            योजना व सेवा पाहून पैसे भरणे म्हणजे या सेवा-अटी मान्य करणे.
            <span class="mt-1 block text-emerald-800 dark:text-emerald-200">Paying after reviewing the plan and services means accepting these service terms.</span>
        </p>
    </section>

    {{-- Compliance / anti-fraud disclosure (kept, calmer) --}}
    <div class="mt-4 space-y-1 px-1 text-xs leading-5 text-gray-500 dark:text-gray-400">
        @if ($collectorDisclosure !== '')
            <p>{{ $collectorDisclosure }}</p>
        @endif
        <p>
            @if (empty($showTrackAIdentity))
                ही पेमेंट प्लॅटफॉर्मकडून घेतली जाते; येथे थेट सूचक UPI/QR दाखवले जात नाही. · This customer is billed by the platform, so direct Suchak UPI/QR is not shown here.
            @else
                वरील UPI / QR या सूचकांच्या ग्राहक-वसुलीसाठी आहेत — प्लॅटफॉर्म सबस्क्रिप्शन बिलिंगसाठी नाहीत. · The UPI / QR above are for this Suchak's customer collection only, not platform subscription billing.
            @endif
        </p>
        <p>कोणताही सूचक या पडताळणी झालेल्या पानाबाहेर पैसे मागत असल्यास, तुमच्या खात्यातून पुराव्यासह तक्रार नोंदवा. · If any Suchak asks for payment outside this verified page, report it with evidence from your account.</p>
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
