@extends('layouts.app')

@section('content')

@php
    $maritalSelectValue = request('marital_status_id') ?: ($resolvedMaritalStatusId ?? null);
    $filterKeys = [
        'religion_id',
        'caste_id',
        'sub_caste_id',
        'country_id',
        'state_id',
        'district_id',
        'taluka_id',
        'city_id',
        'age_from',
        'age_to',
        'height_from',
        'height_to',
        'marital_status_id',
        'marital_status',
        'education',
        'profession_id',
        'serious_intent_id',
        'has_photo',
        'verified_only',
    ];
    $hasActiveFilters = collect($filterKeys)->contains(fn ($k) => request()->filled($k));
    $perPageActive = (int) request('per_page', 15) !== 15;
    $resolvedSort = $sort ?? request('sort', 'latest');
    if (! in_array($resolvedSort, ['latest', 'age_asc', 'age_desc', 'height_asc', 'height_desc', 'discover'], true)) {
        $resolvedSort = 'latest';
    }
    $hasResultsChrome = $hasActiveFilters || $perPageActive || ($resolvedSort !== 'latest');
@endphp

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">

                <div class="mb-6">
                    <h1 class="text-2xl font-bold">
                    {{ __('search.matrimony_profiles') }}
                </h1>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Use filters to narrow profiles by community, age, location, profession, and trust signals.
                    </p>
                </div>

                <hr class="mb-4 border-gray-300 dark:border-gray-600">

                <form method="GET" action="{{ route('matrimony.profiles.index') }}">
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-[320px_minmax(0,1fr)] lg:gap-6">
                    {{-- Filter sidebar: collapsible on mobile, always open on desktop (see script below) --}}
                    <details id="search-filters-details" class="group rounded-xl border border-gray-200/80 dark:border-gray-700/70 bg-gray-50/40 dark:bg-gray-900/25 shadow-sm lg:rounded-xl lg:border lg:shadow-sm">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 rounded-xl px-4 py-3.5 text-sm font-semibold text-gray-900 dark:text-gray-100 lg:hidden [&::-webkit-details-marker]:hidden">
                            <span>{{ __('search.filters_sidebar_title') }}</span>
                            <span class="text-xs font-normal text-gray-400 dark:text-gray-500" aria-hidden="true">▼</span>
                        </summary>
                        <div class="border-t border-gray-200/70 dark:border-gray-700/70 px-4 pb-4 pt-1 lg:border-0 lg:px-4 lg:pb-4 lg:pt-4">
                        <div class="space-y-5">
                            <div class="pb-1 lg:border-b lg:border-gray-200/80 lg:dark:border-gray-700/70 lg:pb-3">
                                <h2 class="hidden text-sm font-semibold text-gray-900 dark:text-gray-100 lg:block">Filters</h2>
                                <p class="mt-0 text-xs text-gray-500 dark:text-gray-400 lg:mt-1">Use sections below to narrow results.</p>
                            </div>

                            <section class="space-y-3">
                                <h3 class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Community</h3>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" for="search-filter-religion">{{ __('search.religion') }}</label>
                                    <select id="search-filter-religion" name="religion_id" class="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded px-3 py-2 text-sm">
                                        <option value="">{{ __('search.any') }}</option>
                                        @foreach(($religions ?? collect()) as $r)
                                            <option value="{{ $r->id }}" {{ (string) request('religion_id') === (string) $r->id ? 'selected' : '' }}>{{ $r->display_label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" for="search-filter-caste">{{ __('search.caste') }}</label>
                                    <select id="search-filter-caste" name="caste_id" class="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded px-3 py-2 text-sm">
                                        <option value="">{{ __('search.any') }}</option>
                                        @foreach(($castes ?? collect()) as $c)
                                            <option value="{{ $c->id }}" {{ (string) request('caste_id') === (string) $c->id ? 'selected' : '' }}>{{ $c->display_label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" for="search-filter-sub-caste">{{ __('search.sub_caste') }}</label>
                                    <select id="search-filter-sub-caste" name="sub_caste_id" class="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded px-3 py-2 text-sm">
                                        <option value="">{{ __('search.any') }}</option>
                                        @foreach(($subCastes ?? collect()) as $sc)
                                            <option value="{{ $sc->id }}" {{ (string) request('sub_caste_id') === (string) $sc->id ? 'selected' : '' }}>{{ $sc->display_label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </section>

                            <section class="space-y-3 border-t border-gray-200/70 pt-4 dark:border-gray-700/70">
                                <h3 class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('search.location') }}</h3>
                        <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" for="search-filter-country">{{ __('search.country') }}</label>
                                    <select id="search-filter-country" name="country_id" class="w-full min-w-0 border border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded px-3 py-2 text-sm">
                                        <option value="">{{ __('search.any') }}</option>
                                        @foreach(($countries ?? collect()) as $co)
                                            <option value="{{ $co->id }}" {{ (string) ($locationDisplayCountryId ?? '') === (string) $co->id ? 'selected' : '' }}>{{ $co->name }}</option>
                                        @endforeach
                                    </select>
                        </div>
                        <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" for="search-filter-state">{{ __('search.state') }}</label>
                                    <select id="search-filter-state" name="state_id" class="w-full min-w-0 border border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded px-3 py-2 text-sm">
                                        <option value="">{{ __('search.any') }}</option>
                                        @foreach(($states ?? collect()) as $st)
                                            <option value="{{ $st->id }}" {{ (string) ($locationDisplayStateId ?? '') === (string) $st->id ? 'selected' : '' }}>{{ $st->name }}</option>
                                        @endforeach
                                    </select>
                        </div>
                        <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" for="search-filter-district">{{ __('search.district') }}</label>
                                    <select id="search-filter-district" name="district_id" class="w-full min-w-0 border border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded px-3 py-2 text-sm">
                                        <option value="">{{ __('search.any') }}</option>
                                        @foreach(($districts ?? collect()) as $d)
                                            <option value="{{ $d->id }}" {{ (string) ($locationDisplayDistrictId ?? '') === (string) $d->id ? 'selected' : '' }}>{{ $d->name }}</option>
                                        @endforeach
                                    </select>
                        </div>
                        <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" for="search-filter-taluka">{{ __('search.taluka') }}</label>
                                    <select id="search-filter-taluka" name="taluka_id" class="w-full min-w-0 border border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded px-3 py-2 text-sm">
                                        <option value="">{{ __('search.any') }}</option>
                                        @foreach(($talukas ?? collect()) as $tk)
                                            <option value="{{ $tk->id }}" {{ (string) ($locationDisplayTalukaId ?? '') === (string) $tk->id ? 'selected' : '' }}>{{ $tk->name }}</option>
                                        @endforeach
                                    </select>
                        </div>
                        <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" for="search-filter-city">{{ __('search.city') }}</label>
                                    <select id="search-filter-city" name="city_id" class="w-full min-w-0 border border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded px-3 py-2 text-sm">
                                        <option value="">{{ __('search.any') }}</option>
                                        @foreach(($cities ?? collect()) as $city)
                                            <option value="{{ $city->id }}" {{ (string) ($locationDisplayCityId ?? '') === (string) $city->id ? 'selected' : '' }}>{{ $city->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </section>

                            <section class="space-y-3 border-t border-gray-200/70 pt-4 dark:border-gray-700/70">
                                <h3 class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Basic details</h3>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('search.age_from') }}</label>
                                        <input type="number" name="age_from" value="{{ request('age_from') }}" min="18" max="80" class="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded px-3 py-2 text-sm" placeholder="{{ __('search.min_age') }}">
                        </div>
                        <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('search.age_to') }}</label>
                                        <input type="number" name="age_to" value="{{ request('age_to') }}" min="18" max="80" class="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded px-3 py-2 text-sm" placeholder="{{ __('search.max_age') }}">
                                    </div>
                        </div>
                        <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('search.marital_status') }}</label>
                                    <select name="marital_status_id" class="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded px-3 py-2 text-sm">
                                        <option value="">{{ __('search.any') }}</option>
                                        @foreach(($maritalStatuses ?? collect()) as $ms)
                                            <option value="{{ $ms->id }}" {{ (string) $maritalSelectValue === (string) $ms->id ? 'selected' : '' }}>{{ $ms->label }}</option>
                                        @endforeach
                            </select>
                        </div>
                        <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('search.education') }}</label>
                                    <input type="text" name="education" value="{{ request('education') }}" class="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded px-3 py-2 text-sm" placeholder="{{ __('search.education_placeholder') }}">
                                </div>
                            </section>

                            <section class="space-y-3 border-t border-gray-200/70 pt-4 dark:border-gray-700/70">
                                <h3 class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Career &amp; profile</h3>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('search.profession') }}</label>
                                    <select name="profession_id" class="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded px-3 py-2 text-sm">
                                        <option value="">{{ __('search.any') }}</option>
                                        @foreach(($professions ?? collect()) as $p)
                                            <option value="{{ $p->id }}" {{ (string) request('profession_id') === (string) $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('search.height_from_cm') }}</label>
                                        <input type="number" name="height_from" value="{{ request('height_from') }}" class="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded px-3 py-2 text-sm" placeholder="{{ __('search.min_cm') }}">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('search.height_to_cm') }}</label>
                                        <input type="number" name="height_to" value="{{ request('height_to') }}" class="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded px-3 py-2 text-sm" placeholder="{{ __('search.max_cm') }}">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('search.serious_intent') }}</label>
                                    <select name="serious_intent_id" class="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded px-3 py-2 text-sm">
                                        <option value="">{{ __('search.any') }}</option>
                                        @foreach(($seriousIntents ?? collect()) as $si)
                                            <option value="{{ $si->id }}" {{ (string) request('serious_intent_id') === (string) $si->id ? 'selected' : '' }}>{{ $si->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </section>

                            <section class="space-y-2 border-t border-gray-200/70 pt-4 dark:border-gray-700/70">
                                <h3 class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Trust filters</h3>
                                <div class="space-y-2 rounded-lg bg-white/60 p-2 dark:bg-gray-900/40">
                                    <label class="flex cursor-pointer items-start gap-3 rounded-md border border-transparent px-2 py-2 text-sm text-gray-800 hover:border-gray-200 hover:bg-white dark:text-gray-200 dark:hover:border-gray-600 dark:hover:bg-gray-900/60">
                                        <input type="checkbox" name="has_photo" value="1" class="mt-0.5 size-4 shrink-0 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900" {{ request()->boolean('has_photo') ? 'checked' : '' }}>
                                        <span class="leading-snug">{{ __('search.has_profile_photo') }}</span>
                                    </label>
                                    <label class="flex cursor-pointer items-start gap-3 rounded-md border border-transparent px-2 py-2 text-sm text-gray-800 hover:border-gray-200 hover:bg-white dark:text-gray-200 dark:hover:border-gray-600 dark:hover:bg-gray-900/60">
                                        <input type="checkbox" name="verified_only" value="1" class="mt-0.5 size-4 shrink-0 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900" {{ request()->boolean('verified_only') ? 'checked' : '' }}>
                                        <span class="leading-snug">{{ __('search.email_verified_only') }}</span>
                                    </label>
                                </div>
                            </section>

                            <div class="border-t border-gray-200/70 pt-4 dark:border-gray-700/70">
                                <h3 class="mb-2 text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Utility</h3>
                                <div class="flex flex-col gap-2">
                                    <button type="submit" class="w-full rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">{{ __('search.search') }}</button>
                                    <a href="{{ route('matrimony.profiles.index') }}" class="w-full rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-center text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800">{{ __('search.clear_filters') }}</a>
                                </div>
                            </div>
                        </div>
                        </div>
                    </details>

                    {{-- Results area --}}
                    <div class="min-w-0">
                        {{-- Results header --}}
                        <div class="mb-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900/40 px-4 py-4 shadow-sm">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between lg:gap-4">
                                <div class="min-w-0 flex-1">
                                    <p class="text-base font-semibold text-gray-900 dark:text-gray-100">
                                        @if ($profiles->total() === 0)
                                            {{ __('search.results_none') }}
                                        @else
                                            {!! __('search.results_range', [
                                                'from' => $profiles->firstItem(),
                                                'to' => $profiles->lastItem(),
                                                'total' => $profiles->total(),
                                            ]) !!}
                                        @endif
                                    </p>
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Results update based on your selected filters.</p>
                                </div>

                                <div class="flex w-full flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end lg:w-auto lg:shrink-0 lg:justify-end">
                                    <div class="min-w-0 sm:min-w-[11rem]">
                                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" for="search-sort">{{ __('search.sort_by') }}</label>
                                        <select id="search-sort" name="sort" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900" onchange="this.form.requestSubmit()">
                                            <option value="latest" {{ $resolvedSort === 'latest' ? 'selected' : '' }}>{{ __('search.sort_latest') }}</option>
                                            <option value="age_asc" {{ $resolvedSort === 'age_asc' ? 'selected' : '' }}>{{ __('search.sort_age_asc') }}</option>
                                            <option value="age_desc" {{ $resolvedSort === 'age_desc' ? 'selected' : '' }}>{{ __('search.sort_age_desc') }}</option>
                                            <option value="height_asc" {{ $resolvedSort === 'height_asc' ? 'selected' : '' }}>{{ __('search.sort_height_asc') }}</option>
                                            <option value="height_desc" {{ $resolvedSort === 'height_desc' ? 'selected' : '' }}>{{ __('search.sort_height_desc') }}</option>
                                            @auth
                                            <option value="discover" {{ $resolvedSort === 'discover' ? 'selected' : '' }}>{{ __('search.sort_discover') }}</option>
                                            @endauth
                                        </select>
                                    </div>
                                    <div class="min-w-0 sm:min-w-[6rem]">
                                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" for="search-per-page">{{ __('search.per_page') }}</label>
                                        <select id="search-per-page" name="per_page" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900" onchange="this.form.requestSubmit()">
                                @foreach ([15, 25, 50] as $n)
                                    <option value="{{ $n }}" {{ (int) request('per_page', 15) === $n ? 'selected' : '' }}>{{ $n }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                            </div>

                            @if ($hasResultsChrome)
                                <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 dark:border-gray-700 pt-3">
                                    <div class="text-xs font-semibold text-gray-700 dark:text-gray-200">{{ __('search.active_filters') }}</div>
                                    <a href="{{ route('matrimony.profiles.index') }}" class="text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('search.clear_filters') }}</a>
                                </div>
                            @endif
                        </div>

                        @if ($hasResultsChrome)
                            @php
                                $chips = [];
                                if (request()->filled('religion_id')) {
                                    $r = ($religions ?? collect())->firstWhere('id', (int) request('religion_id'));
                                    $chips[] = ['k' => 'Religion', 'v' => $r ? $r->display_label : ('#' . request('religion_id'))];
                                }
                                if (request()->filled('caste_id')) {
                                    $c = ($castes ?? collect())->firstWhere('id', (int) request('caste_id'));
                                    $chips[] = ['k' => 'Caste', 'v' => $c ? $c->display_label : ('#' . request('caste_id'))];
                                }
                                if (request()->filled('sub_caste_id')) {
                                    $sc = ($subCastes ?? collect())->firstWhere('id', (int) request('sub_caste_id'));
                                    $chips[] = ['k' => 'Sub-caste', 'v' => $sc ? $sc->display_label : ('#' . request('sub_caste_id'))];
                                }
                                if (request()->filled('country_id')) {
                                    $co = ($countries ?? collect())->firstWhere('id', (int) request('country_id'));
                                    $chips[] = ['k' => __('search.country'), 'v' => $co ? $co->name : ('#' . request('country_id'))];
                                }
                                if (request()->filled('state_id')) {
                                    $st = ($states ?? collect())->firstWhere('id', (int) request('state_id'));
                                    $chips[] = ['k' => __('search.state'), 'v' => $st ? $st->name : ('#' . request('state_id'))];
                                }
                                if (request()->filled('district_id')) {
                                    $d = ($districts ?? collect())->firstWhere('id', (int) request('district_id'));
                                    $chips[] = ['k' => __('search.district'), 'v' => $d ? $d->name : ('#' . request('district_id'))];
                                }
                                if (request()->filled('taluka_id')) {
                                    $tk = ($talukas ?? collect())->firstWhere('id', (int) request('taluka_id'));
                                    $chips[] = ['k' => __('search.taluka'), 'v' => $tk ? $tk->name : ('#' . request('taluka_id'))];
                                }
                                if (request()->filled('city_id')) {
                                    $ct = ($cities ?? collect())->firstWhere('id', (int) request('city_id'));
                                    $chips[] = ['k' => __('search.city'), 'v' => $ct ? $ct->name : ('#' . request('city_id'))];
                                }
                                if (request()->filled('age_from') || request()->filled('age_to')) {
                                    $chips[] = ['k' => 'Age', 'v' => (request('age_from') ?: '—') . '–' . (request('age_to') ?: '—')];
                                }
                                if (request()->filled('height_from') || request()->filled('height_to')) {
                                    $chips[] = ['k' => 'Height', 'v' => (request('height_from') ?: '—') . '–' . (request('height_to') ?: '—') . ' ' . __('search.cm_abbr')];
                                }
                                if ($maritalSelectValue) {
                                    $m = ($maritalStatuses ?? collect())->firstWhere('id', (int) $maritalSelectValue);
                                    $chips[] = ['k' => 'Marital', 'v' => $m ? $m->label : ('#' . $maritalSelectValue)];
                                }
                                if (request()->filled('education')) {
                                    $chips[] = ['k' => 'Education', 'v' => (string) request('education')];
                                }
                                if (request()->filled('profession_id')) {
                                    $p = ($professions ?? collect())->firstWhere('id', (int) request('profession_id'));
                                    $chips[] = ['k' => 'Profession', 'v' => $p ? $p->name : ('#' . request('profession_id'))];
                                }
                                if (request()->filled('serious_intent_id')) {
                                    $si = ($seriousIntents ?? collect())->firstWhere('id', (int) request('serious_intent_id'));
                                    $chips[] = ['k' => 'Intent', 'v' => $si ? $si->name : ('#' . request('serious_intent_id'))];
                                }
                                if (request()->boolean('has_photo')) {
                                    $chips[] = ['k' => 'Photo', 'v' => __('search.summary_has_photo')];
                                }
                                if (request()->boolean('verified_only')) {
                                    $chips[] = ['k' => 'Verification', 'v' => __('search.summary_email_verified_only')];
                                }
                                if ($resolvedSort !== 'latest') {
                                    $sortChipLabels = [
                                        'age_asc' => __('search.sort_age_asc'),
                                        'age_desc' => __('search.sort_age_desc'),
                                        'height_asc' => __('search.sort_height_asc'),
                                        'height_desc' => __('search.sort_height_desc'),
                                        'discover' => __('search.sort_discover'),
                                    ];
                                    $chips[] = ['k' => 'Sort', 'v' => $sortChipLabels[$resolvedSort] ?? $resolvedSort];
                                }
                                if ($perPageActive) {
                                    $chips[] = ['k' => 'Per page', 'v' => (string) (int) request('per_page', 15)];
                                }
                            @endphp
                            @if ($chips !== [])
                                <div class="mb-4">
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($chips as $chip)
                                            <span class="inline-flex items-center gap-1 rounded-full border border-indigo-200 dark:border-indigo-800 bg-indigo-50/80 dark:bg-indigo-950/30 px-3 py-1 text-xs font-medium text-indigo-900 dark:text-indigo-100">
                                                <span class="text-indigo-700/80 dark:text-indigo-200/80">{{ $chip['k'] }}:</span>
                                                <span class="max-w-[14rem] truncate">{{ $chip['v'] }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                    </div>
                            @endif
                        @endif

                @if ($profiles->isEmpty())
                            <div class="rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/40 px-4 py-6 text-center">
                                <p class="text-gray-700 dark:text-gray-300 mb-2">{{ __('search.no_profiles_found_detail') }}</p>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">Try clearing a filter or widening age / location.</p>
                                <a href="{{ route('matrimony.profiles.index') }}" class="text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('search.clear_filters_try_again') }}</a>
                            </div>
                @else
                    @foreach ($profiles as $matrimonyProfile)
                        @php
                            $genderKey = strtolower(trim((string) ($matrimonyProfile->gender?->key ?? '')));
                            $genderLabel = trim((string) ($matrimonyProfile->gender?->label ?? ''));
                            if ($genderLabel === '' && $genderKey !== '') {
                                $genderLabel = ucfirst($genderKey);
                            }
                            if ($genderLabel === '') {
                                $genderLabel = '—';
                            }

                            $ageText = null;
                            if ($matrimonyProfile->date_of_birth) {
                                $ageText = \Carbon\Carbon::parse($matrimonyProfile->date_of_birth)->age.' '.__('search.years_short');
                            }

                            $cityName = trim((string) ($matrimonyProfile->city?->name ?? ''));
                            $talukaName = trim((string) ($matrimonyProfile->taluka?->name ?? ''));
                            $districtName = trim((string) ($matrimonyProfile->district?->name ?? ''));
                            $stateName = trim((string) ($matrimonyProfile->state?->name ?? ''));

                            $locationParts = array_values(array_filter([
                                $cityName !== '' ? $cityName : null,
                                $districtName !== '' && $cityName === '' ? $districtName : null,
                                $stateName !== '' ? $stateName : null,
                            ]));
                            $locationLine = $locationParts !== [] ? implode(' · ', $locationParts) : ($talukaName !== '' ? $talukaName : '—');

                            $hasApprovedPhoto = $matrimonyProfile->profile_photo && $matrimonyProfile->photo_approved !== false;
                            $edu = trim((string) ($matrimonyProfile->highest_education ?? ''));
                            $heightCm = $matrimonyProfile->height_cm;
                            $profName = trim((string) ($matrimonyProfile->profession?->name ?? ''));
                            $msLabel = trim((string) ($matrimonyProfile->maritalStatus?->label ?? ''));
                            $casteLabel = $matrimonyProfile->caste?->display_label ?? '';
                            $religionLabel = trim((string) ($matrimonyProfile->religion?->display_label ?? ''));
                            $subCasteLabel = trim((string) ($matrimonyProfile->subCaste?->display_label ?? ''));
                            $emailVerified = (bool) optional($matrimonyProfile->user)->email_verified_at;
                            $seriousLabel = trim((string) ($matrimonyProfile->seriousIntent?->name ?? ''));
                        @endphp

                        <div class="mb-4 flex flex-col overflow-visible rounded-xl border border-gray-200 bg-white text-gray-900 shadow-sm dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-100 sm:flex-row sm:items-stretch">
                            {{-- Photo: full-height left block on desktop; portrait ratio on mobile (avoid thin strip) --}}
                            <div class="relative aspect-[3/4] w-full shrink-0 overflow-hidden rounded-t-xl bg-gray-100 dark:bg-gray-800 sm:aspect-auto sm:h-auto sm:w-44 sm:min-h-[11rem] md:w-48 sm:self-stretch sm:rounded-l-xl sm:rounded-tr-none">
                                @if ($hasApprovedPhoto)
        <img
            src="{{ asset('uploads/matrimony_photos/'.$matrimonyProfile->profile_photo) }}"
                                        alt=""
                                        class="absolute inset-0 h-full w-full object-cover"
        />
    @else
        @php
            if ($genderKey === 'male') {
                $placeholderSrc = asset('images/placeholders/male-profile.svg');
            } elseif ($genderKey === 'female') {
                $placeholderSrc = asset('images/placeholders/female-profile.svg');
            } else {
                $placeholderSrc = asset('images/placeholders/default-profile.svg');
            }
        @endphp
        <img
            src="{{ $placeholderSrc }}"
                                        alt=""
                                        class="absolute inset-0 h-full w-full object-cover object-center opacity-95"
        />
    @endif
</div>

                            <div class="flex min-w-0 flex-1 flex-col gap-4 rounded-b-xl p-4 sm:flex-row sm:items-center sm:justify-between sm:rounded-b-none sm:rounded-r-xl sm:p-5">
                                <div class="min-w-0 flex-1 space-y-1.5">
                                    @php
                                        $isListingOwnProfile = auth()->check()
                                            && auth()->user()->matrimonyProfile
                                            && (int) auth()->user()->matrimonyProfile->id === (int) $matrimonyProfile->id;
                                        $chipFirstName = \Illuminate\Support\Str::before(trim((string) ($matrimonyProfile->full_name ?? '')), ' ');
                                        $chipFirstName = $chipFirstName !== '' ? $chipFirstName : 'Profile';
                                    @endphp
                                    {{-- Row 1: name + menu --}}
                                    <div class="flex items-start justify-between gap-3">
                                        <p class="min-w-0 flex-1 truncate font-semibold text-lg text-gray-900 dark:text-gray-100">{{ $matrimonyProfile->full_name ?: '—' }}</p>
                                        @auth
                                            @if (! $isListingOwnProfile)
                                                @include('matrimony.profile.partials.viewer-profile-actions-menu', [
                                                    'matrimonyProfile' => $matrimonyProfile,
                                                    'isListingOwnProfile' => $isListingOwnProfile,
                                                ])
                                            @endif
                                        @endauth
                                    </div>
                                    {{-- Row 2: online (truthful) + compatibility --}}
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        @if ($matrimonyProfile->online_status_summary)
                                            @if (($matrimonyProfile->online_status_summary['is_online'] ?? false))
                                                <span class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">
                                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-500" aria-hidden="true"></span>
                                                    <span>{{ $matrimonyProfile->online_status_summary['label'] ?? '' }}</span>
                                                </span>
                                            @else
                                                <span class="inline-flex max-w-full items-center rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-[11px] font-medium text-gray-700 dark:border-gray-600 dark:bg-gray-800/80 dark:text-gray-200">
                                                    {{ $matrimonyProfile->online_status_summary['label'] ?? '' }}
                                                </span>
                                            @endif
                                        @endif
                                        @if ($matrimonyProfile->compatibility_summary && ! $isListingOwnProfile)
                                            <span
                                                class="inline-flex max-w-full items-center gap-1 rounded-full border border-violet-200 bg-violet-50 px-2.5 py-1 text-[11px] font-semibold text-violet-800 dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-200"
                                                title="{{ $matrimonyProfile->compatibility_summary['label'] ?? '' }}"
                                            >
                                                <span aria-hidden="true">❤</span>
                                                <span class="min-w-0 truncate">
                                                    You &amp; {{ $chipFirstName }} match · {{ $matrimonyProfile->compatibility_summary['matched_count'] }}/{{ $matrimonyProfile->compatibility_summary['total_count'] }}
                                                </span>
                                            </span>
                                        @endif
                                    </div>
                                    {{-- Row 3: gender / age / height / marital --}}
                                    <div class="flex flex-wrap items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400">
                                        <span class="rounded-full bg-gray-100 dark:bg-gray-800 px-2 py-0.5">{{ $genderLabel }}</span>
        @if ($ageText)
                                            <span class="rounded-full bg-gray-100 dark:bg-gray-800 px-2 py-0.5">{{ $ageText }}</span>
                                        @endif
                                        @if ($heightCm)
                                            <span class="rounded-full bg-gray-100 dark:bg-gray-800 px-2 py-0.5">{{ $heightCm }} {{ __('search.cm_abbr') }}</span>
                                        @endif
                                        @if ($msLabel !== '')
                                            <span class="rounded-full bg-gray-100 dark:bg-gray-800 px-2 py-0.5">{{ $msLabel }}</span>
        @endif
    </div>
                                    <p class="text-sm text-gray-700 dark:text-gray-300">
                                        <span class="text-gray-500 dark:text-gray-500">{{ __('search.card_location') }}</span>
                                        {{ $locationLine }}
                                    </p>
                                    @if ($religionLabel !== '' || $casteLabel !== '' || $subCasteLabel !== '')
                                        <p class="text-sm text-gray-700 dark:text-gray-300">
                                            @php
                                                $communityParts = array_values(array_filter([
                                                    $religionLabel !== '' ? $religionLabel : null,
                                                    $casteLabel !== '' ? $casteLabel : null,
                                                    $subCasteLabel !== '' ? $subCasteLabel : null,
                                                ]));
                                            @endphp
                                            <span class="text-gray-500 dark:text-gray-500">Community:</span>
                                            {{ $communityParts !== [] ? implode(' · ', $communityParts) : '—' }}
                                        </p>
                                    @endif
                                    @if ($edu !== '' || $profName !== '' || $casteLabel !== '')
                                        <p class="text-sm text-gray-700 dark:text-gray-300">
                                            @if ($edu !== '')
                                                <span>{{ $edu }}</span>
                                            @endif
                                            @if ($edu !== '' && $profName !== '')
                                                <span class="text-gray-400"> · </span>
                                            @endif
                                            @if ($profName !== '')
                                                <span>{{ $profName }}</span>
                                            @endif
                                            {{-- caste moved to Community row above --}}
                                        </p>
                                    @endif
                                    <div class="flex flex-wrap gap-1.5 pt-1">
                                        @if ($seriousLabel !== '')
                                            <span class="inline-flex items-center rounded-full border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-950/40 px-2.5 py-1 text-[11px] font-semibold text-emerald-800 dark:text-emerald-200">{{ __('search.badge_serious') }}: {{ $seriousLabel }}</span>
                                        @endif
                                        @if ($emailVerified)
                                            <span class="inline-flex items-center rounded-full border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-800 px-2.5 py-1 text-[11px] font-semibold text-slate-700 dark:text-slate-300">{{ __('search.badge_email_verified') }}</span>
                                        @endif
                                        @if (! $hasApprovedPhoto)
                                            <span class="inline-flex items-center rounded-full border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/40 px-2.5 py-1 text-[11px] font-semibold text-amber-900 dark:text-amber-200">{{ __('search.badge_no_photo') }}</span>
                                        @endif
</div>
</div>

                                <div class="flex shrink-0 items-start justify-end sm:items-center sm:self-stretch sm:pl-2">
    @auth
        <a
            href="{{ route('matrimony.profile.show', $matrimonyProfile->id) }}"
                                        class="inline-flex min-w-[9rem] w-full items-center justify-center rounded-lg bg-blue-600 px-4 py-2.5 text-center text-sm font-semibold text-white shadow-sm hover:bg-blue-700 sm:w-auto"
        >
            {{ __('search.view_profile') }}
        </a>
    @else
        <a
            href="{{ route('login') }}"
                                        class="inline-block w-full text-center text-sm font-medium text-gray-500 hover:underline dark:text-gray-400 sm:w-auto"
            title="{{ __('search.login_required_to_view_full_profile') }}"
        >
            {{ __('search.login_to_view_profile') }}
        </a>
    @endauth
</div>
                            </div>
                        </div>
                    @endforeach

                    <div class="mt-6">{{ $profiles->links() }}</div>
                @endif
                    </div>
                </div>
                </form>

                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        var detailsEl = document.getElementById('search-filters-details');
                        if (detailsEl && window.matchMedia('(min-width: 1024px)').matches) {
                            detailsEl.open = true;
                        }

                        var anyLabel = @json(__('search.any'));
                        var baseUrl = @json(rtrim(url('/'), '/'));

                        function rowLabel(row) {
                            if (!row) {
                                return '';
                            }
                            if (row.label != null) {
                                return String(row.label);
                            }
                            if (row.name != null) {
                                return String(row.name);
                            }
                            return '';
                        }

                        function setSelectOptions(select, items, preferredValue, emptyLabel) {
                            var pv = preferredValue != null ? String(preferredValue) : '';
                            var list = Array.isArray(items) ? items : [];
                            select.innerHTML = '';
                            var optAny = document.createElement('option');
                            optAny.value = '';
                            optAny.textContent = emptyLabel;
                            select.appendChild(optAny);
                            list.forEach(function (row) {
                                if (!row || row.id == null) {
                                    return;
                                }
                                var o = document.createElement('option');
                                o.value = String(row.id);
                                o.textContent = rowLabel(row);
                                select.appendChild(o);
                            });
                            if (pv !== '' && Array.prototype.some.call(select.options, function (opt) { return opt.value === pv; })) {
                                select.value = pv;
                            } else {
                                select.value = '';
                            }
                        }

                        function fetchJson(url) {
                            return fetch(url, {
                                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                credentials: 'same-origin',
                            }).then(function (res) {
                                if (!res.ok) {
                                    throw new Error('HTTP ' + res.status);
                                }
                                return res.json();
                            });
                        }

                        function unwrapInternalLocationPayload(json) {
                            if (json && json.success && Array.isArray(json.data)) {
                                return json.data;
                            }
                            throw new Error('Unexpected location API response');
                        }

                        var countryEl = document.getElementById('search-filter-country');
                        var stateEl = document.getElementById('search-filter-state');
                        var districtEl = document.getElementById('search-filter-district');
                        var talukaEl = document.getElementById('search-filter-taluka');
                        var cityEl = document.getElementById('search-filter-city');

                        if (countryEl && stateEl && districtEl && talukaEl && cityEl) {
                            function refreshStatesForCountry(preferredState) {
                                if (!countryEl.value) {
                                    setSelectOptions(stateEl, [], '', anyLabel);
                                    return Promise.resolve();
                                }
                                return fetchJson(baseUrl + '/api/internal/location/states?country_ids[]=' + encodeURIComponent(countryEl.value))
                                    .then(unwrapInternalLocationPayload)
                                    .then(function (rows) {
                                        setSelectOptions(stateEl, rows, preferredState != null ? preferredState : stateEl.value, anyLabel);
                                    });
                            }

                            function refreshDistrictsForState(preferredDistrict) {
                                if (!stateEl.value) {
                                    setSelectOptions(districtEl, [], '', anyLabel);
                                    setSelectOptions(talukaEl, [], '', anyLabel);
                                    setSelectOptions(cityEl, [], '', anyLabel);
                                    return Promise.resolve();
                                }
                                return fetchJson(baseUrl + '/api/internal/location/districts?state_id=' + encodeURIComponent(stateEl.value))
                                    .then(unwrapInternalLocationPayload)
                                    .then(function (rows) {
                                        setSelectOptions(districtEl, rows, preferredDistrict != null ? preferredDistrict : districtEl.value, anyLabel);
                                    });
                            }

                            function refreshTalukasForDistrict(preferredTaluka) {
                                if (!districtEl.value) {
                                    setSelectOptions(talukaEl, [], '', anyLabel);
                                    setSelectOptions(cityEl, [], '', anyLabel);
                                    return Promise.resolve();
                                }
                                return fetchJson(baseUrl + '/api/internal/location/talukas?district_id=' + encodeURIComponent(districtEl.value))
                                    .then(unwrapInternalLocationPayload)
                                    .then(function (rows) {
                                        setSelectOptions(talukaEl, rows, preferredTaluka != null ? preferredTaluka : talukaEl.value, anyLabel);
                                    });
                            }

                            function refreshCitiesForTaluka(preferredCity) {
                                if (!talukaEl.value) {
                                    setSelectOptions(cityEl, [], '', anyLabel);
                                    return Promise.resolve();
                                }
                                return fetchJson(baseUrl + '/api/internal/location/cities?taluka_id=' + encodeURIComponent(talukaEl.value))
                                    .then(unwrapInternalLocationPayload)
                                    .then(function (rows) {
                                        setSelectOptions(cityEl, rows, preferredCity != null ? preferredCity : cityEl.value, anyLabel);
                                    });
                            }

                            function onCountryChange() {
                                var ps = stateEl.value;
                                var pd = districtEl.value;
                                var pt = talukaEl.value;
                                var pc = cityEl.value;
                                if (!countryEl.value) {
                                    setSelectOptions(stateEl, [], '', anyLabel);
                                    setSelectOptions(districtEl, [], '', anyLabel);
                                    setSelectOptions(talukaEl, [], '', anyLabel);
                                    setSelectOptions(cityEl, [], '', anyLabel);
                                    return;
                                }
                                refreshStatesForCountry(ps).then(function () {
                                    return refreshDistrictsForState(pd);
                                }).then(function () {
                                    return refreshTalukasForDistrict(pt);
                                }).then(function () {
                                    return refreshCitiesForTaluka(pc);
                                }).catch(function (err) {
                                    console.warn('[search location] country cascade failed', err);
                                });
                            }

                            function onStateChange() {
                                var pd = districtEl.value;
                                var pt = talukaEl.value;
                                var pc = cityEl.value;
                                if (!stateEl.value) {
                                    setSelectOptions(districtEl, [], '', anyLabel);
                                    setSelectOptions(talukaEl, [], '', anyLabel);
                                    setSelectOptions(cityEl, [], '', anyLabel);
                                    return;
                                }
                                refreshDistrictsForState(pd).then(function () {
                                    return refreshTalukasForDistrict(pt);
                                }).then(function () {
                                    return refreshCitiesForTaluka(pc);
                                }).catch(function (err) {
                                    console.warn('[search location] state cascade failed', err);
                                });
                            }

                            function onDistrictChange() {
                                var pt = talukaEl.value;
                                var pc = cityEl.value;
                                if (!districtEl.value) {
                                    setSelectOptions(talukaEl, [], '', anyLabel);
                                    setSelectOptions(cityEl, [], '', anyLabel);
                                    return;
                                }
                                refreshTalukasForDistrict(pt).then(function () {
                                    return refreshCitiesForTaluka(pc);
                                }).catch(function (err) {
                                    console.warn('[search location] district cascade failed', err);
                                });
                            }

                            function onTalukaChange() {
                                var pc = cityEl.value;
                                if (!talukaEl.value) {
                                    setSelectOptions(cityEl, [], '', anyLabel);
                                    return;
                                }
                                refreshCitiesForTaluka(pc).catch(function (err) {
                                    console.warn('[search location] taluka cascade failed', err);
                                });
                            }

                            countryEl.addEventListener('change', onCountryChange);
                            stateEl.addEventListener('change', onStateChange);
                            districtEl.addEventListener('change', onDistrictChange);
                            talukaEl.addEventListener('change', onTalukaChange);

                            (function initLocationChain() {
                                var ic = countryEl.value;
                                var is = stateEl.value;
                                var id = districtEl.value;
                                var it = talukaEl.value;
                                var icy = cityEl.value;
                                var chain = Promise.resolve();
                                if (ic) {
                                    chain = chain.then(function () {
                                        return refreshStatesForCountry(is);
                                    }).then(function () {
                                        return refreshDistrictsForState(id);
                                    }).then(function () {
                                        return refreshTalukasForDistrict(it);
                                    }).then(function () {
                                        return refreshCitiesForTaluka(icy);
                                    });
                                } else if (is) {
                                    chain = chain.then(function () {
                                        return refreshDistrictsForState(id);
                                    }).then(function () {
                                        return refreshTalukasForDistrict(it);
                                    }).then(function () {
                                        return refreshCitiesForTaluka(icy);
                                    });
                                } else if (id) {
                                    chain = chain.then(function () {
                                        return refreshTalukasForDistrict(it);
                                    }).then(function () {
                                        return refreshCitiesForTaluka(icy);
                                    });
                                } else if (it) {
                                    chain = chain.then(function () {
                                        return refreshCitiesForTaluka(icy);
                                    });
                                }
                                chain.catch(function (err) {
                                    console.warn('[search location] initial chain failed', err);
                                });
                            })();
                        }

                        var religionEl = document.getElementById('search-filter-religion');
                        var casteEl = document.getElementById('search-filter-caste');
                        var subCasteEl = document.getElementById('search-filter-sub-caste');

                        if (religionEl && casteEl && subCasteEl) {
                            function castesUrl(religionId) {
                                return baseUrl + '/api/castes/' + encodeURIComponent(religionId);
                            }
                            function subcastesUrl(casteId) {
                                return baseUrl + '/api/subcastes/' + encodeURIComponent(casteId);
                            }

                            function refreshSubCastesForCurrentCaste(preferredSubCasteId) {
                                var cid = casteEl.value;
                                if (!cid) {
                                    setSelectOptions(subCasteEl, [], '', anyLabel);
                                    return Promise.resolve();
                                }
                                return fetchJson(subcastesUrl(cid)).then(function (rows) {
                                    setSelectOptions(subCasteEl, rows, preferredSubCasteId != null ? preferredSubCasteId : subCasteEl.value, anyLabel);
                                });
                            }

                            function refreshCastesForCurrentReligion(preferredCasteId) {
                                var rid = religionEl.value;
                                if (!rid) {
                                    setSelectOptions(casteEl, [], '', anyLabel);
                                    setSelectOptions(subCasteEl, [], '', anyLabel);
                                    return Promise.resolve();
                                }
                                return fetchJson(castesUrl(rid)).then(function (rows) {
                                    setSelectOptions(casteEl, rows, preferredCasteId != null ? preferredCasteId : casteEl.value, anyLabel);
                                });
                            }

                            function onReligionChange() {
                                var keepCaste = casteEl.value;
                                var keepSub = subCasteEl.value;
                                if (!religionEl.value) {
                                    setSelectOptions(casteEl, [], '', anyLabel);
                                    setSelectOptions(subCasteEl, [], '', anyLabel);
                                    return;
                                }
                                refreshCastesForCurrentReligion(keepCaste).then(function () {
                                    return refreshSubCastesForCurrentCaste(keepSub);
                                }).catch(function (err) {
                                    console.warn('[search filters] religion cascade failed', err);
                                });
                            }

                            function onCasteChange() {
                                var keepSub = subCasteEl.value;
                                if (!casteEl.value) {
                                    setSelectOptions(subCasteEl, [], '', anyLabel);
                                    return;
                                }
                                refreshSubCastesForCurrentCaste(keepSub).catch(function (err) {
                                    console.warn('[search filters] sub-caste fetch failed', err);
                                });
                            }

                            religionEl.addEventListener('change', onReligionChange);
                            casteEl.addEventListener('change', onCasteChange);

                            (function initialDependentOptions() {
                                var initRel = religionEl.value;
                                var initCaste = casteEl.value;
                                var initSub = subCasteEl.value;
                                var chain = Promise.resolve();
                                if (initRel) {
                                    chain = chain.then(function () {
                                        return refreshCastesForCurrentReligion(initCaste);
                                    }).then(function () {
                                        return refreshSubCastesForCurrentCaste(initSub);
                                    });
                                } else if (initCaste) {
                                    chain = chain.then(function () {
                                        return refreshSubCastesForCurrentCaste(initSub);
                                    });
                                }
                                chain.catch(function (err) {
                                    console.warn('[search filters] initial dependent options failed', err);
                                });
                            })();
                        }

                        document.querySelectorAll('[data-profile-card-actions]').forEach(function (el) {
                            el.addEventListener('toggle', function () {
                                if (!el.open) {
                                    return;
                                }
                                document.querySelectorAll('[data-profile-card-actions]').forEach(function (other) {
                                    if (other !== el) {
                                        other.open = false;
                                    }
                                });
                            });
                        });
                    });
                </script>

            </div>
        </div>
    </div>
</div>

@endsection
