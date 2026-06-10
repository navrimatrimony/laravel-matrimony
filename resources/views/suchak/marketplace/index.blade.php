@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-8">
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
            <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">Public Suchak Marketplace</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">Find verified Suchaks</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                Browse factual Suchak service profiles. Requests stay inside the platform workflow and candidate contact stays masked.
            </p>
        </div>
        <a href="{{ route('suchak.home') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">
            Suchak Centre
        </a>
    </div>

    <form method="GET" action="{{ route('suchak.marketplace.index') }}" class="mb-6 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="grid gap-4 md:grid-cols-5">
            <div>
                <label for="district_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">District</label>
                <select id="district_id" name="district_id" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Any district</option>
                    @foreach ($filterOptions['districts'] as $option)
                        <option value="{{ $option['id'] }}" @selected(($filters['district_id'] ?? null) === $option['id'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="taluka_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Taluka</label>
                <select id="taluka_id" name="taluka_id" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Any taluka</option>
                    @foreach ($filterOptions['talukas'] as $option)
                        <option value="{{ $option['id'] }}" @selected(($filters['taluka_id'] ?? null) === $option['id'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="religion_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Community</label>
                <select id="religion_id" name="religion_id" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Any community</option>
                    @foreach ($filterOptions['religions'] as $option)
                        <option value="{{ $option['id'] }}" @selected(($filters['religion_id'] ?? null) === $option['id'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="caste_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Caste</label>
                <select id="caste_id" name="caste_id" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Any caste</option>
                    @foreach ($filterOptions['castes'] as $option)
                        <option value="{{ $option['id'] }}" @selected(($filters['caste_id'] ?? null) === $option['id'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="service" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Service</label>
                <input id="service" name="service" value="{{ $filters['service'] ?? '' }}" maxlength="80" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            </div>
        </div>
        <div class="mt-4 flex justify-end gap-3">
            <a href="{{ route('suchak.marketplace.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-gray-700 dark:text-gray-200">Reset</a>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Apply filters</button>
        </div>
    </form>

    <div class="grid gap-4 md:grid-cols-2">
        @forelse ($suchaks as $suchak)
            <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $suchak['account']['name'] }}</h2>
                            <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">{{ $suchak['account']['verified_badge'] }}</span>
                        </div>
                        @if ($suchak['account']['office_name'])
                            <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $suchak['account']['office_name'] }}</p>
                        @endif
                        <dl class="mt-3 grid gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <div><dt class="inline font-semibold">Type:</dt> <dd class="inline">{{ $suchak['account']['business_type'] }}</dd></div>
                            <div><dt class="inline font-semibold">Area:</dt> <dd class="inline">{{ $suchak['area']['line'] }}</dd></div>
                            <div><dt class="inline font-semibold">Public profiles:</dt> <dd class="inline">{{ $suchak['metrics']['public_representations_count'] }}</dd></div>
                        </dl>
                    </div>
                    <a href="{{ $suchak['account']['public_profile_url'] }}" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                        View profile
                    </a>
                </div>

                @if ($suchak['communities']->isNotEmpty())
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($suchak['communities'] as $community)
                            <span class="rounded-full border border-gray-200 px-2.5 py-1 text-xs font-medium text-gray-700 dark:border-gray-700 dark:text-gray-300">{{ $community }}</span>
                        @endforeach
                    </div>
                @endif

                <div class="mt-4 space-y-2">
                    @forelse ($suchak['packages'] as $package)
                        <div class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900/50">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $package['name'] }}</p>
                                <p class="text-gray-600 dark:text-gray-300">{{ $package['price_label'] }}</p>
                            </div>
                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ $package['stage_count'] }} stages · {{ $package['deliverable_count'] }} deliverables</p>
                        </div>
                    @empty
                        <p class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900/50 dark:text-gray-300">No published service package listed.</p>
                    @endforelse
                </div>
            </article>
        @empty
            <div class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-600 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 md:col-span-2">
                No verified public Suchaks matched the selected filters.
            </div>
        @endforelse
    </div>

    <div class="mt-6">
        {{ $suchaks->links() }}
    </div>
</div>
@endsection
