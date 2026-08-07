@extends('layouts.admin')

@section('content')
@php
    $tab = request('tab', 'content');
    $sectionLabels = [
        'trust' => 'Trust strip / विश्वास पट्टी',
        'how_it_works' => 'How it works / प्रक्रिया',
        'assisted_service' => 'Assisted service / सहाय्यक सेवा',
        'success_stories' => 'Success stories / यशोगाथा',
        'safety' => 'Safety & verification / सुरक्षितता',
        'plans' => 'Plans preview / योजना',
        'app_section' => 'App section / अ‍ॅप विभाग',
        'retail_outlet' => 'Retail outlet / कार्यालय',
        'final_cta' => 'Final CTA / शेवटची कृती',
    ];
    $sectionHints = [
        'trust' => 'Always shown when enabled.',
        'how_it_works' => 'Always shown when enabled.',
        'assisted_service' => 'Always shown when enabled (optional image on Images tab).',
        'success_stories' => 'Shown only when enabled and at least one published story exists (empty placeholder is never shown).',
        'safety' => 'Always shown when enabled.',
        'plans' => 'Shown only when enabled and at least one active visible plan exists.',
        'app_section' => 'Shown when enabled and Android link, iOS link, and/or phone mockup image is configured.',
        'retail_outlet' => 'Shown only when enabled and outlet image is uploaded.',
        'final_cta' => 'Always shown when enabled.',
    ];
    $searchFieldLabels = [
        'gender' => 'Gender',
        'age' => 'Age range',
        'religion' => 'Religion',
        'caste' => 'Caste',
        'state' => 'State',
        'district' => 'District',
        'marital_status' => 'Marital status',
    ];
@endphp

<div class="space-y-6" x-data="{ tab: @js($tab) }">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Homepage settings</h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Marathi + English homepage copy, section images, visibility, search fields, and success stories.</p>
        </div>
        <a href="{{ url('/') }}" target="_blank" rel="noopener" class="inline-flex items-center justify-center gap-2 rounded-md border border-indigo-200 bg-white px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-gray-600 dark:bg-gray-800 dark:text-indigo-200">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H18m0 0v4.5M18 6l-7.5 7.5M6 6h4.5M6 6v12h12v-4.5" /></svg>
            View homepage
        </a>
    </div>

    @if (($errors ?? null) && $errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="border-b border-gray-200 dark:border-gray-700">
        <nav class="-mb-px flex flex-wrap gap-5">
            <button type="button" @click="tab='content'" :class="tab === 'content' ? 'border-indigo-600 text-indigo-700 dark:border-indigo-400 dark:text-indigo-200' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400'" class="inline-flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-semibold">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 6.75h15M4.5 12h15M4.5 17.25h9" /></svg>
                Content
            </button>
            <button type="button" @click="tab='controls'" :class="tab === 'controls' ? 'border-indigo-600 text-indigo-700 dark:border-indigo-400 dark:text-indigo-200' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400'" class="inline-flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-semibold">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5" /></svg>
                Controls
            </button>
            <button type="button" @click="tab='images'" :class="tab === 'images' ? 'border-indigo-600 text-indigo-700 dark:border-indigo-400 dark:text-indigo-200' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400'" class="inline-flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-semibold">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
                Images
            </button>
            <button type="button" @click="tab='stories'" :class="tab === 'stories' ? 'border-indigo-600 text-indigo-700 dark:border-indigo-400 dark:text-indigo-200' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400'" class="inline-flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-semibold">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" /></svg>
                Success stories
            </button>
        </nav>
    </div>

    <form method="POST" action="{{ route('admin.homepage-settings.update') }}" class="space-y-6">
        @csrf

        <div x-show="tab === 'content'" x-cloak class="space-y-6">
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                @foreach ([
                    'hero_badge' => 'Hero badge',
                    'hero_title' => 'Hero title',
                    'hero_subtitle' => 'Hero subtitle',
                    'primary_cta' => 'Primary button',
                    'secondary_cta' => 'Secondary button',
                    'assisted_title' => 'Assisted title',
                    'assisted_body' => 'Assisted body',
                    'success_title' => 'Success title',
                    'success_intro' => 'Success intro',
                    'final_cta_title' => 'Final CTA title',
                    'final_cta_body' => 'Final CTA body',
                    'app_title' => 'App section title',
                    'app_body' => 'App section body',
                ] as $base => $label)
                    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $label }}</h2>
                        <div class="mt-3 grid grid-cols-1 gap-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Marathi</label>
                                @if (str_contains($base, 'body') || str_contains($base, 'subtitle') || str_contains($base, 'intro'))
                                    <textarea name="{{ $base }}_mr" rows="3" class="block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old($base.'_mr', $settings[$base.'_mr'] ?? '') }}</textarea>
                                @else
                                    <input type="text" name="{{ $base }}_mr" value="{{ old($base.'_mr', $settings[$base.'_mr'] ?? '') }}" class="block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                @endif
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">English</label>
                                @if (str_contains($base, 'body') || str_contains($base, 'subtitle') || str_contains($base, 'intro'))
                                    <textarea name="{{ $base }}_en" rows="3" class="block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old($base.'_en', $settings[$base.'_en'] ?? '') }}</textarea>
                                @else
                                    <input type="text" name="{{ $base }}_en" value="{{ old($base.'_en', $settings[$base.'_en'] ?? '') }}" class="block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div x-show="tab === 'controls'" x-cloak class="space-y-6">
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Homepage sections — show / hide & order</h2>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Uncheck to hide a block on the public homepage. Lower sort order appears earlier. Hero search is always visible.</p>
                    <div class="mt-4 space-y-3">
                        @foreach ($sectionLabels as $key => $label)
                            <div class="rounded-md border border-gray-100 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900/50">
                                <div class="grid grid-cols-[1fr_6rem] items-center gap-3">
                                    <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                                        <input type="checkbox" name="section_enabled[]" value="{{ $key }}" @checked((bool) data_get($settings, "sections.$key.enabled", true)) class="rounded border-gray-300 text-indigo-600">
                                        {{ $label }}
                                    </label>
                                    <input type="number" min="1" max="999" name="section_sort_order[{{ $key }}]" value="{{ data_get($settings, "sections.$key.sort_order", 50) }}" class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" title="Sort order">
                                </div>
                                @if (! empty($sectionHints[$key] ?? ''))
                                    <p class="mt-2 text-[11px] leading-5 text-gray-500 dark:text-gray-400">{{ $sectionHints[$key] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="space-y-4">
                <div class="rounded-lg border border-indigo-100 bg-indigo-50/40 p-4 shadow-sm dark:border-indigo-900/50 dark:bg-indigo-950/20">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Android & iOS app links</h2>
                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">Store badges appear on the homepage when the App section is enabled below. Optional phone mockup: Images tab → App Download.</p>
                    <div class="mt-4 grid grid-cols-1 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Google Play URL (Android)</label>
                            <input type="url" name="app_android_url" value="{{ old('app_android_url', $settings['app_android_url'] ?? '') }}" placeholder="https://play.google.com/store/apps/details?id=..." class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Apple App Store URL (iOS)</label>
                            <input type="url" name="app_ios_url" value="{{ old('app_ios_url', $settings['app_ios_url'] ?? '') }}" placeholder="https://apps.apple.com/app/id..." class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        </div>
                        <div class="flex flex-wrap gap-4">
                            <input type="hidden" name="app_show_android" value="0">
                            <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                                <input type="checkbox" name="app_show_android" value="1" @checked((bool) old('app_show_android', $settings['app_show_android'] ?? true)) class="rounded border-gray-300 text-indigo-600">
                                Show Google Play badge
                            </label>
                            <input type="hidden" name="app_show_ios" value="0">
                            <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                                <input type="checkbox" name="app_show_ios" value="1" @checked((bool) old('app_show_ios', $settings['app_show_ios'] ?? true)) class="rounded border-gray-300 text-indigo-600">
                                Show App Store badge
                            </label>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Search form controls</h2>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">These fields submit to the existing profile search route.</p>
                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ($searchFieldLabels as $key => $label)
                            <label class="inline-flex items-center gap-2 rounded-md border border-gray-100 bg-gray-50 p-3 text-sm font-medium text-gray-800 dark:border-gray-700 dark:bg-gray-900/50 dark:text-gray-100">
                                <input type="checkbox" name="search_fields[]" value="{{ $key }}" @checked((bool) data_get($settings, "search_fields.$key", true)) class="rounded border-gray-300 text-indigo-600">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>

                    <div class="mt-5 grid grid-cols-1 gap-4">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Age control style</label>
                            <select name="hero_search_age_control" class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                <option value="inputs" @selected(($settings['hero_search_age_control'] ?? 'inputs') === 'inputs')>Two inputs: Age from + Age to</option>
                                <option value="slider" @selected(($settings['hero_search_age_control'] ?? 'inputs') === 'slider')>Range slider with hidden real values</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Community fields</label>
                            <select name="hero_search_community_mode" class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                <option value="none" @selected(($settings['hero_search_community_mode'] ?? 'caste') === 'none')>Hide community fields</option>
                                <option value="caste" @selected(($settings['hero_search_community_mode'] ?? 'caste') === 'caste')>Caste only</option>
                                <option value="religion_caste" @selected(($settings['hero_search_community_mode'] ?? 'caste') === 'religion_caste')>Religion + caste</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Location fields</label>
                            <select name="hero_search_location_mode" class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                <option value="none" @selected(($settings['hero_search_location_mode'] ?? 'state_district') === 'none')>Hide location</option>
                                <option value="state" @selected(($settings['hero_search_location_mode'] ?? 'state_district') === 'state')>State only</option>
                                <option value="state_district" @selected(($settings['hero_search_location_mode'] ?? 'state_district') === 'state_district')>State + district</option>
                            </select>
                        </div>
                    </div>

                </div>
                </div>
            </div>
        </div>

        <div x-show="tab === 'content' || tab === 'controls'" x-cloak class="flex justify-end">
            <button type="submit" class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                Save homepage settings
            </button>
        </div>
    </form>

    <div x-show="tab === 'images'" x-cloak>
        @include('admin.homepage-settings.partials.section-images', ['sections' => $sections])
    </div>

    <div x-show="tab === 'stories'" x-cloak class="grid grid-cols-1 gap-6 xl:grid-cols-[22rem_1fr]">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Add success story</h2>
            <form method="POST" action="{{ route('admin.homepage-settings.stories.store') }}" enctype="multipart/form-data" class="mt-4 space-y-3">
                @csrf
                @include('admin.homepage-settings.partials.story-fields', ['story' => null])
                <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Add story
                </button>
            </form>
        </div>

        <div class="space-y-4">
            @forelse ($stories as $story)
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex flex-col gap-4 lg:flex-row">
                        <div class="lg:w-40">
                            @if ($story->imageUrl())
                                <img src="{{ $story->imageUrl() }}" alt="" class="h-28 w-full rounded-md border border-gray-200 object-cover dark:border-gray-700">
                            @else
                                <div class="flex h-28 w-full items-center justify-center rounded-md border border-dashed border-gray-300 text-xs text-gray-400 dark:border-gray-600">No image</div>
                            @endif
                            <div class="mt-2 flex flex-wrap gap-1 text-[11px]">
                                <span class="rounded-full px-2 py-0.5 {{ $story->is_published ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600' }}">{{ $story->is_published ? 'Published' : 'Hidden' }}</span>
                                @if ($story->is_featured)
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800">Featured</span>
                                @endif
                                @if ($story->consent_confirmed)
                                    <span class="rounded-full bg-sky-100 px-2 py-0.5 text-sky-800">Consent</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex-1 space-y-3">
                            <form method="POST" action="{{ route('admin.homepage-settings.stories.update', $story) }}" enctype="multipart/form-data" class="space-y-3">
                                @csrf
                                @method('PUT')
                                @include('admin.homepage-settings.partials.story-fields', ['story' => $story])
                                <div class="flex justify-end">
                                    <button type="submit" class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                        Save
                                    </button>
                                </div>
                            </form>
                            <form method="POST" action="{{ route('admin.homepage-settings.stories.destroy', $story) }}" onsubmit="return confirm('Delete this success story?');" class="flex justify-end">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex items-center gap-2 rounded-md border border-red-200 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166M19.228 5.79L18.16 19.673A2.25 2.25 0 0115.916 21.75H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79" /></svg>
                                    Delete
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    No success stories yet.
                </div>
            @endforelse
        </div>
        </div>
    </div>
</div>
@endsection
