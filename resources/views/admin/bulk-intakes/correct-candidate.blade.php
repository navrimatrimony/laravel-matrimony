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
    $screeningAdvisor = is_array($screeningAdvisor ?? null) ? $screeningAdvisor : [
        'decision' => 'review',
        'label' => 'Needs review',
        'reasons' => [
            ['code' => 'parsed_json_missing', 'label' => 'Parsed JSON missing'],
        ],
        'suggested_next_action' => 'Review: Parser output is not ready.',
    ];
    $screeningDecision = (string) ($screeningAdvisor['decision'] ?? 'review');
    $screeningBadgeClass = match ($screeningDecision) {
        'eligible' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'stop' => 'border-red-200 bg-red-50 text-red-700',
        default => 'border-amber-200 bg-amber-50 text-amber-800',
    };
    $screeningCardClass = match ($screeningDecision) {
        'eligible' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
        'stop' => 'border-red-200 bg-red-50 text-red-900',
        default => 'border-amber-200 bg-amber-50 text-amber-900',
    };
    $screeningReasons = is_array($screeningAdvisor['reasons'] ?? null) ? $screeningAdvisor['reasons'] : [];
    $readyForConsent = is_array($readyForConsent ?? null) ? $readyForConsent : ['ready' => false, 'reasons' => []];
    $isReadyForConsent = (bool) ($readyForConsent['ready'] ?? false);
    $readyForConsentReasonLabels = [
        'manual_screening_required' => 'Manual screening required',
        'manual_screening_not_eligible' => 'Manual screening not eligible',
        'manual_duplicate' => 'Manual duplicate',
        'missing_mobile' => 'Missing mobile',
        'missing_identity' => 'Missing identity',
    ];
    $screeningReview = is_array($screeningReview ?? null) ? $screeningReview : null;
    $screeningReviewOptions = is_array($screeningReviewOptions ?? null) ? $screeningReviewOptions : [];
    $manualScreeningActive = $screeningReview !== null;
    $manualScreeningStatus = (string) ($screeningReview['status'] ?? '');
    $manualScreeningLabel = match ($manualScreeningStatus) {
        'eligible_for_consent' => 'Eligible for consent',
        'needs_review' => 'Needs review',
        'stopped' => 'Stopped',
        default => 'Manual screening',
    };
    $manualScreeningBadgeClass = match ($manualScreeningStatus) {
        'eligible_for_consent' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'stopped' => 'border-red-200 bg-red-50 text-red-700',
        default => 'border-amber-200 bg-amber-50 text-amber-800',
    };
    $screeningReasonLabels = [
        'corrected_basic_fields' => 'Corrected basic fields',
        'valid_mobile_ready' => 'Valid mobile ready',
        'admin_verified' => 'Admin verified',
        'missing_mobile' => 'Missing mobile',
        'invalid_mobile' => 'Invalid mobile',
        'dob_unclear' => 'DOB unclear',
        'age_issue' => 'Age issue',
        'gender_unclear' => 'Gender unclear',
        'possible_duplicate' => 'Possible duplicate',
        'unclear_biodata' => 'Unclear biodata',
        'admin_followup_needed' => 'Admin follow-up needed',
        'manual_duplicate' => 'Manual duplicate',
        'duplicate_existing_profile' => 'Duplicate existing profile',
        'already_married' => 'Already married',
        'not_interested' => 'Not interested',
        'wrong_number' => 'Wrong number',
        'blocked_or_complaint' => 'Blocked or complaint',
        'invalid_candidate' => 'Invalid candidate',
    ];
    $itemMeta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
    $manualDuplicateReview = is_array(data_get($itemMeta, 'duplicate_review')) ? data_get($itemMeta, 'duplicate_review') : [];
    $manualDuplicateActive = (string) data_get($manualDuplicateReview, 'status') === 'manual_duplicate';
    $heightOptions = [];
    for ($heightInches = 54; $heightInches <= 84; $heightInches++) {
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

    .bulk-height-combobox {
        position: relative;
    }

    .bulk-height-options {
        position: absolute;
        left: 0;
        right: 0;
        top: calc(100% + 0.25rem);
        z-index: 40;
        max-height: 16rem;
        overflow-y: auto;
    }

    .bulk-image-zoom-container {
        max-height: 38rem;
        overflow: auto;
    }

    .bulk-image-preview {
        display: block;
        width: 100%;
        max-width: none;
        transform-origin: top left;
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
                        <div class="mt-2 rounded-lg border border-gray-200 bg-gray-50 p-3" data-bulk-image-zoom>
                            <div data-testid="bulk-image-zoom-toolbar" class="mb-2 flex flex-wrap items-center gap-2 text-xs">
                                <button type="button" data-zoom-action="out" class="rounded border border-gray-300 bg-white px-2 py-1 font-semibold text-gray-700 hover:bg-gray-50">Zoom -</button>
                                <button type="button" data-zoom-action="in" class="rounded border border-gray-300 bg-white px-2 py-1 font-semibold text-gray-700 hover:bg-gray-50">Zoom +</button>
                                <button type="button" data-zoom-action="reset" class="rounded border border-gray-300 bg-white px-2 py-1 font-semibold text-gray-700 hover:bg-gray-50">Reset</button>
                                <button type="button" data-zoom-action="fit" class="rounded border border-gray-300 bg-white px-2 py-1 font-semibold text-gray-700 hover:bg-gray-50">Fit width</button>
                                <button type="button" data-zoom-action="100" class="rounded border border-gray-300 bg-white px-2 py-1 font-semibold text-gray-700 hover:bg-gray-50">100%</button>
                                <span data-zoom-level class="rounded bg-gray-100 px-2 py-1 font-semibold text-gray-600">100%</span>
                            </div>
                            <div data-testid="bulk-image-zoom-container" class="bulk-image-zoom-container rounded border border-gray-200 bg-white p-2">
                                <img src="{{ $imagePreview['data_uri'] }}" alt="Original biodata image preview" loading="lazy" decoding="async" class="bulk-image-preview rounded object-contain" data-testid="bulk-image-preview" data-zoom-image>
                            </div>
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
                                <div data-testid="bulk-height-combobox" class="bulk-height-combobox" data-height-combobox>
                                    <div class="relative">
                                        <input
                                            type="text"
                                            name="height"
                                            value="{{ $value }}"
                                            placeholder="165 cm or 5'5&quot;"
                                            autocomplete="off"
                                            aria-autocomplete="list"
                                            aria-expanded="false"
                                            @disabled(! $canSave)
                                            class="{{ $inputClass }} pr-10"
                                            data-testid="bulk-correction-height-input"
                                            data-height-combobox-input
                                        >
                                        <button
                                            type="button"
                                            class="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-lg border-l border-gray-300 bg-white text-gray-500 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                                            aria-label="Show height options"
                                            data-height-combobox-toggle
                                            @disabled(! $canSave)
                                        >
                                            <span aria-hidden="true">▾</span>
                                        </button>
                                    </div>
                                    <div class="bulk-height-options hidden rounded-lg border border-gray-200 bg-white shadow-lg" data-height-combobox-panel>
                                        @foreach ($heightOptions as $heightOption)
                                            <button type="button" class="block w-full px-3 py-2 text-left text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-800" data-height-value="{{ $heightOption['value'] }}">
                                                {{ $heightOption['label'] }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
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
                        <button type="submit" name="after_save" value="stay" @disabled(! $canSave) class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50">
                            Save and stay
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

            <div class="rounded-lg bg-white p-6 shadow" data-testid="bulk-correction-screening-advisor-card">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Screening advisor</h2>
                        <p class="mt-1 text-sm text-gray-600">Read-only eligibility signal for the next consent phase.</p>
                    </div>
                    <span data-testid="bulk-correction-screening-badge" class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $screeningBadgeClass }}">
                        {{ $screeningAdvisor['label'] ?? 'Needs review' }}
                    </span>
                </div>

                <div class="mt-4 rounded-lg border p-3 text-sm {{ $screeningCardClass }}">
                    {{ $screeningAdvisor['suggested_next_action'] ?? 'Review: Check candidate fields before consent.' }}
                </div>

                @if ($screeningReasons !== [])
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($screeningReasons as $reason)
                            <span data-testid="bulk-correction-screening-reason" class="rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-semibold text-gray-700">
                                {{ $reason['label'] ?? str_replace('_', ' ', (string) ($reason['code'] ?? 'review')) }}
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="rounded-lg bg-white p-6 shadow" data-testid="bulk-correction-ready-for-consent-card">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Ready for Consent</h2>
                        <p class="mt-1 text-sm text-gray-600">Read-only readiness signal for the consent phase.</p>
                    </div>
                    @if ($isReadyForConsent)
                        <span data-testid="bulk-correction-ready-for-consent-badge" class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">Ready</span>
                    @else
                        <span data-testid="bulk-correction-not-ready-for-consent-badge" class="rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-semibold text-gray-600">Not Ready</span>
                    @endif
                </div>

                @if (! $isReadyForConsent && is_array($readyForConsent['reasons'] ?? null) && $readyForConsent['reasons'] !== [])
                    <div class="mt-4">
                        <p class="text-sm font-semibold text-gray-700">Reasons</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600">
                            @foreach ($readyForConsent['reasons'] as $reason)
                                <li data-testid="bulk-correction-ready-for-consent-reason">{{ $readyForConsentReasonLabels[$reason] ?? str_replace('_', ' ', (string) $reason) }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <div id="bulk-manual-screening-card" class="rounded-lg bg-white p-6 shadow" data-testid="bulk-correction-manual-screening-card">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Manual screening decision</h2>
                        <p class="mt-1 text-sm text-gray-600">Stores only screening review metadata on this bulk item. Does not change item status.</p>
                    </div>
                    @if ($manualScreeningActive)
                        <span data-testid="bulk-correction-manual-screening-badge" class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $manualScreeningBadgeClass }}">{{ $manualScreeningLabel }}</span>
                    @endif
                </div>

                @if ($screeningReasons !== [])
                    <div class="mt-4 flex flex-wrap gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Advisor hints</span>
                        @foreach ($screeningReasons as $reason)
                            <span data-testid="bulk-correction-screening-advisor-hint" class="rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-[10px] font-medium text-gray-600">
                                {{ $reason['label'] ?? str_replace('_', ' ', (string) ($reason['code'] ?? 'review')) }}
                            </span>
                        @endforeach
                    </div>
                @endif

                @error('screening_review')
                    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ $message }}</div>
                @enderror
                @if ($errors->has('status') || $errors->has('reason_key') || $errors->has('note'))
                    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        @foreach (['status', 'reason_key', 'note'] as $screeningField)
                            @error($screeningField)
                                <div>{{ $message }}</div>
                            @enderror
                        @endforeach
                    </div>
                @endif

                @if ($manualScreeningActive)
                    <dl class="mt-4 grid gap-3 text-sm text-gray-700 sm:grid-cols-2">
                        <div>
                            <dt class="font-semibold">Status</dt>
                            <dd>{{ $manualScreeningLabel }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold">Reason</dt>
                            <dd>{{ $screeningReasonLabels[data_get($screeningReview, 'reason_key')] ?? (data_get($screeningReview, 'reason_key') ?: '-') }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="font-semibold">Note</dt>
                            <dd class="whitespace-pre-wrap">{{ data_get($screeningReview, 'note') ?: '-' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold">Reviewed by</dt>
                            <dd>#{{ data_get($screeningReview, 'reviewed_by_user_id') ?: '-' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold">Reviewed at</dt>
                            <dd>{{ data_get($screeningReview, 'reviewed_at') ?: '-' }}</dd>
                        </div>
                    </dl>

                    <form method="POST" action="{{ route('admin.bulk-intakes.items.clear-screening-review', [$batch, $item]) }}" class="mt-4">
                        @csrf
                        <button type="submit" class="rounded-lg border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">
                            Clear screening
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.bulk-intakes.items.save-screening-review', [$batch, $item]) }}" class="mt-4 space-y-4" data-testid="bulk-correction-manual-screening-form">
                        @csrf
                        <label class="block text-sm">
                            <span class="mb-1 block font-semibold text-gray-800">Decision</span>
                            <select name="status" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" data-bulk-screening-status>
                                <option value="">Select decision</option>
                                <option value="eligible_for_consent" @selected(old('status') === 'eligible_for_consent')>Eligible for consent</option>
                                <option value="needs_review" @selected(old('status') === 'needs_review')>Needs review</option>
                                <option value="stopped" @selected(old('status') === 'stopped')>Stopped</option>
                            </select>
                        </label>
                        <label class="block text-sm">
                            <span class="mb-1 block font-semibold text-gray-800">Reason</span>
                            <select name="reason_key" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" data-bulk-screening-reason>
                                <option value="">Select reason (optional for eligible)</option>
                                @foreach ($screeningReviewOptions as $statusKey => $reasonKeys)
                                    <optgroup label="{{ match ($statusKey) { 'eligible_for_consent' => 'Eligible for consent', 'needs_review' => 'Needs review', 'stopped' => 'Stopped', default => $statusKey } }}" data-bulk-screening-reason-group="{{ $statusKey }}">
                                        @foreach ($reasonKeys as $reasonKey)
                                            <option value="{{ $reasonKey }}" data-bulk-screening-reason-for="{{ $statusKey }}" @selected(old('reason_key') === $reasonKey)>{{ $screeningReasonLabels[$reasonKey] ?? str_replace('_', ' ', $reasonKey) }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm">
                            <span class="mb-1 block font-semibold text-gray-800">Note</span>
                            <textarea name="note" rows="3" maxlength="1000" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('note') }}</textarea>
                        </label>
                        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            Save screening decision
                        </button>
                    </form>
                    <script>
                        (() => {
                            const form = document.querySelector('[data-testid="bulk-correction-manual-screening-form"]');
                            if (!form) return;
                            const statusSelect = form.querySelector('[data-bulk-screening-status]');
                            const reasonSelect = form.querySelector('[data-bulk-screening-reason]');
                            const syncReasonOptions = () => {
                                const status = statusSelect.value;
                                reasonSelect.querySelectorAll('option[data-bulk-screening-reason-for]').forEach((option) => {
                                    const visible = !status || option.dataset.bulkScreeningReasonFor === status;
                                    option.hidden = !visible;
                                    option.disabled = !visible;
                                });
                                const selected = reasonSelect.selectedOptions[0];
                                if (selected && selected.disabled) {
                                    reasonSelect.value = '';
                                }
                            };
                            statusSelect.addEventListener('change', syncReasonOptions);
                            syncReasonOptions();
                        })();
                    </script>
                @endif
            </div>

            <div class="rounded-lg bg-white p-6 shadow" data-testid="bulk-correction-manual-duplicate-card">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Manual duplicate</h2>
                        <p class="mt-1 text-sm text-gray-600">Stores only duplicate review metadata on this bulk item.</p>
                    </div>
                    @if ($manualDuplicateActive)
                        <span data-testid="bulk-correction-manual-duplicate-badge" class="rounded-full border border-rose-200 bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700">Manual duplicate</span>
                    @endif
                </div>

                @error('duplicate_review')
                    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ $message }}</div>
                @enderror

                @if ($manualDuplicateActive)
                    <dl class="mt-4 grid gap-3 text-sm text-gray-700 sm:grid-cols-2">
                        <div>
                            <dt class="font-semibold">Matched intake</dt>
                            <dd>{{ data_get($manualDuplicateReview, 'matched_biodata_intake_id') ?: '-' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold">Matched profile</dt>
                            <dd>{{ data_get($manualDuplicateReview, 'matched_profile_id') ?: '-' }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="font-semibold">Reason</dt>
                            <dd class="whitespace-pre-wrap">{{ data_get($manualDuplicateReview, 'reason') ?: '-' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold">Marked by</dt>
                            <dd>#{{ data_get($manualDuplicateReview, 'marked_by_user_id') ?: '-' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold">Marked at</dt>
                            <dd>{{ data_get($manualDuplicateReview, 'marked_at') ?: '-' }}</dd>
                        </div>
                    </dl>

                    <form method="POST" action="{{ route('admin.bulk-intakes.items.clear-duplicate', [$batch, $item]) }}" class="mt-4">
                        @csrf
                        <button type="submit" class="rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                            Clear duplicate
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.bulk-intakes.items.mark-duplicate', [$batch, $item]) }}" class="mt-4 space-y-4">
                        @csrf
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block text-sm">
                                <span class="mb-1 block font-semibold text-gray-800">Matched intake id</span>
                                <input type="number" min="1" name="matched_biodata_intake_id" value="{{ old('matched_biodata_intake_id') }}" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @error('matched_biodata_intake_id')
                                    <span class="mt-1 block text-xs font-medium text-red-700">{{ $message }}</span>
                                @enderror
                            </label>
                            <label class="block text-sm">
                                <span class="mb-1 block font-semibold text-gray-800">Matched profile id</span>
                                <input type="number" min="1" name="matched_profile_id" value="{{ old('matched_profile_id') }}" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @error('matched_profile_id')
                                    <span class="mt-1 block text-xs font-medium text-red-700">{{ $message }}</span>
                                @enderror
                            </label>
                        </div>
                        <label class="block text-sm">
                            <span class="mb-1 block font-semibold text-gray-800">Reason</span>
                            <textarea name="reason" rows="3" maxlength="1000" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('reason') }}</textarea>
                            @error('reason')
                                <span class="mt-1 block text-xs font-medium text-red-700">{{ $message }}</span>
                            @enderror
                        </label>
                        <button type="submit" class="rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                            Mark duplicate
                        </button>
                    </form>
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

<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.LocationTypeahead && window.LocationTypeahead.init) {
            window.LocationTypeahead.init();
        }

        document.querySelectorAll('[data-height-combobox]').forEach(function (combobox) {
            var input = combobox.querySelector('[data-height-combobox-input]');
            var toggle = combobox.querySelector('[data-height-combobox-toggle]');
            var panel = combobox.querySelector('[data-height-combobox-panel]');
            if (!input || !toggle || !panel) return;

            function openPanel() {
                if (input.disabled) return;
                panel.classList.remove('hidden');
                input.setAttribute('aria-expanded', 'true');
            }

            function closePanel() {
                panel.classList.add('hidden');
                input.setAttribute('aria-expanded', 'false');
            }

            input.addEventListener('focus', openPanel);
            input.addEventListener('click', openPanel);
            input.addEventListener('input', openPanel);
            toggle.addEventListener('click', function () {
                if (panel.classList.contains('hidden')) {
                    openPanel();
                    input.focus();
                } else {
                    closePanel();
                }
            });

            panel.querySelectorAll('[data-height-value]').forEach(function (option) {
                option.addEventListener('click', function () {
                    input.value = option.getAttribute('data-height-value') || '';
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    closePanel();
                    input.focus();
                });
            });

            document.addEventListener('click', function (event) {
                if (!combobox.contains(event.target)) {
                    closePanel();
                }
            });
        });

        document.querySelectorAll('[data-bulk-image-zoom]').forEach(function (root) {
            var image = root.querySelector('[data-zoom-image]');
            var level = root.querySelector('[data-zoom-level]');
            if (!image) return;
            var zoom = 100;

            function applyZoom(nextZoom) {
                zoom = Math.max(75, Math.min(300, nextZoom));
                image.style.width = zoom + '%';
                if (level) {
                    level.textContent = zoom + '%';
                }
            }

            root.querySelectorAll('[data-zoom-action]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var action = button.getAttribute('data-zoom-action');
                    if (action === 'in') {
                        applyZoom(zoom + 25);
                    } else if (action === 'out') {
                        applyZoom(zoom - 25);
                    } else {
                        applyZoom(100);
                    }
                });
            });

            image.addEventListener('click', function () {
                applyZoom(zoom === 100 ? 150 : 100);
            });

            applyZoom(100);
        });
    });
</script>
@endsection
