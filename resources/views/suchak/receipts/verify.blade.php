@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-8">
    <div class="mb-6">
        <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">Suchak Receipt Verification</p>
        <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $receipt->document_number }}</h1>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            This page verifies that the receipt was issued from the Suchak customer payment ledger.
        </p>
    </div>

    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Payment Status</div>
                <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ ucwords(str_replace('_', ' ', $payment->payment_status)) }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Amount Received</div>
                <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $payment->currency }} {{ $payment->amount_received }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Payment Mode</div>
                <div class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ ucwords(str_replace('_', ' ', $payment->payment_mode)) }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Issued At</div>
                <div class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $receipt->issued_at->format('d M Y, h:i A') }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Suchak</div>
                <div class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $payment->suchakAccount->office_name ?: $payment->suchakAccount->suchak_name }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Package</div>
                <div class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $payment->customerAgreement->package_name }}</div>
            </div>
        </div>
    </section>
</div>
@endsection
