<?php

namespace Database\Seeders\Support;

use App\Models\City;
use App\Models\District;
use App\Models\State;
use App\Models\Taluka;
use App\Models\Village;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * English display names → Marathi labels for states / districts / talukas (location seeders).
 */
final class LocationMarathiLabels
{
    /**
     * @return array<string, string>
     */
    public static function englishToMarathi(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $path = database_path('seeders/data/location_label_mr.php');
        $map = is_readable($path) ? require $path : [];

        return is_array($map) ? $map : [];
    }

    /**
     * @return array<string, string>
     */
    public static function indiaStateEnglishToMarathi(): array
    {
        static $merged = null;
        if ($merged !== null) {
            return $merged;
        }
        $path = database_path('seeders/data/state_name_mr_india.php');
        $india = is_readable($path) ? require $path : [];
        $india = is_array($india) ? $india : [];
        $merged = array_merge(self::englishToMarathi(), $india);

        return $merged;
    }

    /**
     * Fix empty, wrong-encoding, or outdated {@code states.name_mr} for India using packaged UTF-8 strings.
     */
    public static function syncIndianStateNameMr(): void
    {
        $map = self::indiaStateEnglishToMarathi();
        foreach (State::query()->whereHas('country', fn ($q) => $q->where('iso_alpha2', 'IN'))->cursor() as $state) {
            $name = trim((string) $state->name);
            if ($name === '') {
                continue;
            }
            $mr = $map[$name] ?? null;
            if ($mr === null || trim($mr) === '') {
                continue;
            }
            if ((string) $state->name_mr !== $mr) {
                $state->name_mr = $mr;
                $state->save();
            }
        }
    }

    /**
     * Packaged census-style geo: English district name → Marathi (UTF-8).
     * File is {@code database/seeders/data/geo/districts.json} (currently Maharashtra set).
     *
     * @return array<string, string>
     */
    public static function districtEnglishToMarathiFromGeoJson(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        $path = database_path('seeders/data/geo/districts.json');
        if (! is_readable($path)) {
            return $map;
        }
        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows)) {
            return $map;
        }
        foreach ($rows as $row) {
            $en = trim((string) ($row['districtnameenglish'] ?? ''));
            $mr = trim((string) ($row['districtlocalname'] ?? ''));
            if ($en === '' || $mr === '') {
                continue;
            }
            $map[$en] = $mr;
        }

        return $map;
    }

    /**
     * English district name → Marathi: geo JSON wins over {@see englishToMarathi()} on key clash.
     *
     * @return array<string, string>
     */
    public static function indiaDistrictEnglishToMarathi(): array
    {
        static $merged = null;
        if ($merged !== null) {
            return $merged;
        }
        $merged = array_merge(self::englishToMarathi(), self::districtEnglishToMarathiFromGeoJson());

        return $merged;
    }

    /**
     * Overwrites {@code districts.name_mr} for India using packaged UTF-8 maps (fixes NULL, mojibake, wrong encodings).
     * Only updates rows whose English {@code name} exists in the merged map; other districts are left unchanged.
     */
    public static function syncIndianDistrictNameMr(): void
    {
        $map = self::indiaDistrictEnglishToMarathi();
        foreach (District::query()->whereHas('state', fn ($q) => $q->whereHas('country', fn ($c) => $c->where('iso_alpha2', 'IN')))->cursor() as $district) {
            $name = trim((string) $district->name);
            if ($name === '') {
                continue;
            }
            $mr = $map[$name] ?? null;
            if ($mr === null || trim($mr) === '') {
                continue;
            }
            $mr = trim($mr);
            if ((string) $district->name_mr !== $mr) {
                $district->name_mr = $mr;
                $district->save();
            }
        }
    }

    private const TALUKA_COMPOSITE_SEP = "\x1E";

    /**
     * {@code districtEnglish . SEP . talukaEnglish} → Marathi (UTF-8) from {@code geo/talukas.json} + {@code geo/districts.json}.
     *
     * @return array<string, string>
     */
    public static function indiaTalukaCompositeKeyToMarathi(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        $dCodeToName = [];
        $dPath = database_path('seeders/data/geo/districts.json');
        if (is_readable($dPath)) {
            $dRows = json_decode((string) file_get_contents($dPath), true);
            if (is_array($dRows)) {
                foreach ($dRows as $row) {
                    $code = trim((string) ($row['districtcode'] ?? ''));
                    $name = trim((string) ($row['districtnameenglish'] ?? ''));
                    if ($code !== '' && $name !== '') {
                        $dCodeToName[$code] = $name;
                    }
                }
            }
        }
        $tPath = database_path('seeders/data/geo/talukas.json');
        if (! is_readable($tPath)) {
            return $map;
        }
        $tRows = json_decode((string) file_get_contents($tPath), true);
        if (! is_array($tRows)) {
            return $map;
        }
        foreach ($tRows as $row) {
            $dCode = trim((string) ($row['districtcode'] ?? ''));
            $tEn = trim((string) ($row['subdistrictnameenglish'] ?? ''));
            $tMr = trim((string) ($row['subdistrictlocalname'] ?? ''));
            $dName = $dCodeToName[$dCode] ?? '';
            if ($dName === '' || $tEn === '' || $tMr === '') {
                continue;
            }
            $map[$dName.self::TALUKA_COMPOSITE_SEP.$tEn] = $tMr;
        }

        return $map;
    }

    /**
     * Overwrites {@code talukas.name_mr} for India (UTF-8 from packaged geo JSON).
     */
    public static function syncIndianTalukaNameMr(): void
    {
        $map = self::indiaTalukaCompositeKeyToMarathi();
        $sep = self::TALUKA_COMPOSITE_SEP;
        foreach (Taluka::query()->with(['district.state.country'])->cursor() as $taluka) {
            $country = $taluka->district?->state?->country;
            if ($country === null || (string) $country->iso_alpha2 !== 'IN') {
                continue;
            }
            $dName = trim((string) ($taluka->district?->name ?? ''));
            $tName = trim((string) $taluka->name);
            if ($dName === '' || $tName === '') {
                continue;
            }
            $mr = $map[$dName.$sep.$tName] ?? null;
            if ($mr === null || $mr === '') {
                continue;
            }
            if ((string) $taluka->name_mr !== $mr) {
                $taluka->name_mr = $mr;
                $taluka->save();
            }
        }
    }

    /**
     * Overwrites {@code villages.name_mr} from {@code geo/villages.json} (LGD village code → local name).
     * Raises memory limit while parsing the large JSON file once.
     */
    public static function syncIndianVillageNameMrFromGeoJson(): void
    {
        if (! Schema::hasTable('villages')) {
            return;
        }
        $path = database_path('seeders/data/geo/villages.json');
        if (! is_readable($path)) {
            return;
        }
        $prev = ini_get('memory_limit');
        @ini_set('memory_limit', '512M');
        try {
            $rows = json_decode((string) file_get_contents($path), true);
        } finally {
            if ($prev !== false) {
                @ini_set('memory_limit', (string) $prev);
            }
        }
        if (! is_array($rows)) {
            return;
        }
        $lgdToMr = [];
        foreach ($rows as $row) {
            $lgd = trim((string) ($row['villagecode'] ?? ''));
            $mr = trim((string) ($row['villagelocalname'] ?? ''));
            if ($lgd === '' || $mr === '') {
                continue;
            }
            $lgdToMr[$lgd] = $mr;
        }
        foreach (Village::query()->cursor() as $village) {
            $lgd = trim((string) ($village->lgd_code ?? ''));
            if ($lgd === '') {
                continue;
            }
            $mr = $lgdToMr[$lgd] ?? null;
            if ($mr === null || $mr === '') {
                continue;
            }
            if ((string) ($village->name_mr ?? '') !== $mr) {
                $village->name_mr = $mr;
                $village->save();
            }
        }
    }

    /**
     * Copies {@code villages.name_mr} onto {@code cities.name_mr} where the city row mirrors the village (same taluka + English name).
     */
    public static function syncIndianCityNameMrFromVillageMirror(): void
    {
        if (! Schema::hasTable('cities') || ! Schema::hasColumn('cities', 'name_mr') || ! Schema::hasTable('villages')) {
            return;
        }
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement(
                'UPDATE cities c INNER JOIN villages v ON v.taluka_id = c.taluka_id AND v.name_en = c.name '.
                "SET c.name_mr = v.name_mr WHERE v.name_mr IS NOT NULL AND TRIM(v.name_mr) <> ''"
            );

            return;
        }
        City::query()->with('taluka:id')->chunkById(200, function ($cities): void {
            foreach ($cities as $city) {
                $mr = Village::query()
                    ->where('taluka_id', $city->taluka_id)
                    ->where('name_en', $city->name)
                    ->value('name_mr');
                if ($mr === null || trim((string) $mr) === '') {
                    continue;
                }
                $mr = trim((string) $mr);
                if ((string) ($city->name_mr ?? '') !== $mr) {
                    $city->name_mr = $mr;
                    $city->save();
                }
            }
        });
    }

    /**
     * Runs district + taluka + village + city packaged UTF-8 syncs (Step 2 geo layer).
     */
    public static function syncIndianLocationHierarchyPackagedMr(): void
    {
        self::syncIndianDistrictNameMr();
        self::syncIndianTalukaNameMr();
        self::syncIndianVillageNameMrFromGeoJson();
        self::syncIndianCityNameMrFromVillageMirror();
    }

    public static function applyIfEmpty(Model $model, string $englishName): void
    {
        $en = trim($englishName);
        if ($en === '') {
            return;
        }
        $mr = self::englishToMarathi()[$en] ?? null;
        if ($mr === null || $mr === '') {
            return;
        }
        $current = $model->getAttribute('name_mr');
        if ($current !== null && trim((string) $current) !== '') {
            return;
        }
        $model->setAttribute('name_mr', $mr);
        $model->save();
    }
}
