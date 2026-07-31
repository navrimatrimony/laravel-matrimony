<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PO decision 2026-07-31: whose number it is decides what the number IS.
 *
 * Only a number the Suchak says belongs to the candidate becomes that account's
 * login mobile. A father's or a brother's is a household line — the family
 * shares it, two sisters on one number is ordinary here — so it is stored as a
 * profile contact instead. Before this, every Suchak-created customer took the
 * typed number as their own account mobile, which is unique, so the second
 * sister could not be registered at all.
 */
class SuchakHouseholdMobileCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_sister_can_be_registered_on_the_family_number(): void
    {
        $this->actingAsVerifiedSuchak('9876506201');

        $first = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Sunita Gaikwad',
            'candidate_mobile' => '9876506202',
            'candidate_gender' => 'female',
            'registering_for' => 'sibling',
        ])->assertCreated()->assertJsonPath('data.outcome', 'created');

        $second = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Rashi Gaikwad',
            'candidate_mobile' => '9876506202',
            'candidate_gender' => 'female',
            'registering_for' => 'sibling',
        ])->assertCreated()->assertJsonPath('data.outcome', 'created');

        $this->assertNotSame($first->json('data.profile_id'), $second->json('data.profile_id'));

        // Neither took the number as a login identity.
        $this->assertSame(0, User::query()->where('mobile', '9876506202')->count());

        // Both are still reachable on it.
        $this->assertSame(2, DB::table('profile_contacts')->where('phone_number', '9876506202')->count());
    }

    public function test_the_household_number_is_stored_with_the_relation_and_stays_reachable(): void
    {
        $this->actingAsVerifiedSuchak('9876506203');

        $response = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Ganesh Jadhav',
            'candidate_mobile' => '9876506204',
            'candidate_gender' => 'male',
            'registering_for' => 'parent_guardian',
        ])->assertCreated();

        $profileId = (int) $response->json('data.profile_id');
        $contact = DB::table('profile_contacts')->where('profile_id', $profileId)->first();

        $this->assertNotNull($contact);
        $this->assertSame('9876506204', $contact->phone_number);
        $this->assertTrue((bool) $contact->is_primary);

        $relationId = DB::table('master_contact_relations')->where('key', 'guardian')->value('id');
        $this->assertSame((int) $relationId, (int) $contact->contact_relation_id);

        // Consent still goes to the number that was typed: primary_contact_number
        // prefers the contact row over the (now empty) account mobile.
        $profile = MatrimonyProfile::query()->findOrFail($profileId);
        $this->assertSame('9876506204', $profile->primary_contact_number);
    }

    public function test_the_candidates_own_number_still_becomes_their_account_mobile(): void
    {
        $this->actingAsVerifiedSuchak('9876506205');

        $response = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Pooja Shinde',
            'candidate_mobile' => '9876506206',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertCreated();

        $profile = MatrimonyProfile::query()->findOrFail((int) $response->json('data.profile_id'));
        $this->assertSame('9876506206', $profile->user?->mobile);
        $this->assertSame(0, DB::table('profile_contacts')->where('profile_id', $profile->id)->count());
    }

    public function test_the_own_number_route_still_refuses_a_second_account(): void
    {
        $this->actingAsVerifiedSuchak('9876506207');

        $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Anjali More',
            'candidate_mobile' => '9876506208',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertCreated();

        // Claiming the same number as a second person's OWN is still a
        // duplicate, and still hands over to consent rather than creating.
        $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Someone Else',
            'candidate_mobile' => '9876506208',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertStatus(409)->assertJsonPath('data.outcome', 'existing_profile_confirmation_required');
    }

    private function actingAsVerifiedSuchak(string $mobile): SuchakAccount
    {
        foreach ([['female', 'Female'], ['male', 'Male']] as [$key, $label]) {
            MasterGender::query()->firstOrCreate(['key' => $key], ['label' => $label, 'is_active' => true]);
        }

        $user = User::factory()->create(['mobile' => $mobile, 'mobile_verified_at' => now()]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        Sanctum::actingAs($user);

        return $account;
    }
}
