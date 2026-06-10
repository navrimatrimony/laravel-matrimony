@extends('layouts.admin')

@section('content')
@php
    $fieldClass = 'w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100';
    $labelClass = 'block text-sm font-semibold text-gray-700 dark:text-gray-200';
    $helpClass = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $checked = fn (string $key): bool => old($key, ($current[$key] ?? false) ? '1' : '0') === '1';
@endphp

<div class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak Settings Center</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Controlled policy settings for Suchak operations. Every saved change is written to admin audit.</p>
            </div>
            <a href="{{ route('admin.suchak.dashboard') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">
                Back to Suchak dashboard
            </a>
        </div>
    </div>

    @if ($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-700 dark:bg-red-950/40 dark:text-red-100">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.suchak.settings.update') }}" class="space-y-6">
        @csrf

        <section class="rounded-lg border border-amber-200 bg-amber-50 p-5 dark:border-amber-800 dark:bg-amber-950/30">
            <label for="reason" class="{{ $labelClass }}">Reason for change</label>
            <textarea id="reason" name="reason" rows="3" required minlength="10" maxlength="500" class="{{ $fieldClass }} mt-2" placeholder="Example: Adjust Suchak limits for launch pilot.">{{ old('reason') }}</textarea>
            <p class="{{ $helpClass }}">Required for audit. Existing accounts, subscriptions, representations, and candidate profiles are not mutated by saving these settings.</p>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Consent and SLA</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <label for="default_consent_validity_months" class="{{ $labelClass }}">Default consent validity (months)</label>
                    <input id="default_consent_validity_months" type="number" name="default_consent_validity_months" min="1" max="60" value="{{ old('default_consent_validity_months', $current['default_consent_validity_months'] ?? 12) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div>
                    <label for="request_action_sla_hours" class="{{ $labelClass }}">Request action SLA (hours)</label>
                    <input id="request_action_sla_hours" type="number" name="request_action_sla_hours" min="1" max="720" value="{{ old('request_action_sla_hours', $current['request_action_sla_hours'] ?? 48) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div>
                    <label for="collaboration_sla_days" class="{{ $labelClass }}">Collaboration SLA (days)</label>
                    <input id="collaboration_sla_days" type="number" name="collaboration_sla_days" min="1" max="365" value="{{ old('collaboration_sla_days', $current['collaboration_sla_days'] ?? 7) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div class="space-y-3 rounded-md border border-gray-200 p-4 dark:border-gray-700">
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                        <input type="hidden" name="allow_two_year_consent" value="0">
                        <input type="checkbox" name="allow_two_year_consent" value="1" class="rounded border-gray-300 text-indigo-600" @checked($checked('allow_two_year_consent'))>
                        Allow two-year consent
                    </label>
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                        <input type="hidden" name="allow_until_revoked_consent" value="0">
                        <input type="checkbox" name="allow_until_revoked_consent" value="1" class="rounded border-gray-300 text-indigo-600" @checked($checked('allow_until_revoked_consent'))>
                        Allow until-revoked consent
                    </label>
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Operational Limits</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label for="pdf_download_limit_per_day" class="{{ $labelClass }}">PDF/QR daily limit</label>
                    <input id="pdf_download_limit_per_day" type="number" name="pdf_download_limit_per_day" min="1" max="10000" value="{{ old('pdf_download_limit_per_day', $current['pdf_download_limit_per_day'] ?? 20) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div>
                    <label for="qr_token_expiry_days" class="{{ $labelClass }}">QR expiry (days)</label>
                    <input id="qr_token_expiry_days" type="number" name="qr_token_expiry_days" min="1" max="365" value="{{ old('qr_token_expiry_days', $current['qr_token_expiry_days'] ?? 30) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div>
                    <label for="suchak_upload_daily_limit" class="{{ $labelClass }}">Upload daily limit</label>
                    <input id="suchak_upload_daily_limit" type="number" name="suchak_upload_daily_limit" min="1" max="10000" value="{{ old('suchak_upload_daily_limit', $current['suchak_upload_daily_limit'] ?? 25) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div>
                    <label for="suchak_active_profile_limit_by_plan" class="{{ $labelClass }}">Fallback active profile limit</label>
                    <input id="suchak_active_profile_limit_by_plan" type="number" name="suchak_active_profile_limit_by_plan" min="0" max="100000" value="{{ old('suchak_active_profile_limit_by_plan', $current['suchak_active_profile_limit_by_plan'] ?? 0) }}" class="{{ $fieldClass }} mt-1">
                    <p class="{{ $helpClass }}">0 keeps plan feature limits authoritative when available.</p>
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Pricing and Payment</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <label for="suchak_plan_pricing_mode" class="{{ $labelClass }}">Pricing mode</label>
                    <select id="suchak_plan_pricing_mode" name="suchak_plan_pricing_mode" class="{{ $fieldClass }} mt-1">
                        @foreach ($pricingModes as $value => $label)
                            <option value="{{ $value }}" @selected(old('suchak_plan_pricing_mode', $current['suchak_plan_pricing_mode'] ?? 'manual_catalog') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="suchak_payment_mode" class="{{ $labelClass }}">Platform payment mode</label>
                    <select id="suchak_payment_mode" name="suchak_payment_mode" class="{{ $fieldClass }} mt-1">
                        @foreach ($paymentModes as $value => $label)
                            <option value="{{ $value }}" @selected(old('suchak_payment_mode', $current['suchak_payment_mode'] ?? 'manual_only') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="suchak_free_trial_days" class="{{ $labelClass }}">Free trial days</label>
                    <input id="suchak_free_trial_days" type="number" name="suchak_free_trial_days" min="0" max="365" value="{{ old('suchak_free_trial_days', $current['suchak_free_trial_days'] ?? 0) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div>
                    <label for="suchak_grace_period_days" class="{{ $labelClass }}">Grace period days</label>
                    <input id="suchak_grace_period_days" type="number" name="suchak_grace_period_days" min="0" max="365" value="{{ old('suchak_grace_period_days', $current['suchak_grace_period_days'] ?? 0) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div class="md:col-span-2">
                    <label for="suchak_visit_confirmation_policy_mode" class="{{ $labelClass }}">Visit payout confirmation policy</label>
                    <select id="suchak_visit_confirmation_policy_mode" name="suchak_visit_confirmation_policy_mode" class="{{ $fieldClass }} mt-1">
                        @foreach ($visitConfirmationModes as $value => $label)
                            <option value="{{ $value }}" @selected(old('suchak_visit_confirmation_policy_mode', $current['suchak_visit_confirmation_policy_mode'] ?? 'user_and_admin') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="{{ $helpClass }}">Controls which confirmations are required before a platform visit payout can be qualified.</p>
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Commission Rules</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <label for="commission_mode" class="{{ $labelClass }}">Commission mode</label>
                    <select id="commission_mode" name="commission_mode" class="{{ $fieldClass }} mt-1">
                        @foreach ($commissionModes as $value => $label)
                            <option value="{{ $value }}" @selected(old('commission_mode', $current['commission_mode'] ?? 'to_be_discussed') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="commission_default_percent" class="{{ $labelClass }}">Default percent</label>
                    <input id="commission_default_percent" type="number" name="commission_default_percent" min="0" max="100" value="{{ old('commission_default_percent', $current['commission_default_percent'] ?? 0) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div>
                    <label for="commission_default_amount" class="{{ $labelClass }}">Default fixed amount</label>
                    <input id="commission_default_amount" type="number" name="commission_default_amount" min="0" max="10000000" step="0.01" value="{{ old('commission_default_amount', $current['commission_default_amount'] ?? 0) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div class="flex items-center rounded-md border border-gray-200 p-4 dark:border-gray-700">
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                        <input type="hidden" name="commission_require_ack" value="0">
                        <input type="checkbox" name="commission_require_ack" value="1" class="rounded border-gray-300 text-indigo-600" @checked($checked('commission_require_ack'))>
                        Require commission acknowledgement
                    </label>
                </div>
            </div>
        </section>

        <div class="flex justify-end">
            <button type="submit" class="rounded-md bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
                Save settings
            </button>
        </div>
    </form>
</div>
@endsection
