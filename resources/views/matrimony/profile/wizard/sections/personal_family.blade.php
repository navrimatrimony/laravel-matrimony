{{-- Education & Career engine (Shaadi-style) + Parent + Family. Section label: Education, Career & Family. --}}
@php
    $namePrefix = $namePrefix ?? '';
    $isFullSection = ($currentSection ?? '') === 'full';
    $eduHistory = $isFullSection ? ($profileEducation ?? collect())->map(fn($r) => ['id' => $r->id ?? null, 'degree' => $r->degree ?? '', 'specialization' => $r->specialization ?? '', 'university' => $r->university ?? '', 'year_completed' => $r->year_completed ?? 0])->values()->all() : [];
    $careerHist = $isFullSection ? ($profileCareer ?? collect())->map(fn($r) => ['id' => $r->id ?? null, 'designation' => $r->designation ?? '', 'company' => $r->company ?? '', 'location' => $r->location ?? '', 'city_id' => $r->city_id ?? null, 'start_year' => $r->start_year ?? null, 'end_year' => $r->end_year ?? null, 'is_current' => !empty($r->is_current)])->values()->all() : [];
    if ($isFullSection && $careerHist === []) {
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
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Education, Career & Family</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2">
            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-2">Education and Career</h3>
            <x-education-occupation-income-engine
                :profile="$profile"
                :currencies="$currencies ?? []"
                :mode="$isFullSection ? 'full' : 'compact'"
                :showHistory="$isFullSection"
                :educationHistory="$eduHistory"
                :careerHistory="$careerHist"
                :namePrefix="$namePrefix"
            />
        </div>
        <div class="md:col-span-2">
            <x-parent-engine
                :profile="$profile"
                :currencies="$currencies ?? []"
                :errors="$errors ?? []"
                :read-only="false"
                :namePrefix="$namePrefix"
            />
        </div>
        <div class="md:col-span-2">
            <x-family-overview
                :profile="$profile"
                :currencies="$currencies ?? []"
                :errors="$errors ?? []"
                :namePrefix="$namePrefix"
            />
        </div>
    </div>
</div>
