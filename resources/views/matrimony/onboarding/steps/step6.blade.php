@php
    use Illuminate\Support\Str;
    $en = old('extended_narrative', $extendedAttrs ?? new \stdClass());
    $narrativeAboutMe = is_object($en) ? ($en->narrative_about_me ?? '') : ($en['narrative_about_me'] ?? '');
    $aboutTpl = $aboutMeQuickTemplates ?? [];
    if (! is_array($aboutTpl) || count($aboutTpl) === 0) {
        $aboutTpl = [['label' => '—', 'text' => '']];
    }
    $uid = Str::random(10);
    $chipCls = 'inline-flex max-w-none shrink-0 items-center whitespace-nowrap rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-800 hover:bg-indigo-100 dark:border-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-200 dark:hover:bg-indigo-900/50 cursor-pointer transition-colors';
@endphp

<form method="POST" action="{{ route('matrimony.onboarding.store', ['step' => 6]) }}" class="space-y-6">
    @csrf

    <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-900/20 p-4 sm:p-5 space-y-3">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('onboarding.step6_hint') }}
        </p>

        <div class="space-y-3">
            <label class="block text-sm font-medium text-gray-800 dark:text-gray-200" for="narrative-about-{{ $uid }}">{{ __('profile.narrative_intro_label') }}</label>
            @if (count($aboutTpl) > 0)
                <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1 [scrollbar-width:thin]">
                    @foreach ($aboutTpl as $idx => $tpl)
                        @php $label = is_array($tpl) ? ($tpl['label'] ?? '#'.($idx + 1)) : (string) $tpl; @endphp
                        <button type="button" class="{{ $chipCls }}" data-about-template data-about-index="{{ $idx }}" data-about-target="narrative-about-{{ $uid }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                <script type="application/json" data-about-json>@json($aboutTpl)</script>
            @endif
            <textarea
                id="narrative-about-{{ $uid }}"
                name="extended_narrative[narrative_about_me]"
                rows="5"
                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2"
                placeholder="{{ __('profile.narrative_about_me_placeholder') }}"
            >{{ $narrativeAboutMe }}</textarea>
        </div>
    </div>

    <x-onboarding.form-footer
        :back-url="route('matrimony.onboarding.show', ['step' => 5])"
        :submit-label="__('onboarding.continue')"
    />
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var payloadEl = document.querySelector('script[data-about-json]');
    if (!payloadEl) return;
    var templates = [];
    try { templates = JSON.parse(payloadEl.textContent || '[]'); } catch (e) { templates = []; }
    document.querySelectorAll('[data-about-template]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var idx = parseInt(btn.getAttribute('data-about-index') || '-1', 10);
            var target = document.getElementById(btn.getAttribute('data-about-target') || '');
            if (!target || idx < 0 || idx >= templates.length) return;
            var tpl = templates[idx];
            var text = '';
            if (tpl && typeof tpl === 'object' && tpl.text != null) text = String(tpl.text);
            else if (typeof tpl === 'string') text = tpl;
            target.value = text;
        });
    });
});
</script>
