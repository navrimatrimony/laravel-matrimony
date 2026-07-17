<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakFeatureSuspension;
use App\Models\SuchakGrowthAttribution;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPlatformPayout;
use App\Models\SuchakPlatformPayoutDetail;
use App\Models\SuchakPolicy;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakGrowthRewardService;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPaymentRequestService;
use App\Modules\Suchak\Services\SuchakPlatformPayoutService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Modules\Suchak\Services\SuchakQualityControlService;
use App\Modules\Suchak\Services\SuchakSourceLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Feature\Suchak\Support\CreatesSuchakAdmin;
use Tests\TestCase;

class SuchakQualityControlSuspensionTest extends TestCase
{
    use CreatesSuchakAdmin;
    use RefreshDatabase;

    public function test_day_54_quality_control_schema_admin_ui_audit_and_public_non_leak(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_feature_suspensions'));
        foreach ([
            'suchak_account_id',
            'feature_key',
            'suspension_status',
            'reason',
            'created_by_admin_user_id',
            'created_admin_audit_log_id',
            'released_by_admin_user_id',
            'released_admin_audit_log_id',
            'released_at',
            'release_reason',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_feature_suspensions', $column), $column);
        }

        $admin = $this->createSuchakSuperAdmin();
        $nonAdmin = User::factory()->create(['is_admin' => false]);
        [$suchakUser, $account] = $this->verifiedSuchakActor([
            'suchak_name' => 'Day54 Quality Suchak',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.safety.accounts.feature-suspensions.store', $account), [
                'feature_key' => SuchakFeatureSuspension::FEATURE_UPLOAD,
                'reason' => 'Day54 upload control review before new source intake.',
            ])
            ->assertRedirect(route('admin.suchak.safety.index'));

        $suspension = SuchakFeatureSuspension::query()->firstOrFail();
        $this->assertSame(SuchakFeatureSuspension::STATUS_ACTIVE, $suspension->suspension_status);
        $this->assertNotNull($suspension->created_admin_audit_log_id);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action_type' => 'suchak_feature_suspension_created',
            'entity_type' => 'SuchakFeatureSuspension',
            'entity_id' => $suspension->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.safety.index'))
            ->assertOk()
            ->assertSee('Quality Score + Granular Suspension', false)
            ->assertSee('Day54 Quality Suchak', false)
            ->assertSee('Upload', false)
            ->assertSee('Day54 upload control review before new source intake.', false);

        $this->actingAs($nonAdmin)
            ->get(route('admin.suchak.safety.index'))
            ->assertForbidden();

        $this->actingAs($suchakUser)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertDontSee('Quality Score + Granular Suspension', false)
            ->assertDontSee('Day54 upload control review before new source intake.', false);

        $this->actingAs($admin)
            ->post(route('admin.suchak.safety.feature-suspensions.release', $suspension), [
                'reason' => 'Day54 upload review completed with enough evidence.',
            ])
            ->assertRedirect(route('admin.suchak.safety.index'));

        $released = $suspension->fresh();
        $this->assertSame(SuchakFeatureSuspension::STATUS_RELEASED, $released->suspension_status);
        $this->assertNotNull($released->released_admin_audit_log_id);
        $this->assertNotNull($released->released_at);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action_type' => 'suchak_feature_suspension_released',
            'entity_type' => 'SuchakFeatureSuspension',
            'entity_id' => $suspension->id,
        ]);
    }

    public function test_day_54_feature_suspensions_block_mutations_before_records_are_created(): void
    {
        Bus::fake();

        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $qualityControlService = app(SuchakQualityControlService::class);

        [$uploadUser, $uploadAccount] = $this->verifiedSuchakActor();
        $qualityControlService->suspendFeature(
            $uploadAccount,
            $admin,
            SuchakFeatureSuspension::FEATURE_UPLOAD,
            'Day54 upload capability is paused before mutation.',
        );

        try {
            app(SuchakSourceLinkService::class)->createFromIntakeUpload(
                $uploadAccount,
                $uploadUser,
                null,
                'Day54 blocked source link text.',
            );
            $this->fail('Upload suspension should block source link mutation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Upload capability is suspended', $exception->getMessage());
        }

        $this->assertDatabaseCount('biodata_intakes', 0);
        $this->assertDatabaseCount('suchak_biodata_intake_links', 0);

        [$paymentUser, $paymentAccount, , $package, $agreement, $paymentContext] = $this->acceptedAgreementFixture();
        $qualityControlService->suspendFeature(
            $paymentAccount,
            $admin,
            SuchakFeatureSuspension::FEATURE_PAYMENT,
            'Day54 payment request capability is paused before mutation.',
        );

        try {
            app(SuchakPaymentRequestService::class)->createAndSend($package, $agreement, $paymentContext, $paymentUser);
            $this->fail('Payment suspension should block payment request mutation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Payment request capability is suspended', $exception->getMessage());
        }

        $this->assertDatabaseMissing('suchak_payment_requests', [
            'suchak_account_id' => $paymentAccount->id,
        ]);

        [$payoutAccount, $payoutContext] = $this->platformContextFixture();
        $qualityControlService->suspendFeature(
            $payoutAccount,
            $admin,
            SuchakFeatureSuspension::FEATURE_PAYOUT,
            'Day54 payout capability is paused before mutation.',
        );

        try {
            app(SuchakPlatformPayoutService::class)->qualifyFromPlatformEvent(
                $payoutContext,
                $admin,
                $this->payoutPayload('day54-blocked-payout'),
            );
            $this->fail('Payout suspension should block payout qualification mutation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Payout capability is suspended', $exception->getMessage());
        }

        $this->assertDatabaseMissing('suchak_platform_payouts', [
            'suchak_account_id' => $payoutAccount->id,
        ]);

        [, $growthAccount] = $this->verifiedSuchakActor();
        $qualityControlService->suspendFeature(
            $growthAccount,
            $admin,
            SuchakFeatureSuspension::FEATURE_REFERRAL,
            'Day54 referral capability is paused before mutation.',
        );

        try {
            app(SuchakGrowthRewardService::class)->recordAttribution(
                $growthAccount,
                $admin,
                [
                    'attribution_source' => SuchakGrowthAttribution::SOURCE_REFERRAL_CODE,
                    'attribution_key' => 'day54-referral-block',
                    'referral_code' => 'D54REF',
                    'attribution_note' => 'Referral attribution should be blocked before storage.',
                ],
            );
            $this->fail('Referral suspension should block growth attribution mutation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Referral / coupon capability is suspended', $exception->getMessage());
        }

        $this->assertDatabaseMissing('suchak_growth_attributions', [
            'suchak_account_id' => $growthAccount->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: SuchakAccount}
     */
    private function verifiedSuchakActor(array $overrides = []): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create(array_merge([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ], $overrides));

        return [$user, $account];
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: SuchakCustomerContext, 3: SuchakServicePackage, 4: SuchakCustomerAgreement, 5: SuchakPaymentContext}
     */
    private function acceptedAgreementFixture(): array
    {
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE],
            [
                'policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Auto publish packages for Day-54 quality control fixture.',
                'is_active' => true,
            ],
        );

        [$suchakUser, $account] = $this->verifiedSuchakActor();
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Day 54 Payment Candidate',
            'date_of_birth' => now()->subYears(27)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'payer_name' => 'Day54 customer family',
            'payer_relationship_to_candidate' => 'Parent',
            'service_context' => SuchakCustomerContext::SERVICE_PACKAGE_LEAD,
            'source_owner' => SuchakCustomerContext::SOURCE_OWNER_SUCHAK,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $suchakUser->id,
            'classified_by_user_id' => $suchakUser->id,
            'classified_at' => now(),
            'opened_at' => now(),
        ]);

        $package = app(SuchakPackageCatalogService::class)->createCustomPackage(
            $account,
            $suchakUser,
            [
                'package_name' => 'Day-54 Quality Control Package',
                'package_description' => 'Structured customer package for suspension guard test.',
                'price_amount' => '15000',
                'currency' => 'INR',
            ],
            $this->stagePayload(),
            $this->deliverablePayload(),
            $customerContext,
        );

        $agreement = app(SuchakAgreementService::class)->createAgreementForPackage(
            $package,
            $suchakUser,
            [
                'agreement_title' => 'Day-54 agreement terms',
                'agreement_body' => 'Customer confirms the package scope before payment request.',
            ],
        );
        $agreement = app(SuchakAgreementService::class)->acceptTerms($agreement, $suchakUser);

        $paymentContext = SuchakPaymentContext::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'matrimony_profile_id' => $profile->id,
            'source_owner' => SuchakPaymentContext::SOURCE_SUCHAK,
            'payment_collector' => SuchakPaymentContext::COLLECTOR_SUCHAK,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $suchakUser->id,
            'resolution_note' => 'Day-54 payment request fixture.',
        ]);

        return [
            $suchakUser,
            $account,
            $customerContext,
            $package->fresh(['suchakAccount.user', 'customerContext', 'stages', 'deliverables.servicePackageStage']),
            $agreement->fresh(['suchakAccount', 'customerContext', 'servicePackage', 'stages', 'deliverables']),
            $paymentContext->fresh(['suchakAccount', 'customerContext', 'matrimonyProfile']),
        ];
    }

    /**
     * @return array{0: SuchakAccount, 1: SuchakPaymentContext}
     */
    private function platformContextFixture(): array
    {
        [$suchakUser, $account] = $this->verifiedSuchakActor();
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Day 54 Platform Candidate',
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'payer_name' => 'Day54 platform customer family',
            'payer_relationship_to_candidate' => 'Parent',
            'service_context' => SuchakCustomerContext::SERVICE_PACKAGE_LEAD,
            'source_owner' => SuchakCustomerContext::SOURCE_OWNER_PLATFORM,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_PLATFORM_REQUEST,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $suchakUser->id,
            'classified_by_user_id' => $suchakUser->id,
            'classified_at' => now(),
            'opened_at' => now(),
        ]);
        $paymentContext = SuchakPaymentContext::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'matrimony_profile_id' => $profile->id,
            'source_owner' => SuchakPaymentContext::SOURCE_PLATFORM,
            'payment_collector' => SuchakPaymentContext::COLLECTOR_PLATFORM,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $suchakUser->id,
            'resolution_note' => 'Day-54 platform payout fixture.',
        ]);

        return [
            $account,
            $paymentContext->fresh(['suchakAccount', 'customerContext', 'matrimonyProfile']),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stagePayload(): array
    {
        return [
            [
                'stage_key' => 'intake_and_shortlist',
                'stage_name' => 'Intake and shortlist',
                'stage_description' => 'Collect requirements and prepare shortlist.',
                'sort_order' => 10,
                'expected_days' => 7,
            ],
            [
                'stage_key' => 'family_coordination',
                'stage_name' => 'Family coordination',
                'stage_description' => 'Coordinate discussion and next steps.',
                'sort_order' => 20,
                'expected_days' => 14,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function deliverablePayload(): array
    {
        return [
            [
                'stage_key' => 'intake_and_shortlist',
                'deliverable_key' => 'shortlist_report',
                'deliverable_name' => 'Shortlist report',
                'deliverable_description' => 'Candidate shortlist summary.',
                'sort_order' => 10,
            ],
            [
                'stage_key' => 'family_coordination',
                'deliverable_key' => 'meeting_followup',
                'deliverable_name' => 'Meeting follow-up',
                'deliverable_description' => 'Follow-up notes after discussion.',
                'sort_order' => 20,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payoutPayload(string $eventKey): array
    {
        return [
            'platform_event_type' => SuchakPlatformPayout::EVENT_PLATFORM_CUSTOMER_PAYMENT,
            'platform_event_key' => $eventKey,
            'payout_reason' => SuchakPlatformPayout::REASON_PLATFORM_CUSTOMER_PAYMENT_REWARD,
            'qualification_source' => SuchakPlatformPayout::SOURCE_PLATFORM_CONFIRMED_EVENT,
            'amount' => '2500',
            'currency' => 'INR',
            'qualification_note' => 'Platform-confirmed payment qualifies payout liability.',
            'payout_details' => [
                'payout_method' => SuchakPlatformPayoutDetail::METHOD_BANK_TRANSFER,
                'beneficiary_name' => 'Verified Suchak Office',
                'account_last_four' => '4321',
                'ifsc_code' => 'HDFC0001234',
                'verification_status' => SuchakPlatformPayoutDetail::STATUS_PENDING,
                'verification_note' => 'Payout details pending verification.',
            ],
        ];
    }
}
