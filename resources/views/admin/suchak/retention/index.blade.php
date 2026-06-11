@extends('layouts.admin')

@section('content')
@php
    $label = fn (string $value) => ucfirst(str_replace('_', ' ', $value));
@endphp

<div class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak Retention Center</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Campaign rules, loyalty tier snapshots, monthly value reports, and renewal/revenue-share offer tracking.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.suchak.dashboard') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-700">Dashboard</a>
                <a href="{{ route('admin.suchak.payouts.index') }}" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">Payouts</a>
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">{{ session('error') }}</div>
    @endif

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Policy-Driven Loyalty Tiers</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Current month: {{ $summary['month'] }}. Tiers are read from the active Suchak policy row.</p>
            </div>
            <form method="GET" action="{{ route('admin.suchak.retention.index') }}" class="flex gap-2">
                <input type="month" name="month" value="{{ $summary['month'] }}" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <button type="submit" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">Load</button>
            </form>
        </div>
        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            @foreach ($summary['loyalty_policy'] as $tier)
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $tier['tier_label'] }}</div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Minimum score {{ $tier['minimum_score'] }}</div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Create Campaign Rule</h2>
        <form method="POST" action="{{ route('admin.suchak.retention.campaign-rules.store') }}" class="mt-4 grid gap-4 lg:grid-cols-4">
            @csrf
            <input name="campaign_key" value="{{ old('campaign_key') }}" required maxlength="96" placeholder="campaign_key" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            <input name="campaign_name" value="{{ old('campaign_name') }}" required minlength="10" maxlength="160" placeholder="Campaign name" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            <select name="campaign_goal" required class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                @foreach ($goals as $goal)
                    <option value="{{ $goal }}">{{ $label($goal) }}</option>
                @endforeach
            </select>
            <select name="qualification_metric" required class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                @foreach ($metrics as $metric)
                    <option value="{{ $metric }}">{{ $label($metric) }}</option>
                @endforeach
            </select>
            <input type="number" step="0.01" min="0" name="threshold_value" value="{{ old('threshold_value', 0) }}" required placeholder="Threshold" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            <select name="bonus_type" required class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                @foreach ($bonusTypes as $bonusType)
                    <option value="{{ $bonusType }}">{{ $label($bonusType) }}</option>
                @endforeach
            </select>
            <input type="number" step="0.01" min="0" name="bonus_amount" value="{{ old('bonus_amount', 0) }}" required placeholder="Bonus amount" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            <input name="bonus_currency" value="{{ old('bonus_currency', 'INR') }}" required maxlength="3" class="rounded-md border-gray-300 text-sm uppercase dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            <input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            <input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            <div class="lg:col-span-2">
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Create rule</button>
            </div>
        </form>
    </section>

    <section class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Campaign Rules + Bonus Qualification</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Rule</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Metric</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Qualify Bonus</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($summary['campaign_rules'] as $rule)
                        <tr>
                            <td class="px-4 py-3 align-top">
                                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $rule->campaign_name }}</div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $rule->campaign_key }} · {{ $label($rule->campaign_status) }}</div>
                            </td>
                            <td class="px-4 py-3 align-top text-gray-700 dark:text-gray-300">
                                {{ $label($rule->qualification_metric) }} ≥ {{ $rule->threshold_value }}<br>
                                {{ $label($rule->bonus_type) }} {{ $rule->bonus_amount }} {{ $rule->bonus_currency }}
                            </td>
                            <td class="px-4 py-3 align-top">
                                <form method="POST" action="{{ route('admin.suchak.retention.campaign-rules.qualify', $rule) }}" class="grid gap-2 xl:grid-cols-[160px_110px_110px_minmax(220px,1fr)_auto]">
                                    @csrf
                                    <select name="suchak_account_id" required class="rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                        @foreach ($summary['accounts'] as $account)
                                            <option value="{{ $account->id }}">#{{ $account->id }} {{ $account->suchak_name }}</option>
                                        @endforeach
                                    </select>
                                    <input type="month" name="qualification_month" value="{{ $summary['month'] }}" required class="rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                    <input type="number" step="0.01" min="0" name="metric_value" placeholder="Metric" class="rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                    <textarea name="qualification_note" rows="2" required minlength="10" maxlength="1000" placeholder="Qualification note" class="rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                                    <button type="submit" class="rounded-md bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">Qualify</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No Suchak campaign rules yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Generate Monthly Value Report / Offer</h2>
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <form method="POST" action="{{ $summary['accounts']->isNotEmpty() ? route('admin.suchak.retention.accounts.reports.generate', $summary['accounts']->first()) : '#' }}" class="grid gap-3">
                @csrf
                <select name="account_route_selector" onchange="this.form.action=this.options[this.selectedIndex].dataset.action" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    @foreach ($summary['accounts'] as $account)
                        <option data-action="{{ route('admin.suchak.retention.accounts.reports.generate', $account) }}">#{{ $account->id }} {{ $account->suchak_name }}</option>
                    @endforeach
                </select>
                <input type="month" name="report_month" value="{{ $summary['month'] }}" required class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <button type="submit" @disabled($summary['accounts']->isEmpty()) class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">Generate report</button>
            </form>

            <form method="POST" action="{{ $summary['accounts']->isNotEmpty() ? route('admin.suchak.retention.accounts.offers.store', $summary['accounts']->first()) : '#' }}" class="grid gap-3">
                @csrf
                <select name="account_route_selector" onchange="this.form.action=this.options[this.selectedIndex].dataset.action" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    @foreach ($summary['accounts'] as $account)
                        <option data-action="{{ route('admin.suchak.retention.accounts.offers.store', $account) }}">#{{ $account->id }} {{ $account->suchak_name }}</option>
                    @endforeach
                </select>
                <select name="monthly_value_report_id" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">No linked report</option>
                    @foreach ($summary['recent_reports'] as $report)
                        <option value="{{ $report->id }}">Report #{{ $report->id }} · {{ $report->suchakAccount?->suchak_name }} · {{ $report->report_month }}</option>
                    @endforeach
                </select>
                <select name="offer_type" required class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    @foreach ($offerTypes as $offerType)
                        <option value="{{ $offerType }}">{{ $label($offerType) }}</option>
                    @endforeach
                </select>
                <div class="grid gap-3 sm:grid-cols-3">
                    <input type="number" step="0.01" min="0" max="100" name="discount_percent" placeholder="Discount %" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <input type="number" step="0.01" min="0" max="100" name="revenue_share_percent" placeholder="Share %" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <input name="currency" value="INR" required maxlength="3" class="rounded-md border-gray-300 text-sm uppercase dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <input type="number" step="0.01" min="0" name="offer_amount" placeholder="Offer amount" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <textarea name="offer_note" rows="2" required minlength="10" maxlength="1000" placeholder="Offer note" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                <button type="submit" @disabled($summary['accounts']->isEmpty()) class="rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 disabled:opacity-50">Record offer</button>
            </form>
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent Reports</h2>
            </div>
            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($summary['recent_reports'] as $report)
                    <div class="p-4 text-sm">
                        <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                            <div>
                                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $report->suchakAccount?->suchak_name }} · {{ $report->report_month }}</div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Tier {{ $report->loyaltyTierSnapshot?->tier_label ?: '-' }} · Platform leads {{ $report->platform_leads_count }}</div>
                                <div class="mt-2 text-gray-700 dark:text-gray-300">
                                    Platform value {{ $report->platform_customer_value_amount }} · Suchak customer value {{ $report->suchak_customer_value_amount }} · Campaign bonus {{ $report->campaign_bonus_amount }}
                                </div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $report->unsupported_claims_note }}</div>
                            </div>
                            <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold uppercase text-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ $label($report->report_status) }}</span>
                        </div>
                    </div>
                @empty
                    <div class="p-4 text-sm text-gray-500 dark:text-gray-400">No monthly value reports generated.</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent Offers</h2>
            </div>
            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($summary['recent_offers'] as $offer)
                    <div class="p-4 text-sm">
                        <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $offer->suchakAccount?->suchak_name }} · {{ $label($offer->offer_type) }}</div>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $label($offer->offer_status) }} · {{ $offer->offered_at?->format('Y-m-d H:i') }}</div>
                        <div class="mt-2 text-gray-700 dark:text-gray-300">{{ $offer->offer_note }}</div>
                    </div>
                @empty
                    <div class="p-4 text-sm text-gray-500 dark:text-gray-400">No retention offers recorded.</div>
                @endforelse
            </div>
        </section>
    </div>
</div>
@endsection
