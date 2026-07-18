@extends('layouts.admin')

@section('content')
@php
    $verificationLinks = [
        'Pending' => ['count' => $stats['pending'], 'status' => \App\Models\SuchakAccount::VERIFICATION_PENDING],
        'Verified' => ['count' => $stats['verified'], 'status' => \App\Models\SuchakAccount::VERIFICATION_VERIFIED],
        'Rejected' => ['count' => $stats['rejected'], 'status' => \App\Models\SuchakAccount::VERIFICATION_REJECTED],
        'Suspended' => ['count' => $stats['suspended'], 'status' => \App\Models\SuchakAccount::VERIFICATION_SUSPENDED],
        'Archived' => ['count' => $stats['archived'], 'status' => \App\Models\SuchakAccount::VERIFICATION_ARCHIVED],
    ];
@endphp

<div class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak Admin Dashboard</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Operational status, risk signals, evidence activity, and source links for the Suchak system.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.suchak.safety.index') }}" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                    Safety center
                </a>
                <a href="{{ route('admin.suchak.accounts.index') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                    Review accounts
                </a>
                <a href="{{ route('admin.suchak.plans.index') }}" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                    Plan catalog
                </a>
                @if (\App\Support\Suchak\SuchakMvpFeatures::adminLinkVisible('retention'))
                    <a href="{{ route('admin.suchak.retention.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-700">
                        Retention
                    </a>
                @endif
                @if (\App\Support\Suchak\SuchakMvpFeatures::adminLinkVisible('academy'))
                    <a href="{{ route('admin.suchak.academy.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-700">
                        Academy
                    </a>
                @endif
                <a href="{{ route('admin.suchak.settings.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-700">
                    Settings
                </a>
                <a href="{{ route('admin.suchak.apk-settings.index') }}" class="rounded-md border border-emerald-300 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-200 dark:hover:bg-emerald-950">
                    APK Settings
                </a>
            </div>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ($verificationLinks as $label => $item)
            <a href="{{ route('admin.suchak.accounts.index', ['verification_status' => $item['status']]) }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm hover:border-indigo-300 hover:bg-indigo-50 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-indigo-500 dark:hover:bg-gray-900">
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($item['count']) }}</div>
                <div class="mt-1 text-xs text-indigo-600 dark:text-indigo-300">Open source list</div>
            </a>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Registrations & Approvals</h2>
            <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">New 7 days</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['registered_last_7_days']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Pending accounts</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($approvalsSummary['accounts_pending']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Approved 7 days</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($approvalsSummary['accounts_approved_last_7_days']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Records pending</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($approvalsSummary['records_pending']) }}</dd>
                </div>
            </dl>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Consent Health</h2>
            <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Pending action</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($consentHealth['pending_action']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Accepted valid</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($consentHealth['accepted_valid']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Expiring 30 days</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($consentHealth['expiring_soon']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Revoked / expired</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($consentHealth['revoked'] + $consentHealth['expired']) }}</dd>
                </div>
            </dl>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Account Visibility</h2>
            <dl class="mt-4 grid grid-cols-3 gap-4 text-sm">
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Public active</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['public_active']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Hidden</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['public_hidden']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Inactive</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['public_inactive']) }}</dd>
                </div>
            </dl>
        </section>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Payment & Subscription Health</h2>
            <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Active plans</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($subscriptionHealth['active']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Pending review</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($subscriptionHealth['pending_admin_review']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Ending 14 days</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($subscriptionHealth['ending_soon']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Verified no plan</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($subscriptionHealth['verified_without_active_plan']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Customer due</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($customerPaymentHealth['due'] + $customerPaymentHealth['overdue']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Customer paid</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($customerPaymentHealth['paid']) }}</dd>
                </div>
            </dl>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Disputes & Abuse</h2>
            <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Open</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($disputeSummary['open']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Under review</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($disputeSummary['under_review']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">High priority</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($disputeSummary['high_priority_open']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Abuse reports</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($disputeSummary['abuse_reports']) }}</dd>
                </div>
            </dl>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">PDF / QR Activity</h2>
            <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">PDF 7 days</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($pdfQrActivity['pdf_generated_last_7_days']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Shared 7 days</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($pdfQrActivity['pdf_shared_last_7_days']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Active QR</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($pdfQrActivity['qr_active']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Total scans</dt>
                    <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($pdfQrActivity['qr_scans_total']) }}</dd>
                </div>
            </dl>
        </section>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 xl:col-span-1">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent Accounts</h2>
            </div>
            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($recentAccounts as $account)
                    <a href="{{ route('admin.suchak.accounts.show', $account) }}" class="block px-5 py-4 hover:bg-gray-50 dark:hover:bg-gray-900">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="font-medium text-gray-900 dark:text-gray-100">{{ $account->suchak_name }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $account->user?->email }}</div>
                            </div>
                            <div class="text-right text-xs text-gray-500 dark:text-gray-400">
                                <div>{{ ucfirst($account->verification_status) }}</div>
                                <div>{{ $account->created_at?->format('Y-m-d H:i') }}</div>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="px-5 py-6 text-sm text-gray-500 dark:text-gray-400">No Suchak accounts yet.</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 xl:col-span-2">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Evidence Timeline</h2>
            </div>
            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($evidenceTimeline as $item)
                    <div class="px-5 py-4 text-sm">
                        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded bg-gray-100 px-2 py-1 text-xs font-semibold uppercase text-gray-600 dark:bg-gray-900 dark:text-gray-300">{{ $item['type'] }}</span>
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $item['label'] }}</span>
                                </div>
                                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $item['account_name'] }} · {{ $item['source_label'] }} · {{ $item['status'] }}
                                </div>
                            </div>
                            <div class="text-left text-xs text-gray-500 dark:text-gray-400 md:text-right">
                                <div>{{ $item['occurred_at']?->format('Y-m-d H:i') ?: '-' }}</div>
                                @if ($item['account_url'])
                                    <a href="{{ $item['account_url'] }}" class="mt-1 inline-flex font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">
                                        Open source
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-6 text-sm text-gray-500 dark:text-gray-400">No operational evidence recorded yet.</div>
                @endforelse
            </div>
        </section>
    </div>
</div>
@endsection
