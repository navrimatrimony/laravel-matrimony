<form method="POST" action="{{ route('matrimony.onboarding.store', ['step' => 2]) }}" class="space-y-6">
    @csrf
    <div data-lv-highlight-wrap data-lv-scroll-target>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('wizard.full_name') }} <span class="text-red-500">*</span></label>
        <input type="text" name="full_name" value="{{ old('full_name', $profile->full_name) }}" required
            class="w-full rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-4 py-3 text-base min-h-[48px] focus:ring-2 focus:ring-indigo-500">
        @error('full_name')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
    </div>

    <div
        data-lv-highlight-wrap
        data-lv-scroll-target
        class="border border-gray-200 dark:border-gray-600 rounded-xl p-4 bg-gray-50/80 dark:bg-gray-900/40"
        x-data="onboardingGender({{ (int) old('gender_id', $profile->gender_id) ?: 'null' }})"
    >
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">{{ __('wizard.gender') }} <span class="text-red-500">*</span></label>
        <input type="hidden" name="gender_id" :value="genderId">
        <span class="hidden bg-blue-600 bg-pink-500" aria-hidden="true"></span>
        <div class="flex rounded-full border border-gray-300 dark:border-gray-600 overflow-hidden bg-gray-100 dark:bg-gray-700/60 min-h-[48px]">
            @foreach($genders as $g)
                @php
                    $isFirst = $loop->first;
                    $isLast = $loop->last;
                    $selectedBg = $g->key === 'male' ? 'bg-blue-600' : 'bg-pink-500';
                @endphp
                @if(!$isFirst)<span class="w-px shrink-0 bg-gray-300 dark:bg-gray-500 self-stretch" aria-hidden="true"></span>@endif
                <button type="button"
                    class="flex-1 py-3 px-4 cursor-pointer transition-all text-base font-semibold border-none outline-none focus:ring-0 {{ $isFirst ? 'rounded-l-full' : '' }} {{ $isLast ? 'rounded-r-full' : '' }} text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200"
                    :class="genderId == {{ $g->id }} ? '{{ $selectedBg }} !text-white' : ''"
                    @click="genderId = {{ $g->id }}">
                    {{ $g->key === 'male' ? __('wizard.male') : __('wizard.female') }}
                </button>
            @endforeach
        </div>
        @error('gender_id')<p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
    </div>

    <div data-lv-highlight-wrap data-lv-scroll-target>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('wizard.date_of_birth') }}</label>
        @php
            $yMin = now()->subYears(60)->format('Y');
            $yMax = now()->subYears(18)->format('Y');
            $dobAnchorYear = (int) now()->subYears(25)->format('Y');
            $dobValue = '';
            $dobRaw = $profile->date_of_birth ?? null;
            if ($dobRaw instanceof \Carbon\CarbonInterface) {
                $dobValue = $dobRaw->format('Y-m-d');
            } elseif (is_string($dobRaw) && trim($dobRaw) !== '') {
                try { $dobValue = \Carbon\Carbon::parse($dobRaw)->format('Y-m-d'); } catch (\Throwable $e) { $dobValue = ''; }
            }
            $dobValue = old('date_of_birth', $dobValue);
            $dobDisplay = '';
            if ($dobValue !== '') {
                try {
                    $dobDisplay = \Carbon\Carbon::parse($dobValue)->format('d/m/Y');
                } catch (\Throwable $e) {
                    $dobDisplay = '';
                }
            }
            // No default date autofill when empty; mask + optional calendar: resources/js/onboarding-dob-picker.js
        @endphp
        <div
            class="flex gap-2 items-stretch"
            data-onboarding-dob-wrap
            data-dob-anchor-year="{{ $dobAnchorYear }}"
            data-dob-min="{{ $yMin }}-01-01"
            data-dob-max="{{ $yMax }}-12-31"
        >
            <input type="hidden" name="date_of_birth" value="{{ $dobValue }}" autocomplete="off" />
            <input
                type="text"
                data-onboarding-dob-display
                value="{{ $dobDisplay }}"
                inputmode="numeric"
                autocomplete="off"
                placeholder="{{ __('onboarding.dob_placeholder') }}"
                class="flex-1 min-w-0 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-4 py-3 text-base min-h-[48px] focus:ring-2 focus:ring-indigo-500 bg-white dark:bg-gray-700"
                aria-describedby="dob-format-hint"
            />
            <button
                type="button"
                data-onboarding-dob-calendar
                class="shrink-0 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-3 min-h-[48px] min-w-[52px] flex items-center justify-center text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 focus:ring-2 focus:ring-indigo-500"
                title="{{ __('onboarding.dob_open_calendar') }}"
                aria-label="{{ __('onboarding.dob_open_calendar') }}"
            >
                <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5a2.25 2.25 0 002.25-2.25m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5a2.25 2.25 0 012.25 2.25v7.5" />
                </svg>
            </button>
        </div>
        <p id="dob-format-hint" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('onboarding.dob_format_hint') }}</p>
        @error('date_of_birth')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
    </div>

    @include('matrimony.profile.wizard.sections.marital_engine', [
        'namePrefix' => '',
        'profile' => $profile,
        'maritalStatuses' => $maritalStatuses ?? collect(),
        'profileMarriages' => $profileMarriages ?? collect(),
        'profileChildren' => $profileChildren ?? collect(),
        'childLivingWithOptions' => $childLivingWithOptions ?? collect(),
        'hideStatusDetailsOptional' => true,
    ])

    <x-onboarding.form-footer />
</form>

<script>
document.addEventListener('alpine:init', function () {
    Alpine.data('onboardingGender', function (initial) {
        return {
            genderId: initial != null ? parseInt(initial, 10) : null,
        };
    });
});
</script>
