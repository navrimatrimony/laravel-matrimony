<?php

namespace Tests\Feature\Intake;

use App\Models\City;
use App\Models\CityAlias;
use App\Models\Country;
use App\Services\Location\LocationNormalizationService;
use App\Services\Parsing\IntakeControlledFieldNormalizer;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeLocationBirthNativeNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_snapshot_sets_birth_city_id_from_city_alias(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $city = City::query()->where('name', 'Pune City')->first();
        $this->assertNotNull($city);
        CityAlias::query()->create([
            'city_id' => $city->id,
            'alias_name' => 'Pune',
            'normalized_alias' => 'pune',
            'is_active' => true,
        ]);

        $snapshot = [
            'core' => [
                'birth_place' => 'Pune',
            ],
        ];

        $out = app(IntakeControlledFieldNormalizer::class)->normalizeSnapshot($snapshot);

        $city->load('taluka.district');
        $this->assertSame($city->id, (int) ($out['core']['birth_city_id'] ?? 0));
        $this->assertSame($city->id, (int) ($out['birth_place']['city_id'] ?? 0));
        $this->assertSame((int) $city->taluka_id, (int) ($out['core']['birth_taluka_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district_id, (int) ($out['core']['birth_district_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district->state_id, (int) ($out['core']['birth_state_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district_id, (int) ($out['birth_place']['district_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district->state_id, (int) ($out['birth_place']['state_id'] ?? 0));
    }

    public function test_normalize_snapshot_sets_native_city_id_from_city_alias(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $city = City::query()->where('name', 'Pune City')->first();
        $this->assertNotNull($city);
        CityAlias::query()->create([
            'city_id' => $city->id,
            'alias_name' => 'Pune',
            'normalized_alias' => 'pune',
            'is_active' => true,
        ]);

        $snapshot = [
            'core' => [],
            'native_place' => [
                'raw' => 'Pune',
                'address_line' => 'Pune',
            ],
        ];

        $out = app(IntakeControlledFieldNormalizer::class)->normalizeSnapshot($snapshot);

        $city->load('taluka.district');
        $this->assertSame($city->id, (int) ($out['core']['native_city_id'] ?? 0));
        $this->assertSame($city->id, (int) ($out['native_place']['city_id'] ?? 0));
        $this->assertSame((int) $city->taluka_id, (int) ($out['core']['native_taluka_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district_id, (int) ($out['core']['native_district_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district->state_id, (int) ($out['core']['native_state_id'] ?? 0));
    }

    public function test_location_normalization_service_returns_city_district_state(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $city = City::query()->with('taluka.district')->where('name', 'Pune City')->first();
        $this->assertNotNull($city);
        CityAlias::query()->create([
            'city_id' => $city->id,
            'alias_name' => 'Pune',
            'normalized_alias' => 'pune',
            'is_active' => true,
        ]);

        $r = app(LocationNormalizationService::class)->normalizeFromText('Pune');

        $this->assertTrue($r['matched']);
        $this->assertSame($city->id, $r['city_id']);
        $this->assertSame((int) $city->taluka->district_id, $r['district_id']);
        $this->assertSame((int) $city->taluka->district->state_id, $r['state_id']);
        $this->assertSame((int) $city->taluka_id, $r['taluka_id']);
        $india = Country::query()->where('name', 'India')->first();
        $this->assertNotNull($india);
        $this->assertSame((int) $india->id, (int) ($r['country_id'] ?? 0));
        $this->assertGreaterThanOrEqual(0.80, (float) ($r['confidence'] ?? 0));
        $this->assertSame('Pune', $r['raw_input']);
    }

    public function test_unmatched_birth_sets_birth_place_text_only(): void
    {
        $this->seed(MinimalLocationSeeder::class);

        $snapshot = [
            'core' => [
                'birth_place' => 'Unknown Village XYZ',
            ],
        ];

        $out = app(IntakeControlledFieldNormalizer::class)->normalizeSnapshot($snapshot);

        $this->assertNull($out['core']['birth_city_id'] ?? null);
        $this->assertNull($out['core']['birth_district_id'] ?? null);
        $this->assertNull($out['core']['birth_state_id'] ?? null);
        $this->assertSame('Unknown Village XYZ', (string) ($out['core']['birth_place_text'] ?? ''));
    }
}
