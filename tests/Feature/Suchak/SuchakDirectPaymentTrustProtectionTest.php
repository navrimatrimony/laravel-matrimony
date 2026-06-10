<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminAuditLog;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakDirectPaymentEvidence;
use App\Models\SuchakDispute;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentFeatureFreeze;
use App\Models\SuchakPolicy;
use App\Models\SuchakPayoutHold;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPaymentRequestService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakDirectPaymentTrustProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_41_direct_payment_trust_tables_are_structured_and_non_destructive(): void
    {
        $this->assertContains(SuchakDispute::TYPE_DIRECT_PAYMENT_REQUEST, SuchakDispute::TYPES);
        $this->assertContains(SuchakDispute::RISK_SOURCE_CUSTOMER_DIRECT_PAYMENT_REPORT, SuchakDispute::RISK_SOURCES);
        $this->assertTrue(Schema::hasColumn('suchak_disputes', 'customer_context_id'));
        $this->assertTrue(Schema::hasColumn('suchak_disputes', 'payment_context_id'));
        $this->assertTrue(Schema::hasColumn('suchak_disputes', 'risk_source'));

        $this->assertTrue(Schema::hasTable('suchak_direct_payment_evidence'));
        $this->assertTrue(Schema::hasTable('suchak_payment_feature_freezes'));
        $this->assertTrue(Schema::hasTable('suchak_payout_holds'));

        foreach ([
            'suchak_dispute_id',
            'suchak_account_id',
            'customer_context_id',
            'payment_context_id',
            'submitted_by_user_id',
            'evidence_type',
            'evidence_reference',
            'evidence_note',
            'submitted_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_direct_payment_evidence', $column), $column);
        }

        foreach ([
            'suchak_dispute_id',
            'suchak_account_id',
            'customer_context_id',
            'payment_context_id',
            'freeze_scope',
            'freeze_status',
            'freeze_reason',
            'created_by_admin_user_id',
            'released_by_admin_user_id',
            'released_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_payment_feature_freezes', $column), $column);
        }

        foreach ([
            'suchak_dispute_id',
            'suchak_account_id',
            'customer_context_id',
            'payment_context_id',
            'hold_scope',
            'hold_status',
            'hold_reason',
            'created_by_user_id',
            'released_by_user_id',
            'released_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_payout_holds', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_direct_payment_evidence', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_payment_feature_freezes', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_payout_holds', 'deleted_at'));
    }

    public function test_platform_customer_can_report_direct_payment_request_with_evidence_and_payout_hold(): void
    {
        [$reporter, $account, $profile, $customerContext, $paymentContext] = $this->platformPaymentContextFixture();
        $beforeUpdatedAt = $profile->updated_at?->toDateTimeString();

        $this->actingAs($reporter)
            ->post(route('suchak.direct-payment-complaints.store'), [
                'suchak_account_id' => $account->id,
                'customer_context_id' => $customerContext->id,
                'payment_context_id' => $paymentContext->id,
                'summary' => 'Suchak asked our family to send direct UPI payment outside platform.',
                'evidence_type' => SuchakDirectPaymentEvidence::TYPE_UPI_OR_BANK_DETAIL,
                'evidence_reference' => 'chat-message-12',
                'evidence_note' => 'Message included a private UPI handle and asked us to bypass platform collection.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Direct payment complaint submitted for admin review.');

        $dispute = SuchakDispute::query()->firstOrFail();
        $this->assertSame($account->id, $dispute->suchak_account_id);
        $this->assertSame($profile->id, $dispute->matrimony_profile_id);
        $this->assertSame($customerContext->id, $dispute->customer_context_id);
        $this->assertSame($paymentContext->id, $dispute->payment_context_id);
        $this->assertSame($reporter->id, $dispute->opened_by_user_id);
        $this->assertSame(SuchakDispute::TYPE_DIRECT_PAYMENT_REQUEST, $dispute->dispute_type);
        $this->assertSame(SuchakDispute::STATUS_OPEN, $dispute->status);
        $this->assertSame(SuchakDispute::PRIORITY_HIGH, $dispute->priority);
        $this->assertSame(SuchakDispute::RISK_SOURCE_CUSTOMER_DIRECT_PAYMENT_REPORT, $dispute->risk_source);

        $evidence = SuchakDirectPaymentEvidence::query()->firstOrFail();
        $this->assertSame($dispute->id, $evidence->suchak_dispute_id);
        $this->assertSame($paymentContext->id, $evidence->payment_context_id);
        $this->assertSame(SuchakDirectPaymentEvidence::TYPE_UPI_OR_BANK_DETAIL, $evidence->evidence_type);
        $this->assertSame('chat-message-12', $evidence->evidence_reference);

        $hold = SuchakPayoutHold::query()->firstOrFail();
        $this->assertSame($dispute->id, $hold->suchak_dispute_id);
        $this->assertSame(SuchakPayoutHold::STATUS_ACTIVE, $hold->hold_status);
        $this->assertSame(SuchakPayoutHold::SCOPE_DIRECT_PAYMENT_RISK, $hold->hold_scope);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $reporter->id,
            'actor_type' => SuchakActivityLog::ACTOR_USER,
            'action_type' => SuchakActivityLog::ACTION_DIRECT_PAYMENT_COMPLAINT_OPENED,
            'target_type' => 'suchak_dispute',
            'target_id' => $dispute->id,
        ]);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $reporter->id,
            'actor_type' => SuchakActivityLog::ACTOR_USER,
            'action_type' => SuchakActivityLog::ACTION_DIRECT_PAYMENT_EVIDENCE_ADDED,
            'target_type' => 'suchak_direct_payment_evidence',
            'target_id' => $evidence->id,
        ]);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $reporter->id,
            'actor_type' => SuchakActivityLog::ACTOR_USER,
            'action_type' => SuchakActivityLog::ACTION_PAYOUT_HOLD_OPENED,
            'target_type' => 'suchak_payout_hold',
            'target_id' => $hold->id,
        ]);
        $this->assertSame($beforeUpdatedAt, $profile->fresh()->updated_at?->toDateTimeString());
    }

    public function test_direct_payment_complaint_requires_the_platform_customer_and_platform_collector_context(): void
    {
        [$reporter, $account, , $customerContext, $platformContext] = $this->platformPaymentContextFixture();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->post(route('suchak.direct-payment-complaints.store'), [
                'suchak_account_id' => $account->id,
                'customer_context_id' => $customerContext->id,
                'payment_context_id' => $platformContext->id,
                'summary' => 'Trying to report a context I do not own.',
                'evidence_type' => SuchakDirectPaymentEvidence::TYPE_OTHER,
                'evidence_note' => 'This should be rejected because the reporter does not own the profile.',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Only the platform customer can report this Suchak payment context.');

        $suchakCollectedContext = SuchakPaymentContext::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'matrimony_profile_id' => $platformContext->matrimony_profile_id,
            'source_owner' => SuchakPaymentContext::SOURCE_SUCHAK,
            'payment_collector' => SuchakPaymentContext::COLLECTOR_SUCHAK,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $account->user_id,
            'resolution_note' => 'Day-41 non-platform collector rejection fixture.',
        ]);

        $this->actingAs($reporter)
            ->post(route('suchak.direct-payment-complaints.store'), [
                'suchak_account_id' => $account->id,
                'customer_context_id' => $customerContext->id,
                'payment_context_id' => $suchakCollectedContext->id,
                'summary' => 'Trying to report a non-platform collector context.',
                'evidence_type' => SuchakDirectPaymentEvidence::TYPE_OTHER,
                'evidence_note' => 'This should be rejected because only platform collection can be reported here.',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Only platform-collected Suchak customer contexts can open direct payment complaints.');

        $this->assertSame(0, SuchakDispute::query()->count());
        $this->assertSame(0, SuchakDirectPaymentEvidence::query()->count());
        $this->assertSame(0, SuchakPayoutHold::query()->count());
    }

    public function test_admin_review_can_freeze_payment_ability_and_direct_collection_guard_blocks_requests(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$suchakUser, $account, $customerContext, $package, $agreement, $paymentContext] = $this->directPaymentRequestFixture();
        $dispute = SuchakDispute::query()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $paymentContext->matrimony_profile_id,
            'representation_id' => null,
            'customer_context_id' => $customerContext->id,
            'payment_context_id' => $paymentContext->id,
            'opened_by_user_id' => $admin->id,
            'dispute_type' => SuchakDispute::TYPE_DIRECT_PAYMENT_REQUEST,
            'status' => SuchakDispute::STATUS_OPEN,
            'priority' => SuchakDispute::PRIORITY_HIGH,
            'risk_source' => SuchakDispute::RISK_SOURCE_ADMIN_CASE,
            'summary' => 'Admin is reviewing a suspected direct collection abuse complaint.',
            'evidence_summary' => 'Customer submitted direct payment request evidence.',
            'opened_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.safety.disputes.payment-freeze', $dispute), [
                'freeze_reason' => 'Freeze direct collection while payment abuse evidence is reviewed.',
            ])
            ->assertRedirect(route('admin.suchak.safety.index'))
            ->assertSessionHas('success', 'Suchak payment ability frozen.');

        $freeze = SuchakPaymentFeatureFreeze::query()->firstOrFail();
        $this->assertSame($dispute->id, $freeze->suchak_dispute_id);
        $this->assertSame($customerContext->id, $freeze->customer_context_id);
        $this->assertSame($paymentContext->id, $freeze->payment_context_id);
        $this->assertSame(SuchakPaymentFeatureFreeze::SCOPE_CUSTOMER_CONTEXT, $freeze->freeze_scope);
        $this->assertSame(SuchakPaymentFeatureFreeze::STATUS_ACTIVE, $freeze->freeze_status);
        $this->assertSame($admin->id, $freeze->created_by_admin_user_id);

        $hold = SuchakPayoutHold::query()->firstOrFail();
        $this->assertSame($dispute->id, $hold->suchak_dispute_id);
        $this->assertSame(SuchakPayoutHold::STATUS_ACTIVE, $hold->hold_status);

        $adminAudit = AdminAuditLog::query()
            ->where('action_type', 'suchak_payment_feature_freeze_opened')
            ->where('entity_type', 'SuchakDispute')
            ->where('entity_id', $dispute->id)
            ->first();
        $this->assertNotNull($adminAudit);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => SuchakActivityLog::ACTION_PAYMENT_FEATURE_FREEZE_OPENED,
            'target_type' => 'suchak_payment_feature_freeze',
            'target_id' => $freeze->id,
            'admin_audit_log_id' => $adminAudit->id,
        ]);

        try {
            app(SuchakPaymentRequestService::class)->createAndSend($package, $agreement, $paymentContext, $suchakUser);
            $this->fail('Active direct payment freeze should block Suchak payment request creation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Suchak direct payment collection is frozen for this customer context during a payment risk review.',
                $exception->getMessage(),
            );
        }
    }

    public function test_customer_warnings_are_visible_on_payment_request_and_portal_surfaces(): void
    {
        [$suchakUser, , , $package, $agreement, $paymentContext] = $this->directPaymentRequestFixture();
        $result = app(SuchakPaymentRequestService::class)->createAndSend($package, $agreement, $paymentContext, $suchakUser);

        $this->get($result['public_url'])
            ->assertOk()
            ->assertSee('Platform-collected customers should not make direct Suchak payments', false)
            ->assertDontSee('secret-upi@bank');

        $this->get($result['portal_url'])
            ->assertOk()
            ->assertSee('Platform-collected customers should not make direct Suchak payments', false)
            ->assertDontSee('secret-upi@bank');
    }

    public function test_direct_payment_trust_records_are_not_deleted_or_silently_mutated(): void
    {
        [$reporter, $account, , $customerContext, $paymentContext] = $this->platformPaymentContextFixture();

        $this->actingAs($reporter)->post(route('suchak.direct-payment-complaints.store'), [
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'payment_context_id' => $paymentContext->id,
            'summary' => 'Suchak asked our family to send direct UPI payment outside platform.',
            'evidence_type' => SuchakDirectPaymentEvidence::TYPE_UPI_OR_BANK_DETAIL,
            'evidence_note' => 'Message included a private UPI handle and asked us to bypass platform collection.',
        ]);

        $evidence = SuchakDirectPaymentEvidence::query()->firstOrFail();
        $hold = SuchakPayoutHold::query()->firstOrFail();

        try {
            $evidence->update(['evidence_note' => 'changed']);
            $this->fail('Direct payment evidence update should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak direct payment evidence records are immutable and cannot be modified.', $exception->getMessage());
        }

        try {
            $evidence->delete();
            $this->fail('Direct payment evidence delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak direct payment evidence records cannot be deleted.', $exception->getMessage());
        }

        try {
            $hold->delete();
            $this->fail('Payout hold delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak payout hold records cannot be deleted.', $exception->getMessage());
        }
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: MatrimonyProfile, 3: SuchakCustomerContext, 4: SuchakPaymentContext}
     */
    private function platformPaymentContextFixture(): array
    {
        [$suchakUser, $account] = $this->verifiedSuchakActor();
        $reporter = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $reporter->id,
            'full_name' => 'Day 41 Platform Customer',
            'date_of_birth' => now()->subYears(29)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);

        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'payer_name' => 'Platform customer family',
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
            'resolution_note' => 'Day-41 platform complaint fixture.',
        ]);

        return [
            $reporter,
            $account,
            $profile,
            $customerContext,
            $paymentContext->fresh(['suchakAccount', 'customerContext', 'matrimonyProfile']),
        ];
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: SuchakCustomerContext, 3: SuchakServicePackage, 4: SuchakCustomerAgreement, 5: SuchakPaymentContext}
     */
    private function directPaymentRequestFixture(): array
    {
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE],
            [
                'policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Auto publish packages for Day-41 trust protection fixture.',
                'is_active' => true,
            ],
        );

        [$suchakUser, $account] = $this->verifiedSuchakActor();
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Day 41 Direct Customer',
            'date_of_birth' => now()->subYears(27)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'payer_name' => 'Candidate family',
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
                'package_name' => 'Day-41 Family Coordination',
                'package_description' => 'Structured customer package for trust protection test.',
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
                'agreement_title' => 'Day-41 agreement terms',
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
            'resolution_note' => 'Day-41 direct payment request fixture.',
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
}
