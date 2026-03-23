<?php

namespace App\Services\Location;

use App\Models\City;
use App\Models\State;
use App\Services\LocationSearchService;

/**
 * Maps external geocoder address parts to canonical city/taluka/district/state IDs.
 * Does not treat Nominatim "importance" as ground truth — uses internal matching only.
 */
class CanonicalLocationMatchService
{
    public function __construct(
        private LocationSearchService $locationSearchService
    ) {}

    /**
     * @return array{
     *   success: bool,
     *   confidence: float,
     *   primary: array<string, mixed>|null,
     *   alternatives: array<int, array<string, mixed>>,
     *   reason?: string
     * }
     */
    public function matchFromNominatim(array $nominatim): array
    {
        $addr = $nominatim['address'] ?? [];
        if (! is_array($addr) || $addr === []) {
            return ['success' => false, 'confidence' => 0.0, 'primary' => null, 'alternatives' => [], 'reason' => 'no_address'];
        }

        $country = strtolower(trim((string) ($addr['country_code'] ?? '')));
        if ($country !== '' && $country !== 'in') {
            return ['success' => false, 'confidence' => 0.0, 'primary' => null, 'alternatives' => [], 'reason' => 'unsupported_country'];
        }

        $postcode = preg_replace('/\D/', '', (string) ($addr['postcode'] ?? ''));
        if (strlen($postcode) === 6) {
            $pinCities = City::query()
                ->with(['taluka.district.state'])
                ->where('pincode', $postcode)
                ->orderBy('name')
                ->limit(8)
                ->get();
            if ($pinCities->count() === 1) {
                $city = $pinCities->first();
                $payload = $this->locationSearchService->canonicalPayloadFromCity($city);

                return [
                    'success' => true,
                    'confidence' => 0.92,
                    'primary' => $payload,
                    'alternatives' => [],
                ];
            }
            if ($pinCities->count() > 1) {
                $alts = [];
                foreach ($pinCities as $c) {
                    $alts[] = $this->locationSearchService->canonicalPayloadFromCity($c);
                }

                return [
                    'success' => true,
                    'confidence' => 0.48,
                    'primary' => $alts[0],
                    'alternatives' => array_slice($alts, 1, 2),
                ];
            }
        }

        $state = $this->resolveState($addr);
        if ($state === null) {
            return ['success' => false, 'confidence' => 0.0, 'primary' => null, 'alternatives' => [], 'reason' => 'state_not_found'];
        }

        $place = $this->extractPlaceName($addr);
        if ($place === '') {
            return ['success' => false, 'confidence' => 0.0, 'primary' => null, 'alternatives' => [], 'reason' => 'place_not_found'];
        }

        $query = $place.' '.$state->name;
        $search = $this->locationSearchService->search($query, [(int) $state->id], []);
        $results = $search['results'] ?? [];
        if ($results === []) {
            $search = $this->locationSearchService->search($place, [(int) $state->id], []);
            $results = $search['results'] ?? [];
        }

        if ($results === []) {
            return ['success' => false, 'confidence' => 0.0, 'primary' => null, 'alternatives' => [], 'reason' => 'no_city_match'];
        }

        $districtHint = strtolower(trim((string) ($addr['state_district'] ?? $addr['county'] ?? '')));
        $addrPostcode = preg_replace('/\D/', '', (string) ($addr['postcode'] ?? ''));

        $scored = [];
        foreach ($results as $idx => $row) {
            $score = 0.72;
            $dName = strtolower((string) ($row['district_name'] ?? ''));
            if ($districtHint !== '' && $dName !== '' && (str_contains($dName, $districtHint) || str_contains($districtHint, $dName))) {
                $score += 0.12;
            }
            if (strlen($addrPostcode) === 6 && isset($row['city_id'])) {
                $pin = (string) City::query()->whereKey($row['city_id'])->value('pincode');
                if ($pin !== '' && $pin === $addrPostcode) {
                    $score += 0.15;
                }
            }
            $scored[] = ['row' => $row, 'score' => $score, 'idx' => $idx];
        }

        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = $scored[0];
        $confidence = min(0.95, (float) $top['score']);

        $alternatives = [];
        foreach (array_slice($scored, 1, 3) as $s) {
            if (($s['score'] ?? 0) >= ($top['score'] - 0.08)) {
                $alternatives[] = $s['row'];
            }
        }
        $alternatives = array_slice($alternatives, 0, 2);

        if (count($scored) >= 2 && abs($scored[0]['score'] - $scored[1]['score']) < 0.02) {
            $confidence = min($confidence, 0.55);
            $alternatives = array_slice(array_map(static fn ($s) => $s['row'], $scored), 1, 2);
        }

        return [
            'success' => true,
            'confidence' => $confidence,
            'primary' => $top['row'],
            'alternatives' => $alternatives,
        ];
    }

    /**
     * @param  array<string, mixed>  $addr
     */
    private function resolveState(array $addr): ?State
    {
        $stateName = trim((string) ($addr['state'] ?? ''));
        if ($stateName === '') {
            return null;
        }

        $normalized = strtolower($stateName);
        $state = State::query()->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])->first();
        if ($state) {
            return $state;
        }

        return State::query()
            ->whereRaw('LOWER(name) LIKE ?', ['%'.$normalized.'%'])
            ->orderByRaw('LENGTH(name)')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $addr
     */
    private function extractPlaceName(array $addr): string
    {
        foreach (['city', 'town', 'village', 'city_district', 'county', 'municipality'] as $key) {
            $v = trim((string) ($addr[$key] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }
}
