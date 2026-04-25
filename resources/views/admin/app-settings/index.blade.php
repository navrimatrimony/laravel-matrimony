@extends('layouts.admin')

@section('content')
<style>
.admin-toggle { position: relative; display: inline-flex; align-items: center; cursor: pointer; }
.admin-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
.admin-toggle .toggle-track { width: 52px; height: 28px; background-color: #d1d5db; border-radius: 9999px; transition: background-color 0.2s ease; position: relative; }
.admin-toggle input:checked + .toggle-track { background-color: #10b981; }
.admin-toggle .toggle-thumb { position: absolute; top: 2px; left: 2px; width: 24px; height: 24px; background-color: white; border-radius: 9999px; transition: transform 0.2s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
.admin-toggle input:checked + .toggle-track .toggle-thumb { transform: translateX(24px); }
.admin-toggle .toggle-label { margin-left: 12px; font-weight: 600; font-size: 14px; }
.admin-toggle .toggle-label.on { color: #059669; }
.admin-toggle .toggle-label.off { color: #6b7280; }
</style>

<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6" x-data="{ tab: 'general' }">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">App settings</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">Application-wide switches stored in the database. When no DB value exists, the value falls back to environment configuration.</p>

    @if (session('success'))
        <div class="mb-4 rounded-lg bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100 px-4 py-3 text-sm border border-emerald-200 dark:border-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif

    @if (! ($canManageBillingSettings ?? false))
        <div class="mb-4 rounded-lg bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-100 px-4 py-3 text-sm border border-amber-200 dark:border-amber-800">
            Only super_admin can change billing/invoice settings.
        </div>
    @endif

    <form method="POST" action="{{ route('admin.app-settings.update') }}" class="space-y-6">
        @csrf

        <div class="border-b border-gray-200 dark:border-gray-700">
            <nav class="-mb-px flex flex-wrap gap-4" aria-label="App settings sections">
                <button type="button" @click="tab='general'" :class="tab==='general' ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-300' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'" class="border-b-2 px-1 py-3 text-sm font-semibold transition">General</button>
                <button type="button" @click="tab='dashboard'" :class="tab==='dashboard' ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-300' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'" class="border-b-2 px-1 py-3 text-sm font-semibold transition">Dashboard UX</button>
                <button type="button" @click="tab='monitoring'" :class="tab==='monitoring' ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-300' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'" class="border-b-2 px-1 py-3 text-sm font-semibold transition">Payment Monitoring</button>
                <button type="button" @click="tab='billing'" :class="tab==='billing' ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-300' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'" class="border-b-2 px-1 py-3 text-sm font-semibold transition">Billing & Invoice</button>
            </nav>
        </div>

        <div x-show="tab === 'general'" x-cloak class="space-y-6">
            <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600">
                <label class="admin-toggle">
                    <input type="checkbox" name="admin_bypass_mode" value="1" {{ $adminBypassMode ? 'checked' : '' }}>
                    <span class="toggle-track"><span class="toggle-thumb"></span></span>
                    <span class="toggle-label {{ $adminBypassMode ? 'on' : 'off' }}">Admin Bypass Mode</span>
                </label>
                <p class="text-sm text-gray-600 dark:text-gray-300 mt-3 font-medium">When enabled, admin users bypass all limits</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Applies to users with the <code class="text-xs bg-gray-200 dark:bg-gray-600 px-1 rounded">is_admin</code> flag. If this setting has never been saved, <code class="text-xs">ADMIN_BYPASS_MODE</code> in <code class="text-xs">.env</code> is used.</p>
            </div>

            <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600">
                <label class="admin-toggle">
                    <input type="checkbox" name="plans_enforce_gender_specific_visibility" value="1" {{ $plansEnforceGenderSpecificVisibility ? 'checked' : '' }}>
                    <span class="toggle-track"><span class="toggle-thumb"></span></span>
                    <span class="toggle-label {{ $plansEnforceGenderSpecificVisibility ? 'on' : 'off' }}">Plans: gender-specific visibility</span>
                </label>
                <p class="text-sm text-gray-600 dark:text-gray-300 mt-3 font-medium">ON: male userला male plans आणि female userला female plansच दिसतील</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Default ON. OFF केल्यावर gender matching drift असताना blank catalog टाळण्यासाठी all paid plans fallback दिसू शकतो.</p>
            </div>

            <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600">
                <label class="admin-toggle">
                    <input type="checkbox" name="mobile_clean_mode" value="1" {{ ($mobileCleanMode ?? true) ? 'checked' : '' }}>
                    <span class="toggle-track"><span class="toggle-thumb"></span></span>
                    <span class="toggle-label {{ ($mobileCleanMode ?? true) ? 'on' : 'off' }}">Mobile clean mode</span>
                </label>
                <p class="text-sm text-gray-600 dark:text-gray-300 mt-3 font-medium">ON: mobile वर floating help/chat/viewer overlays hide ठेवून content-first UI दाखवा</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Recommended ON for cleaner small-screen experience.</p>
            </div>

            <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600">
                <label class="block text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">Interest — minimum core completeness (%)</label>
                <input type="number" name="interest_min_core_completeness_pct" min="0" max="100" required
                    value="{{ old('interest_min_core_completeness_pct', $interestMinCorePct) }}"
                    class="block w-full max-w-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100">
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                    Mandatory-field completeness score required to <strong>send interest</strong>, <strong>receive interest</strong> (when non-zero), and <strong>accept interest</strong>.
                    Use <strong>0</strong> to disable this check (default — no blocking).
                    Stored as <code class="text-xs bg-gray-200 dark:bg-gray-600 px-1 rounded">interest_min_core_completeness_pct</code>.
                </p>
            </div>

            <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600">
                <label class="block text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">Member presence — treat as “online” for (minutes)</label>
                <input type="number" name="member_presence_online_threshold_minutes" min="1" max="1440" required
                    value="{{ old('member_presence_online_threshold_minutes', $presenceOnlineThresholdMin) }}"
                    class="block w-full max-w-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100">
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                    If the member’s last activity was within this window, profile / listing shows <strong>online / active now</strong> (based on <code class="text-xs bg-gray-200 dark:bg-gray-600 px-1 rounded">users.last_seen_at</code>).
                    Default <strong>5</strong> minutes. Same-day behaviour and “last active” labels follow after that window. Stored as
                    <code class="text-xs bg-gray-200 dark:bg-gray-600 px-1 rounded">member_presence_online_threshold_minutes</code>.
                </p>
            </div>
        </div>

        <div x-show="tab === 'dashboard'" x-cloak class="space-y-6">
            <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Dashboard notification summary</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs mb-1">Top notification boxes count</label>
                        <input type="number" min="1" max="3" name="dashboard_notification_cards_limit" value="{{ old('dashboard_notification_cards_limit', $dashboardNotificationCardsLimit ?? 2) }}" class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100">
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Dashboard top row मध्ये welcome च्या बाजूला किती notification cards दाखवायचे.</p>
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Activity strip auto-hide (seconds)</label>
                        <input type="number" min="3" max="30" name="dashboard_activity_autohide_seconds" value="{{ old('dashboard_activity_autohide_seconds', $dashboardActivityAutoHideSeconds ?? 7) }}" class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100">
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">“What is happening” strip किती सेकंदांनी hide व्हायचा.</p>
                    </div>
                </div>
            </div>
        </div>

        <div x-show="tab === 'monitoring'" x-cloak class="space-y-6">
            <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Payment monitoring thresholds</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs mb-1">Success rate threshold (%)</label>
                        <input type="number" step="0.01" min="1" max="100" name="success_rate_threshold" value="{{ old('success_rate_threshold', $successRateThreshold) }}" class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Webhook failure threshold (5m)</label>
                        <input type="number" min="1" max="10000" name="webhook_failure_threshold" value="{{ old('webhook_failure_threshold', $webhookFailureThreshold) }}" class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Queue lag threshold (seconds)</label>
                        <input type="number" min="1" max="10000" name="queue_lag_threshold" value="{{ old('queue_lag_threshold', $queueLagThreshold) }}" class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Invoice failure threshold (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="invoice_failure_threshold" value="{{ old('invoice_failure_threshold', $invoiceFailureThreshold) }}" class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100">
                    </div>
                </div>
            </div>
        </div>

        <div x-show="tab === 'billing'" x-cloak class="space-y-6">
            <fieldset @disabled(!($canManageBillingSettings ?? false)) class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600 space-y-3 disabled:opacity-60">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Billing / Invoice settings</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs mb-1">Legal name</label>
                        <input type="text" name="billing_legal_name" required value="{{ old('billing_legal_name', $billingLegalName) }}" class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Billing email</label>
                        <input type="email" name="billing_email" required value="{{ old('billing_email', $billingEmail) }}" class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Billing phone</label>
                        <input type="text" name="billing_phone" required value="{{ old('billing_phone', $billingPhone) }}" class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Invoice prefix</label>
                        <input type="text" name="billing_invoice_prefix" value="{{ old('billing_invoice_prefix', $billingInvoicePrefix) }}" class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-xs mb-1">GSTIN</label>
                        <input type="text" name="billing_gstin" value="{{ old('billing_gstin', $billingGstin) }}" class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-xs mb-1">PAN</label>
                        <input type="text" name="billing_pan" value="{{ old('billing_pan', $billingPan) }}" class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-xs mb-1">State code</label>
                        <input type="text" name="billing_state_code" value="{{ old('billing_state_code', $billingStateCode) }}" class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs mb-1">Billing address</label>
                        <textarea name="billing_address" rows="3" required class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100">{{ old('billing_address', $billingAddress) }}</textarea>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs mb-1">Invoice terms / notes</label>
                        <textarea name="billing_invoice_terms" rows="3" class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100">{{ old('billing_invoice_terms', $billingInvoiceTerms) }}</textarea>
                    </div>
                </div>
            </fieldset>
        </div>

        <div class="pt-2">
            <button type="submit" class="inline-flex items-center px-4 py-2 rounded-md bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-500">
                Save settings
            </button>
        </div>
    </form>
</div>
@endsection
