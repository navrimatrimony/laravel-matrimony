@extends('layouts.admin')

@section('content')
@php
    $label = fn (string $value) => ucfirst(str_replace('_', ' ', $value));
@endphp

<div class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak Meetings</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Admin confirmation, dispute and payout qualification for recorded Suchak meetings.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.suchak.payouts.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-700">Payouts</a>
                <a href="{{ route('admin.suchak.safety.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-700">Safety</a>
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
            Status
            <select name="visit_status" class="mt-1 block rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <option value="">All</option>
                @foreach ($statuses as $value)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label($value) }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Filter</button>
    </form>

    <div class="space-y-4">
        @forelse ($visits as $visit)
            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                            Meeting #{{ $visit->id }} &middot; {{ $visit->suchakAccount?->suchak_name ?? 'Suchak '.$visit->suchak_account_id }}
                        </p>
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                            Pipeline {{ $visit->pipeline_id }} &middot; meeting {{ $visit->meeting_sequence }} &middot; {{ $label($visit->meeting_mode) }}
                            @if ($visit->helperSuchakAccount)
                                &middot; candidate from {{ $visit->helperSuchakAccount->suchak_name }}
                            @endif
                        </p>
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                            {{-- This meeting's fee only. D17: no accumulated total on a screen about one meeting. --}}
                            {{--
                                Labelled CUSTOMER-side on purpose. This is what the Suchak quoted the
                                customer, frozen at schedule time from the Suchak's own agreement and
                                package — a figure the Suchak sets. It is not the platform's obligation
                                to anybody, and it must never read as one on the same card as a payout
                                form. Rendered in the currency frozen WITH the amount: a quote carries
                                its own unit, so a USD package must not print as ₹.
                            --}}
                            Customer fee for this meeting (quoted by the Suchak):
                            {{ \App\Support\MoneyFormat::amount($visit->fee_amount, $visit->fee_currency ?? 'INR') ?? 'not quoted' }}
                        </p>
                    </div>
                    <div class="text-right text-xs text-gray-600 dark:text-gray-300">
                        <span class="inline-flex rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold text-indigo-900 dark:bg-indigo-950 dark:text-indigo-100">{{ $label($visit->visit_status) }}</span>
                        <p class="mt-2">Policy: {{ $label($visit->confirmation_policy_mode) }}</p>
                        <p>Suchak: {{ $label($visit->suchak_completion_status) }}</p>
                        <p>Customer: {{ $label($visit->user_confirmation_status) }}</p>
                        <p>Admin: {{ $label($visit->admin_confirmation_status) }}</p>
                    </div>
                </div>

                <div class="mt-4 grid gap-4 lg:grid-cols-3">
                    <form method="POST" action="{{ route('admin.suchak.visits.confirm', $visit) }}" class="space-y-2">
                        @csrf
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-200">Admin confirmation note</label>
                        <textarea name="confirmation_note" rows="2" required minlength="10" maxlength="1000" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                        <button type="submit" class="w-full rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Confirm</button>
                    </form>

                    <form method="POST" action="{{ route('admin.suchak.visits.dispute', $visit) }}" class="space-y-2">
                        @csrf
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-200">Dispute reason</label>
                        <textarea name="dispute_reason" rows="2" required minlength="10" maxlength="1000" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                        <button type="submit" class="w-full rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700">Open dispute</button>
                    </form>

                    <form method="POST" action="{{ route('admin.suchak.visits.qualify-payout', $visit) }}" class="space-y-2">
                        @csrf
                        {{--
                            Deliberately EMPTY, and it stays empty until a platform-controlled source
                            for this number exists.

                            This form mints a SuchakPlatformPayout with REASON_PLATFORM_VISIT_REWARD —
                            the PLATFORM paying the SUCHAK. Pre-filling it with $visit->fee_amount
                            offered the payee their own number as the platform's default: a Suchak who
                            sets per_meeting_fee_amount high gets a form pre-loaded to pay them that
                            much of platform money, and one accepted default is a real payout.

                            The comparable existing caller, SuchakGrowthRewardService, never asks a
                            human for the figure at all — it takes $rule->reward_amount off a
                            platform-owned SuchakGrowthRewardRule. There is no equivalent rule for a
                            visit reward yet (SuchakGrowthRewardRule only triggers on
                            platform_payment_confirmed), so there is nothing honest to bind to. An
                            empty field that an admin must type into is the correct interim: it makes
                            the amount a decision, not a default. Give visit rewards a platform-owned
                            rule and bind this to it — do not re-point it at the payee's fee.
                        --}}
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-200">Platform payout amount (INR)</label>
                        <input type="number" step="0.01" min="0.01" name="amount" required placeholder="Platform's own reward amount" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Platform money paid to the Suchak. Not the customer fee shown above.</p>
                        <textarea name="qualification_note" rows="2" required minlength="10" maxlength="1000" placeholder="Qualification note" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                        <button type="submit" class="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Qualify payout</button>
                    </form>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-600 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">No Suchak meetings recorded yet.</div>
        @endforelse
    </div>

    <div>{{ $visits->links() }}</div>
</div>
@endsection
