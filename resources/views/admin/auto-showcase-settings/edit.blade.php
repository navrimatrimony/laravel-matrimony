@extends('layouts.admin-showcase')

{{-- Admin main uses bg-gray-100: all copy must be dark-on-light (no text-white on transparent). --}}
@php
    $ctl = 'mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm placeholder:text-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';
    $lbl = 'block text-xs font-semibold text-gray-800';
    $bulkLc = old('showcase_bulk_create_lifecycle', $bulkLifecycle);
    $engineLc = old('showcase_auto_engine_lifecycle', $engineLifecycle);
    $allowSelected = $errors->any()
        ? (is_array(old('auto_showcase_religion_allowlist')) ? array_map('intval', old('auto_showcase_religion_allowlist')) : [])
        : $religionAllowlistSelectedIds;
@endphp

@section('showcase_content')
    <div
        class="max-w-4xl space-y-6 pb-24 text-gray-900"
        x-data="{
            tab: 'common',
            setTab(t) {
                this.tab = t;
                try { history.replaceState(null, '', '#' + t); } catch (e) {}
            }
        }"
        x-init="
            (function () {
                var h = (typeof location !== 'undefined' && location.hash) ? location.hash.slice(1) : '';
                if (['common', 'bulk', 'search'].indexOf(h) !== -1) tab = h;
            })();
        "
        @hashchange.window="
            var h = location.hash.slice(1);
            if (['common', 'bulk', 'search'].indexOf(h) !== -1) tab = h;
        "
    >
        {{-- Page header --}}
        <div class="space-y-2">
            <h1 class="text-2xl font-bold tracking-tight text-gray-900">Showcase settings</h1>
            <p class="text-sm leading-relaxed text-gray-600">
                Control <strong class="text-gray-900">shared</strong> behaviour for all showcase profiles, then tune <strong class="text-gray-900">admin bulk create</strong> and <strong class="text-gray-900">search auto-create</strong> separately. One Save updates every tab.
            </p>
        </div>

        @if ($errors->any())
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 shadow-sm" role="alert">
                <p class="font-semibold">काही फील्ड तपासा</p>
                <p class="mt-1 text-xs leading-relaxed text-amber-900/90">
                    <strong>Common</strong> — partner preferences, residence JSON, min population.
                    <strong>Admin bulk</strong> — lifecycle.
                    <strong>Search auto-create</strong> — engine, strict JSON, limits, religion.
                </p>
            </div>
        @endif

        {{-- Tab bar --}}
        <div
            class="sticky top-0 z-20 -mx-1 rounded-2xl border border-gray-200/80 bg-white/90 p-1.5 shadow-sm backdrop-blur-md sm:mx-0"
            role="tablist"
            aria-label="Showcase settings sections"
        >
            <div class="grid grid-cols-1 gap-1.5 sm:grid-cols-3">
                <button
                    type="button"
                    role="tab"
                    id="tab-btn-common"
                    :aria-selected="tab === 'common'"
                    :tabindex="tab === 'common' ? 0 : -1"
                    @click="setTab('common')"
                    :class="tab === 'common'
                        ? 'border-indigo-200 bg-indigo-50 text-indigo-950 ring-2 ring-indigo-500/25'
                        : 'border-transparent bg-gray-50 text-gray-600 hover:border-gray-200 hover:bg-white hover:text-gray-900'"
                    class="flex flex-col items-start rounded-xl border px-4 py-3 text-left transition duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                >
                    <span class="flex items-center gap-2 text-sm font-semibold">
                        <svg class="h-5 w-5 shrink-0 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655-5.653a2.548 2.548 0 010-3.586l3.03-2.496a2.652 2.652 0 013.586 0l5.877 5.877a2.548 2.548 0 010 3.586l-2.496 3.03" /></svg>
                        Common
                    </span>
                    <span class="mt-0.5 text-xs font-normal leading-snug text-gray-600">Admin + search auto-create दोघांसाठी</span>
                </button>
                <button
                    type="button"
                    role="tab"
                    id="tab-btn-bulk"
                    :aria-selected="tab === 'bulk'"
                    :tabindex="tab === 'bulk' ? 0 : -1"
                    @click="setTab('bulk')"
                    :class="tab === 'bulk'
                        ? 'border-indigo-200 bg-indigo-50 text-indigo-950 ring-2 ring-indigo-500/25'
                        : 'border-transparent bg-gray-50 text-gray-600 hover:border-gray-200 hover:bg-white hover:text-gray-900'"
                    class="flex flex-col items-start rounded-xl border px-4 py-3 text-left transition duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                >
                    <span class="flex items-center gap-2 text-sm font-semibold">
                        <svg class="h-5 w-5 shrink-0 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>
                        Admin bulk create
                    </span>
                    <span class="mt-0.5 text-xs font-normal leading-snug text-gray-600">तुम्ही Admin मधून तयार करता</span>
                </button>
                <button
                    type="button"
                    role="tab"
                    id="tab-btn-search"
                    :aria-selected="tab === 'search'"
                    :tabindex="tab === 'search' ? 0 : -1"
                    @click="setTab('search')"
                    :class="tab === 'search'
                        ? 'border-indigo-200 bg-indigo-50 text-indigo-950 ring-2 ring-indigo-500/25'
                        : 'border-transparent bg-gray-50 text-gray-600 hover:border-gray-200 hover:bg-white hover:text-gray-900'"
                    class="flex flex-col items-start rounded-xl border px-4 py-3 text-left transition duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                >
                    <span class="flex items-center gap-2 text-sm font-semibold">
                        <svg class="h-5 w-5 shrink-0 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                        Search auto-create
                    </span>
                    <span class="mt-0.5 text-xs font-normal leading-snug text-gray-600">Member search नंतर engine</span>
                </button>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.auto-showcase-settings.update') }}" class="rounded-2xl border border-gray-200 bg-white shadow-sm">
            @csrf

            {{-- ─── Tab 1: Common ─── --}}
            <div
                x-show="tab === 'common'"
                x-cloak
                id="tab-panel-common"
                role="tabpanel"
                aria-labelledby="tab-btn-common"
                class="space-y-6 border-b border-gray-100 p-6 sm:p-8"
            >
                <div class="rounded-xl border border-emerald-100 bg-gradient-to-br from-emerald-50/90 to-white px-4 py-3">
                    <p class="text-sm font-medium text-emerald-950">या विभागातील सेटिंग्ज दोन्ही मार्गांसाठी लागू होतात</p>
                    <p class="mt-1 text-xs leading-relaxed text-emerald-900/80">
                        Partner preferences, residence / city steps, आणि खाली city population टूल्स — <strong>Admin bulk</strong> किंवा <strong>search नंतर auto-create</strong> ने profile तयार झाल्यावर समान factory वापरतात.
                    </p>
                </div>

                <fieldset class="rounded-xl border border-gray-200 bg-gray-50/80 p-5 space-y-4">
                    <legend class="text-sm font-bold text-gray-900">Partner preferences (दोन्ही मार्ग)</legend>
                    <p class="text-xs leading-relaxed text-gray-600">
                        <strong class="text-gray-900">A)</strong> Match searching user — फक्त auto-create ला searcher; bulk ला rules.
                        <strong class="text-gray-900">B)</strong> Rules autofill — wizard-style.
                        <strong class="text-gray-900">C)</strong> Mixed — rules + searcher overlay on auto-create.
                    </p>
                    <div class="space-y-3">
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-transparent p-2 hover:border-gray-200 hover:bg-white">
                            <input type="radio" name="showcase_partner_pref_mode" value="match_searcher" class="mt-0.5 size-4 shrink-0 border-gray-400 text-indigo-600 focus:ring-indigo-500" @checked(old('showcase_partner_pref_mode', $partnerPrefMode) === 'match_searcher')>
                            <span class="text-sm font-medium text-gray-900">A) Match searching user</span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-transparent p-2 hover:border-gray-200 hover:bg-white">
                            <input type="radio" name="showcase_partner_pref_mode" value="rules_autofill" class="mt-0.5 size-4 shrink-0 border-gray-400 text-indigo-600 focus:ring-indigo-500" @checked(old('showcase_partner_pref_mode', $partnerPrefMode) === 'rules_autofill')>
                            <span class="text-sm font-medium text-gray-900">B) Rules autofill</span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-transparent p-2 hover:border-gray-200 hover:bg-white">
                            <input type="radio" name="showcase_partner_pref_mode" value="mixed" class="mt-0.5 size-4 shrink-0 border-gray-400 text-indigo-600 focus:ring-indigo-500" @checked(old('showcase_partner_pref_mode', $partnerPrefMode) === 'mixed')>
                            <span class="text-sm font-medium text-gray-900">C) Mixed</span>
                        </label>
                    </div>
                </fieldset>

                <div class="space-y-4 rounded-xl border border-gray-200 bg-white p-5">
                    <h3 class="text-sm font-bold text-gray-900">Residence &amp; cities (दोन्ही मार्ग)</h3>
                    <p class="text-xs leading-relaxed text-gray-600">Showcase profile ला city/district भरण्यासाठी fallback क्रम आणि लहान शहरांसाठी लोकसंख्या थ्रेशहोल्ड.</p>
                    <div>
                        <label class="{{ $lbl }}">Residence fallback order (JSON)</label>
                        <p class="mt-0.5 text-xs text-gray-500">उदा. <code class="rounded bg-gray-100 px-1 font-mono text-gray-800">search_city</code>, <code class="rounded bg-gray-100 px-1 font-mono text-gray-800">district_seat</code>, <code class="rounded bg-gray-100 px-1 font-mono text-gray-800">min_population</code></p>
                        <textarea name="auto_showcase_residence_fallback" rows="2" class="{{ $ctl }} mt-1 font-mono text-xs">{{ old('auto_showcase_residence_fallback', $residenceFallbackJson) }}</textarea>
                    </div>
                    <div>
                        <label class="{{ $lbl }}">Min population (min_population step)</label>
                        <input type="number" name="auto_showcase_min_population" value="{{ old('auto_showcase_min_population', $minPopulation) }}" min="0" autocomplete="off" class="{{ $ctl }} max-w-xs">
                    </div>
                </div>
            </div>

            {{-- ─── Tab 2: Admin bulk ─── --}}
            <div
                x-show="tab === 'bulk'"
                x-cloak
                id="tab-panel-bulk"
                role="tabpanel"
                aria-labelledby="tab-btn-bulk"
                class="space-y-6 border-b border-gray-100 p-6 sm:p-8"
            >
                <div class="rounded-xl border border-violet-100 bg-gradient-to-br from-violet-50/90 to-white px-4 py-3">
                    <p class="text-sm font-medium text-violet-950">फक्त Admin मधून तयार केलेले showcase profiles</p>
                    <p class="mt-1 text-xs leading-relaxed text-violet-900/80">
                        येथील lifecycle <strong>फक्त</strong> “Bulk showcase create” वापरताना लागू होतो — member search engine याला बदलत नाही.
                    </p>
                    <a href="{{ route('admin.showcase-profile.bulk-create') }}" class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-violet-700 underline decoration-violet-300 underline-offset-2 hover:text-violet-900">
                        Bulk create पृष्ठावर जा
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                    </a>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <span class="{{ $lbl }}">New profile lifecycle</span>
                    <p class="mt-1 text-xs text-gray-600">Draft = search मध्ये दिसणार नाही जोपर्यंत publish करत नाही. Active = इतर नियम पूर्ण असतील तर दिसू शकते.</p>
                    <div class="mt-4 inline-flex rounded-full border border-gray-300 bg-gray-100 p-1 shadow-inner" role="radiogroup" aria-label="Bulk showcase lifecycle">
                        <label class="relative inline-flex cursor-pointer rounded-full">
                            <input type="radio" name="showcase_bulk_create_lifecycle" value="draft" class="peer absolute inset-0 z-10 h-full w-full cursor-pointer opacity-0" @checked($bulkLc === 'draft')>
                            <span class="relative z-0 block rounded-full px-5 py-2.5 text-sm font-medium text-gray-600 transition peer-checked:bg-white peer-checked:text-gray-900 peer-checked:shadow-sm">Draft</span>
                        </label>
                        <label class="relative inline-flex cursor-pointer rounded-full">
                            <input type="radio" name="showcase_bulk_create_lifecycle" value="active" class="peer absolute inset-0 z-10 h-full w-full cursor-pointer opacity-0" @checked($bulkLc === 'active')>
                            <span class="relative z-0 block rounded-full px-5 py-2.5 text-sm font-medium text-gray-600 transition peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:shadow-sm">Active</span>
                        </label>
                    </div>
                </div>

                <p class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-700">
                    <strong class="text-gray-900">Profile photo</strong> policy is configured and applied separately — not on this form.
                </p>

                @php
                    $bp = $bulkPolicy;
                    $bulkRelSel = old('bulk_religion_ids', $bp['religion_ids']);
                    $bulkRelSel = is_array($bulkRelSel) ? array_map('intval', $bulkRelSel) : [];
                    $bulkCasteSel = old('bulk_caste_ids', $bp['caste_ids']);
                    $bulkCasteSel = is_array($bulkCasteSel) ? array_map('intval', $bulkCasteSel) : [];
                    $bulkCountrySel = old('bulk_country_ids', $bp['country_ids']);
                    $bulkCountrySel = is_array($bulkCountrySel) ? array_map('intval', $bulkCountrySel) : [];
                    $bulkStateSel = old('bulk_state_ids', $bp['state_ids']);
                    $bulkStateSel = is_array($bulkStateSel) ? array_map('intval', $bulkStateSel) : [];
                    $bulkDistrictSel = old('bulk_district_ids', $bp['district_ids']);
                    $bulkDistrictSel = is_array($bulkDistrictSel) ? array_map('intval', $bulkDistrictSel) : [];
                    $bulkMaritalSel = old('bulk_marital_status_ids', $bp['marital_status_ids']);
                    $bulkMaritalSel = is_array($bulkMaritalSel) ? array_map('intval', $bulkMaritalSel) : [];
                    $bulkDietSel = old('bulk_diet_ids', $bp['diet_ids']);
                    $bulkDietSel = is_array($bulkDietSel) ? array_map('intval', $bulkDietSel) : [];
                    $bulkEduSel = old('bulk_master_education_ids', $bp['master_education_ids']);
                    $bulkEduSel = is_array($bulkEduSel) ? array_map('intval', $bulkEduSel) : [];
                    $bulkNeverSel = old('bulk_never_fill_keys', $bp['never_fill_keys']);
                    $bulkNeverSel = is_array($bulkNeverSel) ? $bulkNeverSel : [];
                    $bulkRandomSel = old('bulk_random_fill_keys', $bp['random_fill_keys']);
                    $bulkRandomSel = is_array($bulkRandomSel) ? $bulkRandomSel : [];
                    $bulkCxFixed = old('bulk_fixed_complexion_ids', $bp['fixed_complexion_ids']);
                    $bulkCxFixed = is_array($bulkCxFixed) ? array_map('intval', $bulkCxFixed) : [];
                    $bulkPbFixed = old('bulk_fixed_physical_build_ids', $bp['fixed_physical_build_ids']);
                    $bulkPbFixed = is_array($bulkPbFixed) ? array_map('intval', $bulkPbFixed) : [];
                    $aboutTpl = old('bulk_about_me_templates', implode("\n", $bp['about_me_templates'] ?? []));
                    $expectTpl = old('bulk_expectations_templates', implode("\n", $bp['expectations_templates'] ?? []));
                @endphp

                <div class="space-y-8 rounded-2xl border border-violet-200/60 bg-gradient-to-b from-white to-violet-50/30 p-6 shadow-sm">
                    <div>
                        <h3 class="text-base font-bold text-gray-900">Bulk field policy</h3>
                        <p class="mt-1 text-xs leading-relaxed text-gray-600">
                            खाली सोडले = जुने default (religion: Hindu/Buddhist/Muslim pool, इतर random). निवडले = <strong>फक्त</strong> त्या pool मधून निवड.
                            <strong>Never fill</strong> = ते field <em>रिकामे / null</em> ठेवा (narrative साठी About / Expectations वेगळे).
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label class="{{ $lbl }}">Age from (years)</label>
                            <input type="number" name="bulk_age_min" value="{{ old('bulk_age_min', $bp['age_min']) }}" min="18" max="80" class="{{ $ctl }}">
                        </div>
                        <div>
                            <label class="{{ $lbl }}">Age to (years)</label>
                            <input type="number" name="bulk_age_max" value="{{ old('bulk_age_max', $bp['age_max']) }}" min="18" max="80" class="{{ $ctl }}">
                        </div>
                        <div>
                            <label class="{{ $lbl }}">Height min (cm)</label>
                            <input type="number" name="bulk_height_cm_min" value="{{ old('bulk_height_cm_min', $bp['height_cm_min']) }}" min="120" max="220" class="{{ $ctl }}">
                        </div>
                        <div>
                            <label class="{{ $lbl }}">Height max (cm)</label>
                            <input type="number" name="bulk_height_cm_max" value="{{ old('bulk_height_cm_max', $bp['height_cm_max']) }}" min="120" max="220" class="{{ $ctl }}">
                        </div>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-2">
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <span class="{{ $lbl }}">Religion pool (bulk)</span>
                            <p class="mt-0.5 text-xs text-gray-500">एकही निवड नाही = default religion pool.</p>
                            <div class="mt-2 max-h-40 overflow-y-auto rounded-lg border border-gray-100 p-2" id="bulk-religion-pool">
                                @foreach ($religions as $religion)
                                    <label class="flex cursor-pointer items-center gap-2 py-0.5 text-sm text-gray-900 hover:bg-violet-50/50">
                                        <input type="checkbox" name="bulk_religion_ids[]" value="{{ $religion->id }}" data-bulk-religion class="size-4 rounded border-gray-400 text-violet-600" @checked(in_array((int) $religion->id, $bulkRelSel, true))>
                                        <span>{{ $religion->display_label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <span class="{{ $lbl }}">Caste pool (filtered by selected religions)</span>
                            <p class="mt-0.5 text-xs text-gray-500">Religion निवडल्याशिवाय सर्व castes दिसतील.</p>
                            <div class="mt-2 max-h-48 overflow-y-auto rounded-lg border border-gray-100 p-2" id="bulk-caste-pool">
                                @foreach ($bulkCastes as $caste)
                                    <label class="bulk-caste-row flex cursor-pointer items-center gap-2 py-0.5 text-sm text-gray-900 hover:bg-violet-50/50" data-religion-id="{{ (int) $caste->religion_id }}">
                                        <input type="checkbox" name="bulk_caste_ids[]" value="{{ $caste->id }}" class="size-4 rounded border-gray-400 text-violet-600" @checked(in_array((int) $caste->id, $bulkCasteSel, true))>
                                        <span>{{ $caste->display_label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-3">
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <span class="{{ $lbl }}">Country filter</span>
                            <select name="bulk_country_ids[]" multiple size="6" class="{{ $ctl }} mt-1 min-h-[7rem] text-xs">
                                @foreach ($bulkCountries as $c)
                                    <option value="{{ $c->id }}" @selected(in_array((int) $c->id, $bulkCountrySel, true))>{{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <span class="{{ $lbl }}">State filter</span>
                            <select name="bulk_state_ids[]" multiple size="6" class="{{ $ctl }} mt-1 min-h-[7rem] text-xs">
                                @foreach ($bulkStates as $s)
                                    <option value="{{ $s->id }}" @selected(in_array((int) $s->id, $bulkStateSel, true))>{{ $s->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <span class="{{ $lbl }}">District filter (real-user districts)</span>
                            <select name="bulk_district_ids[]" multiple size="6" class="{{ $ctl }} mt-1 min-h-[7rem] text-xs">
                                @foreach ($bulkDistricts as $d)
                                    <option value="{{ $d->id }}" @selected(in_array((int) $d->id, $bulkDistrictSel, true))>{{ $d->name }} @if($d->state) — {{ $d->state->name }} @endif</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-3">
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <span class="{{ $lbl }}">Marital status pool</span>
                            <div class="mt-2 max-h-44 overflow-y-auto space-y-1 rounded-lg border border-gray-100 p-2">
                                @foreach ($bulkMaritalStatuses as $ms)
                                    <label class="flex cursor-pointer items-center gap-2 text-sm">
                                        <input type="checkbox" name="bulk_marital_status_ids[]" value="{{ $ms->id }}" class="size-4 rounded border-gray-400 text-violet-600" @checked(in_array((int) $ms->id, $bulkMaritalSel, true))>
                                        <span>{{ $ms->label ?? $ms->key }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <span class="{{ $lbl }}">Diet pool</span>
                            <div class="mt-2 max-h-44 overflow-y-auto space-y-1 rounded-lg border border-gray-100 p-2">
                                @foreach ($bulkDiets as $diet)
                                    <label class="flex cursor-pointer items-center gap-2 text-sm">
                                        <input type="checkbox" name="bulk_diet_ids[]" value="{{ $diet->id }}" class="size-4 rounded border-gray-400 text-violet-600" @checked(in_array((int) $diet->id, $bulkDietSel, true))>
                                        <span>{{ $diet->label ?? $diet->key }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <span class="{{ $lbl }}">Education (master) pool</span>
                            <div class="mt-2 max-h-44 overflow-y-auto space-y-1 rounded-lg border border-gray-100 p-2">
                                @foreach ($bulkEducations as $edu)
                                    <label class="flex cursor-pointer items-center gap-2 text-sm">
                                        <input type="checkbox" name="bulk_master_education_ids[]" value="{{ $edu->id }}" class="size-4 rounded border-gray-400 text-violet-600" @checked(in_array((int) $edu->id, $bulkEduSel, true))>
                                        <span>{{ $edu->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-2">
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <span class="{{ $lbl }}">Fixed — spectacles / lens</span>
                            <select name="bulk_fixed_spectacles_lens" class="{{ $ctl }} mt-1">
                                <option value="">(no override — random)</option>
                                <option value="no" @selected(old('bulk_fixed_spectacles_lens', $bp['fixed_spectacles_lens']) === 'no')>no</option>
                                <option value="spectacles" @selected(old('bulk_fixed_spectacles_lens', $bp['fixed_spectacles_lens']) === 'spectacles')>spectacles</option>
                                <option value="contact_lens" @selected(old('bulk_fixed_spectacles_lens', $bp['fixed_spectacles_lens']) === 'contact_lens')>contact_lens</option>
                                <option value="both" @selected(old('bulk_fixed_spectacles_lens', $bp['fixed_spectacles_lens']) === 'both')>both</option>
                            </select>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <span class="{{ $lbl }}">Fixed — smoking &amp; drinking (optional)</span>
                            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="text-xs text-gray-600">Smoking status</label>
                                    <select name="bulk_fixed_smoking_status_id" class="{{ $ctl }} mt-0.5 text-xs">
                                        <option value="">(random)</option>
                                        @foreach ($bulkSmokingStatuses as $row)
                                            <option value="{{ $row->id }}" @selected((string) old('bulk_fixed_smoking_status_id', $bp['fixed_smoking_status_id'] ?? '') === (string) $row->id)>{{ $row->label ?? $row->key }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="text-xs text-gray-600">Drinking status</label>
                                    <select name="bulk_fixed_drinking_status_id" class="{{ $ctl }} mt-0.5 text-xs">
                                        <option value="">(random)</option>
                                        @foreach ($bulkDrinkingStatuses as $row)
                                            <option value="{{ $row->id }}" @selected((string) old('bulk_fixed_drinking_status_id', $bp['fixed_drinking_status_id'] ?? '') === (string) $row->id)>{{ $row->label ?? $row->key }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-2">
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <span class="{{ $lbl }}">Fixed — complexion (pick one or more; random among selected)</span>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($bulkComplexions as $row)
                                    <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-gray-200 bg-gray-50 px-2 py-1 text-xs hover:border-violet-300">
                                        <input type="checkbox" name="bulk_fixed_complexion_ids[]" value="{{ $row->id }}" class="size-3.5 rounded border-gray-400 text-violet-600" @checked(in_array((int) $row->id, $bulkCxFixed, true))>
                                        <span>{{ $row->label ?? $row->key }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <span class="{{ $lbl }}">Fixed — physical build (random among selected)</span>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($bulkPhysicalBuilds as $row)
                                    <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-gray-200 bg-gray-50 px-2 py-1 text-xs hover:border-violet-300">
                                        <input type="checkbox" name="bulk_fixed_physical_build_ids[]" value="{{ $row->id }}" class="size-3.5 rounded border-gray-400 text-violet-600" @checked(in_array((int) $row->id, $bulkPbFixed, true))>
                                        <span>{{ $row->label ?? $row->key }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-2">
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <label class="{{ $lbl }}">About me — one template per line (random pick)</label>
                            <textarea name="bulk_about_me_templates" rows="4" class="{{ $ctl }} mt-1 text-sm" placeholder="Leave empty to auto-build from education/occupation.">{{ $aboutTpl }}</textarea>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <label class="{{ $lbl }}">Expectations — one template per line (random pick)</label>
                            <textarea name="bulk_expectations_templates" rows="4" class="{{ $ctl }} mt-1 text-sm" placeholder="Leave empty for default expectations text.">{{ $expectTpl }}</textarea>
                        </div>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-2">
                        <fieldset class="rounded-xl border border-emerald-200/80 bg-emerald-50/40 p-4">
                            <legend class="px-1 text-xs font-bold uppercase tracking-wide text-emerald-900">Random re-roll (after base fill)</legend>
                            <p class="mb-2 text-xs text-emerald-900/80">निवडलेल्या fields पुन्हा पूर्ण random master मधून भरतात (fixed नंतरही चालू शकते — काळजीपूर्वक निवडा).</p>
                            <div class="max-h-52 space-y-1 overflow-y-auto">
                                @foreach ($bulkRandomFillOptions as $key => $label)
                                    <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-900">
                                        <input type="checkbox" name="bulk_random_fill_keys[]" value="{{ $key }}" class="size-4 rounded border-gray-400 text-emerald-600" @checked(in_array($key, $bulkRandomSel, true))>
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <fieldset class="rounded-xl border border-rose-200/80 bg-rose-50/40 p-4">
                            <legend class="px-1 text-xs font-bold uppercase tracking-wide text-rose-900">Never fill (empty / null)</legend>
                            <p class="mb-2 text-xs text-rose-900/80">निवडलेले core fields create नंतर <strong>रिकामे</strong> ठेवतात. Narrative: About / Expectations स्वतंत्र.</p>
                            <div class="max-h-52 space-y-1 overflow-y-auto">
                                @foreach ($bulkNeverFillOptions as $key => $label)
                                    <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-900">
                                        <input type="checkbox" name="bulk_never_fill_keys[]" value="{{ $key }}" class="size-4 rounded border-gray-400 text-rose-600" @checked(in_array($key, $bulkNeverSel, true))>
                                        <span>{{ $label }} <code class="text-[10px] text-gray-500">{{ $key }}</code></span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    </div>
                </div>

                <script>
                    (function () {
                        function selectedReligionIds() {
                            var ids = [];
                            document.querySelectorAll('[data-bulk-religion]:checked').forEach(function (el) { ids.push(String(el.value)); });
                            return ids;
                        }
                        function syncCasteRows() {
                            var ids = selectedReligionIds();
                            document.querySelectorAll('.bulk-caste-row').forEach(function (row) {
                                var rid = String(row.getAttribute('data-religion-id') || '');
                                row.style.display = (ids.length === 0 || ids.indexOf(rid) !== -1) ? '' : 'none';
                            });
                        }
                        document.querySelectorAll('[data-bulk-religion]').forEach(function (el) {
                            el.addEventListener('change', syncCasteRows);
                        });
                        syncCasteRows();
                    })();
                </script>
            </div>

            {{-- ─── Tab 3: Search auto-create ─── --}}
            <div
                x-show="tab === 'search'"
                x-cloak
                id="tab-panel-search"
                role="tabpanel"
                aria-labelledby="tab-btn-search"
                class="space-y-6 p-6 sm:p-8"
            >
                <div class="rounded-xl border border-sky-100 bg-gradient-to-br from-sky-50/90 to-white px-4 py-3">
                    <p class="text-sm font-medium text-sky-950">Member search नंतर — auto-showcase engine</p>
                    <p class="mt-1 text-xs leading-relaxed text-sky-900/80">
                        खालील नियम फक्त तेव्हा वापरतात जेव्हा सदस्य search करतो आणि निकाल खूप कमी असतात. Engine बंद केला असेल तर हे सर्व दुर्लक्ष होतात.
                    </p>
                </div>

                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm hover:border-indigo-200">
                    <input type="checkbox" name="auto_showcase_engine_enabled" value="1" class="size-5 rounded border-gray-400 text-indigo-600 focus:ring-indigo-500" @checked($engineEnabled)>
                    <span>
                        <span class="block text-sm font-semibold text-gray-900">Enable auto-showcase engine</span>
                        <span class="mt-0.5 block text-xs text-gray-600">Search नंतर कमी निकाल असल्यास (खालील नियमानुसार) showcase profile तयार करू शकते.</span>
                    </span>
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-100 bg-gray-50/80 p-3 text-sm font-medium text-gray-900">
                        <input type="checkbox" name="auto_showcase_require_low_total" value="1" class="size-4 rounded border-gray-400 text-indigo-600 focus:ring-indigo-500" @checked($requireLowTotal)>
                        Require low total results
                    </label>
                    <div>
                        <label class="{{ $lbl }}">Max total results (trigger when ≤)</label>
                        <input type="number" name="auto_showcase_min_total_results" value="{{ old('auto_showcase_min_total_results', $minTotalResults) }}" min="0" max="500" autocomplete="off" class="{{ $ctl }}">
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-100 bg-gray-50/80 p-3 text-sm font-medium text-gray-900">
                        <input type="checkbox" name="auto_showcase_require_strict_low" value="1" class="size-4 rounded border-gray-400 text-indigo-600 focus:ring-indigo-500" @checked($requireStrictLow)>
                        Require low strict-match count
                    </label>
                    <div>
                        <label class="{{ $lbl }}">Strict max (trigger when strict count ≤)</label>
                        <input type="number" name="auto_showcase_strict_max" value="{{ old('auto_showcase_strict_max', $strictMax) }}" min="0" max="100" autocomplete="off" class="{{ $ctl }}">
                    </div>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <label class="{{ $lbl }}">Strict dimensions (JSON array of field keys)</label>
                    <p class="mt-0.5 text-xs text-gray-500">Search मध्ये “strict match” मोजण्यासाठी कोणते फील्ड वापरायचे — engine trigger साठी.</p>
                    <textarea name="auto_showcase_strict_field_keys" rows="3" class="{{ $ctl }} mt-1 font-mono text-xs">{{ old('auto_showcase_strict_field_keys', $strictFieldKeysJson) }}</textarea>
                    @error('auto_showcase_strict_field_keys')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <label class="{{ $lbl }}">Per search max creates</label>
                        <input type="number" name="auto_showcase_per_search_max_create" value="{{ old('auto_showcase_per_search_max_create', $perSearchMaxCreate) }}" min="0" max="10" autocomplete="off" class="{{ $ctl }}">
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <label class="{{ $lbl }}">Daily cap per user (0 = unlimited)</label>
                        <input type="number" name="auto_showcase_daily_user_cap" value="{{ old('auto_showcase_daily_user_cap', $dailyUserCap) }}" min="0" max="100" autocomplete="off" class="{{ $ctl }}">
                    </div>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <span class="{{ $lbl }}">New profile lifecycle (engine)</span>
                    <p class="mt-1 text-xs text-gray-600">Engine ने profile तयार केल्यावर draft की active.</p>
                    <div class="mt-4 inline-flex rounded-full border border-gray-300 bg-gray-100 p-1 shadow-inner" role="radiogroup" aria-label="Auto engine showcase lifecycle">
                        <label class="relative inline-flex cursor-pointer rounded-full">
                            <input type="radio" name="showcase_auto_engine_lifecycle" value="draft" class="peer absolute inset-0 z-10 h-full w-full cursor-pointer opacity-0" @checked($engineLc === 'draft')>
                            <span class="relative z-0 block rounded-full px-5 py-2.5 text-sm font-medium text-gray-600 transition peer-checked:bg-white peer-checked:text-gray-900 peer-checked:shadow-sm">Draft</span>
                        </label>
                        <label class="relative inline-flex cursor-pointer rounded-full">
                            <input type="radio" name="showcase_auto_engine_lifecycle" value="active" class="peer absolute inset-0 z-10 h-full w-full cursor-pointer opacity-0" @checked($engineLc === 'active')>
                            <span class="relative z-0 block rounded-full px-5 py-2.5 text-sm font-medium text-gray-600 transition peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:shadow-sm">Active</span>
                        </label>
                    </div>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <label class="{{ $lbl }}">Religion allowlist (optional)</label>
                    <p class="mt-1 text-xs leading-relaxed text-gray-600">
                        Engine फक्त निवडलेल्या religions साठी profile तयार करू शकते. <strong class="text-gray-900">सर्व unchecked</strong> = कोणतीही मर्यादा नाही.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" id="religion_allowlist_select_all" class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-1.5 text-xs font-semibold text-gray-800 transition hover:bg-white">Select all</button>
                        <button type="button" id="religion_allowlist_clear_all" class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-1.5 text-xs font-semibold text-gray-800 transition hover:bg-white">Clear all</button>
                    </div>
                    <div class="mt-3 max-h-60 overflow-y-auto rounded-xl border border-gray-100 bg-gray-50/80 p-4">
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($religions as $religion)
                                <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-transparent px-2 py-1.5 text-sm text-gray-900 transition hover:border-gray-200 hover:bg-white">
                                    <input
                                        type="checkbox"
                                        name="auto_showcase_religion_allowlist[]"
                                        value="{{ $religion->id }}"
                                        data-religion-allow-checkbox
                                        class="size-4 rounded border-gray-400 text-indigo-600 focus:ring-indigo-500"
                                        @checked(in_array((int) $religion->id, $allowSelected, true))
                                    >
                                    <span>{{ $religion->display_label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    @error('auto_showcase_religion_allowlist')
                        <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                    @error('auto_showcase_religion_allowlist.*')
                        <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Sticky save --}}
            <div class="sticky bottom-0 z-10 flex flex-col gap-2 border-t border-gray-200 bg-gradient-to-t from-white via-white to-white/95 px-6 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8">
                <p class="text-xs text-gray-500">सर्व टॅबमधील बदल एकाच Save ने जतन होतात.</p>
                <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-md transition hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:ring-offset-2">
                    Save all settings
                </button>
            </div>
        </form>

        <script>
            (function () {
                var boxes = document.querySelectorAll('[data-religion-allow-checkbox]');
                if (!boxes.length) return;
                var btnAll = document.getElementById('religion_allowlist_select_all');
                var btnClr = document.getElementById('religion_allowlist_clear_all');
                if (btnAll) btnAll.addEventListener('click', function () { boxes.forEach(function (c) { c.checked = true; }); });
                if (btnClr) btnClr.addEventListener('click', function () { boxes.forEach(function (c) { c.checked = false; }); });
            })();
        </script>

        {{-- City population: same “Common” context, own POST routes --}}
        <div x-show="tab === 'common'" x-cloak class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm sm:p-8 space-y-4 text-gray-900">
            <h2 class="text-lg font-bold tracking-tight text-gray-900">Eligible city population</h2>
            <p class="text-xs text-gray-600 leading-relaxed">
                Showcase residence resolution साठी city <code class="rounded bg-gray-100 px-1 font-mono text-gray-800">population</code> भरणे. फक्त अशा cities जिथे non-showcase profiles आहेत आणि population अजून null.
                <strong>AI cost:</strong> एका district मध्ये AI ने यशस्वी fill केल्यानंतर तो district पुन्हा AI साठी lock होतो.
                @if ($openAiConfigured)
                    <span class="font-medium text-emerald-700">OpenAI key configured.</span>
                @else
                    <span class="font-medium text-amber-800">OpenAI key नाही — AI fill चालणार नाही.</span>
                @endif
            </p>
            <ul class="text-sm text-gray-800 list-disc list-inside space-y-1">
                <li>Eligible cities (any fill): <strong>{{ number_format($eligibleCityPopulationCount) }}</strong></li>
                <li>Eligible for <strong>AI</strong>: <strong>{{ number_format($eligibleCityPopulationForAiCount) }}</strong></li>
                <li>Districts AI-locked: <strong>{{ number_format($aiPopulationLockedDistrictCount) }}</strong></li>
            </ul>
            <div class="flex flex-wrap items-end gap-4">
                <form method="POST" action="{{ route('admin.auto-showcase-settings.fill-city-population') }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <input type="hidden" name="fill_mode" value="heuristic">
                    <div>
                        <label class="{{ $lbl }}">Max cities</label>
                        <input type="number" name="population_fill_limit" value="100" min="1" max="500" autocomplete="off" class="mt-1 w-28 rounded-md border border-gray-300 bg-white px-2 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    </div>
                    <button type="submit" class="rounded-lg bg-slate-700 px-3 py-2 text-sm font-medium text-white hover:bg-slate-600">Fill (heuristic)</button>
                </form>
                <form method="POST" action="{{ route('admin.auto-showcase-settings.fill-city-population') }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <input type="hidden" name="fill_mode" value="ai">
                    <div>
                        <label class="{{ $lbl }}">Max cities</label>
                        <input type="number" name="population_fill_limit" value="30" min="1" max="500" autocomplete="off" class="mt-1 w-28 rounded-md border border-gray-300 bg-white px-2 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    </div>
                    <button type="submit" class="rounded-lg bg-violet-700 px-3 py-2 text-sm font-medium text-white hover:bg-violet-600">Fill (AI)</button>
                </form>
                <form method="POST" action="{{ route('admin.auto-showcase-settings.reset-ai-population-locks') }}" class="flex items-end" onsubmit="return confirm('Clear AI district locks? AI will be allowed again in those districts (new API cost).');">
                    @csrf
                    <button type="submit" class="rounded-lg border border-gray-400 bg-gray-100 px-3 py-2 text-sm font-medium text-gray-900 hover:bg-gray-200">Reset AI district locks</button>
                </form>
            </div>
        </div>
    </div>
@endsection
