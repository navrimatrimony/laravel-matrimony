@extends('layouts.admin')

@section('content')
@php
    $label = fn (string $value) => ucfirst(str_replace('_', ' ', $value));
    $riskSeverityClasses = [
        'urgent' => 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-100',
        'high' => 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100',
        'medium' => 'bg-indigo-100 text-indigo-900 dark:bg-indigo-950 dark:text-indigo-100',
    ];
    $qualityBandClasses = [
        'strong' => 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100',
        'review' => 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100',
        'restricted' => 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-100',
    ];
@endphp

<div class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak Safety Center</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Dispute, abuse, freeze, pause, revoke, evidence, and audit operations for Suchak accounts.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.suchak.dashboard') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-700">Dashboard</a>
                <a href="{{ route('admin.suchak.accounts.index') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Accounts</a>
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">{{ session('error') }}</div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Open disputes</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['open_disputes']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Under review</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['under_review']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Abuse reports</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['abuse_reports']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Direct payment complaints</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['direct_payment_complaints']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Payment freezes</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['active_payment_freezes']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Payout holds</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['active_payout_holds']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Feature suspensions</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['active_feature_suspensions']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Frozen accounts</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['frozen_accounts']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Paused public</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['paused_public_accounts']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Revokable reps</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['revokable_representations']) }}</div>
        </div>
    </div>

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Risk + Compliance Center</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Admin-only payment, trust, QR/PDF, and growth-risk signals linked to source evidence records.</p>
            </div>
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                Generated {{ $riskComplianceCenter['generated_at']->format('Y-m-d H:i') }}
            </div>
        </div>

        <div class="mt-5 grid gap-4 xl:grid-cols-2">
            @foreach ($riskComplianceCenter['panels'] as $panel)
                <article class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $panel['title'] }}</h3>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold uppercase {{ $riskSeverityClasses[$panel['severity']] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200' }}">{{ $panel['severity'] }}</span>
                            </div>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $panel['description'] }}</p>
                        </div>
                        <div class="text-left sm:text-right">
                            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($panel['count']) }}</div>
                            <a href="{{ $panel['queue_url'] }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">Open evidence queue</a>
                        </div>
                    </div>

                    <div class="mt-4 divide-y divide-gray-200 rounded-md border border-gray-200 bg-white dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800">
                        @forelse ($panel['records'] as $record)
                            <div class="p-3 text-sm">
                                <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $record['source_label'] }}</div>
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            {{ $record['suchak_name'] }} · {{ $record['status'] }} · {{ $record['occurred_at']?->format('Y-m-d H:i') ?: '-' }}
                                        </div>
                                        <p class="mt-2 text-gray-700 dark:text-gray-300">{{ $record['summary'] }}</p>
                                        @if (! empty($record['badges']))
                                            <div class="mt-2 flex flex-wrap gap-1">
                                                @foreach ($record['badges'] as $badge)
                                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ $badge['label'] }}: {{ $badge['value'] }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                    @if ($record['account_url'])
                                        <a href="{{ $record['account_url'] }}" class="shrink-0 text-xs font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">Open source account</a>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="p-4 text-sm text-gray-500 dark:text-gray-400">No current evidence records for this signal.</div>
                        @endforelse
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Quality Score + Granular Suspension</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Admin-only quality score and capability controls for upload, PDF, payment, payout, referral, collaboration, and public request actions.</p>
            </div>
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                Generated {{ $qualityControlCenter['generated_at']->format('Y-m-d H:i') }}
            </div>
        </div>

        <div class="mt-5 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Suchak</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Quality</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Risk signals</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Suspend capability</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($qualityControlCenter['accounts'] as $qualityAccount)
                        @php
                            $account = $qualityAccount['account'];
                            $activeSuspensions = $qualityAccount['active_suspensions'];
                        @endphp
                        <tr>
                            <td class="px-4 py-3 align-top">
                                <a href="{{ route('admin.suchak.accounts.show', $account) }}" class="font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">{{ $account->suchak_name }}</a>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">#{{ $account->id }} · {{ $account->user?->email ?: '-' }} · {{ $label($account->verification_status) }} / {{ $label($account->public_status) }}</div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex items-center gap-2">
                                    <span class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ $qualityAccount['score'] }}</span>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold uppercase {{ $qualityBandClasses[$qualityAccount['band']] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-900 dark:text-gray-200' }}">{{ $qualityAccount['band'] }}</span>
                                </div>
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @forelse ($activeSuspensions as $suspension)
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-900 dark:bg-red-950 dark:text-red-100">{{ $featureSuspensionFeatures[$suspension->feature_key] ?? $label($suspension->feature_key) }}</span>
                                    @empty
                                        <span class="text-xs text-gray-500 dark:text-gray-400">No active feature suspension.</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <ul class="space-y-1 text-xs text-gray-600 dark:text-gray-300">
                                    @foreach ($qualityAccount['reasons'] as $reason)
                                        <li>{{ $reason }}</li>
                                    @endforeach
                                </ul>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <form method="POST" action="{{ route('admin.suchak.safety.accounts.feature-suspensions.store', $account) }}" class="grid gap-2 lg:grid-cols-[180px_minmax(220px,1fr)_auto] lg:items-start">
                                    @csrf
                                    <select name="feature_key" required class="rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                        @foreach ($featureSuspensionFeatures as $featureKey => $featureLabel)
                                            <option value="{{ $featureKey }}">{{ $featureLabel }}</option>
                                        @endforeach
                                    </select>
                                    <textarea name="reason" rows="2" required minlength="10" maxlength="1000" placeholder="Suspension reason" class="rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                                    <button type="submit" class="rounded-md bg-red-600 px-3 py-2 text-xs font-semibold text-white hover:bg-red-700">Suspend</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No Suchak accounts found for quality scoring.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-6 rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Active Capability Suspensions</h3>
            </div>
            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($qualityControlCenter['active_suspensions'] as $suspension)
                    <div class="grid gap-4 p-4 lg:grid-cols-[minmax(180px,1fr)_minmax(220px,2fr)_minmax(260px,2fr)] lg:items-start">
                        <div>
                            <a href="{{ route('admin.suchak.accounts.show', $suspension->suchakAccount) }}" class="font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">{{ $suspension->suchakAccount?->suchak_name ?: 'Unknown Suchak' }}</a>
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $featureSuspensionFeatures[$suspension->feature_key] ?? $label($suspension->feature_key) }} · {{ $suspension->created_at?->format('Y-m-d H:i') }}</div>
                        </div>
                        <div class="text-sm text-gray-700 dark:text-gray-300">{{ $suspension->reason }}</div>
                        <form method="POST" action="{{ route('admin.suchak.safety.feature-suspensions.release', $suspension) }}" class="grid gap-2 sm:grid-cols-[1fr_auto]">
                            @csrf
                            <textarea name="reason" rows="2" required minlength="10" maxlength="1000" placeholder="Release reason" class="rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                            <button type="submit" class="rounded-md bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">Release</button>
                        </form>
                    </div>
                @empty
                    <div class="p-4 text-sm text-gray-500 dark:text-gray-400">No active capability suspension records.</div>
                @endforelse
            </div>
        </div>
    </section>

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Open Dispute / Abuse Case</h2>
        <form method="POST" action="{{ route('admin.suchak.safety.disputes.store') }}" class="mt-4 grid gap-4 lg:grid-cols-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Suchak account</label>
                <select name="suchak_account_id" required class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected((string) old('suchak_account_id') === (string) $account->id)>
                            #{{ $account->id }} {{ $account->suchak_name }} ({{ $label($account->verification_status) }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Representation ID</label>
                <input type="number" name="representation_id" value="{{ old('representation_id') }}" min="1" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Type</label>
                <select name="dispute_type" required class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    @foreach ($disputeTypes as $disputeType)
                        <option value="{{ $disputeType }}" @selected(old('dispute_type', \App\Models\SuchakDispute::TYPE_ABUSE_REPORT) === $disputeType)>{{ $label($disputeType) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Priority</label>
                <select name="priority" required class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    @foreach ($priorities as $priority)
                        <option value="{{ $priority }}" @selected(old('priority', \App\Models\SuchakDispute::PRIORITY_NORMAL) === $priority)>{{ $label($priority) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="lg:col-span-2">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Summary</label>
                <textarea name="summary" rows="3" required minlength="10" maxlength="1000" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('summary') }}</textarea>
            </div>
            <div class="lg:col-span-2">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Evidence summary</label>
                <textarea name="evidence_summary" rows="3" maxlength="2000" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('evidence_summary') }}</textarea>
            </div>
            <div class="lg:col-span-4">
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Open case</button>
            </div>
        </form>
    </section>

    <section class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Dispute Case Lifecycle</h2>
                <form method="GET" action="{{ route('admin.suchak.safety.index') }}" class="flex flex-wrap items-center gap-2">
                    <select name="status" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">All statuses</option>
                        @foreach ($disputeStatuses as $disputeStatus)
                            <option value="{{ $disputeStatus }}" @selected($status === $disputeStatus)>{{ $label($disputeStatus) }}</option>
                        @endforeach
                    </select>
                    <select name="dispute_type" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">All types</option>
                        @foreach ($disputeTypes as $disputeType)
                            <option value="{{ $disputeType }}" @selected($type === $disputeType)>{{ $label($disputeType) }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">Filter</button>
                </form>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Case</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Suchak</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Evidence</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Lifecycle</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($disputes as $dispute)
                        <tr>
                            <td class="px-4 py-3 align-top">
                                <div class="font-semibold text-gray-900 dark:text-gray-100">#{{ $dispute->id }} {{ $label($dispute->dispute_type) }}</div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $label($dispute->priority) }} priority · Opened {{ $dispute->opened_at?->format('Y-m-d H:i') }}</div>
                                @if ($dispute->risk_source !== \App\Models\SuchakDispute::RISK_SOURCE_ADMIN_CASE)
                                    <div class="mt-2 inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900 dark:bg-amber-950 dark:text-amber-100">{{ $label($dispute->risk_source) }}</div>
                                @endif
                                <p class="mt-2 max-w-xl text-gray-700 dark:text-gray-300">{{ $dispute->summary }}</p>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <a href="{{ route('admin.suchak.accounts.show', $dispute->suchakAccount) }}" class="font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">{{ $dispute->suchakAccount?->suchak_name ?: 'Unknown Suchak' }}</a>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Representation #{{ $dispute->representation_id ?: '-' }}</div>
                                @if ($dispute->customer_context_id || $dispute->payment_context_id)
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Customer #{{ $dispute->customer_context_id ?: '-' }} · Payment context #{{ $dispute->payment_context_id ?: '-' }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top text-gray-700 dark:text-gray-300">
                                {{ $dispute->evidence_summary ?: '-' }}
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $label($dispute->status) }}</div>
                                @if ($dispute->resolved_at)
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Resolved {{ $dispute->resolved_at->format('Y-m-d H:i') }}</div>
                                @endif
                                @php
                                    $hasActiveFreeze = $dispute->paymentFeatureFreezes->contains('freeze_status', \App\Models\SuchakPaymentFeatureFreeze::STATUS_ACTIVE);
                                    $hasActivePayoutHold = $dispute->payoutHolds->contains('hold_status', \App\Models\SuchakPayoutHold::STATUS_ACTIVE);
                                @endphp
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @if ($hasActiveFreeze)
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-900 dark:bg-red-950 dark:text-red-100">Payment frozen</span>
                                    @endif
                                    @if ($hasActivePayoutHold)
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-900 dark:bg-amber-950 dark:text-amber-100">Payout hold</span>
                                    @endif
                                </div>
                                @if (in_array($dispute->status, [\App\Models\SuchakDispute::STATUS_OPEN, \App\Models\SuchakDispute::STATUS_UNDER_REVIEW], true))
                                    <div class="mt-3 space-y-3">
                                        @if (! $hasActiveFreeze)
                                            <form method="POST" action="{{ route('admin.suchak.safety.disputes.payment-freeze', $dispute) }}" class="space-y-2">
                                                @csrf
                                                <textarea name="freeze_reason" rows="2" required minlength="10" maxlength="500" placeholder="Freeze direct payment reason" class="w-full rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                                                <button type="submit" class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">Freeze payment ability</button>
                                            </form>
                                        @endif
                                        @if ($dispute->status === \App\Models\SuchakDispute::STATUS_OPEN)
                                            <form method="POST" action="{{ route('admin.suchak.safety.disputes.review', $dispute) }}" class="space-y-2">
                                                @csrf
                                                <textarea name="review_note" rows="2" required minlength="10" maxlength="500" placeholder="Review note" class="w-full rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                                                <button type="submit" class="rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700">Start review</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('admin.suchak.safety.disputes.close', $dispute) }}" class="space-y-2">
                                            @csrf
                                            <select name="resolution_status" required class="w-full rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                                @foreach ($closingStatuses as $closingStatus)
                                                    <option value="{{ $closingStatus }}">{{ $label($closingStatus) }}</option>
                                                @endforeach
                                            </select>
                                            <textarea name="resolution_note" rows="2" required minlength="10" maxlength="1000" placeholder="Resolution note" class="w-full rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                                            <button type="submit" class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Close case</button>
                                        </form>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No Suchak safety cases found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-700">
            {{ $disputes->links() }}
        </div>
    </section>

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Freeze / Pause Controls</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Suchak</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Status</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Controls</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($accounts as $account)
                        <tr>
                            <td class="px-4 py-3 align-top">
                                <a href="{{ route('admin.suchak.accounts.show', $account) }}" class="font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">{{ $account->suchak_name }}</a>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $account->user?->email }} · {{ $account->disputes_count }} cases · {{ $account->profile_representations_count }} reps</div>
                            </td>
                            <td class="px-4 py-3 align-top text-gray-700 dark:text-gray-300">
                                {{ $label($account->verification_status) }} / {{ $label($account->public_status) }}
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="grid gap-3 xl:grid-cols-2">
                                    @if ($account->verification_status === \App\Models\SuchakAccount::VERIFICATION_VERIFIED)
                                        <form method="POST" action="{{ route('admin.suchak.safety.accounts.freeze', $account) }}" class="space-y-2">
                                            @csrf
                                            <textarea name="reason" rows="2" required minlength="10" maxlength="500" placeholder="Freeze reason" class="w-full rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                                            <button type="submit" class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">Freeze</button>
                                        </form>
                                        @if ($account->public_status === \App\Models\SuchakAccount::PUBLIC_ACTIVE)
                                            <form method="POST" action="{{ route('admin.suchak.safety.accounts.pause', $account) }}" class="space-y-2">
                                                @csrf
                                                <textarea name="reason" rows="2" required minlength="10" maxlength="500" placeholder="Pause reason" class="w-full rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                                                <button type="submit" class="rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700">Pause public</button>
                                            </form>
                                        @elseif ($account->public_status === \App\Models\SuchakAccount::PUBLIC_INACTIVE)
                                            <form method="POST" action="{{ route('admin.suchak.safety.accounts.resume', $account) }}" class="space-y-2">
                                                @csrf
                                                <textarea name="reason" rows="2" required minlength="10" maxlength="500" placeholder="Resume reason" class="w-full rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                                                <button type="submit" class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Resume public</button>
                                            </form>
                                        @endif
                                    @elseif ($account->verification_status === \App\Models\SuchakAccount::VERIFICATION_SUSPENDED)
                                        <form method="POST" action="{{ route('admin.suchak.safety.accounts.unfreeze', $account) }}" class="space-y-2">
                                            @csrf
                                            <textarea name="reason" rows="2" required minlength="10" maxlength="500" placeholder="Unfreeze reason" class="w-full rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                                            <button type="submit" class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Unfreeze</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-gray-500 dark:text-gray-400">No freeze/pause action for this status.</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No Suchak accounts found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Representation Revoke Controls</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Representation</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Suchak</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Revoke</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($representations as $representation)
                        <tr>
                            <td class="px-4 py-3 align-top">
                                <div class="font-semibold text-gray-900 dark:text-gray-100">Representation #{{ $representation->id }}</div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $label($representation->representation_status) }} / {{ $label($representation->consent_status) }}
                                    · Profile #{{ $representation->matrimony_profile_id }}
                                </div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <a href="{{ route('admin.suchak.accounts.show', $representation->suchakAccount) }}" class="font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">{{ $representation->suchakAccount?->suchak_name ?: 'Unknown Suchak' }}</a>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $representation->suchakAccount?->user?->email }}</div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <form method="POST" action="{{ route('admin.suchak.safety.representations.revoke', $representation) }}" class="grid gap-2 md:grid-cols-3">
                                    @csrf
                                    <input type="number" name="dispute_id" min="1" placeholder="Case ID" class="rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                    <textarea name="reason" rows="2" required minlength="10" maxlength="500" placeholder="Revoke reason" class="md:col-span-2 rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                                    <button type="submit" class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">Revoke representation</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No active Suchak representations found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
