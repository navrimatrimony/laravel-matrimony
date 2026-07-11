@extends('layouts.admin')

@section('content')
@php
    $activeAdminProfileTab = 'bulk';
    $fields = is_array($fields ?? null) ? $fields : [];
    $sourceText = is_string($sourceText ?? null) ? $sourceText : null;
    $sourceTextLabel = is_string($sourceTextLabel ?? null) ? $sourceTextLabel : 'Parse input text';
    $sourceSnapshotSource = is_string($sourceSnapshotSource ?? null) ? $sourceSnapshotSource : 'unknown';
    $imagePreview = is_array($imagePreview ?? null) ? $imagePreview : ['available' => false, 'url' => null, 'data_uri' => null, 'label' => null, 'message' => null];
    $canSave = (bool) ($canSave ?? false);
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
    @vite([
        'resources/js/profile/location-typeahead.js',
        'resources/js/profile/religion-caste-selector.js',
        'resources/js/matrimony/occupation-engine-entry.js',
    ])
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
                    @if (! empty($imagePreview['available']) && ! empty($imagePreview['url']))
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
                                <img src="{{ $imagePreview['url'] }}" alt="Original biodata image preview" loading="lazy" decoding="async" class="bulk-image-preview rounded object-contain" data-testid="bulk-image-preview" data-zoom-image>
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
                                <span class="mt-1 block text-xs text-gray-500">Enter one or more valid 10 digit Indian mobile numbers, comma-separated.</span>
                            @elseif ($key === 'date_of_birth')
                                <span class="mt-1 block text-xs text-gray-500">Use YYYY-MM-DD or DD/MM/YYYY. Age below 18 or above 75 should be reviewed.</span>
                            @elseif ($key === 'height')
                                <span class="mt-1 block text-xs text-gray-500">Use cm or feet/inches, for example 165 cm or 5'5".</span>
                            @elseif ($key === 'location')
                                <span class="mt-1 block text-xs text-gray-500">Single location text only. Do not paste a full address paragraph here.</span>
                            @endif
                        </div>
                    @endforeach

                    @if ($correctionProfile instanceof \App\Models\MatrimonyProfile)
                        <div class="space-y-4 border-t border-gray-100 pt-4">
                            <h3 class="text-sm font-semibold text-gray-900">Community &amp; occupation</h3>
                            <x-profile.religion-caste-selector :profile="$correctionProfile" :show-subcaste="true" />
                            <x-occupation-search-engine
                                :profile="$correctionProfile"
                                form-selector="#bulk-candidate-correction-form"
                                :compact="true"
                            />
                        </div>
                    @endif

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
