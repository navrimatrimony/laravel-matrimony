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
        <div class="grid gap-6 lg:grid-cols-[1fr_180px]">
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
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-center dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Receipt verification QR</div>
                <img src="{{ $verificationQrDataUri }}" alt="Receipt verification QR" class="mx-auto mt-3 h-32 w-32 rounded-md border border-gray-200 bg-white p-2 dark:border-gray-700">
                <div class="mt-3 break-all text-xs text-gray-500 dark:text-gray-400">{{ $verificationUrl }}</div>
            </div>
        </div>
        <div class="mt-5 rounded-md bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">
            Verified receipt · {{ $poweredByFooter }}
        </div>
    </section>
</div>
@endsection
