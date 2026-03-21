<form method="POST" action="{{ route('matrimony.onboarding.store', ['step' => 2]) }}" class="space-y-6">
    @csrf
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('wizard.full_name') }} <span class="text-red-500">*</span></label>
        <input type="text" name="full_name" value="{{ old('full_name', $profile->full_name) }}" required
            class="w-full rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-4 py-3 text-base min-h-[48px] focus:ring-2 focus:ring-indigo-500">
        @error('full_name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div class="border border-gray-200 dark:border-gray-600 rounded-xl p-4 bg-gray-50/80 dark:bg-gray-900/40" x-data="onboardingGender({{ (int) old('gender_id', $profile->gender_id) ?: 'null' }})">
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
        @error('gender_id')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('wizard.date_of_birth') }}</label>
        @php
            $yMin = now()->subYears(60)->format('Y');
            $yMax = now()->subYears(18)->format('Y');
            $dobValue = '';
            $dobRaw = $profile->date_of_birth ?? null;
            if ($dobRaw instanceof \Carbon\CarbonInterface) {
                $dobValue = $dobRaw->format('Y-m-d');
            } elseif (is_string($dobRaw) && trim($dobRaw) !== '') {
                try { $dobValue = \Carbon\Carbon::parse($dobRaw)->format('Y-m-d'); } catch (\Throwable $e) { $dobValue = ''; }
            }
            $dobValue = old('date_of_birth', $dobValue);
        @endphp
        <input type="date" name="date_of_birth" value="{{ $dobValue }}"
            min="{{ $yMin }}-01-01" max="{{ $yMax }}-12-31"
            class="w-full rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-4 py-3 text-base min-h-[48px] focus:ring-2 focus:ring-indigo-500">
        @error('date_of_birth')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    @include('matrimony.profile.wizard.sections.marital_engine', [
        'namePrefix' => '',
        'profile' => $profile,
        'maritalStatuses' => $maritalStatuses ?? collect(),
        'profileMarriages' => $profileMarriages ?? collect(),
        'profileChildren' => $profileChildren ?? collect(),
        'childLivingWithOptions' => $childLivingWithOptions ?? collect(),
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
