<?php

namespace Tests\Feature\Suchak;

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakBiodataExport;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakConsent;
use App\Models\SuchakPlan;
use App\Models\SuchakPlanFeature;
use App\Models\SuchakPolicy;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\SuchakSubscription;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use App\Modules\Suchak\Services\SuchakConsentService;
use App\Modules\Suchak\Services\SuchakPdfQrFoundationService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Modules\Suchak\Services\SuchakRepresentationService;
use App\Modules\Suchak\Services\SuchakRequestPipelineService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class SuchakPolicyLimitEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_upload_limit_blocks_intake_mutation_before_source_link_creation(): void
    {
        Bus::fake();
        [$user, $account] = $this->verifiedSuchakActor();
        $this->setPolicy(SuchakPolicyService::KEY_SUCHAK_UPLOAD_DAILY_LIMIT, '1');
        $this->createSourceUpload($account, $user);

        $this->actingAs($user)
            ->post(route('suchak.intakes.store'), [
                'raw_text' => 'This upload should be blocked before intake creation.',
            ])
            ->assertRedirect(route('suchak.dashboard'))
            ->assertSessionHas('error', 'Daily Suchak upload limit reached for this account.');

        $this->assertSame(1, BiodataIntake::query()->count());
        $this->assertSame(1, SuchakBiodataIntakeLink::query()->count());
        Bus::assertNotDispatched(ParseIntakeJob::class);
    }

    public function test_monthly_upload_entitlement_limit_blocks_intake_mutation(): void
    {
        Bus::fake();
        [$user, $account] = $this->verifiedSuchakActor();
        $this->setPolicy(SuchakPolicyService::KEY_SUCHAK_UPLOAD_DAILY_LIMIT, '25');
        $this->assignIntegerFeature($account, SuchakPlanFeature::FEATURE_MONTHLY_UPLOAD_LIMIT, 1);
        $this->createSourceUpload($account, $user);

        $this->actingAs($user)
            ->post(route('suchak.intakes.store'), [
                'raw_text' => 'Monthly entitlement should block this upload.',
            ])
            ->assertRedirect(route('suchak.dashboard'))
            ->assertSessionHas('error', 'Monthly Suchak upload entitlement limit reached for this account.');

        $this->assertSame(1, BiodataIntake::query()->count());
        $this->assertSame(1, SuchakBiodataIntakeLink::query()->count());
        Bus::assertNotDispatched(ParseIntakeJob::class);
    }

    public function test_pdf_limit_blocks_export_and_qr_creation_before_mutation(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $representation = $this->activeRepresentation($account);
        $this->setPolicy(SuchakPolicyService::KEY_PDF_DOWNLOAD_LIMIT_PER_DAY, '1');

        SuchakBiodataExport::query()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $representation->matrimony_profile_id,
            'representation_id' => $representation->id,
            'export_type' => SuchakBiodataExport::TYPE_BIODATA_PDF,
            'file_path' => null,
            'generated_by_user_id' => $user->id,
        ]);

        try {
            app(SuchakPdfQrFoundationService::class)->createGovernedBiodataPdfExport($representation, $user);

            $this->fail('PDF limit should block export creation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Daily PDF/QR limit reached for this Suchak account.', $exception->getMessage());
        }

        $this->assertSame(1, SuchakBiodataExport::query()->count());
        $this->assertDatabaseCount('suchak_qr_tokens', 0);
    }

    public function test_active_profile_limit_blocks_representation_creation_before_mutation(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $this->setPolicy(SuchakPolicyService::KEY_SUCHAK_ACTIVE_PROFILE_LIMIT_BY_PLAN, '1');
        $this->activeRepresentation($account);

        $profile = $this->activeProfile(['full_name' => 'Second Limited Candidate']);
        $sourceLink = $this->linkedSource($user, $account, $profile);

        try {
            app(SuchakRepresentationService::class)->createPendingFromSourceLink($account, $user, $sourceLink, $profile);

            $this->fail('Active profile limit should block representation creation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Active profile limit reached for this Suchak account.', $exception->getMessage());
        }

        $this->assertSame(1, SuchakProfileRepresentation::query()->where('suchak_account_id', $account->id)->count());
    }

    public function test_collaboration_entitlement_limit_and_sla_policy_are_enforced_before_mutation(): void
    {
        [$requestingUser, $requestingAccount] = $this->verifiedSuchakActor(publicActive: true);
        $this->assignIntegerFeature($requestingAccount, SuchakPlanFeature::FEATURE_COLLABORATION_REQUEST_LIMIT, 1);
        $this->setPolicy(SuchakPolicyService::KEY_COLLABORATION_SLA_DAYS, '3');

        $requestingRepresentation = $this->activeRepresentation($requestingAccount, $this->activeProfile());
        [, $firstTargetAccount] = $this->verifiedSuchakActor(publicActive: true);
        $firstTargetRepresentation = $this->activeRepresentation($firstTargetAccount, $this->activeProfile(['full_name' => 'First Target']));
        [, $secondTargetAccount] = $this->verifiedSuchakActor(publicActive: true);
        $secondTargetRepresentation = $this->activeRepresentation($secondTargetAccount, $this->activeProfile(['full_name' => 'Second Target']));

        $first = app(SuchakCollaborationService::class)->createRequest(
            $requestingAccount,
            $requestingUser,
            $requestingRepresentation,
            $firstTargetRepresentation,
        )['request'];

        $this->assertTrue($first->expires_at->greaterThan(now()->addDays(2)));
        $this->assertTrue($first->expires_at->lessThan(now()->addDays(4)));

        try {
            app(SuchakCollaborationService::class)->createRequest(
                $requestingAccount,
                $requestingUser,
                $requestingRepresentation,
                $secondTargetRepresentation,
            );

            $this->fail('Collaboration request limit should block second open request.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Open collaboration request limit reached for this Suchak account.', $exception->getMessage());
        }

        $this->assertSame(1, SuchakCollaborationRequest::query()
            ->where('requesting_suchak_account_id', $requestingAccount->id)
            ->count());
    }

    public function test_lead_request_entitlement_limit_blocks_public_request_before_mutation(): void
    {
        [$suchakUser, $account] = $this->verifiedSuchakActor(publicActive: true);
        $this->assignIntegerFeature($account, SuchakPlanFeature::FEATURE_LEAD_REQUEST_LIMIT, 1);

        $representation = $this->activeRepresentation($account, $this->activeProfile(['full_name' => 'Lead Target']));
        [$firstUser, $firstProfile] = $this->regularActiveUserProfile('First Lead Requester');
        [$secondUser, $secondProfile] = $this->regularActiveUserProfile('Second Lead Requester');

        SuchakProfileRequest::query()->create([
            'requesting_user_id' => $firstUser->id,
            'requesting_matrimony_profile_id' => $firstProfile->id,
            'target_matrimony_profile_id' => $representation->matrimony_profile_id,
            'selected_suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
            'request_status' => SuchakProfileRequest::STATUS_PENDING,
            'request_reason' => null,
            'message' => null,
        ]);

        try {
            app(SuchakRequestPipelineService::class)->createRequest($secondUser, $secondProfile, $representation);

            $this->fail('Lead request limit should block public Suchak request creation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Open Suchak lead request limit reached for this account.', $exception->getMessage());
        }

        $this->assertSame(1, SuchakProfileRequest::query()->where('selected_suchak_account_id', $account->id)->count());
        $this->assertDatabaseCount('suchak_pipelines', 0);
        $this->assertSame($suchakUser->id, $account->user_id);
    }

    public function test_qr_expiry_and_consent_validity_read_policy_values(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $representation = $this->activeRepresentation($account);
        $this->setPolicy(SuchakPolicyService::KEY_QR_TOKEN_EXPIRY_DAYS, '5');

        $result = app(SuchakPdfQrFoundationService::class)->createGovernedBiodataPdfExport($representation, $user);
        $this->assertTrue($result['qr_token']->expires_at->greaterThan(now()->addDays(4)));
        $this->assertTrue($result['qr_token']->expires_at->lessThan(now()->addDays(6)));

        $this->setPolicy(SuchakPolicyService::KEY_DEFAULT_CONSENT_VALIDITY_MONTHS, '6');
        [$pendingUser, $pendingAccount] = $this->verifiedSuchakActor();
        $pendingRepresentation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $pendingAccount->id,
            'matrimony_profile_id' => $this->activeProfile(['full_name' => 'Policy Consent Candidate'])->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
            'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
        ]);

        $consentService = app(SuchakConsentService::class);
        $consent = $consentService->requestConsent($pendingRepresentation, $pendingUser)['consent'];
        $sent = $consentService->recordOtpSent($consent, '123456', $pendingUser);
        $accepted = $consentService->verifyOtpAndAccept($sent, '123456');

        $this->assertTrue($accepted->valid_until->greaterThan(now()->addMonths(5)));
        $this->assertTrue($accepted->valid_until->lessThan(now()->addMonths(7)));
    }

    private function setPolicy(string $key, string $value): void
    {
        SuchakPolicy::query()
            ->where('policy_key', $key)
            ->update(['policy_value' => $value]);
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function verifiedSuchakActor(bool $publicActive = false): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => $publicActive ? SuchakAccount::PUBLIC_ACTIVE : SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => now(),
        ]);

        return [$user, $account->fresh('user')];
    }

    private function assignIntegerFeature(SuchakAccount $account, string $featureKey, int $value): void
    {
        $plan = SuchakPlan::factory()->create([
            'is_active' => true,
            'is_visible' => true,
        ]);

        SuchakPlanFeature::factory()->create([
            'suchak_plan_id' => $plan->id,
            'feature_key' => $featureKey,
            'value_type' => SuchakPlanFeature::TYPE_INTEGER,
            'feature_value' => (string) $value,
            'is_enabled' => true,
        ]);

        SuchakSubscription::factory()->create([
            'suchak_account_id' => $account->id,
            'suchak_plan_id' => $plan->id,
            'status' => SuchakSubscription::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    private function createSourceUpload(SuchakAccount $account, User $user): SuchakBiodataIntakeLink
    {
        $intake = BiodataIntake::query()->create([
            'uploaded_by' => $user->id,
            'raw_ocr_text' => 'Existing upload for limit test.',
            'intake_status' => 'uploaded',
            'parse_status' => 'pending',
            'approved_by_user' => false,
            'intake_locked' => false,
            'snapshot_schema_version' => 1,
        ]);

        return SuchakBiodataIntakeLink::query()->create([
            'suchak_account_id' => $account->id,
            'biodata_intake_id' => $intake->id,
            'matrimony_profile_id' => null,
            'source_status' => SuchakBiodataIntakeLink::STATUS_INTAKE_UPLOADED,
            'created_by_user_id' => $user->id,
        ]);
    }

    private function linkedSource(User $user, SuchakAccount $account, MatrimonyProfile $profile): SuchakBiodataIntakeLink
    {
        $link = $this->createSourceUpload($account, $user);
        SuchakBiodataIntakeLink::query()
            ->whereKey($link->id)
            ->update([
                'matrimony_profile_id' => $profile->id,
                'source_status' => SuchakBiodataIntakeLink::STATUS_LINKED_TO_EXISTING_PROFILE,
            ]);

        return $link->fresh();
    }

    private function activeRepresentation(SuchakAccount $account, ?MatrimonyProfile $profile = null): SuchakProfileRepresentation
    {
        $profile ??= $this->activeProfile();

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

    /**
     * @return array{0: User, 1: MatrimonyProfile}
     */
    private function regularActiveUserProfile(string $name): array
    {
        $user = User::factory()->create();
        $profile = $this->activeProfile([
            'user_id' => $user->id,
            'full_name' => $name,
        ]);

        return [$user, $profile];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeProfile(array $attributes = []): MatrimonyProfile
    {
        $profile = MatrimonyProfile::factory()->create(array_merge([
            'full_name' => 'Policy Limit Candidate',
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'height_cm' => 164,
            'highest_education' => 'B.Com',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], $attributes, [
            'lifecycle_state' => 'draft',
        ]));

        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $leafId]);
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $leafId, null, true, false);
        }

        DB::table($profile->getTable())
            ->where('id', $profile->id)
            ->update([
                'lifecycle_state' => 'active',
                'is_suspended' => false,
                'updated_at' => now(),
            ]);

        return $profile->fresh();
    }
}
