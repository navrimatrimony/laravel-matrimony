<?php

namespace Tests\Feature\Suchak;

use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileUpdateSuggestion;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakProfileUpdateSuggestionService;
use App\Services\MutationService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakProfileUpdateSuggestionFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_suchak_profile_update_suggestion_table_exists_with_day_15_columns(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_profile_update_suggestions'));

        foreach ([
            'suchak_account_id',
            'matrimony_profile_id',
            'representation_id',
            'field_key',
            'old_value',
            'suggested_value',
            'suggestion_status',
            'otp_hash',
            'otp_attempts',
            'last_otp_sent_at',
            'candidate_verified_at',
            'admin_reviewed_at',
            'applied_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_profile_update_suggestions', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_profile_update_suggestions', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_profile_update_suggestions', 'raw_otp'));
        $this->assertFalse(Schema::hasColumn('suchak_profile_update_suggestions', 'profile_snapshot_json'));
    }

    public function test_verified_suchak_can_create_pending_core_update_suggestion_without_mutating_profile(): void
    {
        [$suchakUser, $account, $candidateUser, $profile, $representation] = $this->activeRepresentationFixture([
            'highest_education' => 'B.Com',
        ]);

        $beforeUpdatedAt = $profile->updated_at?->toDateTimeString();

        $suggestion = app(SuchakProfileUpdateSuggestionService::class)->createCoreFieldSuggestion(
            $account,
            $suchakUser,
            $representation,
            'highest_education',
            'M.Com',
            '127.0.0.1',
            'Day-15 test',
        );

        $this->assertSame($account->id, $suggestion->suchak_account_id);
        $this->assertSame($profile->id, $suggestion->matrimony_profile_id);
        $this->assertSame($representation->id, $suggestion->representation_id);
        $this->assertSame('highest_education', $suggestion->field_key);
        $this->assertSame('B.Com', $suggestion->old_value);
        $this->assertSame('M.Com', $suggestion->suggested_value);
        $this->assertSame(SuchakProfileUpdateSuggestion::STATUS_PENDING_CANDIDATE_CONFIRMATION, $suggestion->suggestion_status);
        $this->assertNull($suggestion->otp_hash);
        $this->assertSame('B.Com', $profile->fresh()->highest_education);
        $this->assertSame($beforeUpdatedAt, $profile->fresh()->updated_at?->toDateTimeString());
        $this->assertSame($candidateUser->id, $profile->user_id);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $suchakUser->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_PROFILE_UPDATE_SUGGESTION_CREATED,
            'target_type' => 'suchak_profile_update_suggestion',
            'target_id' => $suggestion->id,
            'matrimony_profile_id' => $profile->id,
        ]);

        $metadata = SuchakActivityLog::query()
            ->where('target_type', 'suchak_profile_update_suggestion')
            ->where('target_id', $suggestion->id)
            ->firstOrFail()
            ->metadata_json;
        $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('B.Com', $metadataJson);
        $this->assertStringNotContainsString('M.Com', $metadataJson);
    }

    public function test_multiple_conflicting_suchak_suggestions_remain_pending_reviewable(): void
    {
        [$suchakUser, $account, , $profile, $representation] = $this->activeRepresentationFixture([
            'annual_income' => 500000,
        ]);
        [$otherSuchakUser, $otherAccount] = $this->verifiedSuchakActor();
        [$otherRepresentation] = $this->activeRepresentation($otherAccount, $profile);
        $service = app(SuchakProfileUpdateSuggestionService::class);

        $first = $service->createCoreFieldSuggestion($account, $suchakUser, $representation, 'annual_income', '700000');
        $second = $service->createCoreFieldSuggestion($otherAccount, $otherSuchakUser, $otherRepresentation, 'annual_income', '900000');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(SuchakProfileUpdateSuggestion::STATUS_PENDING_CANDIDATE_CONFIRMATION, $first->suggestion_status);
        $this->assertSame(SuchakProfileUpdateSuggestion::STATUS_PENDING_CANDIDATE_CONFIRMATION, $second->suggestion_status);
        $this->assertSame(2, SuchakProfileUpdateSuggestion::query()->where('matrimony_profile_id', $profile->id)->count());
        $this->assertSame(500000, (int) $profile->fresh()->annual_income);
    }

    public function test_candidate_otp_confirmation_applies_profile_change_only_through_mutation_service(): void
    {
        [$suchakUser, $account, $candidateUser, $profile, $representation] = $this->activeRepresentationFixture([
            'highest_education' => 'B.Com',
        ]);
        $service = app(SuchakProfileUpdateSuggestionService::class);

        $suggestion = $service->createCoreFieldSuggestion(
            $account,
            $suchakUser,
            $representation,
            'highest_education',
            'M.Com',
        );
        $withOtp = $service->recordOtpSent($suggestion, '123456', $suchakUser);

        $this->assertNotSame('123456', $withOtp->otp_hash);
        $this->assertDatabaseMissing('suchak_profile_update_suggestions', ['otp_hash' => '123456']);
        $this->assertSame('B.Com', $profile->fresh()->highest_education);

        $applied = $service->verifyCandidateOtpAndApply($withOtp, '123456', $candidateUser);

        $this->assertSame(SuchakProfileUpdateSuggestion::STATUS_APPLIED, $applied->suggestion_status);
        $this->assertNotNull($applied->candidate_verified_at);
        $this->assertNotNull($applied->applied_at);
        $this->assertSame('M.Com', $profile->fresh()->highest_education);

        $this->assertDatabaseHas('profile_change_history', [
            'profile_id' => $profile->id,
            'field_name' => 'highest_education',
            'old_value' => 'B.Com',
            'new_value' => 'M.Com',
        ]);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $candidateUser->id,
            'actor_type' => SuchakActivityLog::ACTOR_USER,
            'action_type' => SuchakActivityLog::ACTION_PROFILE_UPDATE_SUGGESTION_APPLIED,
            'target_type' => 'suchak_profile_update_suggestion',
            'target_id' => $suggestion->id,
        ]);
    }

    public function test_stale_current_value_moves_candidate_verified_suggestion_to_admin_review_without_overwrite(): void
    {
        [$suchakUser, $account, $candidateUser, $profile, $representation] = $this->activeRepresentationFixture([
            'highest_education' => 'B.Com',
        ]);
        $service = app(SuchakProfileUpdateSuggestionService::class);

        $suggestion = $service->createCoreFieldSuggestion($account, $suchakUser, $representation, 'highest_education', 'M.Com');
        $withOtp = $service->recordOtpSent($suggestion, '123456', $suchakUser);

        app(MutationService::class)->applyManualSnapshot(
            $profile->fresh(),
            [
                'snapshot_schema_version' => 1,
                'core' => ['highest_education' => 'MBA'],
            ],
            (int) $candidateUser->id,
            'manual',
        );

        $reviewRequired = $service->verifyCandidateOtpAndApply($withOtp, '123456', $candidateUser);

        $this->assertSame(SuchakProfileUpdateSuggestion::STATUS_ADMIN_REVIEW_REQUIRED, $reviewRequired->suggestion_status);
        $this->assertNotNull($reviewRequired->candidate_verified_at);
        $this->assertNull($reviewRequired->applied_at);
        $this->assertSame('MBA', $profile->fresh()->highest_education);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'action_type' => SuchakActivityLog::ACTION_PROFILE_UPDATE_SUGGESTION_STATUS_CHANGED,
            'target_type' => 'suchak_profile_update_suggestion',
            'target_id' => $suggestion->id,
        ]);
    }

    public function test_candidate_can_reject_suggestion_without_profile_change(): void
    {
        [$suchakUser, $account, $candidateUser, $profile, $representation] = $this->activeRepresentationFixture([
            'highest_education' => 'B.Com',
        ]);
        $service = app(SuchakProfileUpdateSuggestionService::class);

        $suggestion = $service->createCoreFieldSuggestion($account, $suchakUser, $representation, 'highest_education', 'M.Com');
        $rejected = $service->rejectByCandidate($suggestion, $candidateUser);

        $this->assertSame(SuchakProfileUpdateSuggestion::STATUS_REJECTED_BY_CANDIDATE, $rejected->suggestion_status);
        $this->assertNotNull($rejected->candidate_verified_at);
        $this->assertSame('B.Com', $profile->fresh()->highest_education);
    }

    public function test_invalid_fields_private_contact_text_and_invalid_representation_are_blocked(): void
    {
        [$suchakUser, $account, , , $representation] = $this->activeRepresentationFixture();
        $service = app(SuchakProfileUpdateSuggestionService::class);

        try {
            $service->createCoreFieldSuggestion($account, $suchakUser, $representation, 'full_name', 'Changed Name');

            $this->fail('Identity field should be blocked for Day-15 foundation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Field is not allowed for Day-15 Suchak profile update suggestions.', $exception->getMessage());
        }

        try {
            $service->createCoreFieldSuggestion($account, $suchakUser, $representation, 'highest_education', 'Call 9876543210');

            $this->fail('Private contact text should be blocked.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak profile update suggestions must not store private contact details.', $exception->getMessage());
        }

        SuchakProfileRepresentation::query()
            ->whereKey($representation->id)
            ->update([
                'representation_status' => SuchakProfileRepresentation::STATUS_REVOKED,
                'consent_status' => SuchakProfileRepresentation::CONSENT_REVOKED,
                'revoked_at' => now(),
            ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Profile update suggestions require active representation with valid consent.');

        $service->createCoreFieldSuggestion($account, $suchakUser, $representation->fresh(), 'highest_education', 'M.Com');
    }

    public function test_invalid_candidate_otp_increments_attempts_without_applying(): void
    {
        [$suchakUser, $account, $candidateUser, $profile, $representation] = $this->activeRepresentationFixture([
            'highest_education' => 'B.Com',
        ]);
        $service = app(SuchakProfileUpdateSuggestionService::class);

        $suggestion = $service->createCoreFieldSuggestion($account, $suchakUser, $representation, 'highest_education', 'M.Com');
        $withOtp = $service->recordOtpSent($suggestion, '123456', $suchakUser);

        try {
            $service->verifyCandidateOtpAndApply($withOtp, '000000', $candidateUser);

            $this->fail('Invalid OTP should not apply suggestion.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Invalid OTP for Suchak profile update suggestion.', $exception->getMessage());
        }

        $fresh = $suggestion->fresh();
        $this->assertSame(1, $fresh->otp_attempts);
        $this->assertSame(SuchakProfileUpdateSuggestion::STATUS_PENDING_CANDIDATE_CONFIRMATION, $fresh->suggestion_status);
        $this->assertSame('B.Com', $profile->fresh()->highest_education);
    }

    public function test_suchak_profile_update_suggestions_cannot_be_deleted(): void
    {
        $suggestion = SuchakProfileUpdateSuggestion::factory()->create();

        try {
            $suggestion->delete();

            $this->fail('Suchak profile update suggestion delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak profile update suggestions cannot be deleted.', $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $profileAttributes
     * @return array{0: User, 1: SuchakAccount, 2: User, 3: MatrimonyProfile, 4: SuchakProfileRepresentation}
     */
    private function activeRepresentationFixture(array $profileAttributes = []): array
    {
        [$suchakUser, $account] = $this->verifiedSuchakActor();
        $candidateUser = User::factory()->create();
        $profile = $this->activeProfile(array_merge([
            'user_id' => $candidateUser->id,
            'highest_education' => 'B.Com',
            'annual_income' => 500000,
            'company_name' => 'Existing Company',
        ], $profileAttributes));
        [$representation] = $this->activeRepresentation($account, $profile);

        return [$suchakUser, $account, $candidateUser, $profile, $representation];
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function verifiedSuchakActor(): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        return [$user, $account];
    }

    /**
     * @return array{0: SuchakProfileRepresentation, 1: SuchakConsent}
     */
    private function activeRepresentation(SuchakAccount $account, MatrimonyProfile $profile): array
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

        $consent = SuchakConsent::factory()->create([
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

        return [$representation, $consent];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeProfile(array $attributes = []): MatrimonyProfile
    {
        $profile = MatrimonyProfile::factory()->create(array_merge([
            'full_name' => 'Suchak Day 15 Candidate',
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'height_cm' => 164,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], $attributes, [
            'lifecycle_state' => 'draft',
        ]));

        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $leafId]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $leafId, null, true, false);
        }

        $profile->update([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        return $profile->fresh();
    }
}
