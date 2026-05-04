<?php

namespace Tests\Feature\Location;

use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\LocationDisplayMeta;
use App\Models\State;
use App\Models\Taluka;
use App\Services\Location\LocationDisplayFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocationDisplayFormatterTest extends TestCase
{
    use RefreshDatabase;

    private function seedIndiaMaharashtraPuneDistrict(): array
    {
        $india = Country::query()->create([
            'name' => 'India',
            'iso_alpha2' => 'IN',
            'name_mr' => 'भारत',
        ]);
        $mh = State::query()->create([
            'country_id' => $india->id,
            'name' => 'Maharashtra',
            'name_mr' => 'महाराष्ट्र',
        ]);
        $puneDistrict = District::query()->create([
            'state_id' => $mh->id,
            'name' => 'Pune',
            'name_mr' => 'पुणे',
            'slug' => 'pune',
        ]);
        $mulshiTaluka = Taluka::query()->create([
            'district_id' => $puneDistrict->id,
            'name' => 'Mulshi',
            'name_mr' => 'मुळशी',
        ]);

        return [$india, $mh, $puneDistrict, $mulshiTaluka];
    }

    public function test_district_hq_city_shows_city_and_state_not_duplicate_district(): void
    {
        [, $mh, $puneDistrict, $mulshiTaluka] = $this->seedIndiaMaharashtraPuneDistrict();

        $puneCity = City::query()->create([
            'taluka_id' => $mulshiTaluka->id,
            'name' => 'Pune',
            'population' => 100,
        ]);

        $fmt = app(LocationDisplayFormatter::class);
        $line = $fmt->formatCityLine($puneCity->fresh(['taluka.district.state.country']));

        $this->assertStringContainsString('Pune', $line);
        $this->assertStringContainsString('Maharashtra', $line);
        $this->assertStringNotContainsString('Pune, Pune', $line);
        $this->assertStringNotContainsString('India', $line);
    }

    public function test_taluka_named_city_shows_taluka_and_district(): void
    {
        [, , $puneDistrict, $mulshiTaluka] = $this->seedIndiaMaharashtraPuneDistrict();

        $mulshiCity = City::query()->create([
            'taluka_id' => $mulshiTaluka->id,
            'name' => 'Mulshi',
            'population' => 50,
        ]);

        $fmt = app(LocationDisplayFormatter::class);
        $line = $fmt->formatCityLine($mulshiCity->fresh(['taluka.district.state.country']));

        $this->assertStringContainsString('Mulshi', $line);
        $this->assertStringContainsString('Pune', $line);
        $this->assertStringNotContainsString('Mulshi, Mulshi', $line);
        $this->assertStringNotContainsString('India', $line);
    }

    public function test_village_shows_city_taluka_district_state(): void
    {
        [, , , $mulshiTaluka] = $this->seedIndiaMaharashtraPuneDistrict();

        $shivapur = City::query()->create([
            'taluka_id' => $mulshiTaluka->id,
            'name' => 'Shivapur',
            'population' => 500,
        ]);

        $fmt = app(LocationDisplayFormatter::class);
        $line = $fmt->formatCityLine($shivapur->fresh(['taluka.district.state.country']));

        $this->assertStringContainsString('Shivapur', $line);
        $this->assertStringContainsString('Mulshi', $line);
        $this->assertStringContainsString('Pune', $line);
        $this->assertStringContainsString('Maharashtra', $line);
        $this->assertStringNotContainsString('India', $line);
    }

    public function test_parent_city_locality_prepends_before_parent_and_state(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('cities', 'parent_city_id')) {
            $this->markTestSkipped('parent_city_id migration not applied');
        }

        [, , , $mulshiTaluka] = $this->seedIndiaMaharashtraPuneDistrict();

        $puneMetro = City::query()->create([
            'taluka_id' => $mulshiTaluka->id,
            'name' => 'Pune',
            'population' => 100000,
        ]);

        $wakad = City::query()->create([
            'taluka_id' => $mulshiTaluka->id,
            'parent_city_id' => $puneMetro->id,
            'name' => 'Wakad',
            'population' => 10000,
        ]);

        $fmt = app(LocationDisplayFormatter::class);
        $line = $fmt->formatCityLine($wakad->fresh(['taluka.district.state.country', 'parentCity']));

        $this->assertStringContainsString('Wakad', $line);
        $this->assertStringContainsString('Pune', $line);
        $this->assertStringContainsString('Maharashtra', $line);
        $this->assertStringNotContainsString('India', $line);
    }

    public function test_meta_force_district_hq_compact_even_when_city_name_differs_from_district(): void
    {
        if (! Schema::hasTable('location_display_meta')) {
            $this->markTestSkipped('location_display_meta migration not applied');
        }

        [, , , $mulshiTaluka] = $this->seedIndiaMaharashtraPuneDistrict();

        $hqLike = City::query()->create([
            'taluka_id' => $mulshiTaluka->id,
            'name' => 'Navi Mumbai',
            'population' => 1000,
        ]);

        LocationDisplayMeta::query()->create([
            'location_id' => $hqLike->id,
            'is_district_hq' => true,
            'display_priority' => 10,
        ]);

        $fmt = app(LocationDisplayFormatter::class);
        $line = $fmt->formatCityLine($hqLike->fresh(['taluka.district.state.country']));

        $this->assertStringContainsString('Navi Mumbai', $line);
        $this->assertStringContainsString('Maharashtra', $line);
        $this->assertStringNotContainsString('Mulshi', $line);
        $this->assertStringNotContainsString('Pune', $line);
    }

    public function test_meta_hide_state_removes_state_label(): void
    {
        if (! Schema::hasTable('location_display_meta')) {
            $this->markTestSkipped('location_display_meta migration not applied');
        }

        [, , , $mulshiTaluka] = $this->seedIndiaMaharashtraPuneDistrict();

        $shivapur = City::query()->create([
            'taluka_id' => $mulshiTaluka->id,
            'name' => 'ShivapurHide',
            'population' => 400,
        ]);

        LocationDisplayMeta::query()->create([
            'location_id' => $shivapur->id,
            'hide_state' => true,
        ]);

        $fmt = app(LocationDisplayFormatter::class);
        $line = $fmt->formatCityLine($shivapur->fresh(['taluka.district.state.country']));

        $this->assertStringContainsString('ShivapurHide', $line);
        $this->assertStringNotContainsString('Maharashtra', $line);
        $this->assertStringContainsString('Mulshi', $line);
    }

    public function test_meta_hide_country_false_shows_india_for_inr(): void
    {
        if (! Schema::hasTable('location_display_meta')) {
            $this->markTestSkipped('location_display_meta migration not applied');
        }

        [, , , $mulshiTaluka] = $this->seedIndiaMaharashtraPuneDistrict();

        $city = City::query()->create([
            'taluka_id' => $mulshiTaluka->id,
            'name' => 'ShowIndia',
            'population' => 50,
        ]);

        LocationDisplayMeta::query()->create([
            'location_id' => $city->id,
            'is_district_hq' => true,
            'hide_country' => false,
        ]);

        $fmt = app(LocationDisplayFormatter::class);
        $line = $fmt->formatCityLine($city->fresh(['taluka.district.state.country']));

        $this->assertStringContainsString('India', $line);
    }
}
