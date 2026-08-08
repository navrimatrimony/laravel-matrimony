<?php

namespace Tests\Feature\Governance;

use App\Models\ConflictRecord;
use App\Models\FieldRegistry;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\ConflictDetectionService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use App\Services\ProfileLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A conflict is two sides claiming a different value for the same fact.
 *
 * Filling a field nobody had answered is not that, and treating it as one had a
 * consequence far out of proportion to the fact involved: one PENDING record on
 * an optional field flips the whole profile to conflict_pending, which hides it
 * from every search until an admin clears the record by hand. Members had no
 * way to see the reason and no way to act on it.
 */
class EmptyFieldIsNotAConflictTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Conflict detection walks the CORE field registry, which is data rather
     * than code, so a fresh test database has to declare the fields these
     * tests reason about.
     */
    private function registerCoreFields(string ...$keys): void
    {
        foreach ($keys as $key) {
            FieldRegistry::query()->firstOrCreate(
                ['field_key' => $key],
                [
                    'field_type' => 'CORE',
                    'data_type' => 'text',
                    'display_label' => $key,
                    'is_user_editable' => true,
                    'lock_after_user_edit' => false,
                ]
            );
        }
    }

    /**
     * A live profile: born draft (the observer only allows a null residence
     * there), given a real residence, then moved to active the way the rest of
     * the system does it.
     */
    private function activeProfile(): MatrimonyProfile
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);

        // Inserted straight into the geo table: the model would demand a whole
        // country→state→district→taluka chain, and none of that is what these
        // tests are about.
        $locationId = DB::table(Location::geoTable())->insertGetId([
            'name' => 'Sakhali Khurd',
            'slug' => 'sakhali-khurd',
            'hierarchy' => 'village',
            'level' => 4,
            'tag' => 'city',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Residence lives in profile_addresses, not on the profile row.
        ProfileCanonicalResidenceService::upsertSelfCurrent($profile->id, $locationId, null, true, false);

        DB::table('matrimony_profiles')
            ->where('id', $profile->id)
            ->update(['lifecycle_state' => 'active']);

        return $profile->fresh();
    }

    public function test_filling_an_empty_field_does_not_raise_a_conflict(): void
    {
        $this->registerCoreFields('other_relatives_text');
        $profile = $this->activeProfile();
        $profile->forceFill(['other_relatives_text' => null])->save();

        $created = ConflictDetectionService::detect($profile, [
            'other_relatives_text' => 'जाधव; पवार; शेळके',
        ], []);

        $this->assertSame([], $created);
        $this->assertSame(0, ConflictRecord::query()->where('profile_id', $profile->id)->count());
    }

    public function test_filling_an_empty_field_leaves_the_profile_visible(): void
    {
        $this->registerCoreFields('property_details');
        $profile = $this->activeProfile();
        $profile->forceFill(['property_details' => null])->save();

        ConflictDetectionService::detect($profile, [
            'property_details' => 'शेती, साखळी खुर्द',
        ], []);
        ProfileLifecycleService::syncLifecycleFromPendingConflicts($profile);

        $profile->refresh();
        $this->assertSame('active', $profile->lifecycle_state);
        $this->assertTrue(ProfileLifecycleService::isVisibleToOthers($profile));
    }

    public function test_a_real_disagreement_still_raises_a_conflict(): void
    {
        // The guard must not disarm governance: when the profile already says
        // one thing and something proposes another, that is still a conflict.
        $this->registerCoreFields('other_relatives_text');
        $profile = $this->activeProfile();
        $profile->forceFill(['other_relatives_text' => 'जाधव'])->save();

        $created = ConflictDetectionService::detect($profile, [
            'other_relatives_text' => 'पवार',
        ], []);

        $this->assertCount(1, $created);
        $this->assertSame('other_relatives_text', $created[0]->field_name);

        ProfileLifecycleService::syncLifecycleFromPendingConflicts($profile);
        $this->assertSame('conflict_pending', $profile->fresh()->lifecycle_state);
    }

    public function test_an_identity_critical_change_still_raises_a_conflict(): void
    {
        $this->registerCoreFields('full_name');
        // The factory already gave this profile a name, and the model refuses a
        // direct identity rewrite on save — which is the point: the only way to
        // propose a different one is through detection.
        $profile = $this->activeProfile();
        $this->assertNotEmpty($profile->full_name);

        $created = ConflictDetectionService::detect($profile, [
            'full_name' => $profile->full_name.' (someone else)',
        ], []);

        $this->assertCount(1, $created);
        $this->assertSame('full_name', $created[0]->field_name);
    }

    public function test_a_blank_proposal_over_a_real_value_is_still_a_conflict(): void
    {
        // The guard is one-directional on purpose: erasing an answer someone
        // gave is exactly the kind of change governance exists to catch.
        $this->registerCoreFields('other_relatives_text');
        $profile = $this->activeProfile();
        $profile->forceFill(['other_relatives_text' => 'जाधव'])->save();

        $created = ConflictDetectionService::detect($profile, [
            'other_relatives_text' => '',
        ], []);

        $this->assertCount(1, $created);
    }
}
