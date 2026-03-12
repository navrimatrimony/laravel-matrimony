{{-- Phase-5 SSOT: Horoscope. Shared horoscope-engine with dependency rules (nakshatra+charan->rashi; nakshatra->gan,nadi,yoni). --}}
@php $namePrefix = $namePrefix ?? 'horoscope'; @endphp
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Horoscope</h2>
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
</div>
