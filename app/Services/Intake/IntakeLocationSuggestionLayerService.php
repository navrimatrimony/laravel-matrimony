<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\City;
use App\Services\LocationSearchService;

/**
 * Step 4.5 (UI-only): candidate options for unresolved intake location text + explicit resolve into approval snapshot.
 * No DB schema changes and no new suggestion rows are created here.
 */
class IntakeLocationSuggestionLayerService
{
    public function __construct(
        private LocationSearchService $locationSearch,
    ) {}

    /**
     * @return array<int, array{field_key: string, label: string, raw_input: string, options: array<int, array<string, mixed>>}>
     */
    public function unresolvedCandidates(BiodataIntake $intake, int $limit = 7): array
    {
        $snapshot = $this->snapshotForEdit($intake);
        $out = [];

        $core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];

        $birthRaw = trim((string) ($core['birth_place_text'] ?? $core['birth_place'] ?? ''));
        if (($core['birth_city_id'] ?? null) === null && $birthRaw !== '') {
            $out[] = $this->entry('birth_place', 'Birth place', $birthRaw, $this->search($birthRaw, $limit));
        }

        $nativeRaw = trim((string) (($snapshot['native_place']['raw'] ?? $snapshot['native_place']['address_line'] ?? $core['native_place'] ?? '')));
        if (($core['native_city_id'] ?? null) === null && $nativeRaw !== '') {
            $out[] = $this->entry('native_place', 'Native place', $nativeRaw, $this->search($nativeRaw, $limit));
        }

        $workRaw = trim((string) ($core['work_location_text'] ?? ''));
        if (($core['work_city_id'] ?? null) === null && $workRaw !== '') {
            $out[] = $this->entry('work_location', 'Work location', $workRaw, $this->search($workRaw, $limit));
        }

        $addresses = is_array($snapshot['addresses'] ?? null) ? $snapshot['addresses'] : [];
        foreach ($addresses as $i => $addr) {
            if (! is_array($addr)) {
                continue;
            }
            if (! empty($addr['city_id'])) {
                continue;
            }
            $raw = trim((string) ($addr['city'] ?? $addr['place'] ?? $addr['location'] ?? $addr['village'] ?? $addr['address_line'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $out[] = $this->entry("addresses.{$i}", 'Address #'.($i + 1), $raw, $this->search($raw, $limit));
        }

        return $out;
    }

    /**
     * Resolve one unresolved field into intake.approval_snapshot_json by selected city.
     *
     * @return array{ok: bool, message?: string}
     */
    public function resolveFieldToCity(BiodataIntake $intake, string $fieldKey, int $cityId): array
    {
        $city = City::query()->with(['taluka.district.state'])->find($cityId);
        if ($city === null) {
            return ['ok' => false, 'message' => 'Selected city not found.'];
        }

        $snapshot = $this->snapshotForEdit($intake);
        if (! is_array($snapshot['core'] ?? null)) {
            $snapshot['core'] = [];
        }
        $core = &$snapshot['core'];

        $districtId = $city->taluka?->parent_id;
        $stateId = $city->taluka?->district?->parent_id;
        $countryId = $city->taluka?->district?->state?->parent_id;

        if ($fieldKey === 'birth_place') {
            if (! empty($core['birth_city_id'])) {
                return ['ok' => false, 'message' => 'Birth place is already resolved.'];
            }
            $core['birth_city_id'] = (int) $city->id;
            if (! is_array($snapshot['birth_place'] ?? null)) {
                $snapshot['birth_place'] = [];
            }
            $snapshot['birth_place']['city_id'] = (int) $city->id;
            $snapshot['birth_place']['taluka_id'] = $city->parent_id !== null ? (int) $city->parent_id : null;
            $snapshot['birth_place']['district_id'] = $districtId !== null ? (int) $districtId : null;
            $snapshot['birth_place']['state_id'] = $stateId !== null ? (int) $stateId : null;
        } elseif ($fieldKey === 'native_place') {
            if (! empty($core['native_city_id'])) {
                return ['ok' => false, 'message' => 'Native place is already resolved.'];
            }
            $core['native_city_id'] = (int) $city->id;
            $core['native_taluka_id'] = $city->parent_id !== null ? (int) $city->parent_id : null;
            $core['native_district_id'] = $districtId !== null ? (int) $districtId : null;
            $core['native_state_id'] = $stateId !== null ? (int) $stateId : null;
            if (! is_array($snapshot['native_place'] ?? null)) {
                $snapshot['native_place'] = [];
            }
            $snapshot['native_place']['city_id'] = (int) $city->id;
            $snapshot['native_place']['taluka_id'] = $core['native_taluka_id'];
            $snapshot['native_place']['district_id'] = $core['native_district_id'];
            $snapshot['native_place']['state_id'] = $core['native_state_id'];
        } elseif ($fieldKey === 'work_location') {
            if (! empty($core['work_city_id'])) {
                return ['ok' => false, 'message' => 'Work location is already resolved.'];
            }
            $core['work_city_id'] = (int) $city->id;
            $core['work_state_id'] = $stateId !== null ? (int) $stateId : null;
            if (is_array($snapshot['career_history'] ?? null) && isset($snapshot['career_history'][0]) && is_array($snapshot['career_history'][0])) {
                $snapshot['career_history'][0]['city_id'] = (int) $city->id;
            }
        } elseif (str_starts_with($fieldKey, 'addresses.')) {
            $parts = explode('.', $fieldKey);
            $idx = isset($parts[1]) ? (int) $parts[1] : -1;
            if (! is_array($snapshot['addresses'] ?? null) || ! isset($snapshot['addresses'][$idx]) || ! is_array($snapshot['addresses'][$idx])) {
                return ['ok' => false, 'message' => 'Address row not found in snapshot.'];
            }
            if (! empty($snapshot['addresses'][$idx]['city_id'])) {
                return ['ok' => false, 'message' => 'Address row is already resolved.'];
            }
            $snapshot['addresses'][$idx]['city_id'] = (int) $city->id;
            $snapshot['addresses'][$idx]['taluka_id'] = $city->parent_id !== null ? (int) $city->parent_id : null;
            $snapshot['addresses'][$idx]['district_id'] = $districtId !== null ? (int) $districtId : null;
            $snapshot['addresses'][$idx]['state_id'] = $stateId !== null ? (int) $stateId : null;
            $snapshot['addresses'][$idx]['country_id'] = $countryId !== null ? (int) $countryId : null;
        } else {
            return ['ok' => false, 'message' => 'Unsupported location field.'];
        }

        $intake->approval_snapshot_json = $snapshot;
        $intake->save();

        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotForEdit(BiodataIntake $intake): array
    {
        $approval = $intake->approval_snapshot_json;
        if (is_array($approval) && $approval !== []) {
            return $approval;
        }
        $parsed = $intake->parsed_json;
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function search(string $raw, int $limit): array
    {
        $res = $this->locationSearch->search($raw, [], [], true);
        $rows = is_array($res['results'] ?? null) ? $res['results'] : [];

        return array_slice(array_values($rows), 0, max(1, $limit));
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     * @return array{field_key: string, label: string, raw_input: string, options: array<int, array<string, mixed>>}
     */
    private function entry(string $fieldKey, string $label, string $rawInput, array $options): array
    {
        return [
            'field_key' => $fieldKey,
            'label' => $label,
            'raw_input' => $rawInput,
            'options' => $options,
        ];
    }
}
