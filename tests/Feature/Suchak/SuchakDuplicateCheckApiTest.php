<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Support\NameMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pre-create duplicate check (PO decision 2026-07-22).
 *
 * Approved scoring: mobile+name+DOB+gender together decide; mobile alone is
 * NOT decisive (family members share numbers); fuzzy names must match common
 * Marathi spelling variants (Shriram/Sriram) and token order (Kadam Shriram).
 * The endpoint reports — it never blocks.
 */
class SuchakDuplicateCheckApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_matcher_folds_marathi_spelling_variants(): void
    {
        $this->assertSame(NameMatcher::LEVEL_STRONG, NameMatcher::matchLevel('Shriram Kadam', 'Sriram Kadam'));
        $this->assertSame(NameMatcher::LEVEL_STRONG, NameMatcher::matchLevel('Shreeram Kadam', 'Shriram Kadam'));
        $this->assertSame(NameMatcher::LEVEL_STRONG, NameMatcher::matchLevel('Kadam Shriram', 'Shriram Kadam'));
        $this->assertSame(NameMatcher::LEVEL_STRONG, NameMatcher::matchLevel('Jayram Patil', 'Jairam Patil'));
        $this->assertSame(NameMatcher::LEVEL_EXACT, NameMatcher::matchLevel('Shriram Kadam', 'shriram  kadam'));
        $this->assertSame(NameMatcher::LEVEL_NONE, NameMatcher::matchLevel('Shriram Kadam', 'Ganesh Jadhav'));
    }

    public function test_own_mobile_plus_identity_is_confirmed_and_linkable(): void
    {
        $this->actingAsVerifiedSuchak('9876505901');
        $profileId = $this->existingMember('9876505902', 'Shriram Kadam', '2000-05-10', 'male');

        $response = $this->postJson('/api/v1/suchak/manual-profiles/duplicate-check', [
            'candidate_name' => 'Sriram Kadam',
            'candidate_mobile' => '9876505902',
            'date_of_birth' => '2000-05-10',
            'candidate_gender' => 'male',
        ])->assertOk()->assertJsonPath('success', true);

        $match = collect($response->json('data.matches'))->firstWhere('profile_id', $profileId);
        $this->assertNotNull($match, 'Existing member must be reported as duplicate.');
        $this->assertSame('confirmed', $match['confidence']);
        $this->assertTrue($match['can_link_existing']);
        $this->assertFalse($match['shared_number_possible']);
        $this->assertSame('strong', $match['signals']['name']);
        // Masked display only — the raw stored name must not leak verbatim keys.
        $this->assertSame('Shriram K.', $match['display_name']);
        $this->assertArrayNotHasKey('full_name', $match);
    }

    public function test_father_number_match_alone_is_high_with_shared_number_flag(): void
    {
        $this->actingAsVerifiedSuchak('9876505903');
        $profileId = $this->existingMember('9876505904', 'Sunita Pawar', '2002-01-15', 'female');
        DB::table('matrimony_profiles')->where('id', $profileId)->update(['father_contact_1' => '9876505999']);

        $response = $this->postJson('/api/v1/suchak/manual-profiles/duplicate-check', [
            'candidate_name' => 'Completely Different Person',
            'candidate_mobile' => '9876505999',
        ])->assertOk();

        $match = collect($response->json('data.matches'))->firstWhere('profile_id', $profileId);
        $this->assertNotNull($match);
        $this->assertSame('high', $match['confidence']);
        $this->assertTrue($match['shared_number_possible'], 'Father-slot hit must warn about shared family numbers.');
        $this->assertFalse($match['can_link_existing'], 'Linking applies only to the candidate\'s own account mobile.');
        $this->assertContains('father', $match['signals']['mobile_sources']);
    }

    public function test_fuzzy_name_dob_gender_without_mobile_is_high(): void
    {
        $this->actingAsVerifiedSuchak('9876505905');
        $profileId = $this->existingMember('9876505906', 'Shriram Kadam', '2000-05-10', 'male');

        $response = $this->postJson('/api/v1/suchak/manual-profiles/duplicate-check', [
            'candidate_name' => 'Sriram Kadam',
            'candidate_mobile' => '9700000777', // different, unknown number
            'date_of_birth' => '2000-05-10',
            'candidate_gender' => 'male',
        ])->assertOk();

        $match = collect($response->json('data.matches'))->firstWhere('profile_id', $profileId);
        $this->assertNotNull($match, 'Identity (fuzzy name + DOB + gender) must match without any mobile hit.');
        $this->assertSame('high', $match['confidence']);
        $this->assertFalse($match['signals']['mobile']);
    }

    public function test_no_signals_returns_empty_and_never_blocks(): void
    {
        $this->actingAsVerifiedSuchak('9876505907');
        $this->existingMember('9876505908', 'Ganesh Jadhav', '1999-09-09', 'male');

        $this->postJson('/api/v1/suchak/manual-profiles/duplicate-check', [
            'candidate_name' => 'Totally New Person',
            'candidate_mobile' => '9700000778',
            'date_of_birth' => '2001-02-02',
            'candidate_gender' => 'female',
        ])->assertOk()->assertJsonPath('data.match_count', 0);
    }

    /**
     * Ownership classification (2026-07-26). Before this the service only ever
     * queried its OWN representations, so it could only say "mine" — the app
     * had no way to tell a free profile from one another Suchak already holds.
     */
    public function test_profile_already_represented_by_me_is_owner_type_mine(): void
    {
        $account = $this->actingAsVerifiedSuchak('9876505909');
        $profileId = $this->existingMember('9876505910', 'Shriram Kadam', '2000-05-10', 'male');
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profileId,
            'representation_mode' => SuchakProfileRepresentation::MODE_MATCHED_EXISTING_PROFILE,
        ]);

        $match = $this->matchFor($profileId, [
            'candidate_name' => 'Shriram Kadam',
            'candidate_mobile' => '9876505910',
            'date_of_birth' => '2000-05-10',
            'candidate_gender' => 'male',
        ]);

        $this->assertSame('mine', $match['owner_type']);
        $this->assertTrue($match['already_represented_by_me']);
        $this->assertSame((int) $representation->id, $match['representation_id']);
    }

    public function test_profile_actively_held_by_another_suchak_is_owner_type_other_suchak(): void
    {
        $this->actingAsVerifiedSuchak('9876505911');
        $profileId = $this->existingMember('9876505912', 'Shriram Kadam', '2000-05-10', 'male');

        $rivalUser = User::factory()->create(['mobile' => '9876505913', 'mobile_verified_at' => now()]);
        $rivalAccount = SuchakAccount::factory()->create([
            'user_id' => $rivalUser->id,
            'suchak_name' => 'Rival Bureau',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $rivalAccount->id,
            'matrimony_profile_id' => $profileId,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        $match = $this->matchFor($profileId, [
            'candidate_name' => 'Shriram Kadam',
            'candidate_mobile' => '9876505912',
            'date_of_birth' => '2000-05-10',
            'candidate_gender' => 'male',
        ]);

        $this->assertSame('other_suchak', $match['owner_type']);
        $this->assertFalse($match['already_represented_by_me']);
        $this->assertNull($match['representation_id']);
        // Publicly routable (verified + public account) → name may be shown,
        // exactly as cross-search already shows it. Nothing else leaks.
        $this->assertSame('Rival Bureau', $match['owner_suchak_name']);
        $this->assertArrayNotHasKey('owner_suchak_account_id', $match);
    }

    public function test_other_suchak_name_stays_hidden_when_not_publicly_routable(): void
    {
        $this->actingAsVerifiedSuchak('9876505914');
        $profileId = $this->existingMember('9876505915', 'Shriram Kadam', '2000-05-10', 'male');

        $rivalUser = User::factory()->create(['mobile' => '9876505916', 'mobile_verified_at' => now()]);
        $rivalAccount = SuchakAccount::factory()->create([
            'user_id' => $rivalUser->id,
            'suchak_name' => 'Hidden Bureau',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $rivalAccount->id,
            'matrimony_profile_id' => $profileId,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        $match = $this->matchFor($profileId, [
            'candidate_name' => 'Shriram Kadam',
            'candidate_mobile' => '9876505915',
            'date_of_birth' => '2000-05-10',
            'candidate_gender' => 'male',
        ]);

        $this->assertSame('other_suchak', $match['owner_type']);
        $this->assertNull($match['owner_suchak_name'], 'A non-public Suchak must not be named.');
        $this->assertNull($match['location_label'], 'Broad location is withheld with the identity.');
    }

    public function test_self_registered_member_with_no_representation_is_platform_member(): void
    {
        $this->actingAsVerifiedSuchak('9876505917');
        $profileId = $this->existingMember('9876505918', 'Shriram Kadam', '2000-05-10', 'male');

        $match = $this->matchFor($profileId, [
            'candidate_name' => 'Shriram Kadam',
            'candidate_mobile' => '9876505918',
            'date_of_birth' => '2000-05-10',
            'candidate_gender' => 'male',
        ]);

        $this->assertSame('platform_member', $match['owner_type']);
        $this->assertTrue($match['can_link_existing']);
        $this->assertTrue($match['is_hard_stop']);
    }

    public function test_profile_without_self_verified_account_is_unrepresented(): void
    {
        $this->actingAsVerifiedSuchak('9876505919');
        $member = User::factory()->create(['mobile' => '9876505920', 'mobile_verified_at' => null, 'email_verified_at' => null]);
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $member->id,
            'full_name' => 'Shriram Kadam',
            'date_of_birth' => '2000-05-10',
            'gender_id' => (int) DB::table('master_genders')->where('key', 'male')->value('id'),
        ]);

        $match = $this->matchFor((int) $profile->id, [
            'candidate_name' => 'Shriram Kadam',
            'candidate_mobile' => '9876505920',
            'date_of_birth' => '2000-05-10',
            'candidate_gender' => 'male',
        ]);

        $this->assertSame('unrepresented', $match['owner_type']);
    }

    /**
     * Recall gap fixed 2026-07-26: identityCandidates() returned [] with no DOB,
     * so the same person on a different number was invisible. It must now be
     * found — but only as an advisory hint, never a hard stop.
     */
    public function test_name_only_match_without_dob_is_found_but_stays_low_confidence(): void
    {
        $this->actingAsVerifiedSuchak('9876505921');
        $profileId = $this->existingMember('9876505922', 'Shriram Kadam', '2000-05-10', 'male');

        $response = $this->postJson('/api/v1/suchak/manual-profiles/duplicate-check', [
            'candidate_name' => 'Sriram Kadam',
            'candidate_mobile' => '9700000779', // unknown number
            'candidate_gender' => 'male',
            // no date_of_birth at all
        ])->assertOk();

        $match = collect($response->json('data.matches'))->firstWhere('profile_id', $profileId);
        $this->assertNotNull($match, 'A DOB-less scan must still find the same name.');
        $this->assertSame('low', $match['confidence']);
        $this->assertFalse($match['is_hard_stop']);
        $this->assertFalse($response->json('data.hard_stop'));
    }

    public function test_matching_caste_lifts_a_dobless_hit_to_medium_but_never_blocks(): void
    {
        $this->actingAsVerifiedSuchak('9876505923');
        $profileId = $this->existingMember('9876505924', 'Shriram Kadam', '2000-05-10', 'male');
        $casteId = $this->ensureCaste();
        DB::table('matrimony_profiles')->where('id', $profileId)->update(['caste_id' => $casteId]);

        $response = $this->postJson('/api/v1/suchak/manual-profiles/duplicate-check', [
            'candidate_name' => 'Sriram Kadam',
            'candidate_mobile' => '9700000780',
            'candidate_gender' => 'male',
            'caste_id' => $casteId,
        ])->assertOk();

        $match = collect($response->json('data.matches'))->firstWhere('profile_id', $profileId);
        $this->assertNotNull($match);
        $this->assertSame('medium', $match['confidence']);
        $this->assertFalse($match['is_hard_stop'], 'A weak village/caste signal must never hard-stop onboarding.');
    }

    /**
     * Suchak-entered DOBs are approximate, so the scan widened from same-MONTH
     * to birth-year ±1. An off-by-a-year DOB must surface — as advisory only.
     */
    public function test_dob_off_by_one_year_is_found_as_medium(): void
    {
        $this->actingAsVerifiedSuchak('9876505925');
        $profileId = $this->existingMember('9876505926', 'Ganesh Jadhav', '1999-11-02', 'male');

        $response = $this->postJson('/api/v1/suchak/manual-profiles/duplicate-check', [
            'candidate_name' => 'Ganesh Jadhav',
            'candidate_mobile' => '9700000781',
            'date_of_birth' => '2000-03-20',
            'candidate_gender' => 'male',
        ])->assertOk();

        $match = collect($response->json('data.matches'))->firstWhere('profile_id', $profileId);
        $this->assertNotNull($match, 'A ±1 year DOB drift must still be reported.');
        $this->assertSame('medium', $match['confidence']);
        $this->assertFalse($match['is_hard_stop']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function matchFor(int $profileId, array $payload): array
    {
        $response = $this->postJson('/api/v1/suchak/manual-profiles/duplicate-check', $payload)->assertOk();
        $match = collect($response->json('data.matches'))->firstWhere('profile_id', $profileId);
        $this->assertNotNull($match, 'Expected profile '.$profileId.' in the duplicate matches.');

        return $match;
    }

    private function actingAsVerifiedSuchak(string $mobile): SuchakAccount
    {
        $this->ensureGenders();
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

    private function existingMember(string $mobile, string $fullName, string $dob, string $genderKey): int
    {
        $member = User::factory()->create(['mobile' => $mobile, 'mobile_verified_at' => now()]);
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $member->id,
            'full_name' => $fullName,
            'date_of_birth' => $dob,
            'gender_id' => (int) DB::table('master_genders')->where('key', $genderKey)->value('id'),
        ]);

        return (int) $profile->id;
    }

    private function ensureCaste(): int
    {
        $religionId = (int) DB::table('master_religions')->insertGetId([
            'key' => 'hindu-dupcheck',
            'label' => 'Hindu',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('master_castes')->insertGetId([
            'religion_id' => $religionId,
            'key' => 'maratha-dupcheck',
            'label' => 'Maratha',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureGenders(): void
    {
        foreach (['male' => 'Male', 'female' => 'Female'] as $key => $label) {
            MasterGender::query()->firstOrCreate(['key' => $key], ['label' => $label, 'is_active' => true]);
        }
    }
}
