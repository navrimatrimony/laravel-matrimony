<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A Suchak typed his customer's father details, saw "saved", reopened the
 * section and found it empty — reported from a real device.
 *
 * Cause: every profile save LOCKED every key present in the payload, including
 * keys whose value was null. The apps post a whole section at once, blank
 * inputs and all, so one save planted locks on fields nobody had filled. A
 * later save by a DIFFERENT actor is then skipped inside MutationService —
 * and manual mode never reports a conflict, so the request still answered
 * HTTP 200 "Matrimony profile updated" with the value thrown away.
 *
 * On production, profile 261 carried locks on 30+ fields owned by another
 * actor. 809 lock rows existed across 40 profiles.
 */
class SuchakEditFieldLockTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: int, 1: int} representation id, profile id */
    private function suchakWithCustomer(string $suchakMobile, string $candidateMobile): array
    {
        MasterGender::query()->firstOrCreate(['key' => 'female'], ['label' => 'Female', 'is_active' => true]);

        $user = User::factory()->create(['mobile' => $suchakMobile, 'mobile_verified_at' => now()]);
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Lock Test Candidate',
            'candidate_mobile' => $candidateMobile,
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertCreated();

        return [(int) $create->json('data.representation_id'), (int) $create->json('data.profile_id')];
    }

    public function test_a_blank_field_in_a_saved_section_is_not_locked(): void
    {
        [$representationId, $profileId] = $this->suchakWithCustomer('9876508101', '9876508102');

        // Save the family section with only ONE field filled — exactly what the
        // app posts when a Suchak fills a single box.
        $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", [
            'father_name' => 'Ramesh Patil',
            'mother_name' => null,
            'father_occupation' => '',
        ])->assertOk();

        $locked = DB::table('profile_field_locks')
            ->where('profile_id', $profileId)
            ->pluck('field_key')
            ->all();

        $this->assertContains('father_name', $locked, 'A field the actor actually filled should be locked.');
        $this->assertNotContains('mother_name', $locked, 'A null field must never be locked — nobody set it.');
        $this->assertNotContains('father_occupation', $locked, 'An empty string must never be locked either.');
    }

    public function test_another_actors_lock_no_longer_swallows_a_suchak_save(): void
    {
        [$representationId, $profileId] = $this->suchakWithCustomer('9876508103', '9876508104');

        // Someone else (the candidate's own account, or an admin) locked the
        // field first. This is the production state that broke the save.
        $otherActorId = (int) User::factory()->create(['mobile' => '9876508199'])->id;
        DB::table('profile_field_locks')->insert([
            'profile_id' => $profileId,
            'field_key' => 'father_name',
            'field_type' => 'CORE',
            'locked_by' => $otherActorId,
            'locked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", [
            'father_name' => 'Ramesh Patil',
        ])->assertOk();

        $stored = DB::table('matrimony_profiles')->where('id', $profileId)->value('father_name');

        // Either the value lands, or the caller is TOLD it did not. What must
        // never happen again is a bare 200 with the value silently gone.
        if ($stored !== 'Ramesh Patil') {
            $this->assertNotEmpty(
                $response->json('skipped_locked_fields'),
                'A save that discarded a value must report it, never answer a bare "updated".',
            );
            $this->assertContains('father_name', $response->json('skipped_locked_fields'));
        } else {
            $this->assertSame('Ramesh Patil', $stored);
        }
    }

    public function test_the_family_section_round_trips_on_a_fresh_customer(): void
    {
        [$representationId, $profileId] = $this->suchakWithCustomer('9876508105', '9876508106');

        $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", [
            'father_name' => 'Ramesh Patil',
            'father_occupation' => 'Farmer',
            'mother_name' => 'Sunita Patil',
        ])->assertOk();

        $row = DB::table('matrimony_profiles')->where('id', $profileId)->first();
        $this->assertSame('Ramesh Patil', $row->father_name);
        $this->assertSame('Farmer', $row->father_occupation);
        $this->assertSame('Sunita Patil', $row->mother_name);

        // And reading it back through the API the app actually uses.
        $read = $this->getJson("/api/v1/suchak/nxt/{$representationId}/profile")->assertOk();
        $this->assertSame('Ramesh Patil', $read->json('profile.father_name'));
    }

    public function test_a_second_save_by_the_same_suchak_still_works(): void
    {
        [$representationId, $profileId] = $this->suchakWithCustomer('9876508107', '9876508108');

        $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", ['father_name' => 'First Name'])->assertOk();
        $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", ['father_name' => 'Corrected Name'])->assertOk();

        $this->assertSame(
            'Corrected Name',
            DB::table('matrimony_profiles')->where('id', $profileId)->value('father_name'),
            'A Suchak must always be able to correct their own earlier entry.',
        );
    }
}
