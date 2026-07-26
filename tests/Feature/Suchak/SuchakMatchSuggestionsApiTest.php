<?php

namespace Tests\Feature\Suchak;

use App\Models\Caste;
use App\Models\City;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakMatchSuggestion;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP surface of the Suchak match suggestions + the learning log behind them.
 *
 * Guarantees under test:
 *   - ranked, masked rows with NO mobile number anywhere in the payload,
 *   - a candidate shown today is not shown again inside the cooling window,
 *   - when everything has cooled off the fallback shows repeats and flags them,
 *   - a decision persists and is echoed back on the next listing,
 *   - another Suchak's representation is invisible, and a pending consent claim
 *     is refused with the shared consent_required shape.
 */
class SuchakMatchSuggestionsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_listing_returns_ranked_masked_suggestions_with_no_mobile_number(): void
    {
        $fixture = $this->universeFixture();
        Sanctum::actingAs($fixture['own_account']->user);

        $representationId = (int) $fixture['seeker_representation']->id;

        $response = $this->getJson("/api/v1/suchak/representations/{$representationId}/suggestions")
            ->assertOk()
            ->assertJsonPath('data.representation_id', $representationId)
            ->assertJsonPath('data.showing_cooled_off', false)
            ->assertJsonPath('data.seeker.profile_id', (int) $fixture['seeker_profile']->id);

        $suggestions = $response->json('data.suggestions');
        $this->assertIsArray($suggestions);
        $this->assertGreaterThanOrEqual(2, count($suggestions));
        $this->assertSame(count($suggestions), $response->json('data.count'));

        $scores = array_column($suggestions, 'match_score');
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores, 'Suggestions must arrive ranked by score, descending.');

        foreach ($suggestions as $row) {
            $this->assertIsInt($row['profile_id']);
            $this->assertArrayHasKey('display_name', $row);
            $this->assertArrayHasKey('age_years', $row);
            $this->assertArrayHasKey('gender', $row);
            $this->assertArrayHasKey('location_label', $row);
            $this->assertArrayHasKey('photo_url', $row);
            $this->assertArrayHasKey('fit_label', $row);
            $this->assertIsArray($row['reasons']);
            $this->assertIsArray($row['warnings']);
            $this->assertContains($row['source'], ['platform_member', 'own_candidate', 'suchak_represented']);
            $this->assertContains($row['acting_actor'], ['member', 'suchak']);
            $this->assertSame(SuchakMatchSuggestion::DECISION_PENDING, $row['decision']);

            // presentSuggestion() is an allow-list, so a payload the engine
            // computes is invisible to the app unless it is named here. The key
            // must always be present; null means "no patrika data", which the
            // app renders differently from "not compatible".
            $this->assertArrayHasKey('gunamilan', $row);
            if ($row['gunamilan'] !== null) {
                $this->assertIsArray($row['gunamilan']);
                $this->assertArrayHasKey('state', $row['gunamilan']);
                $this->assertContains($row['gunamilan']['state'], ['compatible', 'not_compatible', 'unknown']);
            }

            // The masked contact block must never be flattened into the contract.
            $this->assertArrayNotHasKey('contact', $row);
            $this->assertArrayNotHasKey('phone', $row);
        }

        $payload = (string) $response->getContent();
        foreach ($fixture['secret_mobiles'] as $mobile) {
            $this->assertStringNotContainsString($mobile, $payload, 'A mobile number leaked into the suggestions API.');
        }

        // Impressions were recorded for exactly what was returned.
        $this->assertSame(
            count($suggestions),
            SuchakMatchSuggestion::query()->where('seeker_profile_id', $fixture['seeker_profile']->id)->count(),
        );
    }

    public function test_a_candidate_is_not_returned_twice_inside_the_cooling_window(): void
    {
        $fixture = $this->universeFixture();
        Sanctum::actingAs($fixture['own_account']->user);
        $representationId = (int) $fixture['seeker_representation']->id;

        $first = $this->getJson("/api/v1/suchak/representations/{$representationId}/suggestions")->assertOk();
        $firstIds = array_column((array) $first->json('data.suggestions'), 'profile_id');
        $this->assertNotEmpty($firstIds);

        // Same day: everything shown is inside the cooling window, so nothing repeats.
        $second = $this->getJson("/api/v1/suchak/representations/{$representationId}/suggestions")
            ->assertOk()
            ->assertJsonPath('data.showing_cooled_off', false);
        $secondIds = array_column((array) $second->json('data.suggestions'), 'profile_id');

        $this->assertSame([], array_intersect($firstIds, $secondIds));

        // include_seen=1 bypasses the window on demand.
        $seen = $this->getJson("/api/v1/suchak/representations/{$representationId}/suggestions?include_seen=1")
            ->assertOk();
        $seenIds = array_column((array) $seen->json('data.suggestions'), 'profile_id');
        $this->assertNotEmpty(array_intersect($firstIds, $seenIds));
    }

    public function test_when_everything_has_cooled_off_the_fallback_shows_repeats_and_flags_them(): void
    {
        $fixture = $this->universeFixture();
        Sanctum::actingAs($fixture['own_account']->user);
        $representationId = (int) $fixture['seeker_representation']->id;

        $first = $this->getJson("/api/v1/suchak/representations/{$representationId}/suggestions")->assertOk();
        $firstIds = array_column((array) $first->json('data.suggestions'), 'profile_id');
        $this->assertNotEmpty($firstIds);

        // Nothing new exists any more, and the cooling period has elapsed.
        $this->travel(SuchakMatchSuggestion::DEFAULT_COOLING_PERIOD_DAYS + 10)->days();

        $again = $this->getJson("/api/v1/suchak/representations/{$representationId}/suggestions")
            ->assertOk()
            ->assertJsonPath('data.showing_cooled_off', true);

        $againIds = array_column((array) $again->json('data.suggestions'), 'profile_id');
        $this->assertNotEmpty($againIds, 'The cooled-off fallback must show people instead of an empty screen.');
        sort($firstIds);
        sort($againIds);
        $this->assertSame($firstIds, $againIds, 'The same people should come back once they have cooled off.');

        $this->travelBack();
    }

    public function test_a_rejection_with_a_reason_persists_and_shows_on_the_next_listing(): void
    {
        $fixture = $this->universeFixture();
        Sanctum::actingAs($fixture['own_account']->user);
        $representationId = (int) $fixture['seeker_representation']->id;

        $listing = $this->getJson("/api/v1/suchak/representations/{$representationId}/suggestions")->assertOk();
        $candidateId = (int) $listing->json('data.suggestions.0.profile_id');

        $this->postJson(
            "/api/v1/suchak/representations/{$representationId}/suggestions/{$candidateId}/decision",
            [
                'decision' => SuchakMatchSuggestion::DECISION_REJECTED,
                'rejection_reason_code' => SuchakMatchSuggestion::REJECTION_DISTANCE,
                'note' => 'Family wants someone nearer.',
            ],
        )->assertOk()
            ->assertJsonPath('data.profile_id', $candidateId)
            ->assertJsonPath('data.decision', SuchakMatchSuggestion::DECISION_REJECTED)
            ->assertJsonPath('data.rejection_reason_code', SuchakMatchSuggestion::REJECTION_DISTANCE);

        $this->assertDatabaseHas('suchak_match_suggestions', [
            'seeker_profile_id' => $fixture['seeker_profile']->id,
            'candidate_profile_id' => $candidateId,
            'decision' => SuchakMatchSuggestion::DECISION_REJECTED,
            'rejection_reason_code' => SuchakMatchSuggestion::REJECTION_DISTANCE,
            'rejection_note' => 'Family wants someone nearer.',
        ]);

        $next = $this->getJson("/api/v1/suchak/representations/{$representationId}/suggestions?include_seen=1")
            ->assertOk();
        $decisions = collect((array) $next->json('data.suggestions'))->pluck('decision', 'profile_id');
        $this->assertSame(SuchakMatchSuggestion::DECISION_REJECTED, $decisions[$candidateId] ?? null);
    }

    public function test_rejection_without_a_reason_is_a_clean_422(): void
    {
        $fixture = $this->universeFixture();
        Sanctum::actingAs($fixture['own_account']->user);
        $representationId = (int) $fixture['seeker_representation']->id;

        $listing = $this->getJson("/api/v1/suchak/representations/{$representationId}/suggestions")->assertOk();
        $candidateId = (int) $listing->json('data.suggestions.0.profile_id');

        $this->postJson(
            "/api/v1/suchak/representations/{$representationId}/suggestions/{$candidateId}/decision",
            ['decision' => SuchakMatchSuggestion::DECISION_REJECTED],
        )->assertStatus(422)->assertJsonValidationErrors(['rejection_reason_code']);

        $this->postJson(
            "/api/v1/suchak/representations/{$representationId}/suggestions/{$candidateId}/decision",
            ['decision' => 'maybe_later'],
        )->assertStatus(422)->assertJsonValidationErrors(['decision']);
    }

    public function test_another_suchaks_representation_is_not_reachable(): void
    {
        $fixture = $this->universeFixture();
        Sanctum::actingAs($fixture['own_account']->user);

        $foreignProfile = $this->profile([
            'full_name' => 'Rival Represented Candidate',
            'gender_id' => $this->genderId('female'),
            'date_of_birth' => now()->subYears(26)->toDateString(),
            'height_cm' => 157,
        ], activated: false);
        $foreign = $this->activeRepresentation($fixture['other_account'], $foreignProfile);

        $this->getJson("/api/v1/suchak/representations/{$foreign->id}/suggestions")->assertStatus(404);
        $this->postJson(
            "/api/v1/suchak/representations/{$foreign->id}/suggestions/{$fixture['member_profile']->id}/decision",
            ['decision' => SuchakMatchSuggestion::DECISION_CHOSEN],
        )->assertStatus(404);
    }

    public function test_a_pending_consent_claim_is_refused(): void
    {
        $fixture = $this->universeFixture();
        Sanctum::actingAs($fixture['own_account']->user);

        $claimedProfile = $this->profile([
            'full_name' => 'Unconsented Claim',
            'gender_id' => $this->genderId('female'),
            'date_of_birth' => now()->subYears(25)->toDateString(),
            'height_cm' => 156,
        ], activated: true);

        $claim = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $fixture['own_account']->id,
            'matrimony_profile_id' => $claimedProfile->id,
            'representation_mode' => SuchakProfileRepresentation::MODE_MATCHED_EXISTING_PROFILE,
            'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
            'consent_status' => SuchakProfileRepresentation::CONSENT_REQUESTED,
            'consent_verified_at' => null,
            'consent_valid_until' => null,
            'first_verified_consent_at' => null,
        ]);

        $this->assertTrue($claim->fresh()->isPendingConsentClaim());

        $this->getJson("/api/v1/suchak/representations/{$claim->id}/suggestions")
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'consent_required')
            ->assertJsonPath('data.consent_required', true)
            ->assertJsonPath('data.representation_id', (int) $claim->id);

        $this->postJson(
            "/api/v1/suchak/representations/{$claim->id}/suggestions/{$fixture['member_profile']->id}/decision",
            ['decision' => SuchakMatchSuggestion::DECISION_CHOSEN],
        )->assertStatus(403)->assertJsonPath('error_code', 'consent_required');

        $this->assertDatabaseMissing('suchak_match_suggestions', [
            'seeker_profile_id' => $claimedProfile->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function universeFixture(): array
    {
        [$religion, $caste] = $this->community();

        $ownAccount = $this->suchakAccount('9876511001', 'Suggestions Own Suchak');
        $otherAccount = $this->suchakAccount('9876511002', 'Suggestions Other Suchak');

        $community = ['religion_id' => $religion->id, 'caste_id' => $caste->id];

        $seekerProfile = $this->profile(array_merge($community, [
            'full_name' => 'Suggestions Seeker Candidate',
            'gender_id' => $this->genderId('female'),
            'date_of_birth' => now()->subYears(27)->toDateString(),
            'height_cm' => 158,
        ]), activated: false);

        $memberProfile = $this->profile(array_merge($community, [
            'full_name' => 'Suggestions Platform Member',
            'gender_id' => $this->genderId('male'),
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'height_cm' => 172,
        ]), activated: true);

        $otherSuchakProfile = $this->profile(array_merge($community, [
            'full_name' => 'Suggestions Other Suchak Candidate',
            'gender_id' => $this->genderId('male'),
            'date_of_birth' => now()->subYears(29)->toDateString(),
            'height_cm' => 170,
        ]), activated: false);
        $otherSuchakProfile->forceFill(['lifecycle_state' => 'draft', 'is_suspended' => true])->save();
        $otherSuchakProfile->refresh();

        $seekerRepresentation = $this->activeRepresentation($ownAccount, $seekerProfile);
        $this->activeRepresentation($otherAccount, $otherSuchakProfile);

        $secretMobiles = [
            $this->attachPrivateMobile($memberProfile, '9812311011'),
            $this->attachPrivateMobile($otherSuchakProfile, '9812311022'),
        ];

        return [
            'own_account' => $ownAccount->fresh('user'),
            'other_account' => $otherAccount,
            'seeker_profile' => $seekerProfile,
            'member_profile' => $memberProfile,
            'seeker_representation' => $seekerRepresentation,
            'secret_mobiles' => $secretMobiles,
        ];
    }

    /**
     * @return array{0: Religion, 1: Caste}
     */
    private function community(): array
    {
        $religion = Religion::query()->create([
            'key' => 'suggestions_religion',
            'label' => 'Suggestions Religion',
            'label_en' => 'Suggestions Religion',
            'is_active' => true,
        ]);
        $caste = Caste::query()->create([
            'religion_id' => $religion->id,
            'key' => 'suggestions_caste',
            'label' => 'Suggestions Caste',
            'label_en' => 'Suggestions Caste',
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

    private function suchakAccount(string $mobile, string $name): SuchakAccount
    {
        $user = User::factory()->create(['mobile' => $mobile, 'mobile_verified_at' => now()]);

        return SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'suchak_name' => $name,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
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
