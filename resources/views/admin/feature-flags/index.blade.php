@extends('layouts.admin')

@section('content')
@php
    $canModify = (bool) ($canModify ?? false);
@endphp

<style>
.admin-toggle { position: relative; display: inline-flex; align-items: center; cursor: pointer; }
.admin-toggle.is-disabled { cursor: not-allowed; opacity: 0.55; }
.admin-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
.admin-toggle .toggle-track { width: 52px; height: 28px; background-color: #d1d5db; border-radius: 9999px; transition: background-color 0.2s ease; position: relative; }
.admin-toggle input:checked + .toggle-track { background-color: #10b981; }
.admin-toggle .toggle-thumb { position: absolute; top: 2px; left: 2px; width: 24px; height: 24px; background-color: white; border-radius: 9999px; transition: transform 0.2s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
.admin-toggle input:checked + .toggle-track .toggle-thumb { transform: translateX(24px); }
</style>

<div class="space-y-6">
    <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-amber-950 shadow-sm">
        <p class="text-sm font-semibold">Changes made here take effect across the entire application immediately. Use with caution.</p>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-white shadow rounded-lg p-6">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Feature Flags</h1>
            <p class="mt-1 text-sm text-gray-600">
                Global module switches. Disabling a flag gates access only — code and database records are never deleted.
                @if (! $canModify)
                    <span class="font-medium text-gray-800">View only — Super Admin required to change flags.</span>
                @endif
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Feature</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Description</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Status</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Last Modified</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Modified By</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Reason</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Toggle</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($flags as $flag)
                        @php
                            $latest = $flag->latestAudit;
                            $enabled = (bool) $flag->enabled;
                        @endphp
                        <tr class="align-top">
                            <td class="px-4 py-4">
                                <div class="font-semibold text-gray-900">{{ $flag->display_name }}</div>
                                <div class="mt-0.5 font-mono text-xs text-gray-500">{{ $flag->key }}</div>
                            </td>
                            <td class="px-4 py-4 text-gray-600 max-w-md">{{ $flag->description }}</td>
                            <td class="px-4 py-4">
                                @if ($enabled)
                                    <span class="inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">ON</span>
                                @else
                                    <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700">OFF</span>
                                @endif
                            </td>
                            <td class="px-4 py-4 text-gray-600 whitespace-nowrap">
                                {{ $latest?->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') ?? '—' }}
                            </td>
                            <td class="px-4 py-4 text-gray-600">
                                {{ $latest?->changedByUser?->name ?? '—' }}
                            </td>
                            <td class="px-4 py-4 text-gray-600 max-w-xs">
                                {{ $latest?->reason ?: '—' }}
                            </td>
                            <td class="px-4 py-4">
                                <label class="admin-toggle {{ $canModify ? '' : 'is-disabled' }}">
                                    <input
                                        type="checkbox"
                                        @checked($enabled)
                                        @disabled(! $canModify)
                                        @if ($canModify)
                                            data-feature-flag-toggle
                                            data-flag-id="{{ $flag->id }}"
                                            data-flag-name="{{ $flag->display_name }}"
                                            data-flag-enabled="{{ $enabled ? '1' : '0' }}"
                                            data-update-url="{{ route('admin.feature-flags.update', $flag) }}"
                                        @endif
                                    >
                                    <span class="toggle-track"><span class="toggle-thumb"></span></span>
                                </label>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                No feature flags seeded yet. Run <code class="text-xs">php artisan db:seed --class=FeatureFlagSeeder</code>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white shadow rounded-lg p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Recent audit history</h2>
        <p class="text-sm text-gray-500 mb-4">Most recent 20 changes.</p>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Date</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Feature</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Change</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Changed By</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Reason</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($audits as $audit)
                        <tr>
                            <td class="px-4 py-3 text-gray-700 whitespace-nowrap">
                                {{ $audit->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}
                            </td>
                            <td class="px-4 py-3 text-gray-800">
                                {{ $audit->featureFlag?->display_name ?? $audit->key }}
                            </td>
                            <td class="px-4 py-3 font-medium text-gray-800">
                                {{ $audit->old_value ? 'ON' : 'OFF' }} → {{ $audit->new_value ? 'ON' : 'OFF' }}
                            </td>
                            <td class="px-4 py-3 text-gray-700">
                                {{ $audit->changedByUser?->name ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-gray-600">
                                {{ $audit->reason ?: '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-gray-500">No changes recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Confirmation dialog (Enable + Disable) --}}
<div id="featureFlagConfirmModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="featureFlagConfirmTitle">
    <div class="w-full max-w-md rounded-xl bg-white shadow-xl">
        <div class="border-b border-gray-100 px-5 py-4">
            <h3 id="featureFlagConfirmTitle" class="text-lg font-semibold text-gray-900">Confirm</h3>
        </div>
        <div class="space-y-4 px-5 py-4">
            <p id="featureFlagConfirmBody" class="text-sm text-gray-700"></p>
            <ul class="list-disc space-y-1 pl-5 text-sm text-gray-600">
                <li>No code or database records will be deleted.</li>
                <li>The feature can be toggled again at any time.</li>
                <li>Changes take effect immediately across the application.</li>
            </ul>
            <div>
                <label for="featureFlagReason" class="block text-sm font-medium text-gray-700">Reason (optional)</label>
                <input id="featureFlagReason" type="text" maxlength="500" class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm" placeholder="Play Store Review, Temporary Maintenance, Bug Investigation, Testing…">
                <p class="mt-1 text-xs text-gray-500">Examples: Play Store Review · Temporary Maintenance · Bug Investigation · Testing</p>
            </div>
        </div>
        <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-4">
            <button type="button" id="featureFlagConfirmCancel" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
            <button type="button" id="featureFlagConfirmSubmit" class="rounded-md px-4 py-2 text-sm font-semibold text-white">Confirm</button>
        </div>
    </div>
</div>

<form id="featureFlagUpdateForm" method="POST" class="hidden">
    @csrf
    <input type="hidden" name="enabled" id="featureFlagEnabledInput" value="">
    <input type="hidden" name="reason" id="featureFlagReasonInput" value="">
</form>

<script>
(function () {
    const modal = document.getElementById('featureFlagConfirmModal');
    const titleEl = document.getElementById('featureFlagConfirmTitle');
    const bodyEl = document.getElementById('featureFlagConfirmBody');
    const reasonEl = document.getElementById('featureFlagReason');
    const cancelBtn = document.getElementById('featureFlagConfirmCancel');
    const submitBtn = document.getElementById('featureFlagConfirmSubmit');
    const form = document.getElementById('featureFlagUpdateForm');
    const enabledInput = document.getElementById('featureFlagEnabledInput');
    const reasonInput = document.getElementById('featureFlagReasonInput');

    let pendingCheckbox = null;
    let pendingUrl = null;
    let pendingNextEnabled = false;

    function openModal(checkbox) {
        pendingCheckbox = checkbox;
        pendingUrl = checkbox.getAttribute('data-update-url');
        const name = checkbox.getAttribute('data-flag-name') || 'this feature';
        const currentlyEnabled = checkbox.getAttribute('data-flag-enabled') === '1';
        pendingNextEnabled = !currentlyEnabled;

        // Revert visual toggle until confirmed
        checkbox.checked = currentlyEnabled;

        if (pendingNextEnabled) {
            titleEl.textContent = 'Enable ' + name + '?';
            bodyEl.textContent = 'This will immediately enable ' + name + ' throughout the application.';
            submitBtn.textContent = 'Enable';
            submitBtn.className = 'rounded-md px-4 py-2 text-sm font-semibold text-white bg-emerald-600 hover:bg-emerald-700';
        } else {
            titleEl.textContent = 'Disable ' + name + '?';
            bodyEl.textContent = 'This will immediately disable ' + name + ' throughout the application.';
            submitBtn.textContent = 'Disable';
            submitBtn.className = 'rounded-md px-4 py-2 text-sm font-semibold text-white bg-red-600 hover:bg-red-700';
        }

        reasonEl.value = '';
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        reasonEl.focus();
    }

    function closeModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        pendingCheckbox = null;
        pendingUrl = null;
    }

    document.querySelectorAll('[data-feature-flag-toggle]').forEach(function (checkbox) {
        checkbox.addEventListener('click', function (event) {
            event.preventDefault();
            openModal(checkbox);
        });
    });

    cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    submitBtn.addEventListener('click', function () {
        if (!pendingUrl) {
            return;
        }
        enabledInput.value = pendingNextEnabled ? '1' : '0';
        reasonInput.value = reasonEl.value || '';
        form.action = pendingUrl;
        form.submit();
    });
})();
</script>
@endsection
