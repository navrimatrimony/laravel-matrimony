@extends('layouts.admin')

@section('content')
@php
    $fieldClass = 'w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100';
    $labelClass = 'block text-sm font-semibold text-gray-700 dark:text-gray-200';
    $helpClass = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $panelClass = 'rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800';
    $checked = fn (string $key): bool => old($key, ($current[$key] ?? false) ? '1' : '0') === '1';
    $nestedChecked = fn (string $key, bool $default): bool => old($key, $default ? '1' : '0') === '1';

    $heroImageKey = \App\Modules\Suchak\Services\SuchakPolicyService::KEY_SUCHAK_HERO_IMAGE_PATH;
    $homepageCopyKey = \App\Modules\Suchak\Services\SuchakPolicyService::KEY_SUCHAK_HOMEPAGE_COPY_JSON;
    $homepageStyleKey = \App\Modules\Suchak\Services\SuchakPolicyService::KEY_SUCHAK_HOMEPAGE_STYLE_JSON;
    $consentWhatsappPrivacyParagraphKey = \App\Modules\Suchak\Services\SuchakPolicyService::KEY_SUCHAK_CONSENT_WHATSAPP_PRIVACY_PARAGRAPH;
    $suchakHeroImagePath = trim((string) ($current[$heroImageKey] ?? ''));
    $suchakHeroImageUrl = $suchakHeroImagePath !== ''
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($suchakHeroImagePath)
        : null;
    $fallbackSuchakHeroImage = file_exists(public_path('images/homepage/hero_1779797852.jpg'))
        ? asset('images/homepage/hero_1779797852.jpg')
        : asset('images/matrimonial-hero.jpg');
    $heroPreviewUrl = $suchakHeroImageUrl ?: $fallbackSuchakHeroImage;

    $homepageCopyDefaults = \App\Modules\Suchak\Services\SuchakPolicyService::DEFAULT_SUCHAK_HOMEPAGE_COPY;
    $homepageStyleDefaults = \App\Modules\Suchak\Services\SuchakPolicyService::DEFAULT_SUCHAK_HOMEPAGE_STYLE;
    $homepageCopy = array_replace_recursive($homepageCopyDefaults, is_array($current[$homepageCopyKey] ?? null) ? $current[$homepageCopyKey] : []);
    $homepageStyle = array_replace($homepageStyleDefaults, is_array($current[$homepageStyleKey] ?? null) ? $current[$homepageStyleKey] : []);
    $homepageCopy = is_array(old('homepage_copy')) ? array_replace_recursive($homepageCopy, old('homepage_copy')) : $homepageCopy;
    $homepageBenefits = is_array(old('homepage_benefits')) ? old('homepage_benefits') : ($homepageCopy['benefits'] ?? $homepageCopyDefaults['benefits']);
    $homepageProcess = is_array(old('homepage_process')) ? old('homepage_process') : ($homepageCopy['process_steps'] ?? $homepageCopyDefaults['process_steps']);
    $homepageTools = is_array(old('homepage_tools')) ? old('homepage_tools') : ($homepageCopy['tools'] ?? $homepageCopyDefaults['tools']);
    $homepageStyle = is_array(old('homepage_style')) ? array_replace($homepageStyle, old('homepage_style')) : $homepageStyle;

    $activeTab = in_array(request('tab'), ['homepage', 'consent', 'operations', 'pricing', 'commission'], true)
        ? (string) request('tab')
        : 'homepage';
    $tabs = [
        'homepage' => 'Homepage',
        'consent' => 'Consent & SLA',
        'operations' => 'Operations',
        'pricing' => 'Pricing & Payment',
        'commission' => 'Commission',
    ];
    $tabClass = fn (string $key): string => $activeTab === $key
        ? 'border-indigo-600 bg-indigo-50 text-indigo-700 dark:border-indigo-400 dark:bg-indigo-950/40 dark:text-indigo-200'
        : 'border-transparent text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-gray-100';
    $copyFields = [
        ['key' => 'eyebrow', 'label' => 'Hero eyebrow', 'textarea' => false],
        ['key' => 'title', 'label' => 'Hero title', 'textarea' => false],
        ['key' => 'subtitle', 'label' => 'Hero subtitle', 'textarea' => true],
        ['key' => 'primary_cta', 'label' => 'Primary CTA', 'textarea' => false],
        ['key' => 'dashboard_cta', 'label' => 'Dashboard CTA', 'textarea' => false],
        ['key' => 'secondary_cta', 'label' => 'Secondary CTA', 'textarea' => false],
        ['key' => 'trust', 'label' => 'Trust note', 'textarea' => true],
        ['key' => 'hero_form_title', 'label' => 'Hero form title', 'textarea' => false],
        ['key' => 'hero_form_body', 'label' => 'Hero form body', 'textarea' => true],
        ['key' => 'benefits_title', 'label' => 'Benefits eyebrow', 'textarea' => false],
        ['key' => 'benefits_intro', 'label' => 'Benefits intro', 'textarea' => true],
        ['key' => 'business_title', 'label' => 'Business section title', 'textarea' => false],
        ['key' => 'business_body', 'label' => 'Business section body', 'textarea' => true],
        ['key' => 'process_title', 'label' => 'Process eyebrow', 'textarea' => false],
        ['key' => 'tools_title', 'label' => 'Tools eyebrow', 'textarea' => false],
        ['key' => 'final_title', 'label' => 'Final title', 'textarea' => false],
        ['key' => 'final_body', 'label' => 'Final body', 'textarea' => true],
        ['key' => 'status_cta', 'label' => 'Status CTA', 'textarea' => false],
    ];
    $styleColors = [
        'primary_color' => 'Primary button color',
        'primary_dark_color' => 'Primary hover color',
        'ink_color' => 'Text ink color',
        'page_background_color' => 'Page background',
        'hero_background_color' => 'Hero fallback background',
        'overlay_color' => 'Hero overlay color',
    ];
    $imagePositions = ['center' => 'Center', 'top' => 'Top', 'bottom' => 'Bottom', 'left' => 'Left', 'right' => 'Right'];
@endphp

<div class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak Settings Center</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Controlled policy settings for Suchak homepage, onboarding, operations, billing, and commission rules. Every saved change is written to admin audit.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ url('/suchak') }}" target="_blank" rel="noopener" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">
                    View Suchak homepage
                </a>
                <a href="{{ route('admin.suchak.dashboard') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">
                    Back to Suchak dashboard
                </a>
            </div>
        </div>
    </div>

    @if ($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-700 dark:bg-red-950/40 dark:text-red-100">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.suchak.settings.update') }}" class="space-y-6" enctype="multipart/form-data">
        @csrf

        <section class="rounded-lg border border-amber-200 bg-amber-50 p-5 dark:border-amber-800 dark:bg-amber-950/30">
            <label for="reason" class="{{ $labelClass }}">Reason for change</label>
            <textarea id="reason" name="reason" rows="3" required minlength="10" maxlength="500" class="{{ $fieldClass }} mt-2" placeholder="Example: Update Suchak homepage hero copy for launch pilot.">{{ old('reason') }}</textarea>
            <p class="{{ $helpClass }}">Required for audit. Existing accounts, subscriptions, representations, and candidate profiles are not mutated by saving these settings.</p>
        </section>

        <nav class="flex flex-wrap gap-2 rounded-lg border border-gray-200 bg-white p-2 shadow-sm dark:border-gray-700 dark:bg-gray-800" aria-label="Suchak settings tabs">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('admin.suchak.settings.index', ['tab' => $key]) }}" class="rounded-md border px-4 py-2 text-sm font-semibold transition {{ $tabClass($key) }}">
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        <section class="{{ $panelClass }} {{ $activeTab !== 'homepage' ? 'hidden' : '' }}">
            <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Homepage Settings</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Controls the public Suchak page at <span class="font-mono">/suchak</span>: hero image, copy, CTA behavior, overlay, blur, color, and section labels.</p>
                </div>
                <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-200">Public page</span>
            </div>

            <div class="mt-5 grid gap-6 xl:grid-cols-[1fr_1.1fr]">
                <div class="space-y-5">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Hero image</h3>
                                <p class="{{ $helpClass }}">Allowed: JPG, PNG, WEBP. Maximum size: 4 MB.</p>
                            </div>
                            @if ($suchakHeroImagePath !== '')
                                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200">Custom image</span>
                            @else
                                <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-200">Fallback image</span>
                            @endif
                        </div>
                        <div class="mt-4 overflow-hidden rounded-md border border-gray-200 dark:border-gray-700">
                            <div class="min-h-56 bg-cover bg-center p-5" style="background-image: linear-gradient(90deg, {{ data_get($homepageStyle, 'overlay_color', '#fff8f4') }}ee 0%, {{ data_get($homepageStyle, 'overlay_color', '#fff8f4') }}bb 48%, transparent 100%), url('{{ $heroPreviewUrl }}'); background-position: {{ data_get($homepageStyle, 'image_position_desktop', 'center') }};">
                                <div class="max-w-sm">
                                    <p class="text-xs font-extrabold uppercase tracking-wide text-red-700">{{ data_get($homepageCopy, 'en.eyebrow') }}</p>
                                    <p class="mt-3 text-2xl font-extrabold leading-tight text-gray-950">{{ data_get($homepageCopy, 'en.title') }}</p>
                                    <p class="mt-2 text-sm font-semibold leading-6 text-gray-700">{{ data_get($homepageCopy, 'en.subtitle') }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <div>
                                <label for="suchak_hero_image" class="{{ $labelClass }}">Upload hero image</label>
                                <input id="suchak_hero_image" type="file" name="suchak_hero_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="{{ $fieldClass }} mt-1">
                            </div>
                            <div>
                                @if ($suchakHeroImagePath !== '')
                                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">Current file</p>
                                    <p class="mt-1 break-all font-mono text-xs text-gray-500 dark:text-gray-400">{{ $suchakHeroImagePath }}</p>
                                    <label class="mt-3 flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                                        <input type="hidden" name="remove_suchak_hero_image" value="0">
                                        <input type="checkbox" name="remove_suchak_hero_image" value="1" class="rounded border-gray-300 text-indigo-600">
                                        Remove custom image and use fallback
                                    </label>
                                @else
                                    <input type="hidden" name="remove_suchak_hero_image" value="0">
                                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">No custom image set</p>
                                    <p class="{{ $helpClass }}">The public Suchak page is currently using the default fallback hero image.</p>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Hero behavior</h3>
                        <label class="mt-4 flex items-start gap-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                            <input type="hidden" name="suchak_hero_registration_form_enabled" value="0">
                            <input type="checkbox" name="suchak_hero_registration_form_enabled" value="1" class="mt-1 rounded border-gray-300 text-indigo-600" @checked($checked('suchak_hero_registration_form_enabled'))>
                            <span>
                                Show Suchak registration form in homepage hero
                                <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">
                                    Default on. Off ठेवल्यास hero मध्ये फक्त “Register as Suchak” CTA button दिसेल.
                                </span>
                            </span>
                        </label>
                    </div>

                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Hero visual controls</h3>
                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            @foreach ($styleColors as $key => $label)
                                <div>
                                    <label for="homepage_style_{{ $key }}" class="{{ $labelClass }}">{{ $label }}</label>
                                    <input id="homepage_style_{{ $key }}" type="color" name="homepage_style[{{ $key }}]" value="{{ data_get($homepageStyle, $key) }}" class="mt-1 h-10 w-full rounded-md border border-gray-300 bg-white p-1 dark:border-gray-600 dark:bg-gray-800">
                                </div>
                            @endforeach
                            <div>
                                <label for="homepage_style_desktop_overlay_opacity" class="{{ $labelClass }}">Desktop overlay opacity</label>
                                <input id="homepage_style_desktop_overlay_opacity" type="number" name="homepage_style[desktop_overlay_opacity]" min="20" max="100" value="{{ data_get($homepageStyle, 'desktop_overlay_opacity', 94) }}" class="{{ $fieldClass }} mt-1">
                            </div>
                            <div>
                                <label for="homepage_style_mobile_overlay_opacity" class="{{ $labelClass }}">Mobile overlay opacity</label>
                                <input id="homepage_style_mobile_overlay_opacity" type="number" name="homepage_style[mobile_overlay_opacity]" min="20" max="100" value="{{ data_get($homepageStyle, 'mobile_overlay_opacity', 92) }}" class="{{ $fieldClass }} mt-1">
                            </div>
                            <div>
                                <label for="homepage_style_image_position_desktop" class="{{ $labelClass }}">Desktop image position</label>
                                <select id="homepage_style_image_position_desktop" name="homepage_style[image_position_desktop]" class="{{ $fieldClass }} mt-1">
                                    @foreach ($imagePositions as $value => $label)
                                        <option value="{{ $value }}" @selected(data_get($homepageStyle, 'image_position_desktop', 'center') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="homepage_style_image_position_mobile" class="{{ $labelClass }}">Mobile image position</label>
                                <select id="homepage_style_image_position_mobile" name="homepage_style[image_position_mobile]" class="{{ $fieldClass }} mt-1">
                                    @foreach ($imagePositions as $value => $label)
                                        <option value="{{ $value }}" @selected(data_get($homepageStyle, 'image_position_mobile', 'center') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="homepage_style_hero_min_height_desktop" class="{{ $labelClass }}">Desktop hero height (vh)</label>
                                <input id="homepage_style_hero_min_height_desktop" type="number" name="homepage_style[hero_min_height_desktop]" min="55" max="100" value="{{ data_get($homepageStyle, 'hero_min_height_desktop', 86) }}" class="{{ $fieldClass }} mt-1">
                            </div>
                            <div>
                                <label for="homepage_style_hero_min_height_mobile" class="{{ $labelClass }}">Mobile hero height (vh)</label>
                                <input id="homepage_style_hero_min_height_mobile" type="number" name="homepage_style[hero_min_height_mobile]" min="60" max="100" value="{{ data_get($homepageStyle, 'hero_min_height_mobile', 84) }}" class="{{ $fieldClass }} mt-1">
                            </div>
                            <div>
                                <label for="homepage_style_hero_blur_px" class="{{ $labelClass }}">Hero image blur (px)</label>
                                <input id="homepage_style_hero_blur_px" type="number" name="homepage_style[hero_blur_px]" min="0" max="12" value="{{ data_get($homepageStyle, 'hero_blur_px', 0) }}" class="{{ $fieldClass }} mt-1">
                            </div>
                            <div>
                                <label for="homepage_style_form_card_opacity" class="{{ $labelClass }}">Form card opacity</label>
                                <input id="homepage_style_form_card_opacity" type="number" name="homepage_style[form_card_opacity]" min="60" max="100" value="{{ data_get($homepageStyle, 'form_card_opacity', 94) }}" class="{{ $fieldClass }} mt-1">
                            </div>
                            <label class="flex items-start gap-2 rounded-md border border-gray-200 p-3 text-sm font-medium text-gray-800 dark:border-gray-700 dark:text-gray-100">
                                <input type="hidden" name="homepage_style[bottom_fade_enabled]" value="0">
                                <input type="checkbox" name="homepage_style[bottom_fade_enabled]" value="1" class="mt-1 rounded border-gray-300 text-indigo-600" @checked($nestedChecked('homepage_style.bottom_fade_enabled', (bool) data_get($homepageStyle, 'bottom_fade_enabled', true)))>
                                <span>Show bottom fade</span>
                            </label>
                            <label class="flex items-start gap-2 rounded-md border border-gray-200 p-3 text-sm font-medium text-gray-800 dark:border-gray-700 dark:text-gray-100">
                                <input type="hidden" name="homepage_style[form_shadow_enabled]" value="0">
                                <input type="checkbox" name="homepage_style[form_shadow_enabled]" value="1" class="mt-1 rounded border-gray-300 text-indigo-600" @checked($nestedChecked('homepage_style.form_shadow_enabled', (bool) data_get($homepageStyle, 'form_shadow_enabled', true)))>
                                <span>Show form card shadow</span>
                            </label>
                            <div>
                                <label for="homepage_style_bottom_fade_height_rem" class="{{ $labelClass }}">Bottom fade height (rem)</label>
                                <input id="homepage_style_bottom_fade_height_rem" type="number" name="homepage_style[bottom_fade_height_rem]" min="0" max="16" value="{{ data_get($homepageStyle, 'bottom_fade_height_rem', 7) }}" class="{{ $fieldClass }} mt-1">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-5">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Hero and section text</h3>
                        <p class="{{ $helpClass }}">Marathi and English are stored separately. Digits remain as typed, so use 0-9.</p>
                        <div class="mt-4 grid gap-5 lg:grid-cols-2">
                            @foreach (['mr' => 'Marathi', 'en' => 'English'] as $locale => $localeLabel)
                                <div class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                    <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $localeLabel }}</h4>
                                    @foreach ($copyFields as $field)
                                        <div>
                                            <label for="homepage_copy_{{ $locale }}_{{ $field['key'] }}" class="{{ $labelClass }}">{{ $field['label'] }}</label>
                                            @if ($field['textarea'])
                                                <textarea id="homepage_copy_{{ $locale }}_{{ $field['key'] }}" name="homepage_copy[{{ $locale }}][{{ $field['key'] }}]" rows="3" class="{{ $fieldClass }} mt-1">{{ data_get($homepageCopy, $locale.'.'.$field['key']) }}</textarea>
                                            @else
                                                <input id="homepage_copy_{{ $locale }}_{{ $field['key'] }}" type="text" name="homepage_copy[{{ $locale }}][{{ $field['key'] }}]" value="{{ data_get($homepageCopy, $locale.'.'.$field['key']) }}" class="{{ $fieldClass }} mt-1">
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Benefit cards</h3>
                        <div class="mt-4 space-y-4">
                            @foreach ($homepageBenefits as $index => $benefit)
                                <div class="rounded-md border border-gray-200 p-4 dark:border-gray-700">
                                    <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Benefit {{ $index + 1 }}</p>
                                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                                        <input type="text" name="homepage_benefits[{{ $index }}][title_mr]" value="{{ data_get($benefit, 'title_mr') }}" class="{{ $fieldClass }}" placeholder="Marathi title">
                                        <input type="text" name="homepage_benefits[{{ $index }}][title_en]" value="{{ data_get($benefit, 'title_en') }}" class="{{ $fieldClass }}" placeholder="English title">
                                        <textarea name="homepage_benefits[{{ $index }}][body_mr]" rows="2" class="{{ $fieldClass }}" placeholder="Marathi body">{{ data_get($benefit, 'body_mr') }}</textarea>
                                        <textarea name="homepage_benefits[{{ $index }}][body_en]" rows="2" class="{{ $fieldClass }}" placeholder="English body">{{ data_get($benefit, 'body_en') }}</textarea>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid gap-5 lg:grid-cols-2">
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Process steps</h3>
                            <div class="mt-4 space-y-3">
                                @foreach ($homepageProcess as $index => $step)
                                    <div class="grid gap-2">
                                        <label class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Step {{ $index + 1 }}</label>
                                        <input type="text" name="homepage_process[{{ $index }}][label_mr]" value="{{ data_get($step, 'label_mr') }}" class="{{ $fieldClass }}" placeholder="Marathi label">
                                        <input type="text" name="homepage_process[{{ $index }}][label_en]" value="{{ data_get($step, 'label_en') }}" class="{{ $fieldClass }}" placeholder="English label">
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Tool labels</h3>
                            <div class="mt-4 space-y-3">
                                @foreach ($homepageTools as $index => $tool)
                                    <div class="grid gap-2">
                                        <label class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Tool {{ $index + 1 }}</label>
                                        <input type="text" name="homepage_tools[{{ $index }}][label_mr]" value="{{ data_get($tool, 'label_mr') }}" class="{{ $fieldClass }}" placeholder="Marathi label">
                                        <input type="text" name="homepage_tools[{{ $index }}][label_en]" value="{{ data_get($tool, 'label_en') }}" class="{{ $fieldClass }}" placeholder="English label">
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="{{ $panelClass }} {{ $activeTab !== 'consent' ? 'hidden' : '' }}">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Consent and SLA</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label for="suchak_consent_whatsapp_privacy_paragraph" class="{{ $labelClass }}">WhatsApp consent privacy paragraph</label>
                    <textarea id="suchak_consent_whatsapp_privacy_paragraph" name="suchak_consent_whatsapp_privacy_paragraph" rows="3" maxlength="700" required class="{{ $fieldClass }} mt-1">{{ old('suchak_consent_whatsapp_privacy_paragraph', $current[$consentWhatsappPrivacyParagraphKey] ?? \App\Modules\Suchak\Services\SuchakPolicyService::DEFAULT_SUCHAK_CONSENT_WHATSAPP_PRIVACY_PARAGRAPH) }}</textarea>
                    <p class="{{ $helpClass }}">Shown in the Suchak WhatsApp consent message before the secure consent link. Keep this customer-friendly; placeholders are not used in this paragraph.</p>
                </div>
                <div>
                    <label for="default_consent_validity_months" class="{{ $labelClass }}">Default consent validity (months)</label>
                    <input id="default_consent_validity_months" type="number" name="default_consent_validity_months" min="1" max="60" value="{{ old('default_consent_validity_months', $current['default_consent_validity_months'] ?? 12) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div>
                    <label for="request_action_sla_hours" class="{{ $labelClass }}">Request action SLA (hours)</label>
                    <input id="request_action_sla_hours" type="number" name="request_action_sla_hours" min="1" max="720" value="{{ old('request_action_sla_hours', $current['request_action_sla_hours'] ?? 48) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div>
                    <label for="collaboration_sla_days" class="{{ $labelClass }}">Collaboration SLA (days)</label>
                    <input id="collaboration_sla_days" type="number" name="collaboration_sla_days" min="1" max="365" value="{{ old('collaboration_sla_days', $current['collaboration_sla_days'] ?? 7) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div class="space-y-3 rounded-md border border-gray-200 p-4 dark:border-gray-700">
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                        <input type="hidden" name="allow_two_year_consent" value="0">
                        <input type="checkbox" name="allow_two_year_consent" value="1" class="rounded border-gray-300 text-indigo-600" @checked($checked('allow_two_year_consent'))>
                        Allow two-year consent
                    </label>
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                        <input type="hidden" name="allow_until_revoked_consent" value="0">
                        <input type="checkbox" name="allow_until_revoked_consent" value="1" class="rounded border-gray-300 text-indigo-600" @checked($checked('allow_until_revoked_consent'))>
                        Allow until-revoked consent
                    </label>
                </div>
            </div>
        </section>

        <section class="{{ $panelClass }} {{ $activeTab !== 'operations' ? 'hidden' : '' }}">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Operational Limits</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label for="pdf_download_limit_per_day" class="{{ $labelClass }}">PDF/QR daily limit</label>
                    <input id="pdf_download_limit_per_day" type="number" name="pdf_download_limit_per_day" min="1" max="10000" value="{{ old('pdf_download_limit_per_day', $current['pdf_download_limit_per_day'] ?? 20) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div>
                    <label for="qr_token_expiry_days" class="{{ $labelClass }}">QR expiry (days)</label>
                    <input id="qr_token_expiry_days" type="number" name="qr_token_expiry_days" min="1" max="365" value="{{ old('qr_token_expiry_days', $current['qr_token_expiry_days'] ?? 30) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div>
                    <label for="suchak_upload_daily_limit" class="{{ $labelClass }}">Upload daily limit</label>
                    <input id="suchak_upload_daily_limit" type="number" name="suchak_upload_daily_limit" min="1" max="10000" value="{{ old('suchak_upload_daily_limit', $current['suchak_upload_daily_limit'] ?? 25) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div>
                    <label for="suchak_active_profile_limit_by_plan" class="{{ $labelClass }}">Fallback active profile limit</label>
                    <input id="suchak_active_profile_limit_by_plan" type="number" name="suchak_active_profile_limit_by_plan" min="0" max="100000" value="{{ old('suchak_active_profile_limit_by_plan', $current['suchak_active_profile_limit_by_plan'] ?? 0) }}" class="{{ $fieldClass }} mt-1">
                    <p class="{{ $helpClass }}">0 keeps plan feature limits authoritative when available.</p>
                </div>
                <div>
                    <label for="suchak_work_area_min_consented_customers" class="{{ $labelClass }}">Work area customer threshold</label>
                    <input id="suchak_work_area_min_consented_customers" type="number" name="suchak_work_area_min_consented_customers" min="1" max="1000" value="{{ old('suchak_work_area_min_consented_customers', $current['suchak_work_area_min_consented_customers'] ?? 4) }}" class="{{ $fieldClass }} mt-1">
                    <p class="{{ $helpClass }}">A work area is earned automatically only when this many customers from that area have valid consent.</p>
                </div>
                <div class="rounded-md border border-gray-200 p-4 dark:border-gray-700 md:col-span-2 lg:col-span-3">
                    <label class="flex items-start gap-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                        <input type="hidden" name="suchak_allow_work_before_admin_approval" value="0">
                        <input type="checkbox" name="suchak_allow_work_before_admin_approval" value="1" class="mt-1 rounded border-gray-300 text-indigo-600" @checked($checked('suchak_allow_work_before_admin_approval'))>
                        <span>
                            Allow Suchak work before admin approval
                            <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">
                                On ठेवल्यास WhatsApp OTP झाल्यानंतर admin review pending असतानाही Suchak dashboard tools वापरता येतील. Off केल्यास admin approval होईपर्यंत operational tools blocked राहतील.
                            </span>
                        </span>
                    </label>
                </div>
                <div class="rounded-md border border-gray-200 p-4 dark:border-gray-700 md:col-span-2 lg:col-span-3">
                    <label class="flex items-start gap-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                        <input type="hidden" name="suchak_auto_publish_on_approval" value="0">
                        <input type="checkbox" name="suchak_auto_publish_on_approval" value="1" class="mt-1 rounded border-gray-300 text-indigo-600" @checked($checked('suchak_auto_publish_on_approval'))>
                        <span>
                            Auto publish approved Suchak publicly
                            <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">
                                Off ठेवले तर approval नंतर public status hidden राहील. On केल्यास approval सोबत public active होईल.
                            </span>
                        </span>
                    </label>
                </div>
            </div>
        </section>

        <section class="{{ $panelClass }} {{ $activeTab !== 'pricing' ? 'hidden' : '' }}">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Pricing and Payment</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <label for="suchak_plan_pricing_mode" class="{{ $labelClass }}">Pricing mode</label>
                    <select id="suchak_plan_pricing_mode" name="suchak_plan_pricing_mode" class="{{ $fieldClass }} mt-1">
                        @foreach ($pricingModes as $value => $label)
                            <option value="{{ $value }}" @selected(old('suchak_plan_pricing_mode', $current['suchak_plan_pricing_mode'] ?? 'manual_catalog') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="suchak_payment_mode" class="{{ $labelClass }}">Platform payment mode</label>
                    <select id="suchak_payment_mode" name="suchak_payment_mode" class="{{ $fieldClass }} mt-1">
                        @foreach ($paymentModes as $value => $label)
                            <option value="{{ $value }}" @selected(old('suchak_payment_mode', $current['suchak_payment_mode'] ?? 'manual_only') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="suchak_free_trial_days" class="{{ $labelClass }}">Free trial days</label>
                    <input id="suchak_free_trial_days" type="number" name="suchak_free_trial_days" min="0" max="365" value="{{ old('suchak_free_trial_days', $current['suchak_free_trial_days'] ?? 0) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div>
                    <label for="suchak_grace_period_days" class="{{ $labelClass }}">Grace period days</label>
                    <input id="suchak_grace_period_days" type="number" name="suchak_grace_period_days" min="0" max="365" value="{{ old('suchak_grace_period_days', $current['suchak_grace_period_days'] ?? 0) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div class="md:col-span-2">
                    <label for="suchak_visit_confirmation_policy_mode" class="{{ $labelClass }}">Visit payout confirmation policy</label>
                    <select id="suchak_visit_confirmation_policy_mode" name="suchak_visit_confirmation_policy_mode" class="{{ $fieldClass }} mt-1">
                        @foreach ($visitConfirmationModes as $value => $label)
                            <option value="{{ $value }}" @selected(old('suchak_visit_confirmation_policy_mode', $current['suchak_visit_confirmation_policy_mode'] ?? 'user_and_admin') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="{{ $helpClass }}">Controls which confirmations are required before a platform visit payout can be qualified.</p>
                </div>
            </div>
        </section>

        <section class="{{ $panelClass }} {{ $activeTab !== 'commission' ? 'hidden' : '' }}">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Commission Rules</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <label for="commission_mode" class="{{ $labelClass }}">Commission mode</label>
                    <select id="commission_mode" name="commission_mode" class="{{ $fieldClass }} mt-1">
                        @foreach ($commissionModes as $value => $label)
                            <option value="{{ $value }}" @selected(old('commission_mode', $current['commission_mode'] ?? 'to_be_discussed') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="commission_default_percent" class="{{ $labelClass }}">Default percent</label>
                    <input id="commission_default_percent" type="number" name="commission_default_percent" min="0" max="100" value="{{ old('commission_default_percent', $current['commission_default_percent'] ?? 0) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div>
                    <label for="commission_default_amount" class="{{ $labelClass }}">Default fixed amount</label>
                    <input id="commission_default_amount" type="number" name="commission_default_amount" min="0" max="10000000" step="0.01" value="{{ old('commission_default_amount', $current['commission_default_amount'] ?? 0) }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div class="flex items-center rounded-md border border-gray-200 p-4 dark:border-gray-700">
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                        <input type="hidden" name="commission_require_ack" value="0">
                        <input type="checkbox" name="commission_require_ack" value="1" class="rounded border-gray-300 text-indigo-600" @checked($checked('commission_require_ack'))>
                        Require commission acknowledgement
                    </label>
                </div>
            </div>
        </section>

        <div class="flex justify-end">
            <button type="submit" class="rounded-md bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
                Save settings
            </button>
        </div>
    </form>
</div>
@endsection
