<?php

namespace Tests\Feature\Intake;

use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\LocationAlias;
use App\Models\State;
use App\Models\Taluka;
use App\Models\Village;
use App\Services\Location\LocationNormalizationService;
use App\Services\Parsing\IntakeControlledFieldNormalizer;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeLocationBirthNativeNormalizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: \App\Models\State, 1: \App\Models\District, 2: \App\Models\Taluka, 3: \App\Models\Village}
     */
    private function seedMalinagarHierarchy(): array
    {
        $this->seed(MinimalLocationSeeder::class);

        $india = Country::query()->where('name', 'India')->firstOrFail();
        $maharashtra = State::query()->where('parent_id', $india->id)->where('name', 'Maharashtra')->firstOrFail();
        $solapur = District::firstOrCreate(
            ['parent_id' => $maharashtra->id, 'name' => 'Solapur'],
            ['name_mr' => 'सोलापूर']
        );
        $malshiras = Taluka::firstOrCreate(
            ['parent_id' => $solapur->id, 'name' => 'Malshiras'],
            ['name_mr' => 'माळशिरस']
        );
        $village = Village::firstOrCreate(
            ['parent_id' => $malshiras->id, 'name' => 'Malinagar'],
            ['name_mr' => 'माळीनगर', 'pincode' => '413108']
        );

        return [$maharashtra, $solapur, $malshiras, $village];
    }

    public function test_normalize_snapshot_sets_birth_city_id_from_city_alias(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $city = City::query()->where('name', 'Pune City')->first();
        $this->assertNotNull($city);
        LocationAlias::query()->create([
            'location_id' => $city->id,
            'alias' => 'Pune',
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
        $this->assertSame((int) $city->taluka_id, (int) ($out['core']['birth_taluka_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district_id, (int) ($out['core']['birth_district_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district->state_id, (int) ($out['core']['birth_state_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district->state->country_id, (int) ($out['core']['birth_country_id'] ?? 0));
        $this->assertSame($city->id, (int) ($out['birth_place']['city_id'] ?? 0));
        $this->assertSame((int) $city->taluka_id, (int) ($out['birth_place']['taluka_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district_id, (int) ($out['birth_place']['district_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district->state_id, (int) ($out['birth_place']['state_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district->state->country_id, (int) ($out['birth_place']['country_id'] ?? 0));
    }

    public function test_normalize_snapshot_sets_native_city_id_from_city_alias(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $city = City::query()->where('name', 'Pune City')->first();
        $this->assertNotNull($city);
        LocationAlias::query()->create([
            'location_id' => $city->id,
            'alias' => 'Pune',
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
        $this->assertSame((int) $city->taluka->district->state->country_id, (int) ($out['core']['native_country_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district->state->country_id, (int) ($out['native_place']['country_id'] ?? 0));
    }

    public function test_location_normalization_service_returns_city_district_state(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $city = City::query()->with('taluka.district')->where('name', 'Pune City')->first();
        $this->assertNotNull($city);
        LocationAlias::query()->create([
            'location_id' => $city->id,
            'alias' => 'Pune',
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
        $this->assertSame($city->id, (int) ($r['location_id'] ?? 0));
        $this->assertSame('Pune', $r['raw_input']);
    }

    public function test_location_normalization_service_prefers_exact_taluka_over_district_for_single_token_place(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $district = District::query()->where('name', 'Pune')->firstOrFail();
        $taluka = Taluka::firstOrCreate(
            ['parent_id' => $district->id, 'name' => 'Pune'],
            ['name_mr' => 'पुणे']
        );
        $state = State::query()->where('name', 'Maharashtra')->firstOrFail();

        $r = app(LocationNormalizationService::class)->normalizeFromText('Pune');

        $this->assertTrue($r['matched']);
        $this->assertSame($taluka->id, (int) ($r['city_id'] ?? 0));
        $this->assertSame($district->id, (int) ($r['district_id'] ?? 0));
        $this->assertSame($state->id, (int) ($r['state_id'] ?? 0));
        $this->assertSame($taluka->id, (int) ($r['taluka_id'] ?? 0));
    }

    public function test_normalize_snapshot_sets_birth_ids_from_compound_rural_marathi_text(): void
    {
        [$maharashtra, $solapur, $malshiras, $village] = $this->seedMalinagarHierarchy();

        $snapshot = [
            'core' => [
                'birth_place' => 'माळीनगर. ता.- माळशिरस, जि.सोलापूर.',
            ],
        ];

        $out = app(IntakeControlledFieldNormalizer::class)->normalizeSnapshot($snapshot);
        $resolved = app(LocationNormalizationService::class)->normalizeFromText('माळीनगर. ता.- माळशिरस, जि.सोलापूर.');

        $this->assertTrue($resolved['matched']);
        $this->assertSame($village->id, (int) ($resolved['city_id'] ?? 0));
        $this->assertSame($malshiras->id, (int) ($resolved['taluka_id'] ?? 0));
        $this->assertSame($solapur->id, (int) ($resolved['district_id'] ?? 0));
        $this->assertSame($maharashtra->id, (int) ($resolved['state_id'] ?? 0));

        $this->assertSame($village->id, (int) ($out['core']['birth_city_id'] ?? 0));
        $this->assertSame($malshiras->id, (int) ($out['core']['birth_taluka_id'] ?? 0));
        $this->assertSame($solapur->id, (int) ($out['core']['birth_district_id'] ?? 0));
        $this->assertSame($maharashtra->id, (int) ($out['core']['birth_state_id'] ?? 0));
        $this->assertSame((int) $maharashtra->country_id, (int) ($out['core']['birth_country_id'] ?? 0));
        $this->assertSame($village->id, (int) ($out['birth_place']['city_id'] ?? 0));
        $this->assertSame($malshiras->id, (int) ($out['birth_place']['taluka_id'] ?? 0));
        $this->assertSame($solapur->id, (int) ($out['birth_place']['district_id'] ?? 0));
        $this->assertSame($maharashtra->id, (int) ($out['birth_place']['state_id'] ?? 0));
        $this->assertSame((int) $maharashtra->country_id, (int) ($out['birth_place']['country_id'] ?? 0));
    }

    public function test_normalize_snapshot_backfills_birth_parent_ids_when_birth_city_id_already_exists(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $city = City::query()->where('name', 'Pune City')->firstOrFail();
        $city->load('taluka.district');

        $snapshot = [
            'core' => [
                'birth_place_text' => 'Pune',
                'birth_city_id' => $city->id,
            ],
            'birth_place' => null,
        ];

        $out = app(IntakeControlledFieldNormalizer::class)->normalizeSnapshot($snapshot);

        $this->assertSame((int) $city->taluka_id, (int) ($out['core']['birth_taluka_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district_id, (int) ($out['core']['birth_district_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district->state_id, (int) ($out['core']['birth_state_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district->state->country_id, (int) ($out['core']['birth_country_id'] ?? 0));
        $this->assertSame($city->id, (int) ($out['birth_place']['city_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district->state->country_id, (int) ($out['birth_place']['country_id'] ?? 0));
    }

    public function test_normalize_snapshot_sets_native_ids_from_compound_rural_marathi_text(): void
    {
        [$maharashtra, $solapur, $malshiras, $village] = $this->seedMalinagarHierarchy();

        $snapshot = [
            'core' => [],
            'native_place' => [
                'raw' => 'माळीनगर. ता.- माळशिरस, जि.सोलापूर.',
                'address_line' => 'माळीनगर. ता.- माळशिरस, जि.सोलापूर.',
            ],
        ];

        $out = app(IntakeControlledFieldNormalizer::class)->normalizeSnapshot($snapshot);

        $this->assertSame($village->id, (int) ($out['core']['native_city_id'] ?? 0));
        $this->assertSame($malshiras->id, (int) ($out['core']['native_taluka_id'] ?? 0));
        $this->assertSame($solapur->id, (int) ($out['core']['native_district_id'] ?? 0));
        $this->assertSame($maharashtra->id, (int) ($out['core']['native_state_id'] ?? 0));
        $this->assertSame((int) $maharashtra->country_id, (int) ($out['core']['native_country_id'] ?? 0));
        $this->assertSame($village->id, (int) ($out['native_place']['city_id'] ?? 0));
        $this->assertSame($malshiras->id, (int) ($out['native_place']['taluka_id'] ?? 0));
        $this->assertSame($solapur->id, (int) ($out['native_place']['district_id'] ?? 0));
        $this->assertSame($maharashtra->id, (int) ($out['native_place']['state_id'] ?? 0));
        $this->assertSame((int) $maharashtra->country_id, (int) ($out['native_place']['country_id'] ?? 0));
    }

    public function test_normalize_snapshot_sets_address_row_ids_from_compound_rural_marathi_text(): void
    {
        [$maharashtra, $solapur, $malshiras, $village] = $this->seedMalinagarHierarchy();

        $snapshot = [
            'core' => [],
            'addresses' => [
                [
                    'address_line' => 'माळीनगर. ता.- माळशिरस, जि.सोलापूर.',
                ],
            ],
        ];

        $out = app(IntakeControlledFieldNormalizer::class)->normalizeSnapshot($snapshot);

        $this->assertSame($village->id, (int) ($out['addresses'][0]['city_id'] ?? 0));
        $this->assertSame($village->id, (int) ($out['addresses'][0]['location_id'] ?? 0));
        $this->assertSame($malshiras->id, (int) ($out['addresses'][0]['taluka_id'] ?? 0));
        $this->assertSame($solapur->id, (int) ($out['addresses'][0]['district_id'] ?? 0));
        $this->assertSame($maharashtra->id, (int) ($out['addresses'][0]['state_id'] ?? 0));
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
        $this->assertSame('Unknown Village XYZ', (string) ($out['core']['birth_place_text'] ?? ''));
    }
}
