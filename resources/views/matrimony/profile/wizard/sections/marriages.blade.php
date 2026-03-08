{{-- Marriages section: uses centralized MaritalEngine (sagalikade — wizard step "marriages" and "full" both use this). --}}
@php
    $maritalStatuses = $maritalStatuses ?? collect();
    $profileMarriages = $profileMarriages ?? collect();
    $profileChildren = $profileChildren ?? collect();
    $childLivingWithOptions = $childLivingWithOptions ?? collect();
@endphp
<div class="space-y-4">
    @include('matrimony.profile.wizard.sections.marital_engine', [
        'showMaritalStatus' => false,
        'maritalStatuses' => $maritalStatuses,
        'profileMarriages' => $profileMarriages,
        'profileChildren' => $profileChildren,
        'childLivingWithOptions' => $childLivingWithOptions,
    ])
</div>
