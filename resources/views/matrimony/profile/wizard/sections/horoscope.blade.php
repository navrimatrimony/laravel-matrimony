{{-- Phase-5 SSOT: Horoscope. Shared horoscope-engine with dependency rules (nakshatra+charan->rashi; nakshatra->gan,nadi,yoni). --}}
@php $namePrefix = $namePrefix ?? 'horoscope'; @endphp
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Horoscope & Religious Details</h2>
    @php
        $h = old('horoscope', $profile_horoscope_data ?? new \stdClass());
        $hRow = is_object($h) ? (array) $h : $h;
        $dependencyWarnings = $dependencyWarnings ?? [];
        $birthWeekdayExpected = $birthWeekdayExpected ?? null;
        if (empty($hRow['birth_weekday']) && !empty($birthWeekdayExpected)) {
            $hRow['birth_weekday'] = $birthWeekdayExpected;
        }
    @endphp
    <x-profile.horoscope-engine
        :row="$hRow"
        :rashis="$rashis ?? collect()"
        :nakshatras="$nakshatras ?? collect()"
        :gans="$gans ?? collect()"
        :nadis="$nadis ?? collect()"
        :yonis="$yonis ?? collect()"
        :varnas="$varnas ?? collect()"
        :vashyas="$vashyas ?? collect()"
        :rashiLords="$rashiLords ?? collect()"
        :mangalDoshTypes="$mangalDoshTypes ?? collect()"
        :horoscope-rules-json="$horoscopeRulesJson ?? ['rashi_rules' => [], 'nakshatra_attributes' => []]"
        :rashi-ashtakoota-json="$rashiAshtakootaJson ?? []"
        :name-prefix="$namePrefix ?? 'horoscope'"
        mode="wizard"
        :dependencyWarnings="$dependencyWarnings"
        :birth-weekday-expected="$birthWeekdayExpected"
    />

    {{-- Stored on profile_preference_criteria.gunamilan_required — it is a
         requirement for the PARTNER, but asked here because this is the screen
         where the user is already thinking about their patrika. Default OFF:
         the hidden 0 makes an unchecked box post an explicit false instead of
         vanishing from the request. --}}
    @php $gunamilanRequired = (bool) old('gunamilan_required', $gunamilanRequired ?? false); @endphp
    <div class="rounded-lg border border-gray-200 dark:border-gray-600 p-4">
        <label class="flex items-start gap-3 cursor-pointer">
            <input type="hidden" name="gunamilan_required" value="0">
            <input
                type="checkbox"
                name="gunamilan_required"
                value="1"
                @checked($gunamilanRequired)
                class="mt-1 rounded border-gray-300 dark:border-gray-600 text-indigo-600"
            >
            <span>
                <span class="block font-medium text-gray-900 dark:text-gray-100">
                    {{ __('profile.gunamilan_required_label') }}
                </span>
                <span class="block text-sm text-gray-600 dark:text-gray-400">
                    {{ __('profile.gunamilan_required_help') }}
                </span>
            </span>
        </label>
    </div>
</div>
