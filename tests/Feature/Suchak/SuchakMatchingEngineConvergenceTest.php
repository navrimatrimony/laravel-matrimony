<?php

namespace Tests\Feature\Suchak;

use App\Models\Caste;
use App\Models\City;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\ProfileKycSubmission;
use App\Models\Religion;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakSuggestionService;
use App\Services\MatchBoostService;
use App\Services\Matching\MatchingService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Suchak surfaces now score through {@see MatchingService}, and a Suchak-scoped run draws from a wider
 * pool (platform members + represented candidates) without changing the member pool.
 */
class SuchakMatchingEngineConvergenceTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $photoFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    protected function tearDown(): void
    {
        foreach ($this->photoFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->photoFiles = [];

        parent::tearDown();
    }

    public function test_suchak_suggestions_are_engine_scored_and_span_members_and_other_suchak_candidates(): void
    {
        $fixture = $this->universeFixture();

        $suggestions = app(SuchakSuggestionService::class)->suggestionsForRepresentation(
            $fixture['own_account'],
            $fixture['seeker_representation'],
        );

        $this->assertGreaterThanOrEqual(2, $suggestions->count(), 'Suchak pool should reach both universes.');

        $byProfile = $suggestions->keyBy(static fn (array $row): string => (string) $row['basic']['display_name']);

        // Platform member — already visible to members today.
        $this->assertTrue($byProfile->has('Convergence Platform Member'));
        $memberRow = $byProfile->get('Convergence Platform Member');
        $this->assertSame(SuchakSuggestionService::SOURCE_PLATFORM_MEMBER, $memberRow['source']);
        $this->assertSame('member', $memberRow['acting_actor']);

        // Another Suchak's candidate, still awaiting manual activation — invisible to the member pool.
        $this->assertTrue($byProfile->has('Convergence Other Suchak Candidate'));
        $otherRow = $byProfile->get('Convergence Other Suchak Candidate');
        $this->assertSame(SuchakSuggestionService::SOURCE_SUCHAK_REPRESENTED, $otherRow['source']);
        $this->assertSame('suchak', $otherRow['acting_actor']);
        $this->assertNotNull($otherRow['target_suchak_label']);

        foreach ([$memberRow, $otherRow] as $row) {
            // Real engine output, not a boolean heuristic.
            $this->assertGreaterThan(0, $row['match_score']);
            $this->assertLessThanOrEqual(100, $row['match_score']);
            $this->assertNotEmpty($row['reasons']);
            $this->assertArrayHasKey('warnings', $row);
            $this->assertArrayHasKey('community', $row['match_field_points']);
            $this->assertNotSame('', $row['fit_label']);
        }

        // Ranked by score, descending.
        $scores = $suggestions->pluck('match_score')->all();
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);
    }

    public function test_suggestion_payload_never_contains_a_mobile_number(): void
    {
        $fixture = $this->universeFixture();

        $suggestions = app(SuchakSuggestionService::class)->suggestionsForRepresentation(
            $fixture['own_account'],
            $fixture['seeker_representation'],
        );

        $this->assertNotEmpty($suggestions);

        $payload = json_encode($suggestions->all());
        $this->assertIsString($payload);

        foreach ($fixture['secret_mobiles'] as $mobile) {
            $this->assertStringNotContainsString($mobile, $payload, 'A mobile number leaked into Suchak suggestions.');
        }

        foreach ($suggestions as $row) {
            $this->assertTrue($row['contact']['is_masked']);
            $this->assertNull($row['contact']['phone']);
            $this->assertNull($row['contact']['whatsapp']);
        }
    }

    public function test_member_match_pool_is_unchanged_by_the_suchak_pool_option(): void
    {
        $fixture = $this->universeFixture();

        $memberMatches = app(MatchingService::class)->findMatches($fixture['member_seeker'], 50);
        $matchedNames = $memberMatches->map(static fn (array $row): string => (string) $row['profile']->full_name)->all();

        // The activated platform member is still there...
        $this->assertContains('Convergence Platform Member', $matchedNames);

        // ...and the not-yet-activated Suchak candidate is still excluded from the member pool.
        $this->assertNotContains('Convergence Other Suchak Candidate', $matchedNames);
        $this->assertNotContains('Convergence Seeker Candidate', $matchedNames);
    }

    /**
     * The Suchak path deliberately disables the ACTOR-keyed layer (boost + behaviour), because a
     * dormant Suchak-created account has no activity of its own. That must not also throw away the
     * candidate's OWN quality signals — a Suchak has to see the verified, photo-bearing, complete
     * candidate first. Both candidates here are field-identical, so the base score is provably equal
     * and only the quality delta can separate them.
     */
    public function test_suchak_path_ranks_a_verified_photo_bearing_candidate_above_an_identical_empty_one(): void
    {
        $fixture = $this->qualityFixture();

        $suggestions = app(SuchakSuggestionService::class)->suggestionsForRepresentation(
            $fixture['own_account'],
            $fixture['seeker_representation'],
        );

        $byName = $suggestions->keyBy(static fn (array $row): string => (string) $row['basic']['display_name']);
        $this->assertTrue($byName->has('Quality Verified Candidate'));
        $this->assertTrue($byName->has('Quality Empty Candidate'));

        $verified = $byName->get('Quality Verified Candidate');
        $empty = $byName->get('Quality Empty Candidate');

        // Field compatibility is identical by construction — nothing but candidate quality is left.
        $this->assertSame(
            $empty['match_base_score'],
            $verified['match_base_score'],
            'Fixture is broken: the two candidates must share one base score for this test to mean anything.',
        );

        $this->assertGreaterThan(
            $empty['match_score'],
            $verified['match_score'],
            'A verified, photo-bearing, complete candidate must outrank an identical empty one on the Suchak path.',
        );

        // Quality moved the score, so it must be visible in the "why is this on top" reasons.
        $this->assertContains(__('matching_engine.boost_reason_verified_kyc'), $verified['reasons']);
        $this->assertContains(__('matching_engine.boost_reason_photo'), $verified['reasons']);
        $this->assertNotContains(__('matching_engine.boost_reason_verified_kyc'), $empty['reasons']);

        // Ordering of the returned collection follows it.
        $names = $suggestions->map(static fn (array $row): string => (string) $row['basic']['display_name'])->values()->all();
        $this->assertLessThan(
            array_search('Quality Empty Candidate', $names, true),
            array_search('Quality Verified Candidate', $names, true),
        );
    }

    /**
     * The member path already gets these signals inside {@see MatchBoostService::applyBoost()}.
     * Adding the candidate-quality delta there too would double-count them, so it must stay at zero.
     */
    public function test_member_path_scoring_is_unchanged_by_the_candidate_quality_delta(): void
    {
        $fixture = $this->qualityFixture();

        /** @var MatrimonyProfile $seeker */
        $seeker = $fixture['member_seeker'];
        /** @var MatrimonyProfile $candidate */
        $candidate = $fixture['verified_candidate'];

        $breakdown = app(MatchingService::class)->computeMatchBreakdown($seeker, $candidate);

        $this->assertSame(0, $breakdown['quality_delta'], 'The member path must not add the quality delta on top of applyBoost().');
        $this->assertSame([], $breakdown['quality_signals']);

        $expected = max(0, min(100, app(MatchBoostService::class)->applyBoost(
            $seeker->user,
            $candidate->user,
            (int) $breakdown['before_boost'],
        ) + (int) $breakdown['behavior_delta']));

        $this->assertSame($expected, (int) $breakdown['final_score'], 'Member scoring must still be exactly boost + behaviour.');
    }

    /**
     * Two field-identical male candidates plus the Suchak's own female seeker. The candidates differ
     * only in KYC / approved photo / verified mobile — never in anything {@see MatchingService} scores.
     *
     * @return array<string, mixed>
     */
    private function qualityFixture(): array
    {
        [$religion, $caste] = $this->community();
        $ownAccount = $this->suchakAccount(['suchak_name' => 'Quality Own Suchak']);
        $community = ['religion_id' => $religion->id, 'caste_id' => $caste->id];

        $seekerProfile = $this->profile(array_merge($community, [
            'full_name' => 'Quality Seeker Candidate',
            'gender_id' => $this->genderId('female'),
            'date_of_birth' => now()->subYears(27)->toDateString(),
            'height_cm' => 158,
        ]), activated: false);

        $shared = array_merge($community, [
            'gender_id' => $this->genderId('male'),
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'height_cm' => 172,
        ]);

        $verified = $this->profile(array_merge($shared, ['full_name' => 'Quality Verified Candidate']), activated: true);
        $empty = $this->profile(array_merge($shared, ['full_name' => 'Quality Empty Candidate']), activated: true);

        $memberSeeker = $this->profile(array_merge($community, [
            'full_name' => 'Quality Member Seeker',
            'gender_id' => $this->genderId('female'),
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'height_cm' => 160,
        ]), activated: true);

        $this->applyQualitySignals($verified);

        // Recency is the one quality signal both must share, so it cannot explain the gap.
        foreach ([$verified, $empty] as $profile) {
            DB::table('matrimony_profiles')->where('id', $profile->id)->update(['updated_at' => now()->subDay()]);
            User::query()->whereKey($profile->user_id)->update(['last_seen_at' => now()->subDay()]);
        }

        Cache::flush();

        return [
            'own_account' => $ownAccount,
            'seeker_representation' => $this->activeRepresentation($ownAccount, $seekerProfile),
            'verified_candidate' => $verified->fresh(['user']),
            'empty_candidate' => $empty->fresh(['user']),
            'member_seeker' => $memberSeeker->fresh(['user', 'gender']),
        ];
    }

    /**
     * Approved photo + approved KYC + verified mobile. All three are written straight to storage so
     * nothing here touches a field {@see MatchingService::calculateScore()} reads.
     */
    private function applyQualitySignals(MatrimonyProfile $profile): void
    {
        $dir = storage_path('app/public/matrimony_photos');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = 'suchak-quality-'.$profile->id.'.jpg';
        file_put_contents($dir.DIRECTORY_SEPARATOR.$file, 'x');
        $this->photoFiles[] = $dir.DIRECTORY_SEPARATOR.$file;

        DB::table('matrimony_profiles')
            ->where('id', $profile->id)
            ->update(['profile_photo' => $file, 'photo_approved' => true]);

        ProfileKycSubmission::query()->create([
            'matrimony_profile_id' => $profile->id,
            'id_document_path' => 'kyc/'.$profile->id.'.pdf',
            'status' => ProfileKycSubmission::STATUS_APPROVED,
            'reviewed_at' => now()->subDay(),
        ]);

        User::query()->whereKey($profile->user_id)->update(['mobile_verified_at' => now()->subDay()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function universeFixture(): array
    {
        [$religion, $caste] = $this->community();

        $ownAccount = $this->suchakAccount(['suchak_name' => 'Convergence Own Suchak']);
        $otherAccount = $this->suchakAccount(['suchak_name' => 'Convergence Other Suchak']);

        $community = ['religion_id' => $religion->id, 'caste_id' => $caste->id];

        // Seeker: this Suchak's own represented candidate (female).
        $seekerProfile = $this->profile(array_merge($community, [
            'full_name' => 'Convergence Seeker Candidate',
            'gender_id' => $this->genderId('female'),
            'date_of_birth' => now()->subYears(27)->toDateString(),
            'height_cm' => 158,
        ]), activated: false);

        // A self-registered, activated platform member (male).
        $memberProfile = $this->profile(array_merge($community, [
            'full_name' => 'Convergence Platform Member',
            'gender_id' => $this->genderId('male'),
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'height_cm' => 172,
        ]), activated: true);

        // Another Suchak's candidate, still suspended pending manual activation (male).
        $otherSuchakProfile = $this->profile(array_merge($community, [
            'full_name' => 'Convergence Other Suchak Candidate',
            'gender_id' => $this->genderId('male'),
            'date_of_birth' => now()->subYears(29)->toDateString(),
            'height_cm' => 170,
        ]), activated: false);
        $otherSuchakProfile->forceFill(['lifecycle_state' => 'draft', 'is_suspended' => true])->save();
        $otherSuchakProfile->refresh();

        // A plain activated member used to prove the member pool did not change (female seeker).
        $memberSeeker = $this->profile(array_merge($community, [
            'full_name' => 'Convergence Member Seeker',
            'gender_id' => $this->genderId('female'),
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'height_cm' => 160,
        ]), activated: true);

        $seekerRepresentation = $this->activeRepresentation($ownAccount, $seekerProfile);
        $this->activeRepresentation($otherAccount, $otherSuchakProfile);

        $secretMobiles = [
            $this->attachPrivateMobile($memberProfile, '9812300011'),
            $this->attachPrivateMobile($otherSuchakProfile, '9812300022'),
        ];

        return [
            'own_account' => $ownAccount,
            'other_account' => $otherAccount,
            'seeker_representation' => $seekerRepresentation,
            'member_seeker' => $memberSeeker,
            'secret_mobiles' => $secretMobiles,
        ];
    }

    /**
     * @return array{0: Religion, 1: Caste}
     */
    private function community(): array
    {
        $religion = Religion::query()->create([
            'key' => 'convergence_religion',
            'label' => 'Convergence Religion',
            'label_en' => 'Convergence Religion',
            'is_active' => true,
        ]);
        $caste = Caste::query()->create([
            'religion_id' => $religion->id,
            'key' => 'convergence_caste',
            'label' => 'Convergence Caste',
            'label_en' => 'Convergence Caste',
            'is_active' => true,
        ]);

        return [$religion, $caste];
    }

    private function genderId(string $key): int
    {
        return (int) MasterGender::query()->firstOrCreate(
            ['key' => $key],
            ['label' => ucfirst($key), 'is_active' => true],
        )->id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function suchakAccount(array $attributes = []): SuchakAccount
    {
        return SuchakAccount::factory()->create(array_merge([
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function profile(array $attributes, bool $activated): MatrimonyProfile
    {
        $city = City::query()->where('name', 'Pune City')->firstOrFail();

        $profile = MatrimonyProfile::factory()->create(array_merge([
            'highest_education' => 'Graduate',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], $attributes));

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $city->id]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, (int) $city->id, null, true, false);
        }

        $profile->forceFill([
            'lifecycle_state' => $activated ? 'active' : 'draft',
            'is_suspended' => false,
        ])->save();

        return $profile->fresh();
    }

    private function activeRepresentation(SuchakAccount $account, MatrimonyProfile $profile): SuchakProfileRepresentation
    {
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        SuchakConsent::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'consent_status' => SuchakConsent::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'used_at' => now(),
            'otp_verified_at' => now(),
            'valid_from' => now(),
            'valid_until' => $representation->consent_valid_until,
        ]);

        return $representation->fresh(['suchakAccount', 'matrimonyProfile.gender']);
    }

    private function attachPrivateMobile(MatrimonyProfile $profile, string $phoneNumber): string
    {
        User::query()->whereKey($profile->user_id)->update(['mobile' => $phoneNumber]);

        $row = [
            'profile_id' => $profile->id,
            'contact_name' => (string) $profile->full_name,
            'phone_number' => $phoneNumber,
            'is_primary' => true,
            'visibility_rule' => 'unlock_only',
            'verified_status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('profile_contacts', 'contact_relation_id')) {
            $row['contact_relation_id'] = null;
        }
        if (Schema::hasColumn('profile_contacts', 'relation_type')) {
            $row['relation_type'] = 'self';
        }

        DB::table('profile_contacts')->insert($row);

        return $phoneNumber;
    }
}
