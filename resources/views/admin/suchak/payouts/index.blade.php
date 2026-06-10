@extends('layouts.admin')

@section('content')
@php
    $label = fn (?string $value) => $value ? ucfirst(str_replace('_', ' ', $value)) : '-';
    $money = fn ($amount, string $currency = 'INR') => $currency.' '.number_format((float) $amount, 2);
    $liability = $report['liability'] ?? [];
@endphp

<div class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <a href="{{ route('admin.suchak.dashboard') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">Back to Suchak dashboard</a>
                <h1 class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak Payouts</h1>
                <p class="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                    Approve, pay, reverse, and reconcile platform-to-Suchak payout liabilities. Report source: {{ $liability['source'] ?? 'suchak_platform_payouts_only' }}.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.suchak.safety.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-700">Safety</a>
                <a href="{{ route('admin.suchak.accounts.index') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Accounts</a>
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
            <p class="font-semibold">Please fix the highlighted inputs.</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Qualified</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format((int) ($liability['qualified_count'] ?? 0)) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Approved</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format((int) ($liability['approved_count'] ?? 0)) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">On hold</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format((int) ($liability['on_hold_count'] ?? 0)) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Approved net</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $money($liability['approved_net_amount'] ?? 0) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $statementMonth }} paid net</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $money($liability['paid_month_net_amount'] ?? 0) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $statementMonth }} reversed</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $money($liability['reversed_month_amount'] ?? 0) }}</div>
        </div>
    </section>

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Settlement Statement</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $report['period_start'] ?? '' }} to {{ $report['period_end'] ?? '' }}</p>
            </div>
            <form method="GET" action="{{ route('admin.suchak.payouts.index') }}" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="statement_month_filter">Month</label>
                    <input id="statement_month_filter" name="statement_month" value="{{ $statementMonth }}" pattern="\d{4}-\d{2}" class="mt-1 w-36 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="status_filter">Status</label>
                    <select id="status_filter" name="status" class="mt-1 w-44 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $payoutStatus)
                            <option value="{{ $payoutStatus }}" @selected($status === $payoutStatus)>{{ $label($payoutStatus) }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">Filter</button>
            </form>
        </div>

        <form method="POST" action="{{ route('admin.suchak.payouts.settlements.generate') }}" class="mt-5 grid gap-4 lg:grid-cols-4">
            @csrf
            <div class="lg:col-span-2">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="suchak_account_id">Verified Suchak account</label>
                <select id="suchak_account_id" name="suchak_account_id" required class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected((string) old('suchak_account_id') === (string) $account->id)>#{{ $account->id }} {{ $account->suchak_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="statement_month_generate">Month</label>
                <input id="statement_month_generate" name="statement_month" value="{{ old('statement_month', $statementMonth) }}" required pattern="\d{4}-\d{2}" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div class="flex items-end">
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Generate statement</button>
            </div>
        </form>
    </section>

    <section class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Payout Workflow</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Payout</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Amounts</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Settlement</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($payouts as $payout)
                        <tr>
                            <td class="px-4 py-3 align-top">
                                <div class="font-semibold text-gray-900 dark:text-gray-100">#{{ $payout->id }} {{ $label($payout->payout_status) }}</div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $payout->platform_event_key }} · {{ $label($payout->payout_reason) }}</div>
                                <div class="mt-2 text-gray-700 dark:text-gray-300">{{ $payout->suchakAccount?->suchak_name ?: 'Unknown Suchak' }}</div>
                                @if ($payout->hold_reason)
                                    <div class="mt-2 rounded-md bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-900 dark:bg-amber-950 dark:text-amber-100">{{ $payout->hold_reason }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top text-gray-700 dark:text-gray-300">
                                <div>Gross: <span class="font-semibold">{{ $money($payout->amount, $payout->currency) }}</span></div>
                                <div class="mt-1">Deduction: {{ $money($payout->deduction_amount ?? 0, $payout->currency) }}</div>
                                <div class="mt-1">Reversal: {{ $money($payout->reversal_amount ?? 0, $payout->currency) }}</div>
                                <div class="mt-1">Net: <span class="font-semibold">{{ $money($payout->net_amount ?? $payout->amount, $payout->currency) }}</span></div>
                            </td>
                            <td class="px-4 py-3 align-top text-gray-700 dark:text-gray-300">
                                <div>Reference: <span class="font-semibold">{{ $payout->payout_reference_number ?: '-' }}</span></div>
                                <div class="mt-1">Paid: {{ $payout->paid_at?->format('Y-m-d H:i') ?: '-' }}</div>
                                <div class="mt-1">Statement: {{ $payout->settlementStatement?->statement_number ?: '-' }}</div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                @if ($payout->payout_status === \App\Models\SuchakPlatformPayout::STATUS_QUALIFIED)
                                    <form method="POST" action="{{ route('admin.suchak.payouts.approve', $payout) }}" class="space-y-2">
                                        @csrf
                                        <input name="deduction_amount" value="{{ old('deduction_amount', '0.00') }}" inputmode="decimal" class="w-full rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                        <textarea name="status_note" rows="2" required minlength="10" maxlength="1000" placeholder="Approval note" class="w-full rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('status_note') }}</textarea>
                                        <button type="submit" class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Approve</button>
                                    </form>
                                @elseif ($payout->payout_status === \App\Models\SuchakPlatformPayout::STATUS_APPROVED)
                                    <form method="POST" action="{{ route('admin.suchak.payouts.pay', $payout) }}" class="space-y-2">
                                        @csrf
                                        <input name="payout_reference_number" value="{{ old('payout_reference_number') }}" required minlength="3" maxlength="160" placeholder="Reference number" class="w-full rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                        <input name="paid_at" value="{{ old('paid_at', now()->format('Y-m-d H:i:s')) }}" class="w-full rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                        <textarea name="payout_reference_note" rows="2" maxlength="1000" placeholder="Payment note" class="w-full rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('payout_reference_note') }}</textarea>
                                        <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">Mark paid</button>
                                    </form>
                                @elseif ($payout->payout_status === \App\Models\SuchakPlatformPayout::STATUS_PAID)
                                    <form method="POST" action="{{ route('admin.suchak.payouts.reverse', $payout) }}" class="space-y-2">
                                        @csrf
                                        <textarea name="reversal_reason" rows="2" required minlength="10" maxlength="1000" placeholder="Reversal reason" class="w-full rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('reversal_reason') }}</textarea>
                                        <button type="submit" class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">Reverse</button>
                                    </form>
                                @else
                                    <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">No payout action</span>
                                @endif

                                @if (in_array($payout->payout_status, [\App\Models\SuchakPlatformPayout::STATUS_ON_HOLD, \App\Models\SuchakPlatformPayout::STATUS_QUALIFIED, \App\Models\SuchakPlatformPayout::STATUS_APPROVED], true))
                                    <form method="POST" action="{{ route('admin.suchak.payouts.cancel', $payout) }}" class="mt-3 space-y-2">
                                        @csrf
                                        <textarea name="cancellation_reason" rows="2" required minlength="10" maxlength="1000" placeholder="Cancellation reason" class="w-full rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('cancellation_reason') }}</textarea>
                                        <button type="submit" class="rounded-md border border-red-300 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-200 dark:hover:bg-red-950">Cancel</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No Suchak payouts found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-200 px-5 py-4 dark:border-gray-700">
            {{ $payouts->links() }}
        </div>
    </section>

    <section class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent Settlement Statements</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Statement</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Suchak</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Totals</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Audit hash</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($settlements as $settlement)
                        <tr>
                            <td class="px-4 py-3 align-top">
                                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $settlement->statement_number }}</div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $settlement->statement_month }} · {{ $label($settlement->statement_status) }} · {{ $settlement->generated_at?->format('Y-m-d H:i') }}</div>
                            </td>
                            <td class="px-4 py-3 align-top text-gray-700 dark:text-gray-300">{{ $settlement->suchakAccount?->suchak_name ?: 'Unknown Suchak' }}</td>
                            <td class="px-4 py-3 align-top text-gray-700 dark:text-gray-300">
                                <div>{{ number_format($settlement->payout_count) }} payouts</div>
                                <div class="mt-1">Gross {{ $money($settlement->gross_amount, $settlement->currency) }}</div>
                                <div class="mt-1">Deducted {{ $money($settlement->deduction_amount, $settlement->currency) }}</div>
                                <div class="mt-1">Reversed {{ $money($settlement->reversal_amount, $settlement->currency) }}</div>
                                <div class="mt-1 font-semibold">Net {{ $money($settlement->net_amount, $settlement->currency) }}</div>
                            </td>
                            <td class="px-4 py-3 align-top font-mono text-xs text-gray-600 dark:text-gray-300">{{ $settlement->statement_hash }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No settlement statements generated.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
