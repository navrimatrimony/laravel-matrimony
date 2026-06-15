@extends('layouts.app')

@php
    $lookupLabel = static fn ($model): string => (string) ($model?->display_label ?? $model?->label ?? $model?->name ?? '');
@endphp

@section('content')
<div class="mx-auto max-w-6xl px-4 py-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak masked search</h1>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            Find collaboration-ready profiles from other Suchaks. Candidate identity and contact stay masked.
        </p>
    </div>

    @if (session('success'))
        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            {{ session('error') }}
        </div>
    @endif

    <form method="GET" action="{{ route('suchak.search.index') }}" class="mb-6 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="grid gap-4 md:grid-cols-4">
            <div class="md:col-span-2">
                <label for="q" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Education or occupation</label>
                <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="80" class="mt-1 w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div>
                <label for="gender_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Gender</label>
                <select id="gender_id" name="gender_id" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Any gender</option>
                    @foreach ($genderOptions as $option)
                        <option value="{{ $option->id }}" @selected((int) ($filters['gender_id'] ?? 0) === (int) $option->id)>{{ $lookupLabel($option) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="marital_status_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Marital status</label>
                <select id="marital_status_id" name="marital_status_id" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Any status</option>
                    @foreach ($maritalStatusOptions as $option)
                        <option value="{{ $option->id }}" @selected((int) ($filters['marital_status_id'] ?? 0) === (int) $option->id)>{{ $lookupLabel($option) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="age_min" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Min age</label>
                <input id="age_min" name="age_min" type="number" min="18" max="100" value="{{ $filters['age_min'] ?? '' }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div>
                <label for="age_max" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Max age</label>
                <input id="age_max" name="age_max" type="number" min="18" max="100" value="{{ $filters['age_max'] ?? '' }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div>
                <label for="religion_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Religion</label>
                <select id="religion_id" name="religion_id" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Any religion</option>
                    @foreach ($religionOptions as $option)
                        <option value="{{ $option->id }}" @selected((int) ($filters['religion_id'] ?? 0) === (int) $option->id)>{{ $lookupLabel($option) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="caste_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Caste</label>
                <select id="caste_id" name="caste_id" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Any caste</option>
                    @foreach ($casteOptions as $option)
                        <option value="{{ $option->id }}" @selected((int) ($filters['caste_id'] ?? 0) === (int) $option->id)>{{ $lookupLabel($option) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="mt-4 flex justify-end gap-3">
            <a href="{{ route('suchak.search.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-gray-700 dark:text-gray-200">Reset</a>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Search</button>
        </div>
    </form>

    <div class="space-y-4">
        @forelse ($results as $result)
            @php
                $targetCommunity = collect([$result['community']['religion'] ?? null, $result['community']['caste'] ?? null])->filter()->implode(' / ');
                $targetLocation = collect([$result['location']['city'] ?? null, $result['location']['district'] ?? null])->filter()->implode(', ');
            @endphp
            <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $result['candidate_reference'] }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">Masked candidate</h2>
                        <p class="mt-2 text-sm font-semibold text-indigo-700 dark:text-indigo-300">
                            Available through: {{ $result['target_suchak_label'] ?? 'Target Suchak' }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Request goes only to this Suchak.</p>
                        <div class="mt-3 grid gap-2 text-sm text-gray-700 dark:text-gray-300 md:grid-cols-2">
                            <p><span class="font-medium">Age:</span> {{ $result['basic']['age_range'] ?? 'Not available' }}</p>
                            <p><span class="font-medium">Height:</span> {{ $result['basic']['height_range'] ?? 'Not available' }}</p>
                            <p><span class="font-medium">Marital status:</span> {{ $result['basic']['marital_status'] ?? 'Not available' }}</p>
                            <p><span class="font-medium">Education:</span> {{ $result['education']['highest'] ?? 'Not available' }}</p>
                            <p><span class="font-medium">Occupation:</span> {{ $result['occupation']['broad'] ?? 'Not available' }}</p>
                            <p><span class="font-medium">Location:</span> {{ $targetLocation !== '' ? $targetLocation : 'Broad location unavailable' }}</p>
                            <p><span class="font-medium">Community:</span> {{ $targetCommunity !== '' ? $targetCommunity : 'Not available' }}</p>
                        </div>
                    </div>
                    <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                        Contact masked
                    </div>
                </div>
                @if (($ownRepresentationOptions ?? collect())->isNotEmpty() && ! empty($result['representation']['id'] ?? null))
                    <form method="POST" action="{{ route('suchak.collaborations.store') }}" class="mt-5 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/50">
                        @csrf
                        <input type="hidden" name="target_representation_id" value="{{ $result['representation']['id'] }}">
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="rounded-md border border-gray-200 bg-white p-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Target side</div>
                                <div class="mt-2 font-semibold text-gray-900 dark:text-gray-100">{{ $result['candidate_reference'] }}</div>
                                <div class="mt-1 text-gray-600 dark:text-gray-300">
                                    {{ collect([$result['basic']['age_range'] ?? null, $targetCommunity ?: null, $targetLocation ?: null])->filter()->implode(' · ') ?: 'Masked details only' }}
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Your side candidate</label>
                                <select name="requesting_representation_id" required class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                    @foreach ($ownRepresentationOptions as $option)
                                        <option value="{{ $option['representation']['id'] }}">
                                            {{ $option['option_label'] ?? $option['candidate_reference'] }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Choose the represented customer you want to compare with this masked profile.</p>
                            </div>
                        </div>
                        <div class="mt-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Message</label>
                            <textarea name="message" rows="2" maxlength="2000" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" placeholder="Do not write phone/email/contact details here."></textarea>
                        </div>
                        <label class="mt-4 flex items-start gap-3 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" name="commission_ack" value="1" required class="mt-1">
                            <span>मी या match साठी commission / credit sharing terms मान्य करतो.</span>
                        </label>
                        <button type="submit" class="mt-4 rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                            Send collaboration request to this Suchak
                        </button>
                    </form>
                @endif
            </article>
        @empty
            <div class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-600 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                No masked profiles matched the current filters.
            </div>
        @endforelse
    </div>

    <div class="mt-6">
        {{ $results->links() }}
    </div>
</div>
@endsection
