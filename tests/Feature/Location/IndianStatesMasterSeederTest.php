<?php

namespace Tests\Feature\Location;

use App\Models\Country;
use App\Models\State;
use Database\Seeders\CountriesMasterSeeder;
use Database\Seeders\IndianStatesMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndianStatesMasterSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_all_rows_from_state_name_mr_map(): void
    {
        $this->seed(CountriesMasterSeeder::class);
        $this->seed(IndianStatesMasterSeeder::class);

        $india = Country::query()->where('iso_alpha2', 'IN')->firstOrFail();
        $count = State::query()->where('parent_id', $india->id)->count();
        $this->assertGreaterThanOrEqual(28, $count);

        $mh = State::query()->where('parent_id', $india->id)->where('name', 'Maharashtra')->first();
        $this->assertNotNull($mh);
        $this->assertSame('महाराष्ट्र', $mh->name_mr);
    }
}
