<?php

namespace Tests\Feature\Location;

use App\Models\Country;
use App\Models\District;
use App\Models\State;
use App\Models\Taluka;
use Database\Seeders\Support\LocationMarathiLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TalukaMarathiSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_overwrites_bad_taluka_name_mr_using_geo_packaged_utf8(): void
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
        $pune = District::query()->create([
            'state_id' => $mh->id,
            'name' => 'Pune',
            'name_mr' => 'पुणे',
        ]);
        $haveli = Taluka::query()->create([
            'district_id' => $pune->id,
            'name' => 'Haveli',
            'name_mr' => 'garbage-mr',
        ]);

        LocationMarathiLabels::syncIndianTalukaNameMr();

        $haveli->refresh();
        $this->assertSame('हवेली', $haveli->name_mr);
    }
}
