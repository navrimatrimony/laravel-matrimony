<?php

namespace Tests\Feature\Intake;

use App\Models\City;
use App\Models\CityAlias;
use App\Models\Country;
use App\Services\Parsing\IntakeControlledFieldNormalizer;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeAddressWorkLocationNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_snapshot_sets_address_city_and_country_from_alias(): void
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
        $india = Country::query()->where('name', 'India')->first();
        $this->assertNotNull($india);

        $snapshot = [
            'core' => [],
            'addresses' => [
                [
                    'type' => 'current',
                    'city' => 'Pune',
                    'address_line' => 'Some long narrative that should not be used alone',
                ],
            ],
        ];

        $out = app(IntakeControlledFieldNormalizer::class)->normalizeSnapshot($snapshot);

        $addr = $out['addresses'][0];
        $this->assertSame($city->id, (int) ($addr['city_id'] ?? 0));
        $this->assertSame($india->id, (int) ($addr['country_id'] ?? 0));
        $this->assertSame((int) $city->taluka_id, (int) ($addr['taluka_id'] ?? 0));
    }

    public function test_normalize_snapshot_sets_work_city_from_core_work_location_text(): void
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
        $city->load('taluka.district');

        $snapshot = [
            'core' => [
                'work_location_text' => 'Pune',
            ],
            'career_history' => [],
        ];

        $out = app(IntakeControlledFieldNormalizer::class)->normalizeSnapshot($snapshot);

        $this->assertSame($city->id, (int) ($out['core']['work_city_id'] ?? 0));
        $this->assertSame((int) $city->taluka->district->state_id, (int) ($out['core']['work_state_id'] ?? 0));
    }

    public function test_normalize_career_row_sets_city_id_from_location(): void
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
            'career_history' => [
                [
                    'occupation_title' => 'Engineer',
                    'company_name' => 'Acme',
                    'location' => 'Pune',
                ],
            ],
        ];

        $out = app(IntakeControlledFieldNormalizer::class)->normalizeSnapshot($snapshot);

        $this->assertSame($city->id, (int) ($out['career_history'][0]['city_id'] ?? 0));
        $this->assertSame($city->id, (int) ($out['core']['work_city_id'] ?? 0));
    }

    public function test_ambiguous_alias_does_not_auto_resolve_city(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $pune = City::query()->where('name', 'Pune City')->first();
        $this->assertNotNull($pune);
        $other = City::query()->where('id', '!=', $pune->id)->first();
        $this->assertNotNull($other);

        CityAlias::query()->create([
            'city_id' => $pune->id,
            'alias_name' => 'Shared Alias',
            'normalized_alias' => 'sharedalias',
            'is_active' => true,
        ]);
        CityAlias::query()->create([
            'city_id' => $other->id,
            'alias_name' => 'Shared Alias',
            'normalized_alias' => 'sharedalias',
            'is_active' => true,
        ]);

        $snapshot = [
            'core' => [
                'work_location_text' => 'Shared Alias',
            ],
            'career_history' => [],
        ];

        $out = app(IntakeControlledFieldNormalizer::class)->normalizeSnapshot($snapshot);

        $this->assertNull($out['core']['work_city_id'] ?? null);
    }
}
