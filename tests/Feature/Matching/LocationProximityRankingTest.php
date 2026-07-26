<?php

namespace Tests\Feature\Matching;

use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Services\Matching\MatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Marriage searches are strongly local, but location scoring used to jump
 * straight from "same place" (100%) to "same state" (65%). A neighbouring
 * village and someone 600 km away scored identically, so the owner's rule —
 * nearby districts preferred, nearby talukas better still — existed only as
 * displayed text, never as ordering.
 *
 * These tests pin the ladder itself, not any single number.
 */
class LocationProximityRankingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Location> */
    private array $geo = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->geo = $this->makeGeography();
    }

    /** @return array<string, Location> */
    private function makeGeography(): array
    {
        $country = Location::query()->create([
            'name' => 'India', 'slug' => 'prox-india', 'hierarchy' => 'country', 'level' => 0, 'is_active' => true,
        ]);
        $state = Location::query()->create([
            'name' => 'Maharashtra', 'slug' => 'prox-mh', 'hierarchy' => 'state', 'level' => 1,
            'parent_id' => $country->id, 'is_active' => true,
        ]);
        $pune = Location::query()->create([
            'name' => 'Pune', 'slug' => 'prox-pune', 'hierarchy' => 'district', 'level' => 2,
            'parent_id' => $state->id, 'is_active' => true, 'lat' => 18.5204, 'lng' => 73.8567,
        ]);
        $satara = Location::query()->create([
            'name' => 'Satara', 'slug' => 'prox-satara', 'hierarchy' => 'district', 'level' => 2,
            'parent_id' => $state->id, 'is_active' => true, 'lat' => 17.6805, 'lng' => 74.0183,
        ]);
        $gadchiroli = Location::query()->create([
            'name' => 'Gadchiroli', 'slug' => 'prox-gadchiroli', 'hierarchy' => 'district', 'level' => 2,
            'parent_id' => $state->id, 'is_active' => true, 'lat' => 20.1809, 'lng' => 80.0032,
        ]);

        // Haveli and Khed are both in Pune district, ~45 km apart.
        $haveli = Location::query()->create([
            'name' => 'Haveli', 'slug' => 'prox-haveli', 'hierarchy' => 'taluka', 'level' => 3,
            'parent_id' => $pune->id, 'is_active' => true, 'lat' => 18.4529, 'lng' => 73.8600,
        ]);
        // Khandala is in Satara district but only ~60 km from Wagholi — a
        // DIFFERENT district that is still genuinely nearby. This is the case
        // the old scoring could not express at all.
        $karad = Location::query()->create([
            'name' => 'Khandala', 'slug' => 'prox-khandala', 'hierarchy' => 'taluka', 'level' => 3,
            'parent_id' => $satara->id, 'is_active' => true, 'lat' => 18.0500, 'lng' => 73.8500,
        ]);
        // Sironcha is at the far south-east corner of the state, ~600 km away.
        $sironcha = Location::query()->create([
            'name' => 'Sironcha', 'slug' => 'prox-sironcha', 'hierarchy' => 'taluka', 'level' => 3,
            'parent_id' => $gadchiroli->id, 'is_active' => true, 'lat' => 18.8506, 'lng' => 79.9634,
        ]);

        $wagholi = Location::query()->create([
            'name' => 'Wagholi', 'slug' => 'prox-wagholi', 'hierarchy' => 'village', 'level' => 4,
            'parent_id' => $haveli->id, 'is_active' => true, 'lat' => 18.5800, 'lng' => 73.9800,
        ]);
        $lonikand = Location::query()->create([
            'name' => 'Lonikand', 'slug' => 'prox-lonikand', 'hierarchy' => 'village', 'level' => 4,
            'parent_id' => $haveli->id, 'is_active' => true, 'lat' => 18.6100, 'lng' => 74.0100,
        ]);
        $karadVillage = Location::query()->create([
            'name' => 'Shirwal', 'slug' => 'prox-shirwal', 'hierarchy' => 'village', 'level' => 4,
            'parent_id' => $karad->id, 'is_active' => true, 'lat' => 18.1500, 'lng' => 73.9700,
        ]);
        $farVillage = Location::query()->create([
            'name' => 'Asaralli', 'slug' => 'prox-asaralli', 'hierarchy' => 'village', 'level' => 4,
            'parent_id' => $sironcha->id, 'is_active' => true, 'lat' => 18.8600, 'lng' => 79.9700,
        ]);

        return compact(
            'country', 'state', 'pune', 'satara', 'gadchiroli',
            'haveli', 'karad', 'sironcha',
            'wagholi', 'lonikand', 'karadVillage', 'farVillage',
        );
    }

    private function profileAt(?Location $place): MatrimonyProfile
    {
        return MatrimonyProfile::factory()->create([
            'location_id' => $place?->id,
        ]);
    }

    /** Location points only, isolated from every other scoring component. */
    private function locationPoints(MatrimonyProfile $a, MatrimonyProfile $b): int
    {
        $service = app(MatchingService::class);
        $method = new \ReflectionMethod($service, 'scoreLocationPart');
        $method->setAccessible(true);

        /** @var array{points: int, reasons: list<string>} $result */
        $result = $method->invoke($service, $a, $b);

        return (int) $result['points'];
    }

    /** @return list<string> */
    private function locationReasons(MatrimonyProfile $a, MatrimonyProfile $b): array
    {
        $service = app(MatchingService::class);
        $method = new \ReflectionMethod($service, 'scoreLocationPart');
        $method->setAccessible(true);

        return $method->invoke($service, $a, $b)['reasons'];
    }

    public function test_proximity_is_a_strict_ladder_not_a_cliff(): void
    {
        $seeker = $this->profileAt($this->geo['wagholi']);

        $samePlace = $this->locationPoints($seeker, $this->profileAt($this->geo['wagholi']));
        $sameTaluka = $this->locationPoints($seeker, $this->profileAt($this->geo['lonikand']));
        $nearbyOtherDistrict = $this->locationPoints($seeker, $this->profileAt($this->geo['karadVillage']));
        $farSameState = $this->locationPoints($seeker, $this->profileAt($this->geo['farVillage']));

        $this->assertGreaterThan($sameTaluka, $samePlace, 'The same village must beat the same taluka.');
        $this->assertGreaterThan($nearbyOtherDistrict, $sameTaluka, 'The same taluka must beat a nearby other district.');
        $this->assertGreaterThan(
            $farSameState,
            $nearbyOtherDistrict,
            'This is the whole defect: a nearby taluka must outrank someone 600 km away in the same state.',
        );
    }

    public function test_a_neighbour_and_someone_600_km_away_no_longer_score_the_same(): void
    {
        $seeker = $this->profileAt($this->geo['wagholi']);

        $neighbour = $this->locationPoints($seeker, $this->profileAt($this->geo['lonikand']));
        $farAway = $this->locationPoints($seeker, $this->profileAt($this->geo['farVillage']));

        $this->assertNotSame($neighbour, $farAway);
    }

    public function test_the_reason_names_the_tier_the_score_came_from(): void
    {
        $seeker = $this->profileAt($this->geo['wagholi']);

        $this->assertSame(
            [__('matching.reason_same_taluka')],
            $this->locationReasons($seeker, $this->profileAt($this->geo['lonikand'])),
        );

        $nearby = $this->locationReasons($seeker, $this->profileAt($this->geo['karadVillage']));
        $this->assertCount(1, $nearby);
        $this->assertMatchesRegularExpression('/\d+/', $nearby[0], 'A distance tier must state the distance.');
        // Frozen rule: every digit shown to a user is Latin, never Devanagari.
        $this->assertDoesNotMatchRegularExpression('/[\x{0966}-\x{096F}]/u', $nearby[0]);
    }

    public function test_a_missing_position_falls_back_to_the_old_state_score_and_is_never_punished(): void
    {
        // A taluka the backfill deliberately refused to place: no coordinates.
        $unplacedTaluka = Location::query()->create([
            'name' => 'Unplaced', 'slug' => 'prox-unplaced', 'hierarchy' => 'taluka', 'level' => 3,
            'parent_id' => $this->geo['gadchiroli']->id, 'is_active' => true,
        ]);
        $unplacedVillage = Location::query()->create([
            'name' => 'Nowhere', 'slug' => 'prox-nowhere', 'hierarchy' => 'village', 'level' => 4,
            'parent_id' => $unplacedTaluka->id, 'is_active' => true,
        ]);

        $seeker = $this->profileAt($this->geo['wagholi']);

        $unknown = $this->locationPoints($seeker, $this->profileAt($unplacedVillage));
        $farKnown = $this->locationPoints($seeker, $this->profileAt($this->geo['farVillage']));

        $this->assertSame(
            $farKnown,
            $unknown,
            'Unknown distance must score exactly the plain same-state tier — missing data is not a penalty.',
        );
    }

    /**
     * The tiers are integers rounded from an admin-tunable weight. Two bands
     * only 5 points apart both rounded to the same integer at weight 12, which
     * silently collapsed the ladder back into the cliff it replaced — caught
     * here, not in production.
     *
     * 11 is the real floor, not a guess: at 10 the bottom two tiers (0.72 and
     * 0.65) both round to 7 and proximity stops mattering. The shipped default
     * is 15. If someone ever wants a weight below 11, the fractions have to
     * change with it — that is what this test is here to force.
     */
    public function test_the_tiers_stay_distinct_across_the_usable_weight_range(): void
    {
        $fractions = [1.00, 0.90, 0.80, 0.72, 0.65];

        foreach (range(11, 30) as $weight) {
            $points = array_map(static fn (float $f): int => (int) round($weight * $f), $fractions);
            $this->assertSame(
                $points,
                array_values(array_unique($points)),
                "Location tiers collapse into each other at a weight of {$weight}: ".implode(',', $points),
            );
        }

        // And the documented floor is real, not folklore.
        $atTen = array_map(static fn (float $f): int => (int) round(10 * $f), $fractions);
        $this->assertNotSame(
            $atTen,
            array_values(array_unique($atTen)),
            'If weight 10 now works, raise the range above and update the comment on MatchingService::NEARBY_KM.',
        );
    }

    public function test_a_different_district_still_beats_a_different_state(): void
    {
        $otherState = Location::query()->create([
            'name' => 'Karnataka', 'slug' => 'prox-ka', 'hierarchy' => 'state', 'level' => 1,
            'parent_id' => $this->geo['country']->id, 'is_active' => true,
        ]);
        $otherDistrict = Location::query()->create([
            'name' => 'Belagavi', 'slug' => 'prox-belagavi', 'hierarchy' => 'district', 'level' => 2,
            'parent_id' => $otherState->id, 'is_active' => true, 'lat' => 15.8497, 'lng' => 74.4977,
        ]);

        $seeker = $this->profileAt($this->geo['wagholi']);

        $sameStateFar = $this->locationPoints($seeker, $this->profileAt($this->geo['farVillage']));
        $otherStateNear = $this->locationPoints($seeker, $this->profileAt($otherDistrict));

        $this->assertGreaterThan($otherStateNear, $sameStateFar);
    }
}
