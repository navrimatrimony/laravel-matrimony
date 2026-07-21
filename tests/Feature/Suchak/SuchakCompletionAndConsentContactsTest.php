<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\User;
use App\Support\ConsentContactRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A5 (PO decision 2026-07-22):
 * - Suchak GET carries the SAME completion signal members use
 *   (ProfileCompletionService reused, not re-implemented).
 * - Consent may only target numbers already stored on the profile; the system
 *   suggests a fallback number after the configured no-response window rather
 *   than sending anything itself.
 */
class SuchakCompletionAndConsentContactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_get_reports_completion_and_incomplete_sections(): void
    {
        [$representationId] = $this->createRepresentation('9876506101', '9876506102');

        $response = $this->getJson("/api/v1/suchak/nxt/{$representationId}/profile")->assertOk();

        $response->assertJsonStructure([
            'data' => ['completion' => ['percent', 'sections', 'incomplete_sections']],
        ]);

        // A freshly created shell is far from complete, and photo must be listed.
        $this->assertLessThan(100, (int) $response->json('data.completion.percent'));
        $this->assertContains('photo', $response->json('data.completion.incomplete_sections'));
    }

    public function test_consent_contacts_lists_only_stored_numbers_in_priority_order(): void
    {
        [$representationId, $profileId] = $this->createRepresentation('9876506103', '9876506104');

        DB::table('matrimony_profiles')->where('id', $profileId)->update([
            'father_contact_1' => '9822011111',
            'father_name' => 'Ramesh Patil',
        ]);
        DB::table('profile_siblings')->insert([
            'profile_id' => $profileId,
            'relation_type' => 'brother',
            'name' => 'Rohan Patil',
            'contact_number' => '9822022222',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/suchak/nxt/{$representationId}/consent-contacts")->assertOk();
        $options = $response->json('data.options');

        $roles = array_column($options, 'role');
        $this->assertSame(ConsentContactRole::SELF, $roles[0], 'Candidate own number must be first.');
        $this->assertContains(ConsentContactRole::FATHER, $roles);
        $this->assertContains(ConsentContactRole::SIBLING, $roles);
        $this->assertLessThan(
            array_search(ConsentContactRole::SIBLING, $roles, true),
            array_search(ConsentContactRole::FATHER, $roles, true),
            'Father must be tried before sibling.'
        );

        // Numbers are masked for display and carry an owner label.
        $father = collect($options)->firstWhere('role', ConsentContactRole::FATHER);
        $this->assertSame('98220•••11', $father['mobile_masked']);
        $this->assertSame('Ramesh Patil', $father['owner_name']);
        $this->assertSame('वडील', $father['role_label_mr']);

        // Nothing pending yet, so no fallback nudge.
        $this->assertFalse($response->json('data.suggest_alternate'));
    }

    public function test_alternate_number_suggested_only_after_no_response_window(): void
    {
        [$representationId, $profileId] = $this->createRepresentation('9876506105', '9876506106');
        DB::table('matrimony_profiles')->where('id', $profileId)->update(['father_contact_1' => '9822033333']);

        $ownMobile = (string) DB::table('users')
            ->where('id', DB::table('matrimony_profiles')->where('id', $profileId)->value('user_id'))
            ->value('mobile');

        // Create through the real consent API rather than a raw insert, so the
        // test also proves the suggestion reads what the live flow writes.
        $this->postJson("/api/v1/suchak/customers/{$representationId}/consents", [
            'consent_given_by_name' => 'Test Giver',
            'consent_giver_relation' => 'candidate_self',
            'intended_mobile' => $ownMobile,
        ])->assertSuccessful();

        $consentId = (int) DB::table('suchak_consents')
            ->where('matrimony_profile_id', $profileId)
            ->latest('id')
            ->value('id');

        // Fresh request → no suggestion yet.
        $this->getJson("/api/v1/suchak/nxt/{$representationId}/consent-contacts")
            ->assertOk()
            ->assertJsonPath('data.suggest_alternate', false);

        // Age it past the shared no-response window (same config the bulk-intake
        // auto-advance command uses) → suggestion appears.
        $hours = max(1, (int) config('whatsapp.bulk_consent_no_response_hours', 72));
        DB::table('suchak_consents')->where('id', $consentId)->update([
            'created_at' => now()->subHours($hours + 1),
        ]);

        $response = $this->getJson("/api/v1/suchak/nxt/{$representationId}/consent-contacts")->assertOk();
        $this->assertTrue($response->json('data.suggest_alternate'));
        $this->assertSame('no_response', $response->json('data.suggestion_reason'));

        // The already-tried number is flagged so the app can steer elsewhere.
        $tried = collect($response->json('data.options'))->firstWhere('mobile', $ownMobile);
        $this->assertTrue($tried['already_tried']);
    }

    public function test_other_suchak_cannot_read_consent_contacts(): void
    {
        [$representationId] = $this->createRepresentation('9876506107', '9876506108');

        $intruder = User::factory()->create(['mobile' => '9876506109', 'mobile_verified_at' => now()]);
        SuchakAccount::factory()->create([
            'user_id' => $intruder->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        Sanctum::actingAs($intruder);

        $this->getJson("/api/v1/suchak/nxt/{$representationId}/consent-contacts")->assertNotFound();
    }

    /** @return array{0: int, 1: int} representation id, profile id */
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
            'candidate_name' => 'Completion Candidate',
            'candidate_mobile' => $candidateMobile,
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertCreated();

        return [(int) $create->json('data.representation_id'), (int) $create->json('data.profile_id')];
    }
}
