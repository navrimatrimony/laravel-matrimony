{{-- Education & Career only (separate tab). Family details are in family-details section. --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Education & Career</h2>
    @php
        $profileEducation = $profileEducation ?? collect();
        $profileCareer = $profileCareer ?? collect();
        $eduHistory = $profileEducation->map(fn($r) => [
            'id' => $r->id ?? null,
            'degree' => $r->degree ?? '',
            'specialization' => $r->specialization ?? '',
            'university' => $r->university ?? '',
            'year_completed' => $r->year_completed ?? 0,
        ])->values()->all();
        $careerHist = $profileCareer->map(fn($r) => [
            'id' => $r->id ?? null,
            'designation' => $r->designation ?? '',
            'company' => $r->company ?? '',
            'location' => $r->location ?? '',
            'city_id' => $r->city_id ?? null,
            'start_year' => $r->start_year ?? null,
            'end_year' => $r->end_year ?? null,
            'is_current' => !empty($r->is_current),
        ])->values()->all();
        if ($careerHist === []) {
            $careerHist = [[
                'id' => null,
                'designation' => '',
                'company' => '',
                'location' => '',
                'city_id' => null,
                'start_year' => null,
                'end_year' => null,
                'is_current' => false,
            ]];
        }
    @endphp
    <x-education-occupation-income-engine
        :profile="$profile"
        :currencies="$currencies ?? []"
        mode="compact"
        :showHistory="true"
        :educationHistory="$eduHistory"
        :careerHistory="$careerHist"
    />
</div>
