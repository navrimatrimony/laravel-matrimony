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

<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
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

    <form method="POST" action="{{ route('admin.app-settings.update') }}" class="space-y-6">
        @csrf

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

        <div class="pt-2">
            <button type="submit" class="inline-flex items-center px-4 py-2 rounded-md bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-500">
                Save settings
            </button>
        </div>
    </form>
</div>
@endsection
