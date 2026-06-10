<?php

namespace Tests\Feature\Suchak;

use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakConsent;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakConsentService;
use App\Modules\Suchak\Services\SuchakCrmLedgerService;
use App\Modules\Suchak\Services\SuchakPdfQrFoundationService;
use App\Modules\Suchak\Services\SuchakRepresentationShutdownService;
use App\Modules\Suchak\Services\SuchakRequestPipelineService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class SuchakCandidateDeactivationRevocationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_candidate_deactivation_shuts_down_representation_and_preserves_evidence(): void
    {
        [$suchakUser, $account] = $this->verifiedSuchakActor();
        $targetProfile = $this->activeProfile([
            'full_name' => 'Sensitive Deactivation Candidate',
            'father_contact_1' => '9876543210',
        ]);
        [$representation] = $this->activeRepresentation($account, $targetProfile);
        $requestingUser = User::factory()->create();
        $requestingProfile = $this->activeProfile(['user_id' => $requestingUser->id]);

        $qrResult = app(SuchakPdfQrFoundationService::class)
            ->createGovernedBiodataPdfExport($representation, $suchakUser);
        $this->createEvidenceRecords($account, $suchakUser, $targetProfile);

        DB::table($targetProfile->getTable())
            ->where('id', $targetProfile->id)
            ->update([
                'lifecycle_state' => 'archived',
                'updated_at' => now(),
            ]);

        $changed = app(SuchakRepresentationShutdownService::class)->markCandidateDeactivated(
            $targetProfile->fresh(),
            null,
            null,
            'Candidate requested archive from 9876543210.',
        );

        $freshRepresentation = $representation->fresh();

        $this->assertCount(1, $changed);
        $this->assertSame(SuchakProfileRepresentation::STATUS_CANDIDATE_DEACTIVATED, $freshRepresentation->representation_status);
        $this->assertSame(SuchakProfileRepresentation::CONSENT_ACCEPTED, $freshRepresentation->consent_status);
        $this->assertNotNull($freshRepresentation->candidate_deactivated_at);
        $this->assertFalse($freshRepresentation->hasValidConsent());
        $this->assertFalse($freshRepresentation->isPubliclyVisible());

        $activity = SuchakActivityLog::query()
            ->where('action_type', SuchakActivityLog::ACTION_REPRESENTATION_CANDIDATE_DEACTIVATED)
            ->where('target_id', $representation->id)
            ->firstOrFail();
        $metadataJson = json_encode($activity->metadata_json, JSON_THROW_ON_ERROR);

        $this->assertSame(SuchakActivityLog::ACTOR_SYSTEM, $activity->actor_type);
        $this->assertStringContainsString('candidate_deactivated', $metadataJson);
        $this->assertStringNotContainsString('Sensitive Deactivation Candidate', $metadataJson);
        $this->assertStringNotContainsString('9876543210', $metadataJson);

        try {
            app(SuchakRequestPipelineService::class)->createRequest(
                $requestingUser,
                $requestingProfile,
                $freshRepresentation,
            );

            $this->fail('Candidate-deactivated Suchak representation should block new requests.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Target profile must be active to create a Suchak request.', $exception->getMessage());
        }

        try {
            app(SuchakPdfQrFoundationService::class)->scanQrToken($qrResult['raw_qr_token']);

            $this->fail('QR scan should be blocked after candidate deactivation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('QR token is no longer available.', $exception->getMessage());
        }

        $this->assertSame(1, $qrResult['qr_token']->fresh()->scan_count);
        $this->assertPreservedPrivateEvidence($account, $suchakUser, $targetProfile);
    }

    public function test_revoked_consent_blocks_future_visibility_without_deleting_evidence(): void
    {
        [$suchakUser, $account] = $this->verifiedSuchakActor();
        $targetProfile = $this->activeProfile([
            'full_name' => 'Sensitive Revocation Candidate',
            'mother_contact_1' => '9876543211',
        ]);
        [$representation, $consent] = $this->activeRepresentation($account, $targetProfile);
        $requestingUser = User::factory()->create();
        $requestingProfile = $this->activeProfile(['user_id' => $requestingUser->id]);

        $qrResult = app(SuchakPdfQrFoundationService::class)
            ->createGovernedBiodataPdfExport($representation, $suchakUser);
        $this->createEvidenceRecords($account, $suchakUser, $targetProfile);

        $revoked = app(SuchakConsentService::class)->revoke(
            $consent,
            $suchakUser,
            'Candidate revoked Suchak handling.',
        );

        $freshRepresentation = $representation->fresh();

        $this->assertSame(SuchakConsent::STATUS_REVOKED, $revoked->consent_status);
        $this->assertSame(SuchakProfileRepresentation::STATUS_REVOKED, $freshRepresentation->representation_status);
        $this->assertNotNull($freshRepresentation->revoked_at);
        $this->assertFalse($freshRepresentation->hasValidConsent());
        $this->assertFalse($freshRepresentation->isPubliclyVisible());

        try {
            app(SuchakRequestPipelineService::class)->createRequest(
                $requestingUser,
                $requestingProfile,
                $freshRepresentation,
            );

            $this->fail('Revoked Suchak consent should block new requests.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak request requires active representation with valid consent.', $exception->getMessage());
        }

        try {
            app(SuchakPdfQrFoundationService::class)->scanQrToken($qrResult['raw_qr_token']);

            $this->fail('QR scan should be blocked after consent revocation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('QR token is no longer available.', $exception->getMessage());
        }

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $suchakUser->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_CONSENT_REVOKED,
            'target_type' => 'suchak_consent',
            'target_id' => $consent->id,
            'matrimony_profile_id' => $targetProfile->id,
        ]);
        $this->assertSame(1, $qrResult['qr_token']->fresh()->scan_count);
        $this->assertPreservedPrivateEvidence($account, $suchakUser, $targetProfile);
    }

    public function test_candidate_deactivation_shutdown_requires_inactive_profile(): void
    {
        [, $account] = $this->verifiedSuchakActor();
        $targetProfile = $this->activeProfile();
        $this->activeRepresentation($account, $targetProfile);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Candidate profile must be inactive before Suchak representations can be candidate-deactivated.');

        app(SuchakRepresentationShutdownService::class)->markCandidateDeactivated($targetProfile);
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
            'full_name' => 'Suchak Day 14 Candidate',
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'height_cm' => 164,
            'highest_education' => 'Generic Education',
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

    private function createEvidenceRecords(SuchakAccount $account, User $actor, MatrimonyProfile $profile): void
    {
        $service = app(SuchakCrmLedgerService::class);

        $service->createProfileNote($account, $actor, $profile, [
            'note_type' => 'general',
            'note_text' => 'Private evidence note without contact details.',
        ]);
        $service->createLedgerEntry($account, $actor, $profile, [
            'source_owner' => SuchakPaymentContext::SOURCE_SUCHAK,
            'payment_collector' => SuchakPaymentContext::COLLECTOR_SUCHAK,
            'entry_type' => SuchakLedgerEntry::TYPE_REGISTRATION_FEE_EXPECTED,
            'amount' => '500',
        ]);
    }

    private function assertPreservedPrivateEvidence(SuchakAccount $account, User $actor, MatrimonyProfile $profile): void
    {
        $service = app(SuchakCrmLedgerService::class);

        $this->assertCount(1, $service->privateNotesForProfile($account, $actor, $profile));
        $this->assertCount(1, $service->privateLedgerForProfile($account, $actor, $profile));
    }
}
