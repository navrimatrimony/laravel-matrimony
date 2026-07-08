@extends('layouts.admin')

@section('content')
@php
    $activeAdminProfileTab = 'bulk';
    $fields = is_array($fields ?? null) ? $fields : [];
    $sourceText = is_string($sourceText ?? null) ? $sourceText : null;
    $sourceTextLabel = is_string($sourceTextLabel ?? null) ? $sourceTextLabel : 'Parse input text';
    $sourceSnapshotSource = is_string($sourceSnapshotSource ?? null) ? $sourceSnapshotSource : 'unknown';
    $imagePreview = is_array($imagePreview ?? null) ? $imagePreview : ['available' => false, 'data_uri' => null, 'label' => null, 'message' => null];
    $canSave = (bool) ($canSave ?? false);
    $duplicateHints = is_array($duplicateHints ?? null) ? $duplicateHints : [];
    $heightOptions = [];
    for ($heightInches = 48; $heightInches <= 84; $heightInches++) {
        $feet = intdiv($heightInches, 12);
        $inches = $heightInches % 12;
        $heightCm = (int) round($heightInches * 2.54);
        $heightOptions[] = [
            'value' => $feet."'".$inches.'"',
            'label' => $feet."'".$inches.'" / '.$heightCm.' cm',
        ];
    }
@endphp

@once
    @vite(['resources/js/profile/location-typeahead.js'])
@endonce

<style>
    @media (min-width: 1024px) {
        .bulk-correction-layout {
            grid-template-columns: minmax(0, 56%) minmax(380px, 44%);
            align-items: start;
        }
    }
</style>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Bulk Candidate Correction</h1>
            <p class="mt-1 text-sm text-gray-600">
                Bulk Intake #{{ $batch->id }}{{ $batch->batch_name ? ' · '.$batch->batch_name : '' }} · Item #{{ $item->item_sequence }}
            </p>
        </div>
        <a href="{{ route('admin.bulk-intakes.show', $batch) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Back to bulk intake</a>
    </div>

    @include('admin.intake._tabs')

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
    @endif
    @error('candidate')
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $message }}</div>
    @enderror

    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
        Saves only the reviewed intake snapshot. No user/profile creation, WhatsApp queue, apply flow, or paid provider extraction runs here.
    </div>

    <div data-testid="bulk-correction-two-column-layout" class="bulk-correction-layout grid gap-6">
        <section data-testid="bulk-correction-left-evidence" class="space-y-6">
            <div class="rounded-lg bg-white p-6 shadow">
                <h2 class="text-lg font-semibold text-gray-900">Original evidence</h2>
                <dl class="mt-4 grid gap-4 text-sm md:grid-cols-2">
                    <div>
                        <dt class="font-semibold text-gray-700">Linked intake</dt>
                        <dd class="mt-1 text-gray-600">
                            <a href="{{ route('admin.biodata-intakes.show', $intake) }}" class="font-medium text-indigo-600 hover:text-indigo-800">#{{ $intake->id }}</a>
                        </dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-gray-700">Parse status</dt>
                        <dd class="mt-1 text-gray-600">{{ $intake->parse_status ?: '-' }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-gray-700">File/Text</dt>
                        <dd class="mt-1 text-gray-600">{{ $item->original_filename ?: ($intake->original_filename ?: ('Text item #'.$item->item_sequence)) }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-gray-700">Source snapshot</dt>
                        <dd class="mt-1 text-gray-600">{{ $sourceSnapshotSource }}</dd>
                    </div>
                    <div class="md:col-span-2">
                        <dt class="font-semibold text-gray-700">Stored file path</dt>
                        <dd class="mt-1 break-words text-gray-600">{{ $item->source_file_path ?: ($intake->file_path ?: '-') }}</dd>
                    </div>
                    @if ($intake->last_error)
                        <div class="md:col-span-2">
                            <dt class="font-semibold text-gray-700">Last error</dt>
                            <dd class="mt-1 break-words text-red-700">{{ $intake->last_error }}</dd>
                        </div>
                    @endif
                </dl>

                <div class="mt-5">
                    <h3 class="text-sm font-semibold text-gray-800">Original image preview</h3>
                    @if (! empty($imagePreview['available']) && ! empty($imagePreview['data_uri']))
                        <div class="mt-2 rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <img src="{{ $imagePreview['data_uri'] }}" alt="Original biodata image preview" class="max-h-[32rem] w-full rounded object-contain">
                            @if (! empty($imagePreview['label']))
                                <p class="mt-2 break-words text-xs text-gray-500">{{ $imagePreview['label'] }}</p>
                            @endif
                        </div>
                    @else
                        <p class="mt-2 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600">
                            {{ $imagePreview['message'] ?? 'No inline image preview available for this item.' }}
                        </p>
                    @endif
                </div>
            </div>

            <details class="rounded-lg bg-white p-6 shadow">
                <summary class="cursor-pointer text-lg font-semibold text-gray-900">{{ $sourceTextLabel }}</summary>
                <span class="mt-1 block text-xs font-semibold uppercase text-gray-500">Read only</span>
                @if ($sourceText)
                    <pre class="mt-4 max-h-[28rem] overflow-auto whitespace-pre-wrap rounded-lg border border-gray-200 bg-gray-50 p-4 text-xs leading-relaxed text-gray-800">{{ $sourceText }}</pre>
                @else
                    <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">No OCR or parse input text is available for this item.</p>
                @endif
            </details>
        </section>

        <aside data-testid="bulk-correction-right-form" class="space-y-6">
            <div class="rounded-lg bg-white p-6 shadow">
                <h2 class="text-lg font-semibold text-gray-900">Correct candidate fields</h2>

                @if (! $canSave)
                    <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                        Correction is blocked after approval or intake lock.
                    </div>
                @endif

                <form id="bulk-candidate-correction-form" method="POST" action="{{ route('admin.bulk-intakes.items.correct-candidate.update', [$batch, $item]) }}" class="mt-5 space-y-4">
                    @csrf
                    @method('PATCH')

                    @foreach ($fields as $field)
                        @php
                            $key = (string) ($field['key'] ?? '');
                            $label = (string) ($field['label'] ?? $key);
                            $value = old($key, (string) ($field['value'] ?? ''));
                            $type = (string) ($field['type'] ?? 'text');
                            $confidence = is_array($field['confidence'] ?? null) ? $field['confidence'] : [];
                            $isLowConfidence = ! empty($confidence['is_low']);
                            $confidenceLabel = (string) ($confidence['label'] ?? '');
                            $warnings = is_array($field['warnings'] ?? null)
                                ? array_values(array_filter(array_map('strval', $field['warnings'])))
                                : [];
                            $inputClass = $isLowConfidence
                                ? 'w-full rounded-lg border-amber-300 bg-amber-50 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500 disabled:bg-gray-100'
                                : 'w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100';
                        @endphp

                        <div class="block text-sm" @if ($isLowConfidence) data-testid="bulk-correction-low-confidence-{{ $key }}" @endif>
                            <span class="mb-1 flex flex-wrap items-center gap-2 font-semibold text-gray-800">
                                <span>{{ $label }}</span>
                                @if ($isLowConfidence)
                                    <span class="rounded-full border border-amber-300 bg-white px-2 py-0.5 text-[11px] font-bold uppercase text-amber-900">
                                        Low confidence{{ $confidenceLabel !== '' ? ' '.$confidenceLabel : '' }}
                                    </span>
                                @endif
                            </span>

                            @if ($key === 'date_of_birth')
                                <input
                                    type="date"
                                    name="{{ $key }}"
                                    value="{{ preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '' }}"
                                    @disabled(! $canSave)
                                    class="{{ $inputClass }}"
                                    data-testid="bulk-correction-date-input"
                                >
                            @elseif ($key === 'height')
                                <input
                                    type="text"
                                    name="height"
                                    list="bulk-height-options"
                                    value="{{ $value }}"
                                    placeholder="165 cm or 5'5&quot;"
                                    @disabled(! $canSave)
                                    class="{{ $inputClass }}"
                                    data-testid="bulk-correction-height-input"
                                >
                            @elseif ($key === 'education')
                                @php $educationProfile = (object) ['highest_education' => $value]; @endphp
                                <input type="hidden" name="education" value="{{ $value }}">
                                <x-education-multiselect-engine
                                    :profile="$educationProfile"
                                    form-selector="#bulk-candidate-correction-form"
                                    :suffix="'bulk-correction-education-'.$item->id"
                                />
                            @elseif ($key === 'location')
                                <input type="hidden" name="location" value="{{ $value }}">
                                <x-profile.location-typeahead
                                    context="residence"
                                    :value="$value"
                                    placeholder="Type city or village"
                                    label=""
                                    :gps-assist="false"
                                    :no-border="true"
                                    :compact-row="true"
                                    display-sync-name="location"
                                    id="bulk-correction-location-{{ $item->id }}"
                                />
                            @elseif ($type === 'select' && $key === 'gender')
                                <select name="{{ $key }}" @disabled(! $canSave) class="{{ $inputClass }}">
                                    <option value="" @selected($value === '')>Select gender</option>
                                    <option value="male" @selected(strtolower($value) === 'male')>Male</option>
                                    <option value="female" @selected(strtolower($value) === 'female')>Female</option>
                                    <option value="unknown" @selected(strtolower($value) === 'unknown')>Unknown</option>
                                </select>
                            @else
                                <input
                                    type="{{ $type === 'tel' ? 'tel' : 'text' }}"
                                    name="{{ $key }}"
                                    value="{{ $value }}"
                                    @disabled(! $canSave)
                                    class="{{ $inputClass }}"
                                >
                            @endif

                            @error($key)
                                <span class="mt-1 block text-xs font-medium text-red-700">{{ $message }}</span>
                            @enderror

                            @if ($warnings !== [])
                                <span data-testid="bulk-correction-warning-{{ $key }}" class="mt-1 block space-y-1">
                                    @foreach ($warnings as $warning)
                                        <span class="block rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800">{{ $warning }}</span>
                                    @endforeach
                                </span>
                            @endif

                            @if ($key === 'mobile')
                                <span class="mt-1 block text-xs text-gray-500">Use a valid 10 digit Indian mobile number.</span>
                            @elseif ($key === 'date_of_birth')
                                <span class="mt-1 block text-xs text-gray-500">Use YYYY-MM-DD or DD/MM/YYYY. Age below 18 or above 75 should be reviewed.</span>
                            @elseif ($key === 'height')
                                <span class="mt-1 block text-xs text-gray-500">Use cm or feet/inches, for example 165 cm or 5'5".</span>
                            @elseif ($key === 'location')
                                <span class="mt-1 block text-xs text-gray-500">Single location text only. Do not paste a full address paragraph here.</span>
                            @endif
                        </div>
                    @endforeach

                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        <button type="submit" @disabled(! $canSave) class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                            Save correction
                        </button>
                        <a href="{{ route('admin.bulk-intakes.show', $batch) }}" class="text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</a>
                    </div>
                </form>
            </div>

            <div class="rounded-lg bg-white p-6 shadow" data-testid="bulk-correction-duplicate-history-card">
                <h2 class="text-lg font-semibold text-gray-900">Duplicate / history hints</h2>
                <p class="mt-1 text-sm text-gray-600">Read-only signals from existing intakes, hashes, and profile contact indexes.</p>

                @if ($duplicateHints === [])
                    <p class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600">No duplicate or prior-history hints found.</p>
                @else
                    <div class="mt-4 space-y-3">
                        @foreach ($duplicateHints as $hint)
                            <div class="rounded-lg border border-purple-200 bg-purple-50 p-3 text-sm text-purple-900">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span data-testid="bulk-correction-duplicate-history-hint" class="rounded-full border border-purple-300 bg-white px-2 py-0.5 text-xs font-semibold text-purple-700">{{ $hint['label'] ?? 'Possible duplicate' }}</span>
                                    <span class="text-xs font-medium text-purple-700">Confidence: {{ $hint['confidence'] ?? 'unknown' }}</span>
                                </div>
                                <dl class="mt-2 grid gap-1 text-xs text-purple-800 sm:grid-cols-2">
                                    <div>
                                        <dt class="font-semibold">Reason</dt>
                                        <dd>{{ $hint['reason'] ?? '-' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold">Match type</dt>
                                        <dd>{{ $hint['type'] ?? '-' }}</dd>
                                    </div>
                                    @if (! empty($hint['matched_intake_id']))
                                        <div>
                                            <dt class="font-semibold">Matched intake</dt>
                                            <dd>#{{ $hint['matched_intake_id'] }}</dd>
                                        </div>
                                    @endif
                                    @if (! empty($hint['matched_profile_id']))
                                        <div>
                                            <dt class="font-semibold">Matched profile</dt>
                                            <dd>#{{ $hint['matched_profile_id'] }}</dd>
                                        </div>
                                    @endif
                                    @if (! empty($hint['last_seen_at']))
                                        <div>
                                            <dt class="font-semibold">Last seen</dt>
                                            <dd>{{ $hint['last_seen_at'] }}</dd>
                                        </div>
                                    @endif
                                </dl>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="rounded-lg bg-white p-6 shadow">
                <h2 class="text-lg font-semibold text-gray-900">Review flag</h2>
                <p class="mt-1 text-sm text-gray-600">Use this when the item needs manual follow-up before consent or profile work.</p>

                @if ($item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_NEEDS_REVIEW)
                    <form method="POST" action="{{ route('admin.bulk-intakes.items.clear-needs-review', [$batch, $item]) }}" class="mt-4">
                        @csrf
                        <button type="submit" class="rounded-lg border border-green-300 px-4 py-2 text-sm font-semibold text-green-700 hover:bg-green-50">
                            Clear needs review
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.bulk-intakes.items.mark-needs-review', [$batch, $item]) }}" class="mt-4">
                        @csrf
                        <input type="hidden" name="reason" value="Candidate correction needs manual review">
                        <button type="submit" class="rounded-lg border border-amber-300 px-4 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-50">
                            Mark needs review
                        </button>
                    </form>
                @endif
            </div>
        </aside>
    </div>
</div>

<datalist id="bulk-height-options">
    @foreach ($heightOptions as $heightOption)
        <option value="{{ $heightOption['value'] }}">{{ $heightOption['label'] }}</option>
    @endforeach
</datalist>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.LocationTypeahead && window.LocationTypeahead.init) {
            window.LocationTypeahead.init();
        }
    });
</script>
@endsection
