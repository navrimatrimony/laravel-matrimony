{{-- Centralized About Me / Extended narrative engine. Wizard page title = “About me”; field labels differ to avoid repetition. --}}
@props([
    'namePrefix' => 'extended_narrative',
    'value' => null,
    'showAdditionalNotes' => false,
    'showTemplates' => true,
    'profile' => null,
])
@php
    use App\Models\MatrimonyProfile;
    use App\Services\AboutMeQuickTemplateService;
    use Illuminate\Support\Str;
    $en = old($namePrefix, $value ?? new \stdClass());
    if (is_object($en)) {
        $narrativeAboutMe = $en->narrative_about_me ?? '';
        $narrativeExpectations = $en->narrative_expectations ?? '';
        $additionalNotes = $en->additional_notes ?? '';
        $hasId = isset($en->id);
        $idVal = $hasId ? $en->id : null;
    } else {
        $narrativeAboutMe = $en['narrative_about_me'] ?? '';
        $narrativeExpectations = $en['narrative_expectations'] ?? '';
        $additionalNotes = $en['additional_notes'] ?? '';
        $hasId = isset($en['id']);
        $idVal = $hasId ? $en['id'] : null;
    }
    $baseName = $namePrefix !== '' ? $namePrefix.'[' : '';
    $endName = $namePrefix !== '' ? ']' : '';
    $uid = Str::random(10);
    $aboutTpl = [];
    if ($showTemplates && $profile instanceof MatrimonyProfile) {
        $aboutTpl = app(AboutMeQuickTemplateService::class)->resolvedAboutTemplatesForProfile($profile);
    }
    if (count($aboutTpl) === 0) {
        $aboutTpl = trans('profile.about_me_quick_templates');
        $aboutTpl = is_array($aboutTpl) ? $aboutTpl : [];
    }
    $expTpl = trans('profile.expectations_quick_templates');
    $expTpl = is_array($expTpl) ? $expTpl : [];
    $templatePayload = ['about' => $aboutTpl, 'expectations' => $expTpl];
    $chipCls = 'inline-flex max-w-none shrink-0 items-center whitespace-nowrap rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-800 hover:bg-indigo-100 dark:border-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-200 dark:hover:bg-indigo-900/50 cursor-pointer transition-colors';
@endphp
@if ($hasId && $idVal)
    <input type="hidden" name="{{ $baseName }}id{{ $endName }}" value="{{ $idVal }}">
@endif
<div class="space-y-8 about-me-narrative-engine" data-narrative-templates-root>
    @if ($showTemplates && (count($aboutTpl) > 0 || count($expTpl) > 0))
        <script type="application/json" data-narrative-json>@json($templatePayload)</script>
        <p class="text-sm text-gray-600 dark:text-gray-400 -mt-1">{{ __('profile.suggestion_templates_hint') }}</p>
    @endif

    <div class="space-y-3">
        <label class="block text-sm font-medium text-gray-800 dark:text-gray-200" for="narrative-about-{{ $uid }}">{{ __('profile.narrative_intro_label') }}</label>
        @if ($showTemplates && count($aboutTpl) > 0)
            <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1 [scrollbar-width:thin]">
                @foreach ($aboutTpl as $idx => $tpl)
                    @php $label = is_array($tpl) ? ($tpl['label'] ?? '#'.($idx + 1)) : (string) $tpl; @endphp
                    <button
                        type="button"
                        class="{{ $chipCls }}"
                        data-narrative-template
                        data-narrative-group="about"
                        data-narrative-index="{{ $idx }}"
                        data-narrative-target="narrative-about-{{ $uid }}"
                        title="{{ __('profile.template_replaces_text') }}"
                    >{{ $label }}</button>
                @endforeach
            </div>
        @endif
        <textarea
            id="narrative-about-{{ $uid }}"
            name="{{ $baseName }}narrative_about_me{{ $endName }}"
            rows="5"
            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2"
            placeholder="{{ __('profile.narrative_about_me_placeholder') }}"
        >{{ $narrativeAboutMe }}</textarea>
    </div>

    <div class="space-y-3 pt-2 border-t border-gray-100 dark:border-gray-700">
        <label class="block text-sm font-medium text-gray-800 dark:text-gray-200" for="narrative-expectations-{{ $uid }}">{{ __('profile.expectations') }}</label>
        @if ($showTemplates && count($expTpl) > 0)
            <div class="flex flex-wrap gap-2">
                @foreach ($expTpl as $idx => $tpl)
                    @php $label = is_array($tpl) ? ($tpl['label'] ?? '#'.($idx + 1)) : (string) $tpl; @endphp
                    <button
                        type="button"
                        class="{{ $chipCls }}"
                        data-narrative-template
                        data-narrative-group="expectations"
                        data-narrative-index="{{ $idx }}"
                        data-narrative-target="narrative-expectations-{{ $uid }}"
                        title="{{ __('profile.template_replaces_text') }}"
                    >{{ $label }}</button>
                @endforeach
            </div>
        @endif
        <textarea
            id="narrative-expectations-{{ $uid }}"
            name="{{ $baseName }}narrative_expectations{{ $endName }}"
            rows="5"
            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2"
            placeholder="{{ __('profile.expectations_placeholder') }}"
        >{{ $narrativeExpectations }}</textarea>
    </div>

    @if ($showAdditionalNotes)
        <div class="space-y-1 pt-2 border-t border-gray-100 dark:border-gray-700">
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">{{ __('profile.additional_details') }}</label>
            <textarea name="{{ $baseName }}additional_notes{{ $endName }}" rows="2" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2" placeholder="{{ __('intake.additional_notes_placeholder') }}">{{ $additionalNotes }}</textarea>
        </div>
    @endif
</div>
