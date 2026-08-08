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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The owner's rule, in one line: a machine fills what is EMPTY and never
 * touches what is ANSWERED.
 *
 * Both halves used to raise a PENDING conflict, and one PENDING record flips
 * the whole profile to conflict_pending — hidden from every search until an
 * admin clears it by hand. A paid member sat invisible for 37 days over a
 * biodata sheet reading 180.34 where his profile said 168, a question nobody
 * needed to answer because the profile's answer already stood.
 */
class MachineNeverOverwritesAnAnsweredFieldTest extends TestCase
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

    public function test_an_answered_field_is_left_alone_and_raises_nothing(): void
    {
        $this->registerCoreFields('other_relatives_text');
        $profile = $this->activeProfile();
        $profile->forceFill(['other_relatives_text' => 'जाधव'])->save();

        $created = ConflictDetectionService::detect($profile, [
            'other_relatives_text' => 'पवार',
        ], []);

        $this->assertSame([], $created);
        $this->assertSame(0, ConflictRecord::query()->where('profile_id', $profile->id)->count());

        // And the profile stays where anybody can find it.
        ProfileLifecycleService::syncLifecycleFromPendingConflicts($profile);
        $profile->refresh();
        $this->assertSame('active', $profile->lifecycle_state);
        $this->assertSame('जाधव', $profile->other_relatives_text);
    }

    public function test_a_machine_cannot_blank_an_answer_either(): void
    {
        $this->registerCoreFields('other_relatives_text');
        $profile = $this->activeProfile();
        $profile->forceFill(['other_relatives_text' => 'जाधव'])->save();

        $created = ConflictDetectionService::detect($profile, [
            'other_relatives_text' => '',
        ], []);

        $this->assertSame([], $created);
        $this->assertSame('जाधव', $profile->fresh()->other_relatives_text);
    }

    public function test_a_direct_overwrite_of_a_governed_field_is_still_refused(): void
    {
        // Detection stopped filing disputes; it must not have stopped the save
        // guard, which is what keeps a governed value from being rewritten
        // behind MutationService's back.
        $this->registerCoreFields('full_name');
        $profile = $this->activeProfile();
        $this->assertNotEmpty($profile->full_name);

        $this->expectException(ValidationException::class);
        $profile->forceFill(['full_name' => $profile->full_name.' (someone else)'])->save();
    }

    public function test_a_refused_overwrite_leaves_no_ghost_record_behind(): void
    {
        $this->registerCoreFields('full_name');
        $profile = $this->activeProfile();

        try {
            $profile->forceFill(['full_name' => 'Someone Else'])->save();
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, ConflictRecord::query()->where('profile_id', $profile->id)->count());
        $this->assertSame('active', $profile->fresh()->lifecycle_state);
    }
}
