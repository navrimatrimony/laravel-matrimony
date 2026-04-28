<?php

namespace Tests\Feature\Location;

use App\Models\Country;
use App\Models\State;
use Database\Seeders\Support\LocationMarathiLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StateMarathiSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_overwrites_bad_state_name_mr_for_maharashtra(): void
    {
        $india = Country::query()->create([
            'name' => 'India',
            'iso_alpha2' => 'IN',
            'name_mr' => 'भारत',
        ]);
        $state = State::query()->create([
            'country_id' => $india->id,
            'name' => 'Maharashtra',
            'name_mr' => 'garbage-wrong-mr',
        ]);

        LocationMarathiLabels::syncIndianStateNameMr();

        $state->refresh();
        $this->assertSame('महाराष्ट्र', $state->name_mr);
    }
}
