<?php

namespace Tests\Feature\Location;

use App\Models\Country;
use App\Models\District;
use App\Models\State;
use Database\Seeders\Support\LocationMarathiLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistrictMarathiSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_overwrites_bad_district_name_mr_for_pune(): void
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
        $district = District::query()->create([
            'state_id' => $mh->id,
            'name' => 'Pune',
            'name_mr' => 'à¤ªà¥à¤à¤£à¥', // mojibake-style garbage
        ]);

        LocationMarathiLabels::syncIndianDistrictNameMr();

        $district->refresh();
        $this->assertSame('पुणे', $district->name_mr);
    }
}
