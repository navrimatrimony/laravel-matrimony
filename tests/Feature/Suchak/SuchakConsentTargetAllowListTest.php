<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Product rule: a consent request may only be sent to a number ALREADY stored
 * against that person's profile.
 *
 * Before this was enforced, `intended_mobile` was validated as
 * ['required','string','max:20'] and nothing more — so a Suchak could aim the
 * consent link at their own phone, tick "I agree" themselves, and end up
 * holding a consent for someone who never agreed. The read endpoint that
 * defines the allowed set was never consulted by the write path.
 *
 * These tests attack that path directly.
 */
class SuchakConsentTargetAllowListTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: int, 1: int, 2: string} representation id, profile id, candidate mobile */
    private function createRepresentation(string $suchakMobile, string $candidateMobile): array
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
            'candidate_name' => 'Allow List Candidate',
            'candidate_mobile' => $candidateMobile,
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertCreated();

        return [
            (int) $create->json('data.representation_id'),
            (int) $create->json('data.profile_id'),
            $candidateMobile,
        ];
    }

    public function test_a_suchak_cannot_route_a_consent_request_to_their_own_number(): void
    {
        $suchakOwnNumber = '9876507101';
        [$representationId] = $this->createRepresentation($suchakOwnNumber, '9876507102');

        $this->postJson("/api/v1/suchak/customers/{$representationId}/consents", [
            'consent_given_by_name' => 'Not The Candidate',
            'consent_giver_relation' => 'candidate_self',
            'intended_mobile' => $suchakOwnNumber,
        ])->assertStatus(422);

        $this->assertSame(
            0,
            SuchakConsent::query()->where('representation_id', $representationId)->count(),
            'No consent row may exist for a number that is not on the profile.',
        );
    }

    public function test_an_arbitrary_unrelated_number_is_refused(): void
    {
        [$representationId] = $this->createRepresentation('9876507103', '9876507104');

        $this->postJson("/api/v1/suchak/customers/{$representationId}/consents", [
            'consent_given_by_name' => 'Accomplice',
            'consent_giver_relation' => 'father',
            'intended_mobile' => '9000099999',
        ])->assertStatus(422);

        $this->assertSame(0, SuchakConsent::query()->where('representation_id', $representationId)->count());
    }

    public function test_the_candidates_own_stored_number_is_accepted(): void
    {
        [$representationId, , $candidateMobile] = $this->createRepresentation('9876507105', '9876507106');

        $this->postJson("/api/v1/suchak/customers/{$representationId}/consents", [
            'consent_given_by_name' => 'Allow List Candidate',
            'consent_giver_relation' => 'candidate_self',
            'intended_mobile' => $candidateMobile,
        ])->assertSuccessful();

        $this->assertSame(1, SuchakConsent::query()->where('representation_id', $representationId)->count());
    }

    public function test_a_fathers_number_recorded_on_the_profile_is_accepted(): void
    {
        [$representationId, $profileId] = $this->createRepresentation('9876507107', '9876507108');

        $fatherMobile = '9822044444';
        DB::table('matrimony_profiles')->where('id', $profileId)->update([
            'father_contact_1' => $fatherMobile,
            'father_name' => 'Recorded Father',
        ]);

        $this->postJson("/api/v1/suchak/customers/{$representationId}/consents", [
            'consent_given_by_name' => 'Recorded Father',
            'consent_giver_relation' => 'father',
            'intended_mobile' => $fatherMobile,
        ])->assertSuccessful();

        $this->assertSame(1, SuchakConsent::query()->where('representation_id', $representationId)->count());
    }

    public function test_a_number_stored_in_profile_contacts_is_accepted(): void
    {
        [$representationId, $profileId] = $this->createRepresentation('9876507109', '9876507110');

        // profile_contacts keys the relation by FK (contact_relation_id), not by
        // a relation_type string — reading the wrong column used to blow this
        // whole list up, which silently emptied the allow-list.
        $relationId = DB::table('master_contact_relations')->where('key', 'father')->value('id')
            ?? DB::table('master_contact_relations')->insertGetId([
                'key' => 'father', 'label' => 'Father', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

        $contactMobile = '9822055555';
        DB::table('profile_contacts')->insert([
            'profile_id' => $profileId,
            'contact_relation_id' => $relationId,
            'contact_name' => 'Stored Contact',
            'phone_number' => $contactMobile,
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/suchak/customers/{$representationId}/consents", [
            'consent_given_by_name' => 'Stored Contact',
            'consent_giver_relation' => 'father',
            'intended_mobile' => $contactMobile,
        ])->assertSuccessful();

        $this->assertSame(1, SuchakConsent::query()->where('representation_id', $representationId)->count());
    }

    public function test_the_consent_contacts_list_survives_a_profile_contacts_row(): void
    {
        [$representationId, $profileId] = $this->createRepresentation('9876507111', '9876507112');

        $relationId = DB::table('master_contact_relations')->where('key', 'sibling')->value('id')
            ?? DB::table('master_contact_relations')->insertGetId([
                'key' => 'sibling', 'label' => 'Sibling', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

        DB::table('profile_contacts')->insert([
            'profile_id' => $profileId,
            'contact_relation_id' => $relationId,
            'contact_name' => 'Sibling Contact',
            'phone_number' => '9822066666',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Regression guard: this endpoint 500'd on production because the query
        // selected a column that does not exist on profile_contacts.
        $options = $this->getJson("/api/v1/suchak/nxt/{$representationId}/consent-contacts")
            ->assertOk()
            ->json('data.options');

        $mobiles = array_column($options, 'mobile');
        $this->assertContains('9822066666', $mobiles);

        $sibling = collect($options)->firstWhere('mobile', '9822066666');
        $this->assertNotSame(
            'self',
            $sibling['role'],
            'An unrelated stored contact must never be presented as the candidate themselves.',
        );
    }
}
