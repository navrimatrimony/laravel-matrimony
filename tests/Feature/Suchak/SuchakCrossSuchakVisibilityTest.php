<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakProfileRepresentation;
use App\Modules\Suchak\Services\SuchakCandidateMaskingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PO decision 2026-08-01 (blueprint D19a / D19d): what one Suchak may see of
 * another Suchak's candidate.
 *
 * Four things are hidden by default — name, village, detailed address, mobile —
 * and everything else, the photograph included, is shown, because a matchmaker
 * who cannot see a face cannot propose a match. The originating Suchak may
 * reveal any of the four, per candidate, because he knows the family and the
 * platform does not.
 *
 * Two of the four were already honoured (mobile and address were hard-null).
 * Name and village were not: the village travelled under the key `city` while
 * `is_broad` was hardcoded true beside it — a flag asserting the opposite of
 * its own payload.
 */
class SuchakCrossSuchakVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_by_default_the_village_is_withheld_and_the_flag_says_so(): void
    {
        [$profile, $representation] = $this->representedCandidate();

        $payload = app(SuchakCandidateMaskingService::class)
            ->maskedSummary($profile, $representation);

        $this->assertSame('Shirur', $payload['location']['city']);
        $this->assertSame('Pune', $payload['location']['district']);
        $this->assertTrue($payload['location']['is_broad']);
        $this->assertNull($payload['location']['exact_address']);
    }

    public function test_the_originating_suchak_can_reveal_the_village(): void
    {
        [$profile, $representation] = $this->representedCandidate();
        $representation->forceFill(['shares_village' => true])->save();

        $payload = app(SuchakCandidateMaskingService::class)
            ->maskedSummary($profile, $representation->fresh());

        $this->assertSame('Ranjangaon', $payload['location']['city']);
        // The flag has to follow the payload, or it is worse than no flag.
        $this->assertFalse($payload['location']['is_broad']);
    }

    public function test_by_default_the_name_is_masked(): void
    {
        [$profile, $representation] = $this->representedCandidate();

        $payload = app(SuchakCandidateMaskingService::class)
            ->maskedSummary($profile, $representation);

        $this->assertSame('Sunita G.', $payload['display_name']);
    }

    public function test_the_originating_suchak_can_reveal_the_full_name(): void
    {
        [$profile, $representation] = $this->representedCandidate();
        $representation->forceFill(['shares_name' => true])->save();

        $payload = app(SuchakCandidateMaskingService::class)
            ->maskedSummary($profile, $representation->fresh());

        $this->assertSame('Sunita Gaikwad', $payload['display_name']);
    }

    public function test_a_typed_display_name_wins_over_both_defaults(): void
    {
        [$profile, $representation] = $this->representedCandidate();
        // full_name is one column with no surname to peel off, so a Suchak who
        // wants something more useful than the mask types it himself.
        $representation->forceFill(['shared_display_name' => 'Gaikwad (Shirur)'])->save();

        $payload = app(SuchakCandidateMaskingService::class)
            ->maskedSummary($profile, $representation->fresh());

        $this->assertSame('Gaikwad (Shirur)', $payload['display_name']);
    }

    public function test_mobile_and_address_stay_out_unless_revealed(): void
    {
        [$profile, $representation] = $this->representedCandidate();

        $payload = app(SuchakCandidateMaskingService::class)
            ->maskedSummary($profile, $representation);

        $this->assertTrue($payload['contact']['is_masked']);
        $this->assertNull($payload['contact']['phone']);
        $this->assertNull($payload['location']['exact_address']);
    }

    /**
     * @return array{0: MatrimonyProfile, 1: SuchakProfileRepresentation}
     */
    private function representedCandidate(): array
    {
        $state = $this->address('Maharashtra', 'state', 1, null);
        $district = $this->address('Pune', 'district', 2, $state);
        $taluka = $this->address('Shirur', 'taluka', 3, $district);
        $village = $this->address('Ranjangaon', 'village', 4, $taluka, 'rural');

        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Sunita Gaikwad',
            'location_id' => $village,
        ]);

        $representation = SuchakProfileRepresentation::factory()->create([
            'matrimony_profile_id' => $profile->id,
        ]);

        return [$profile->fresh(), $representation];
    }

    private function address(string $name, string $hierarchy, int $level, ?int $parent, ?string $tag = null): int
    {
        return DB::table('addresses')->insertGetId(array_filter([
            'name' => $name,
            'slug' => strtolower($name).'-'.$hierarchy,
            'hierarchy' => $hierarchy,
            'level' => $level,
            'parent_id' => $parent,
            'tag' => $tag,
            'created_at' => now(),
            'updated_at' => now(),
        ], static fn ($v): bool => $v !== null));
    }
}
