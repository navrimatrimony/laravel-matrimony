@extends($layout)

@section('content')
<div class="py-8">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg border border-gray-200 dark:border-gray-700 p-6">
            <div class="flex items-start justify-between gap-4 border-b border-gray-200 dark:border-gray-700 pb-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Tax Invoice</h1>
                    <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">Invoice No: <span class="font-semibold">{{ $invoiceNo }}</span></p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Invoice Date: {{ optional($invoiceDate)->format('Y-m-d H:i') }}</p>
                </div>
                <div class="text-right">
                    <a
                        href="{{ $isAdminView ? route('admin.payments.invoice.pdf', ['txnid' => $payment->txnid]) : route('payments.invoice.pdf', ['txnid' => $payment->txnid]) }}"
                        class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500"
                    >
                        Download PDF
                    </a>
                </div>
                <div class="text-right text-sm text-gray-700 dark:text-gray-200">
                    <p class="font-semibold">{{ $seller['legal_name'] !== '' ? $seller['legal_name'] : 'Seller details not configured' }}</p>
                    @if ($seller['address'] !== '')<p>{{ $seller['address'] }}</p>@endif
                    @if ($seller['email'] !== '')<p>{{ $seller['email'] }}</p>@endif
                    @if ($seller['phone'] !== '')<p>{{ $seller['phone'] }}</p>@endif
                    @if ($seller['gstin'] !== '')<p>GSTIN: {{ $seller['gstin'] }}</p>@endif
                    @if ($seller['pan'] !== '')<p>PAN: {{ $seller['pan'] }}</p>@endif
                    @if ($seller['state_code'] !== '')<p>State Code: {{ $seller['state_code'] }}</p>@endif
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Billed To</h2>
                    <p class="text-sm text-gray-900 dark:text-gray-100 font-medium">{{ $payment->user?->name ?? 'User' }}</p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $payment->user?->email ?? '—' }}</p>
                </div>
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Payment Ref</h2>
                    <p class="text-sm text-gray-700 dark:text-gray-200">Txn ID: {{ $payment->txnid }}</p>
                    @if ($payment->payu_txnid)<p class="text-sm text-gray-700 dark:text-gray-200">Gateway Txn: {{ $payment->payu_txnid }}</p>@endif
                    <p class="text-sm text-gray-700 dark:text-gray-200">Status: {{ $payment->payment_status ?? $payment->status }}</p>
                </div>
            </div>

            <div class="mt-6 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2">Description</th>
                            <th class="px-3 py-2">Period</th>
                            <th class="px-3 py-2 text-right">Amount ({{ $currency }})</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr>
                            <td class="px-3 py-2">{{ $planName }}</td>
                            <td class="px-3 py-2">{{ $billingKey !== '' ? $billingKey : '—' }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($baseAmount, 2) }}</td>
                        </tr>
                        @if ($discountAmount > 0)
                            <tr>
                                <td class="px-3 py-2">Coupon Discount</td>
                                <td class="px-3 py-2">—</td>
                                <td class="px-3 py-2 text-right">-{{ number_format($discountAmount, 2) }}</td>
                            </tr>
                        @endif
                        @if ($walletUsed > 0)
                            <tr>
                                <td class="px-3 py-2">Wallet Used</td>
                                <td class="px-3 py-2">—</td>
                                <td class="px-3 py-2 text-right">-{{ number_format($walletUsed, 2) }}</td>
                            </tr>
                        @endif
                        <tr class="font-semibold">
                            <td class="px-3 py-2">Total Paid</td>
                            <td class="px-3 py-2">—</td>
                            <td class="px-3 py-2 text-right">{{ number_format($finalAmount, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            @if ($seller['terms'] !== '')
                <div class="mt-6 text-xs text-gray-600 dark:text-gray-300 border-t border-gray-200 dark:border-gray-700 pt-3">
                    {{ $seller['terms'] }}
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
