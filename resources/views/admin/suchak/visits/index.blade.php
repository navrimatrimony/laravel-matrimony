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

    {{--
        THE PLATFORM'S OWN PRICE, stated before any payout form is shown.

        This panel is the door for SuchakGrowthRewardService::createRewardRule(),
        which shipped with no caller outside tests. Without it the amount below
        would be bound to a rule that no admin could ever publish.

        A rule is immutable and undeletable, so "change the reward" means publish
        a later rule and let the newest in force win
        (SuchakGrowthRewardRule::visitRewardInForce()). That is deliberate: a
        price the platform paid on is a fact, and editing it away would falsify
        every payout already qualified under it.
    --}}
    <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">Platform visit reward</h2>

        @if ($visitRewardRule)
            <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">
                In force:
                <span class="font-semibold">{{ \App\Support\MoneyFormat::amount($visitRewardRule->reward_amount, $visitRewardRule->reward_currency ?? 'INR') }}</span>
                &middot; rule <code class="text-xs">{{ $visitRewardRule->rule_key }}</code>
                @if ($visitRewardRule->starts_at)
                    &middot; from {{ $visitRewardRule->starts_at->format('d M Y') }}
                @endif
                @if ($visitRewardRule->ends_at)
                    &middot; until {{ $visitRewardRule->ends_at->format('d M Y') }}
                @endif
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Every meeting payout below qualifies at this figure. It is not typed in, and a different amount is refused.
            </p>
            {{--
                Withdrawal, not editing. Publishing a later rule changes the price; this is the only
                way to stop paying for meetings altogether. It is one-way and reprices nothing —
                payouts already qualified under this rule keep the figure they were qualified at.
            --}}
            <form method="POST" action="{{ route('admin.suchak.visits.reward-rule.withdraw', $visitRewardRule) }}" class="mt-3 flex flex-wrap items-end gap-3">
                @csrf
                <label class="block flex-1 text-xs font-semibold text-gray-700 dark:text-gray-200">
                    Reason for withdrawing this price
                    <input type="text" name="withdraw_reason" required minlength="10" maxlength="1000" placeholder="Why the platform stops paying for meetings" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </label>
                <button type="submit" onclick="return confirm('Withdraw this platform visit reward? This cannot be undone — publishing a new rule is the way back.')" class="rounded-md border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-900/30">Withdraw rule</button>
            </form>
        @else
            <p class="mt-2 text-sm text-amber-800 dark:text-amber-200">
                No platform visit reward rule is published. Until one is, an admin types the figure and it is capped at
                <span class="font-semibold">{{ \App\Support\MoneyFormat::amount($visitPayoutCeiling) }}</span> per meeting.
            </p>
        @endif

        <form method="POST" action="{{ route('admin.suchak.visits.reward-rule.store') }}" class="mt-4 grid gap-3 md:grid-cols-5">
            @csrf
            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-200">
                Rule key
                <input type="text" name="rule_key" required minlength="3" maxlength="96" placeholder="visit-reward-2026-08" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            </label>
            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-200">
                Reward amount
                <input type="number" step="0.01" min="0.01" name="reward_amount" required class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            </label>
            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-200">
                Currency
                <input type="text" name="reward_currency" maxlength="3" value="INR" class="mt-1 w-full rounded-md border-gray-300 text-sm uppercase dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            </label>
            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-200">
                In force from (optional)
                <input type="date" name="starts_at" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            </label>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-black dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">Publish rule</button>
            </div>
        </form>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Publishing supersedes the rule in force from the date given; nothing is overwritten.
            @if ($visitPayoutRequiresSecondAdmin)
                Platform policy currently requires a second admin: whoever confirms a meeting cannot qualify its payout.
            @else
                Platform policy currently allows one admin to confirm a meeting and qualify its payout; every such payout is recorded as single-actor and flagged on the row below.
            @endif
        </p>
    </div>

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
                        {{--
                            THE COMPENSATING RECORD FOR SINGLE-ACTOR, ON THE ROW.

                            Platform policy allows one admin to admin-confirm a meeting and then
                            qualify its payout (see
                            SuchakVisitConfirmationService::assertPayoutActorAllowed()). It is
                            written into the visit event metadata and the admin audit log either
                            way, but a fact that only exists in a log is a fact nobody reads. This
                            compares the admin who confirmed against the admin who qualified, off
                            two columns already on the rows, so the same-actor payouts are visible
                            without anybody running a query.
                        --}}
                        @if ($visit->platformPayout && $visit->admin_confirmed_by_user_id && $visit->admin_confirmed_by_user_id === $visit->platformPayout->qualified_by_user_id)
                            <p class="mt-2">
                                <span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900 dark:bg-amber-950 dark:text-amber-100">Single-actor payout</span>
                            </p>
                        @endif
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
                            NOT BOUND TO $visit->fee_amount, AND THAT IS PERMANENT.

                            This form mints a SuchakPlatformPayout with REASON_PLATFORM_VISIT_REWARD —
                            the PLATFORM paying the SUCHAK. Pre-filling it with $visit->fee_amount
                            offered the payee their own number as the platform's default: a Suchak who
                            sets per_meeting_fee_amount high gets a form pre-loaded to pay them that
                            much of platform money, and one accepted default is a real payout. Do not
                            re-point it at the payee's fee.

                            What replaced the empty box is the source that was missing when this
                            comment was first written: a platform-owned SuchakGrowthRewardRule on the
                            new `platform_visit_confirmed` trigger, sourced exactly the way
                            SuchakGrowthRewardService has always sourced $rule->reward_amount. When
                            one is in force there is no input at all — the figure is the platform's,
                            decided before this meeting existed. Until one is published the field
                            stays, bounded by a platform-owned ceiling; the panel at the top of this
                            screen is where the rule that ends that interim gets published.
                        --}}
                        @if ($visitRewardRule)
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-200">Platform payout amount</label>
                            <p class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                {{ \App\Support\MoneyFormat::amount($visitRewardRule->reward_amount, $visitRewardRule->reward_currency ?? 'INR') }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Set by platform rule <code>{{ $visitRewardRule->rule_key }}</code>. Platform money paid to the Suchak, not the customer fee shown above.</p>
                        @else
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-200">Platform payout amount</label>
                            <input type="number" step="0.01" min="0.01" max="{{ $visitPayoutCeiling }}" name="amount" required placeholder="Platform's own reward amount" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            <p class="text-xs text-gray-500 dark:text-gray-400">Platform money paid to the Suchak. Not the customer fee shown above. Capped at {{ \App\Support\MoneyFormat::amount($visitPayoutCeiling) }} until a platform rule is published.</p>
                        @endif
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
