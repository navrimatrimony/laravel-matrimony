<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
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

    private function actingAsVerifiedSuchak(string $mobile): void
    {
        $this->ensureGenders();
        $user = User::factory()->create(['mobile' => $mobile, 'mobile_verified_at' => now()]);
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        Sanctum::actingAs($user);
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

    private function ensureGenders(): void
    {
        foreach (['male' => 'Male', 'female' => 'Female'] as $key => $label) {
            MasterGender::query()->firstOrCreate(['key' => $key], ['label' => $label, 'is_active' => true]);
        }
    }
}
