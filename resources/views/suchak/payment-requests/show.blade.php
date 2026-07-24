@extends('layouts.app')

@php
    $suchakText = \App\Support\Suchak\SuchakLocalizedText::class;
    $localizedText = \App\Support\LocalizedText::class;
@endphp

@section('content')
<div class="mx-auto max-w-4xl px-4 py-8">
    <div class="mb-6">
        <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">Suchak Payment Request</p>
        <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $localizedText::column($paymentRequest, 'request_title') }}</h1>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $localizedText::column($paymentRequest, 'collector_disclosure') }}</p>
    </div>

    <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Status</div>
                <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $suchakText::label($paymentRequest->payment_status) }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Amount</div>
                <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                    @if ($paymentRequest->amount_due !== null)
                        {{ $paymentRequest->currency ?? 'INR' }} {{ $paymentRequest->amount_due }}
                    @else
                        To be confirmed
                    @endif
                </div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Sent</div>
                <div class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ optional($paymentRequest->sent_at)->format('d M Y, h:i A') ?? 'Not sent' }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Expires</div>
                <div class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ optional($paymentRequest->expires_at)->format('d M Y, h:i A') ?? 'No expiry set' }}</div>
            </div>
        </div>
        @if ($localizedText::column($paymentRequest, 'request_note') !== '')
            <p class="mt-4 border-t border-gray-200 pt-4 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-200">{{ $localizedText::column($paymentRequest, 'request_note') }}</p>
        @endif
    </section>

    <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $localizedText::column($agreement, 'agreement_title') }}</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Terms: {{ $suchakText::label($agreement->terms_status) }}</p>
            </div>
            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                Revision {{ $agreement->agreement_revision }}
            </div>
        </div>

        @if ($localizedText::column($agreement, 'agreement_body') !== '')
            <p class="mt-4 whitespace-pre-line text-sm leading-6 text-gray-700 dark:text-gray-200">{{ $localizedText::column($agreement, 'agreement_body') }}</p>
        @endif

        <div class="mt-5 grid gap-5 md:grid-cols-2">
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Stages</h3>
                <div class="mt-3 space-y-3">
                    @foreach ($agreement->stages as $stage)
                        <div class="rounded-md bg-gray-50 p-3 text-sm dark:bg-gray-900">
                            <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $localizedText::column($stage, 'stage_name') }}</div>
                            @if ($localizedText::column($stage, 'stage_description') !== '')
                                <div class="mt-1 text-gray-600 dark:text-gray-300">{{ $localizedText::column($stage, 'stage_description') }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Deliverables</h3>
                <div class="mt-3 space-y-3">
                    @foreach ($agreement->deliverables as $deliverable)
                        <div class="rounded-md bg-gray-50 p-3 text-sm dark:bg-gray-900">
                            <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $localizedText::column($deliverable, 'deliverable_name') }}</div>
                            @if ($localizedText::column($deliverable, 'deliverable_description') !== '')
                                <div class="mt-1 text-gray-600 dark:text-gray-300">{{ $localizedText::column($deliverable, 'deliverable_description') }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    @if (!empty($showTrackAIdentity) && is_array($paymentIdentity ?? null))
        <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Pay this Suchak</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                Customer → Suchak collection (Track A). This is not platform / PayU billing.
            </p>

            @if (!empty($paymentIdentity['is_configured']))
                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    @if (!empty($paymentIdentity['upi_vpa']))
                        <div>
                            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">UPI ID</div>
                            <div class="mt-1 break-all text-base font-semibold text-gray-900 dark:text-gray-100">{{ $paymentIdentity['upi_vpa'] }}</div>
                        </div>
                    @endif
                    @if (!empty($paymentIdentity['payment_qr_url']))
                        <div>
                            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Payment QR</div>
                            <img
                                src="{{ $paymentIdentity['payment_qr_url'] }}"
                                alt="Suchak payment QR"
                                class="mt-2 max-h-56 w-auto rounded-md border border-gray-200 bg-white p-2 dark:border-gray-600"
                            >
                        </div>
                    @endif
                </div>
            @else
                <p class="mt-4 text-sm text-gray-700 dark:text-gray-200">
                    This Suchak has not published a UPI ID or payment QR yet. Contact them using the verified request context, or wait for an updated request.
                </p>
            @endif
        </section>
    @endif

    <section class="rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950 shadow-sm dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
        <p class="font-semibold">Payment request only</p>
        <p class="mt-2">
            This page is not a paid receipt, invoice, credit note, refund record, or payment confirmation.
            @if (empty($showTrackAIdentity))
                Direct UPI or QR payment details are not shown when the collector is the platform.
            @else
                UPI / QR shown above belong to this Suchak for customer collection only — not platform subscription billing.
            @endif
        </p>
        <p class="mt-2">
            Platform-collected customers should not make direct Suchak payments. If any Suchak asks for payment outside this verified platform context, use your logged-in account to report it with evidence.
        </p>
        <p class="mt-2">
            Visibility policy: {{ $suchakText::label($paymentRequest->payment_detail_visibility_policy) }}.
        </p>
    </section>
</div>
@endsection
