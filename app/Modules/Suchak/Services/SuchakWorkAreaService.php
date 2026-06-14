<?php

namespace App\Modules\Suchak\Services;

use App\Models\Location;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Services\Location\LocationFormatterService;

class SuchakWorkAreaService
{
    public function __construct(
        private readonly SuchakPolicyService $policyService,
        private readonly LocationFormatterService $locationFormatter,
    ) {
    }

    /**
     * @return array{
     *     minimum: int,
     *     total_valid_consent_customers: int,
     *     earned_areas: array<int, array{id: int, label: string, customer_count: int, remaining: int, eligible: bool}>,
     *     building_areas: array<int, array{id: int, label: string, customer_count: int, remaining: int, eligible: bool}>,
     *     all_areas: array<int, array{id: int, label: string, customer_count: int, remaining: int, eligible: bool}>
     * }
     */
    public function summary(SuchakAccount $account): array
    {
        $minimum = $this->policyService->workAreaMinimumConsentedCustomers();
        $representations = SuchakProfileRepresentation::query()
            ->withValidConsent()
            ->with('matrimonyProfile.location')
            ->where('suchak_account_id', $account->id)
            ->get();

        $groups = [];

        foreach ($representations as $representation) {
            $profile = $representation->matrimonyProfile;
            if ($profile === null) {
                continue;
            }

            $area = $this->areaForProfile($profile);
            if ($area === null) {
                continue;
            }

            $groups[$area['id']]['id'] = $area['id'];
            $groups[$area['id']]['label'] = $area['label'];
            $groups[$area['id']]['profile_ids'] ??= [];
            $groups[$area['id']]['profile_ids'][(int) $profile->id] = true;
        }

        $areas = collect($groups)
            ->map(function (array $group) use ($minimum): array {
                $count = count($group['profile_ids']);

                return [
                    'id' => (int) $group['id'],
                    'label' => (string) $group['label'],
                    'customer_count' => $count,
                    'remaining' => max(0, $minimum - $count),
                    'eligible' => $count >= $minimum,
                ];
            })
            ->sortByDesc('customer_count')
            ->values();

        return [
            'minimum' => $minimum,
            'total_valid_consent_customers' => $representations->pluck('matrimony_profile_id')->unique()->count(),
            'earned_areas' => $areas->filter(fn (array $area): bool => $area['eligible'])->values()->all(),
            'building_areas' => $areas->reject(fn (array $area): bool => $area['eligible'])->values()->all(),
            'all_areas' => $areas->all(),
        ];
    }

    /**
     * @return array{id: int, label: string}|null
     */
    private function areaForProfile(mixed $profile): ?array
    {
        $geo = method_exists($profile, 'residenceGeoAddressIds')
            ? $profile->residenceGeoAddressIds()
            : [];
        $areaId = (int) ($geo['district_id'] ?? 0);

        if ($areaId <= 0) {
            $areaId = (int) ($profile->location_id ?? 0);
        }

        if ($areaId <= 0) {
            return null;
        }

        $location = Location::query()->find($areaId);
        if ($location === null) {
            return null;
        }

        $label = $this->locationFormatter->formatForLocation($location);
        if ($label === '') {
            $label = $location->localizedName();
        }

        return [
            'id' => $areaId,
            'label' => $label,
        ];
    }
}
