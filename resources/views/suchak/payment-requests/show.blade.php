@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-4xl px-4 py-8">
    <div class="mb-6">
        <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">Suchak Payment Request</p>
        <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $paymentRequest->request_title }}</h1>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $paymentRequest->collector_disclosure }}</p>
    </div>

    <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Status</div>
                <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ ucwords(str_replace('_', ' ', $paymentRequest->payment_status)) }}</div>
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
        @if ($paymentRequest->request_note)
            <p class="mt-4 border-t border-gray-200 pt-4 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-200">{{ $paymentRequest->request_note }}</p>
        @endif
    </section>

    <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $agreement->agreement_title }}</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Terms: {{ ucwords(str_replace('_', ' ', $agreement->terms_status)) }}</p>
            </div>
            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                Revision {{ $agreement->agreement_revision }}
            </div>
        </div>

        @if ($agreement->agreement_body)
            <p class="mt-4 whitespace-pre-line text-sm leading-6 text-gray-700 dark:text-gray-200">{{ $agreement->agreement_body }}</p>
        @endif

        <div class="mt-5 grid gap-5 md:grid-cols-2">
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Stages</h3>
                <div class="mt-3 space-y-3">
                    @foreach ($agreement->stages as $stage)
                        <div class="rounded-md bg-gray-50 p-3 text-sm dark:bg-gray-900">
                            <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $stage->stage_name }}</div>
                            @if ($stage->stage_description)
                                <div class="mt-1 text-gray-600 dark:text-gray-300">{{ $stage->stage_description }}</div>
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
                            <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $deliverable->deliverable_name }}</div>
                            @if ($deliverable->deliverable_description)
                                <div class="mt-1 text-gray-600 dark:text-gray-300">{{ $deliverable->deliverable_description }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950 shadow-sm dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
        <p class="font-semibold">Payment request only</p>
        <p class="mt-2">
            This page is not a paid receipt, invoice, credit note, refund record, or payment confirmation. Direct UPI or QR payment details are not shown on public Suchak marketplace/profile pages.
        </p>
        <p class="mt-2">
            Platform-collected customers should not make direct Suchak payments. If any Suchak asks for payment outside this verified platform context, use your logged-in account to report it with evidence.
        </p>
        <p class="mt-2">
            Visibility policy: {{ ucwords(str_replace('_', ' ', $paymentRequest->payment_detail_visibility_policy)) }}.
        </p>
    </section>
</div>
@endsection
