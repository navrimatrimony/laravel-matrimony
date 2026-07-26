<?php

namespace Tests\Feature\Matching;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * API reachability for "गुणमिलन जुळणे आवश्यक".
 *
 * The web wizard could already set profile_preference_criteria.gunamilan_required
 * (see GunamilanRequiredToggleTest), but an app-only user had no route to it at
 * all — the key was dropped by MOBILE_PARTNER_PREFERENCE_INPUT_KEYS on the way
 * in and was absent from the read payload on the way out, so the gate stayed
 * permanently off for them.
 *
 * Both apps share one contract:
 *   member : GET/PUT  /api/v1/matrimony-profile
 *   Suchak : GET/PUT  /api/v1/suchak/nxt/{representation}/profile
 * The Suchak route is a thin adapter over the same controller methods, so both
 * are pinned here — a regression in either allow-list breaks both apps at once.
 *
 * The value crosses these hops, each of which silently drops unknown keys:
 *   request -> buildMobileProfileSnapshotFromApi()  ('preferences' fragment)
 *           -> MutationService::syncPreferencesFromSnapshot()  ($allowed list)
 *           -> buildGovernanceParityProfilePayload()  (read payload)
 */
class GunamilanRequiredApiToggleTest extends TestCase
{
    use RefreshDatabase;

    private function genderId(string $key = 'female'): int
    {
        return (int) MasterGender::query()->firstOrCreate(
            ['key' => $key],
            ['label' => ucfirst($key), 'is_active' => true],
        )->id;
    }

    private function storedFlag(int $profileId): ?int
    {
        $value = DB::table('profile_preference_criteria')
            ->where('profile_id', $profileId)
            ->value('gunamilan_required');

        return $value === null ? null : (int) $value;
    }

    public function test_member_api_reads_the_default_and_round_trips_both_ways(): void
    {
        $genderId = $this->genderId();
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'gender_id' => $genderId,
        ]);

        Sanctum::actingAs($user);

        // Read: a profile with no criteria row at all still reports a definite
        // OFF, so the app toggle has a state to bind to on day one.
        $this->getJson('/api/v1/matrimony-profile')
            ->assertOk()
            ->assertJsonPath('profile.gunamilan_required', false);

        // Write true.
        $this->putJson('/api/v1/matrimony-profile', [
            'gender_id' => $genderId,
            'gunamilan_required' => true,
        ])
            ->assertOk()
            ->assertJsonPath('profile.gunamilan_required', true);

        $this->assertSame(1, $this->storedFlag((int) $profile->id), 'The API write must reach the column, not a snapshot that gets dropped.');

        // Read it back.
        $this->getJson('/api/v1/matrimony-profile')
            ->assertOk()
            ->assertJsonPath('profile.gunamilan_required', true);

        // Write false.
        $this->putJson('/api/v1/matrimony-profile', [
            'gender_id' => $genderId,
            'gunamilan_required' => false,
        ])
            ->assertOk()
            ->assertJsonPath('profile.gunamilan_required', false);

        $this->assertSame(0, $this->storedFlag((int) $profile->id), 'Turning it off must persist an explicit false.');

        // Read it back.
        $this->getJson('/api/v1/matrimony-profile')
            ->assertOk()
            ->assertJsonPath('profile.gunamilan_required', false);
    }

    public function test_a_member_save_that_omits_the_key_does_not_clear_a_saved_true(): void
    {
        $genderId = $this->genderId();
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'gender_id' => $genderId,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/matrimony-profile', [
            'gender_id' => $genderId,
            'gunamilan_required' => true,
        ])->assertOk();
        $this->assertSame(1, $this->storedFlag((int) $profile->id));

        // A screen that saves partner preferences but has no gunamilan toggle on
        // it (every app screen except kundali) must not wipe the user's choice.
        $this->putJson('/api/v1/matrimony-profile', [
            'gender_id' => $genderId,
            'preferred_age_min' => 24,
            'preferred_age_max' => 30,
        ])->assertOk();

        $this->assertSame(1, $this->storedFlag((int) $profile->id), 'An unrelated preference save must leave gunamilan alone.');
        $this->getJson('/api/v1/matrimony-profile')
            ->assertOk()
            ->assertJsonPath('profile.gunamilan_required', true);

        // Same for a save that touches nothing but the kundali fields.
        $this->putJson('/api/v1/matrimony-profile', [
            'gender_id' => $genderId,
            'gotra' => 'Kashyap',
        ])->assertOk();

        $this->assertSame(1, $this->storedFlag((int) $profile->id), 'A kundali save without the toggle must leave gunamilan alone.');
    }

    public function test_suchak_api_round_trips_the_toggle_for_a_represented_candidate(): void
    {
        $genderId = $this->genderId();

        $suchakUser = User::factory()->create([
            'mobile' => '9876505701',
            'mobile_verified_at' => now(),
        ]);
        SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'suchak_name' => 'Gunamilan Suchak',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        Sanctum::actingAs($suchakUser);

        $create = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Sharvari Deshmukh',
            'candidate_mobile' => '9876505799',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertCreated();

        $representationId = (int) $create->json('data.representation_id');
        $profileId = (int) $create->json('data.profile_id');

        $this->getJson("/api/v1/suchak/nxt/{$representationId}/profile")
            ->assertOk()
            ->assertJsonPath('profile.gunamilan_required', false);

        $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", [
            'gender_id' => $genderId,
            'gunamilan_required' => true,
        ])
            ->assertOk()
            ->assertJsonPath('profile.gunamilan_required', true);

        $this->assertSame(1, $this->storedFlag($profileId));

        $this->getJson("/api/v1/suchak/nxt/{$representationId}/profile")
            ->assertOk()
            ->assertJsonPath('profile.gunamilan_required', true);

        // A later Suchak save with no toggle on the screen must not clear it.
        $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", [
            'gender_id' => $genderId,
            'gotra' => 'Bharadwaj',
        ])->assertOk();
        $this->assertSame(1, $this->storedFlag($profileId), 'A Suchak save without the toggle must leave gunamilan alone.');

        $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", [
            'gender_id' => $genderId,
            'gunamilan_required' => false,
        ])
            ->assertOk()
            ->assertJsonPath('profile.gunamilan_required', false);

        $this->assertSame(0, $this->storedFlag($profileId));

        $this->getJson("/api/v1/suchak/nxt/{$representationId}/profile")
            ->assertOk()
            ->assertJsonPath('profile.gunamilan_required', false);
    }

    public function test_the_column_exists_where_the_contract_says_it_does(): void
    {
        // Guards against the field quietly moving to a second home — the whole
        // point of this feature is one fact with exactly one destination.
        $this->assertTrue(
            Schema::hasColumn('profile_preference_criteria', 'gunamilan_required'),
            'gunamilan_required must stay on profile_preference_criteria.',
        );
    }
}
