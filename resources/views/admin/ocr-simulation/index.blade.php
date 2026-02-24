@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">OCR Mode Simulation (Day-14)</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Test OCR governance logic. NO OCR engine — structure only. No profile data is mutated.</p>
    </div>

    @if (session('success'))
        <div class="mb-4 px-4 py-2 rounded bg-emerald-50 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200 text-sm">{{ session('success') }}</div>
    @endif

    @if (session('simulation_result'))
        @php
            $result = session('simulation_result');
        @endphp
        <div class="mb-6 p-4 rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50/50 dark:bg-blue-900/20">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Simulation Results</h3>
            <div class="space-y-2 text-xs">
                <div><strong>Selected Mode:</strong> {{ $result['mode'] }}</div>
                <div><strong>Profile ID:</strong> {{ $result['profile_id'] ?? 'New Profile' }}</div>
                <div><strong>Conflicts Created:</strong> {{ $result['conflicts_created'] }}</div>
                @if (!empty($result['decisions']))
                    <div class="mt-3">
                        <strong>Field Decisions:</strong>
                        <ul class="list-disc list-inside mt-1 space-y-1">
                            @foreach ($result['decisions'] as $fieldKey => $decision)
                                <li>
                                    <code class="text-xs">{{ $fieldKey }}</code>:
                                    <span class="font-semibold">{{ $decision }}</span>
                                    @if (isset($result['field_modes'][$fieldKey]))
                                        <span class="text-gray-500">(Mode: {{ $result['field_modes'][$fieldKey] }})</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 px-4 py-2 rounded bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 text-sm">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('admin.ocr-simulation.execute') }}" class="space-y-6">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">OCR Mode</label>
            <select name="ocr_mode" required class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                <option value="">Select mode...</option>
                @foreach ($modes as $mode)
                    <option value="{{ $mode }}" {{ old('ocr_mode') === $mode ? 'selected' : '' }}>
                        {{ $mode }}
                    </option>
                @endforeach
            </select>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                MODE_1_FIRST_CREATION: New profile, all fields allowed<br>
                MODE_2_EXISTING_PROFILE: Existing profile, conflicts detected<br>
                MODE_3_POST_HUMAN_EDIT_LOCK: Locked fields skipped
            </p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Profile (optional — leave empty for new profile)</label>
            <select name="profile_id" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                <option value="">New Profile (MODE_1)</option>
                @foreach ($profiles as $profile)
                    <option value="{{ $profile->id }}" {{ old('profile_id') == $profile->id ? 'selected' : '' }}>
                        #{{ $profile->id }} — {{ $profile->full_name ?? '—' }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="grid grid-cols-2 gap-6">
            <div>
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Proposed CORE Fields (dummy data)</h3>
                <div class="space-y-2">
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">caste</label>
                        <input type="text" name="proposed_core[caste]" value="{{ old('proposed_core.caste') }}" placeholder="(optional)" class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1.5 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">highest_education</label>
                        <input type="text" name="proposed_core[highest_education]" value="{{ old('proposed_core.highest_education') }}" placeholder="(optional)" class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1.5 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">location</label>
                        <input type="text" name="proposed_core[location]" value="{{ old('proposed_core.location') }}" placeholder="(optional)" class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1.5 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    </div>
                </div>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Proposed EXTENDED Fields (dummy data)</h3>
                <div class="space-y-2">
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">field_key_1</label>
                        <input type="text" name="proposed_extended[field_key_1]" value="{{ old('proposed_extended.field_key_1') }}" placeholder="(optional)" class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1.5 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">field_key_2</label>
                        <input type="text" name="proposed_extended[field_key_2]" value="{{ old('proposed_extended.field_key_2') }}" placeholder="(optional)" class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1.5 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    </div>
                </div>
            </div>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md font-medium text-sm">Run Governance Simulation</button>
            <a href="{{ route('admin.conflict-records.index') }}" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md font-medium text-sm">View Conflict Records</a>
        </div>
    </form>

    <div class="mt-6 p-4 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50/50 dark:bg-amber-900/20">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Governance Decisions</h3>
        <ul class="text-xs text-gray-600 dark:text-gray-400 space-y-1">
            <li><strong>ALLOW:</strong> Field can be populated (no conflict, no lock)</li>
            <li><strong>SKIP:</strong> Field must be skipped (locked, cannot overwrite)</li>
            <li><strong>CREATE_CONFLICT:</strong> Conflict detected, ConflictRecord created</li>
        </ul>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2"><strong>Note:</strong> This simulation does NOT mutate profile data. It only tests governance logic and creates ConflictRecords where conflicts are detected.</p>
    </div>
</div>
@endsection
