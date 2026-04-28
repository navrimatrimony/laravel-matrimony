<?php

namespace Tests\Feature\Location;

use App\Models\Country;
use App\Models\District;
use App\Models\State;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistrictSlugAndUniqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_district_gets_ascii_slug_from_english_name(): void
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

        $this->assertSame('pune', $pune->fresh()->slug);
    }

    public function test_slug_collision_within_same_state_gets_numeric_suffix(): void
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

        District::query()->create([
            'state_id' => $mh->id,
            'name' => 'Mumbai Suburban',
            'name_mr' => null,
        ]);
        $second = District::query()->create([
            'state_id' => $mh->id,
            'name' => 'Mumbai-Suburban',
            'name_mr' => null,
        ]);

        $this->assertSame('mumbai-suburban', District::query()->where('state_id', $mh->id)->where('name', 'Mumbai Suburban')->first()?->slug);
        $this->assertSame('mumbai-suburban-2', $second->fresh()->slug);
    }

    public function test_duplicate_name_same_state_is_rejected_at_database_level(): void
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

        District::query()->create([
            'state_id' => $mh->id,
            'name' => 'Solapur',
            'name_mr' => null,
        ]);

        $this->expectException(QueryException::class);

        District::query()->create([
            'state_id' => $mh->id,
            'name' => 'Solapur',
            'name_mr' => null,
        ]);
    }

    public function test_same_district_name_allowed_in_different_states(): void
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
        $ka = State::query()->create([
            'country_id' => $india->id,
            'name' => 'Karnataka',
            'name_mr' => null,
        ]);

        $d1 = District::query()->create([
            'state_id' => $mh->id,
            'name' => 'Bidar',
            'name_mr' => null,
        ]);
        $d2 = District::query()->create([
            'state_id' => $ka->id,
            'name' => 'Bidar',
            'name_mr' => null,
        ]);

        $this->assertNotSame($d1->id, $d2->id);
        $this->assertSame('bidar', $d1->fresh()->slug);
        $this->assertSame('bidar', $d2->fresh()->slug);
    }
}
