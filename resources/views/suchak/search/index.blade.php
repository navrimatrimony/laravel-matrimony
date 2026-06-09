@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-5xl px-4 py-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak masked search</h1>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            Discover other Suchak-managed profiles with masked candidate identity and no direct contact exposure.
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
                <label for="age_min" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Min age</label>
                <input id="age_min" name="age_min" type="number" min="18" max="100" value="{{ $filters['age_min'] ?? '' }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div>
                <label for="age_max" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Max age</label>
                <input id="age_max" name="age_max" type="number" min="18" max="100" value="{{ $filters['age_max'] ?? '' }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            </div>
        </div>
        <div class="mt-4 flex justify-end gap-3">
            <a href="{{ route('suchak.search.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-gray-700 dark:text-gray-200">Reset</a>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Search</button>
        </div>
    </form>

    <div class="space-y-4">
        @forelse ($results as $result)
            <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $result['candidate_reference'] }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">Masked candidate</h2>
                        <div class="mt-3 grid gap-2 text-sm text-gray-700 dark:text-gray-300 md:grid-cols-2">
                            <p><span class="font-medium">Age:</span> {{ $result['basic']['age_range'] ?? 'Not available' }}</p>
                            <p><span class="font-medium">Height:</span> {{ $result['basic']['height_range'] ?? 'Not available' }}</p>
                            <p><span class="font-medium">Education:</span> {{ $result['education']['highest'] ?? 'Not available' }}</p>
                            <p><span class="font-medium">Occupation:</span> {{ $result['occupation']['broad'] ?? 'Not available' }}</p>
                            <p><span class="font-medium">Location:</span>
                                {{ collect([$result['location']['city'] ?? null, $result['location']['district'] ?? null])->filter()->implode(', ') ?: 'Broad location unavailable' }}
                            </p>
                            <p><span class="font-medium">Community:</span>
                                {{ collect([$result['community']['religion'] ?? null, $result['community']['caste'] ?? null])->filter()->implode(' / ') ?: 'Not available' }}
                            </p>
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
                        <div class="grid gap-4 md:grid-cols-[1fr_1.5fr]">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Represented profile from your side</label>
                                <select name="requesting_representation_id" required class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                    @foreach ($ownRepresentationOptions as $option)
                                        <option value="{{ $option['representation']['id'] }}">
                                            {{ $option['candidate_reference'] }} · {{ $option['basic']['age_range'] ?? 'age n/a' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Message</label>
                                <textarea name="message" rows="2" maxlength="2000" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" placeholder="Short collaboration note"></textarea>
                            </div>
                        </div>
                        <label class="mt-4 flex items-start gap-3 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" name="commission_ack" value="1" required class="mt-1">
                            <span>मी या match साठी commission / credit sharing terms मान्य करतो.</span>
                        </label>
                        <button type="submit" class="mt-4 rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                            Request collaboration
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
