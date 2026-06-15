@extends('layouts.app')

@php
    $lookupLabel = static fn ($model): string => (string) ($model?->display_label ?? $model?->label ?? $model?->name ?? '');
    $ownRepresentationOptions = collect($ownRepresentationOptions ?? [])->values();
    $selectedOwnRepresentationId = (int) ($selectedOwnRepresentation['representation']['id'] ?? 0);
    $safeOwnOptions = $ownRepresentationOptions
        ->map(function (array $option): array {
            return [
                'id' => (int) ($option['representation']['id'] ?? 0),
                'label' => (string) ($option['option_label'] ?? $option['candidate_reference'] ?? 'Represented profile'),
            ];
        })
        ->filter(fn (array $option): bool => $option['id'] > 0)
        ->values();
    $communityText = static function (array $summary): string {
        $value = collect([$summary['community']['religion'] ?? null, $summary['community']['caste'] ?? null])
            ->filter()
            ->implode(' / ');

        return $value !== '' ? $value : 'Not available';
    };
    $locationText = static function (array $summary): string {
        $value = collect([$summary['location']['city'] ?? null, $summary['location']['district'] ?? null])
            ->filter()
            ->implode(', ');

        return $value !== '' ? $value : 'Broad location unavailable';
    };
    $ageHeightText = static function (array $summary): string {
        return collect([$summary['basic']['age_range'] ?? null, $summary['basic']['height_range'] ?? null])
            ->filter()
            ->implode(' / ') ?: 'Not available';
    };
    $educationJobText = static function (array $summary): string {
        return collect([$summary['education']['highest'] ?? null, $summary['occupation']['broad'] ?? null])
            ->filter()
            ->implode(' / ') ?: 'Not available';
    };
@endphp

@section('content')
<div
    class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8"
    x-data="{
        open: false,
        result: null,
        requestingId: @js($selectedOwnRepresentationId > 0 ? (string) $selectedOwnRepresentationId : ''),
        ownOptions: @js($safeOwnOptions),
        openResult(payload) {
            this.result = payload;
            this.open = true;
            document.body.classList.add('overflow-hidden');
        },
        close() {
            this.open = false;
            this.result = null;
            document.body.classList.remove('overflow-hidden');
        },
        optionLabel(id) {
            const found = this.ownOptions.find((option) => String(option.id) === String(id));
            return found ? found.label : 'Select your represented profile';
        },
        join(items, fallback) {
            const clean = (items || []).filter(Boolean);
            return clean.length ? clean.join(' · ') : fallback;
        },
    }"
    @keydown.escape.window="close()"
>
    <div class="mb-5 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Find Matches</h1>
            <p class="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                Search collaboration-ready masked profiles from other Suchaks. Identity and contact stay hidden until governed collaboration rules allow exchange.
            </p>
        </div>
        <a href="{{ route('suchak.collaborations.index') }}" class="inline-flex w-fit rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">
            Collaboration Center
        </a>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            {{ session('error') }}
        </div>
    @endif

    <form method="GET" action="{{ route('suchak.search.index') }}" class="mb-5 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="grid gap-3 lg:grid-cols-12">
            <div class="lg:col-span-4">
                <label for="requesting_representation_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">My side represented profile</label>
                <select
                    id="requesting_representation_id"
                    name="requesting_representation_id"
                    class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                    @disabled($ownRepresentationOptions->isEmpty())
                >
                    <option value="">Select profile for fit</option>
                    @foreach ($ownRepresentationOptions as $option)
                        @php
                            $optionId = (int) ($option['representation']['id'] ?? 0);
                        @endphp
                        <option value="{{ $optionId }}" @selected($selectedOwnRepresentationId === $optionId)>
                            {{ $option['option_label'] ?? $option['candidate_reference'] }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @if ($ownRepresentationOptions->isEmpty())
                        No active represented profiles are available for request creation.
                    @else
                        Select once here to compare fit signals and reuse it in the request modal.
                    @endif
                </p>
            </div>
            <div class="lg:col-span-3">
                <label for="q" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Education or occupation</label>
                <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="80" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div class="lg:col-span-2">
                <label for="gender_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Gender</label>
                <select id="gender_id" name="gender_id" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Any gender</option>
                    @foreach ($genderOptions as $option)
                        <option value="{{ $option->id }}" @selected((int) ($filters['gender_id'] ?? 0) === (int) $option->id)>{{ $lookupLabel($option) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="lg:col-span-3">
                <label for="marital_status_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Marital status</label>
                <select id="marital_status_id" name="marital_status_id" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Any status</option>
                    @foreach ($maritalStatusOptions as $option)
                        <option value="{{ $option->id }}" @selected((int) ($filters['marital_status_id'] ?? 0) === (int) $option->id)>{{ $lookupLabel($option) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="lg:col-span-2">
                <label for="age_min" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Min age</label>
                <input id="age_min" name="age_min" type="number" min="18" max="100" value="{{ $filters['age_min'] ?? '' }}" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div class="lg:col-span-2">
                <label for="age_max" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Max age</label>
                <input id="age_max" name="age_max" type="number" min="18" max="100" value="{{ $filters['age_max'] ?? '' }}" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div class="lg:col-span-3">
                <label for="religion_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Religion</label>
                <select id="religion_id" name="religion_id" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Any religion</option>
                    @foreach ($religionOptions as $option)
                        <option value="{{ $option->id }}" @selected((int) ($filters['religion_id'] ?? 0) === (int) $option->id)>{{ $lookupLabel($option) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="lg:col-span-3">
                <label for="caste_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Caste</label>
                <select id="caste_id" name="caste_id" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Any caste</option>
                    @foreach ($casteOptions as $option)
                        <option value="{{ $option->id }}" @selected((int) ($filters['caste_id'] ?? 0) === (int) $option->id)>{{ $lookupLabel($option) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end gap-2 lg:col-span-2">
                <a href="{{ route('suchak.search.index') }}" class="inline-flex w-full justify-center rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-900">Reset</a>
                <button type="submit" class="inline-flex w-full justify-center rounded-md bg-red-700 px-3 py-2 text-sm font-semibold text-white hover:bg-red-800">Search</button>
            </div>
        </div>
    </form>

    <div class="mb-3 flex flex-col gap-2 text-sm text-gray-600 dark:text-gray-300 md:flex-row md:items-center md:justify-between">
        <p>
            Showing {{ $results->firstItem() ?? 0 }}-{{ $results->lastItem() ?? 0 }} of {{ number_format($results->total()) }} masked profiles
        </p>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Requests go only to the selected target Suchak. No phone, email, exact address, family names, PDFs, or private notes are shown here.
        </p>
    </div>

    @if ($results->isNotEmpty())
        <div class="hidden overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 md:block">
            <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 text-xs font-semibold uppercase text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                    <tr>
                        <th class="px-3 py-3">Photo/Mask</th>
                        <th class="px-3 py-3">Candidate</th>
                        <th class="px-3 py-3">Age/Height</th>
                        <th class="px-3 py-3">Community</th>
                        <th class="px-3 py-3">Location</th>
                        <th class="px-3 py-3">Education/Job</th>
                        <th class="px-3 py-3">Available through</th>
                        <th class="px-3 py-3">Fit</th>
                        <th class="px-3 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($results as $result)
                        @php
                            $targetCommunity = $communityText($result);
                            $targetLocation = $locationText($result);
                            $targetAgeHeight = $ageHeightText($result);
                            $targetEducationJob = $educationJobText($result);
                            $modalResult = [
                                'target_representation_id' => (int) ($result['representation']['id'] ?? 0),
                                'candidate_reference' => (string) ($result['candidate_reference'] ?? 'Masked candidate'),
                                'age_height' => $targetAgeHeight,
                                'community' => $targetCommunity,
                                'location' => $targetLocation,
                                'education_job' => $targetEducationJob,
                                'marital_status' => (string) ($result['basic']['marital_status'] ?? 'Not available'),
                                'target_suchak_label' => (string) ($result['target_suchak_label'] ?? 'Target Suchak'),
                                'fit_label' => (string) ($result['fit_label'] ?? 'Select your profile'),
                                'fit_summary' => (string) ($result['fit_summary'] ?? 'Select your represented profile to compare deterministic fit signals.'),
                                'reasons' => collect($result['reasons'] ?? [])->filter()->values()->all(),
                                'warnings' => collect($result['warnings'] ?? [])->filter()->values()->all(),
                            ];
                        @endphp
                        <tr class="align-top hover:bg-gray-50 dark:hover:bg-gray-900/60">
                            <td class="px-3 py-4">
                                <span class="inline-flex rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">Masked</span>
                            </td>
                            <td class="px-3 py-4">
                                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $result['candidate_reference'] }}</div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $result['basic']['marital_status'] ?? 'Marital status unavailable' }}</div>
                            </td>
                            <td class="px-3 py-4 text-gray-700 dark:text-gray-300">{{ $targetAgeHeight }}</td>
                            <td class="px-3 py-4 text-gray-700 dark:text-gray-300">{{ $targetCommunity }}</td>
                            <td class="px-3 py-4 text-gray-700 dark:text-gray-300">{{ $targetLocation }}</td>
                            <td class="max-w-[14rem] px-3 py-4 text-gray-700 dark:text-gray-300">{{ $targetEducationJob }}</td>
                            <td class="max-w-[12rem] px-3 py-4 text-gray-700 dark:text-gray-300">
                                {{ $result['target_suchak_label'] ?? 'Target Suchak' }}
                            </td>
                            <td class="max-w-[13rem] px-3 py-4">
                                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $result['fit_label'] ?? 'Select your profile' }}</div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $result['fit_summary'] ?? 'Select your represented profile to compare deterministic fit signals.' }}</div>
                            </td>
                            <td class="px-3 py-4 text-right">
                                <div class="flex justify-end gap-2">
                                    <button type="button" data-result='@json($modalResult, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)' @click="openResult(JSON.parse($el.dataset.result))" class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-900">
                                        Details
                                    </button>
                                    <button type="button" data-result='@json($modalResult, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)' @click="openResult(JSON.parse($el.dataset.result))" class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">
                                        Request
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="space-y-3 md:hidden">
            @foreach ($results as $result)
                @php
                    $targetCommunity = $communityText($result);
                    $targetLocation = $locationText($result);
                    $targetAgeHeight = $ageHeightText($result);
                    $targetEducationJob = $educationJobText($result);
                    $modalResult = [
                        'target_representation_id' => (int) ($result['representation']['id'] ?? 0),
                        'candidate_reference' => (string) ($result['candidate_reference'] ?? 'Masked candidate'),
                        'age_height' => $targetAgeHeight,
                        'community' => $targetCommunity,
                        'location' => $targetLocation,
                        'education_job' => $targetEducationJob,
                        'marital_status' => (string) ($result['basic']['marital_status'] ?? 'Not available'),
                        'target_suchak_label' => (string) ($result['target_suchak_label'] ?? 'Target Suchak'),
                        'fit_label' => (string) ($result['fit_label'] ?? 'Select your profile'),
                        'fit_summary' => (string) ($result['fit_summary'] ?? 'Select your represented profile to compare deterministic fit signals.'),
                        'reasons' => collect($result['reasons'] ?? [])->filter()->values()->all(),
                        'warnings' => collect($result['warnings'] ?? [])->filter()->values()->all(),
                    ];
                @endphp
                <article class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $result['candidate_reference'] }}</p>
                            <h2 class="mt-1 text-base font-semibold text-gray-900 dark:text-gray-100">{{ $result['fit_label'] ?? 'Select your profile' }}</h2>
                        </div>
                        <span class="shrink-0 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">Masked</span>
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Age/Height</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $targetAgeHeight }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Community</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $targetCommunity }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Location</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $targetLocation }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Education/Job</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $targetEducationJob }}</dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Available through: {{ $result['target_suchak_label'] ?? 'Target Suchak' }}</p>
                    <div class="mt-4 flex gap-2">
                        <button type="button" data-result='@json($modalResult, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)' @click="openResult(JSON.parse($el.dataset.result))" class="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-900">
                            Details
                        </button>
                        <button type="button" data-result='@json($modalResult, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)' @click="openResult(JSON.parse($el.dataset.result))" class="flex-1 rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                            Request
                        </button>
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <div class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-600 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
            No masked profiles matched the current filters.
        </div>
    @endif

    <div class="mt-6">
        {{ $results->links() }}
    </div>

    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="suchak-search-request-title" @click.self="close()">
        <div x-show="open" x-transition class="max-h-[92vh] w-full overflow-y-auto rounded-t-lg bg-white shadow-xl dark:bg-gray-800 sm:max-w-2xl sm:rounded-lg">
            <div class="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-gray-200 bg-white px-5 py-4 dark:border-gray-700 dark:bg-gray-800">
                <div>
                    <h2 id="suchak-search-request-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">Details and request</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300" x-text="result ? result.candidate_reference : 'Masked candidate'"></p>
                </div>
                <button type="button" @click="close()" class="rounded-md border border-gray-300 px-2.5 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-900" aria-label="Close request modal">
                    Close
                </button>
            </div>

            <div class="space-y-5 px-5 py-5">
                <section class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900">
                        <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Target summary</div>
                        <dl class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                            <div class="flex justify-between gap-3"><dt>Age/Height</dt><dd class="text-right font-medium" x-text="result ? result.age_height : '-'"></dd></div>
                            <div class="flex justify-between gap-3"><dt>Marital</dt><dd class="text-right font-medium" x-text="result ? result.marital_status : '-'"></dd></div>
                            <div class="flex justify-between gap-3"><dt>Community</dt><dd class="text-right font-medium" x-text="result ? result.community : '-'"></dd></div>
                            <div class="flex justify-between gap-3"><dt>Location</dt><dd class="text-right font-medium" x-text="result ? result.location : '-'"></dd></div>
                            <div class="flex justify-between gap-3"><dt>Education/Job</dt><dd class="text-right font-medium" x-text="result ? result.education_job : '-'"></dd></div>
                        </dl>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900">
                        <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Routing</div>
                        <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="result ? result.target_suchak_label : 'Target Suchak'"></p>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                            Request goes only to this Suchak/representation. Contact exchange remains locked until acceptance and commission acknowledgement.
                        </p>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Fit explanation</div>
                            <h3 class="mt-1 text-base font-semibold text-gray-900 dark:text-gray-100" x-text="result ? result.fit_label : 'Select your profile'"></h3>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300" x-text="result ? result.fit_summary : 'Select your represented profile to compare deterministic fit signals.'"></p>
                        </div>
                        <div class="rounded-md bg-gray-50 px-3 py-2 text-xs font-medium text-gray-600 dark:bg-gray-900 dark:text-gray-300" x-text="optionLabel(requestingId)"></div>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Reasons</div>
                            <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                                <template x-if="!result || !result.reasons || result.reasons.length === 0">
                                    <li>No deterministic reason yet.</li>
                                </template>
                                <template x-for="reason in (result ? result.reasons : [])" :key="reason">
                                    <li x-text="reason"></li>
                                </template>
                            </ul>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Review notes</div>
                            <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                                <template x-if="!result || !result.warnings || result.warnings.length === 0">
                                    <li>No warning notes.</li>
                                </template>
                                <template x-for="warning in (result ? result.warnings : [])" :key="warning">
                                    <li x-text="warning"></li>
                                </template>
                            </ul>
                        </div>
                    </div>
                </section>

                <form method="POST" action="{{ route('suchak.collaborations.store') }}" class="space-y-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    @csrf
                    <input type="hidden" name="target_representation_id" :value="result ? result.target_representation_id : ''">
                    <input type="hidden" name="split_type" value="{{ \App\Models\SuchakCommissionAgreement::SPLIT_TO_BE_DISCUSSED }}">
                    <input type="hidden" name="currency" value="INR">

                    <div>
                        <label for="modal_requesting_representation_id" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">My side represented profile</label>
                        <select id="modal_requesting_representation_id" name="requesting_representation_id" x-model="requestingId" required class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                            <option value="">Select your represented profile</option>
                            @foreach ($ownRepresentationOptions as $option)
                                @php
                                    $optionId = (int) ($option['representation']['id'] ?? 0);
                                @endphp
                                <option value="{{ $optionId }}">{{ $option['option_label'] ?? $option['candidate_reference'] }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Select at the top and search again if you want the table fit explanation refreshed for a different profile.</p>
                    </div>

                    <div>
                        <label for="message" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Message</label>
                        <textarea id="message" name="message" rows="3" maxlength="2000" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100" placeholder="Do not write phone, email, address, or family contact details."></textarea>
                    </div>

                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                        Privacy note: no full name, phone, WhatsApp, email, exact address, family names, PDFs, or private notes are shared from this screen.
                    </div>

                    <label class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" name="commission_ack" value="1" required class="mt-1">
                        <span>मी या match साठी commission / credit sharing terms मान्य करतो.</span>
                    </label>

                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button type="button" @click="close()" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Cancel</button>
                        <button type="submit" :disabled="!result || !result.target_representation_id || !requestingId" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-gray-400">
                            Send collaboration request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
