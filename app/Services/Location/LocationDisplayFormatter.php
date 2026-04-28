<?php

namespace App\Services\Location;

use App\Models\City;
use App\Models\CityDisplayMeta;
use App\Models\Country;
use App\Models\District;
use App\Models\State;
use App\Models\Taluka;
use Illuminate\Support\Facades\Schema;

/**
 * Step 5 — Human-readable location lines + optional {@see CityDisplayMeta} overrides.
 *
 * Population-based heuristics deliberately omitted (plan: core correctness first).
 */
final class LocationDisplayFormatter
{
    public function formatCityLine(?City $city): string
    {
        if ($city === null) {
            return '';
        }

        $city->loadMissing(['taluka.district.state.country']);
        if (Schema::hasColumn('cities', 'parent_city_id')) {
            $city->loadMissing(['parentCity']);
        }

        $meta = $this->metaForCity($city);

        $country = $city->taluka?->district?->state?->country;
        $hideIndiaDefault = $country instanceof Country && strtoupper((string) $country->iso_alpha2) === 'IN';

        $segments = $this->buildSegments($city, $meta);

        return $this->composeSegments($segments, $meta, $hideIndiaDefault);
    }

    private function metaForCity(City $city): ?CityDisplayMeta
    {
        if (! Schema::hasTable('city_display_meta')) {
            return null;
        }

        if ($city->relationLoaded('displayMeta')) {
            return $city->displayMeta;
        }

        return CityDisplayMeta::query()->where('city_id', $city->id)->first();
    }

    /**
     * @return array<int, array{type: string, label: string}>
     */
    private function buildSegments(City $city, ?CityDisplayMeta $meta): array
    {
        $taluka = $city->taluka;
        $district = $taluka?->district;
        $state = $district?->state;
        $country = $state?->country;

        if ($taluka === null || $district === null || $state === null) {
            return [['type' => 'locality', 'label' => $this->cityLabel($city)]];
        }

        if ($meta && $meta->is_district_hq === true) {
            return $this->segmentsCityStateCountry($city, $state, $country);
        }

        if (Schema::hasColumn('cities', 'parent_city_id') && $city->parent_city_id && $city->parentCity) {
            return $this->segmentsParentMetro($city, $state, $country);
        }

        $cn = $this->normName((string) $city->name);
        $dn = $this->normName((string) $district->name);
        $tn = $this->normName((string) $taluka->name);

        $hqByName = $cn !== '' && $cn === $dn;

        if ($hqByName && $meta && $meta->is_district_hq === false) {
            return $this->segmentsVillageChain($city, $taluka, $district, $state, $country);
        }

        if ($hqByName) {
            return $this->segmentsCityStateCountry($city, $state, $country);
        }

        if ($tn !== '' && $cn === $tn && $cn !== $dn) {
            return $this->segmentsTalukaDistrictCountry($taluka, $district, $country);
        }

        if ($cn !== '' && $cn !== $dn) {
            return $this->segmentsVillageChain($city, $taluka, $district, $state, $country);
        }

        return $this->segmentsCityStateCountry($city, $state, $country);
    }

    /**
     * @return array<int, array{type: string, label: string}>
     */
    private function segmentsCityStateCountry(City $city, State $state, ?Country $country): array
    {
        $out = [
            ['type' => 'locality', 'label' => $this->cityLabel($city)],
            ['type' => 'state', 'label' => $this->stateLabel($state)],
        ];
        if ($country instanceof Country) {
            $out[] = ['type' => 'country', 'label' => $this->countryLabel($country)];
        }

        return $out;
    }

    /**
     * @return array<int, array{type: string, label: string}>
     */
    private function segmentsParentMetro(City $city, State $state, ?Country $country): array
    {
        $parent = $city->parentCity;
        if ($parent === null) {
            return [['type' => 'locality', 'label' => $this->cityLabel($city)]];
        }

        $out = [
            ['type' => 'locality', 'label' => $this->cityLabel($city)],
            ['type' => 'parent_city', 'label' => $this->cityLabel($parent)],
            ['type' => 'state', 'label' => $this->stateLabel($state)],
        ];
        if ($country instanceof Country) {
            $out[] = ['type' => 'country', 'label' => $this->countryLabel($country)];
        }

        return $out;
    }

    /**
     * @return array<int, array{type: string, label: string}>
     */
    private function segmentsTalukaDistrictCountry(Taluka $taluka, District $district, ?Country $country): array
    {
        $out = [
            ['type' => 'taluka', 'label' => $this->talukaLabel($taluka)],
            ['type' => 'district', 'label' => $this->districtLabel($district)],
        ];
        if ($country instanceof Country) {
            $out[] = ['type' => 'country', 'label' => $this->countryLabel($country)];
        }

        return $out;
    }

    /**
     * @return array<int, array{type: string, label: string}>
     */
    private function segmentsVillageChain(City $city, Taluka $taluka, District $district, State $state, ?Country $country): array
    {
        $out = [
            ['type' => 'locality', 'label' => $this->cityLabel($city)],
            ['type' => 'taluka', 'label' => $this->talukaLabel($taluka)],
            ['type' => 'district', 'label' => $this->districtLabel($district)],
            ['type' => 'state', 'label' => $this->stateLabel($state)],
        ];
        if ($country instanceof Country) {
            $out[] = ['type' => 'country', 'label' => $this->countryLabel($country)];
        }

        return $out;
    }

    /**
     * @param  array<int, array{type: string, label: string}>  $segments
     */
    private function composeSegments(array $segments, ?CityDisplayMeta $meta, bool $hideIndiaDefault): string
    {
        $filtered = [];
        foreach ($segments as $seg) {
            $type = $seg['type'];
            if ($type === 'state' && $meta && $meta->hide_state === true) {
                continue;
            }
            if ($type === 'country') {
                if ($meta && $meta->hide_country === true) {
                    continue;
                }
                if ($meta && $meta->hide_country === false) {
                    $filtered[] = $seg['label'];

                    continue;
                }
                if ($hideIndiaDefault) {
                    continue;
                }
                $filtered[] = $seg['label'];

                continue;
            }
            $filtered[] = $seg['label'];
        }

        return $this->joinUniqueLabels($filtered);
    }

    /**
     * @param  list<string>  $labels
     */
    private function joinUniqueLabels(array $labels): string
    {
        $out = [];
        $seen = [];
        foreach ($labels as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            $k = $this->normName($t);
            if ($k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $t;
        }

        return implode(', ', $out);
    }

    private function normName(string $s): string
    {
        $s = trim(preg_replace('/\s+City$/iu', '', $s) ?? $s);

        return mb_strtolower($s);
    }

    private function cityLabel(City $city): string
    {
        $en = trim((string) $city->name);

        return $this->useMr() && filled($city->name_mr) ? trim((string) $city->name_mr) : $en;
    }

    private function talukaLabel(Taluka $t): string
    {
        $en = trim((string) $t->name);

        return $this->useMr() && filled($t->name_mr) ? trim((string) $t->name_mr) : $en;
    }

    private function districtLabel(District $d): string
    {
        $en = trim((string) $d->name);

        return $this->useMr() && filled($d->name_mr) ? trim((string) $d->name_mr) : $en;
    }

    private function stateLabel(State $s): string
    {
        $en = trim((string) $s->name);

        return $this->useMr() && filled($s->name_mr) ? trim((string) $s->name_mr) : $en;
    }

    private function countryLabel(Country $c): string
    {
        $en = trim((string) $c->name);

        return $this->useMr() && filled($c->name_mr) ? trim((string) $c->name_mr) : $en;
    }

    private function useMr(): bool
    {
        return app()->getLocale() === 'mr';
    }
}
