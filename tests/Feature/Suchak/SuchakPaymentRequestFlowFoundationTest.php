<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPaymentRequestEvent;
use App\Models\SuchakPolicy;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPaymentRequestService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakPaymentRequestFlowFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_request_tables_exist_without_receipt_or_proof_columns(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_payment_requests'));
        $this->assertTrue(Schema::hasTable('suchak_payment_request_events'));

        foreach ([
            'suchak_account_id',
            'customer_context_id',
            'service_package_id',
            'customer_agreement_id',
            'payment_context_id',
            'requested_by_user_id',
            'request_token_hash',
            'payment_status',
            'payment_detail_visibility_policy',
            'request_title',
            'amount_due',
            'currency',
            'collector_disclosure',
            'sent_at',
            'opened_at',
            'expires_at',
            'cancelled_at',
            'cancelled_by_user_id',
            'expired_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_payment_requests', $column), $column);
        }

        foreach ([
            'payment_request_id',
            'suchak_account_id',
            'event_type',
            'actor_type',
            'actor_user_id',
            'from_status',
            'to_status',
            'event_note',
            'occurred_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_payment_request_events', $column), $column);
        }

        foreach ([
            'paid_at',
            'receipt_number',
            'invoice_number',
            'credit_note_number',
            'proof_file_path',
            'gateway_transaction_id',
            'upi_id',
            'qr_image_path',
        ] as $forbiddenColumn) {
            $this->assertFalse(Schema::hasColumn('suchak_payment_requests', $forbiddenColumn), $forbiddenColumn);
        }

        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_PAYMENT_DETAIL_VISIBILITY_POLICY,
            'policy_value' => SuchakPolicyService::DEFAULT_SUCHAK_PAYMENT_DETAIL_VISIBILITY_POLICY,
            'value_type' => SuchakPolicy::TYPE_STRING,
            'is_active' => true,
        ]);
    }

    public function test_suchak_can_create_secure_payment_request_and_public_open_tracks_opened_without_paid_receipt(): void
    {
        [$suchakUser, , , $package, $agreement, $paymentContext] = $this->acceptedAgreementFixture();

        $result = app(SuchakPaymentRequestService::class)->createAndSend(
            $package,
            $agreement,
            $paymentContext,
            $suchakUser,
            [
                'request_title' => 'Day-37 secure request',
                'request_note' => 'Please review the agreed terms before arranging payment.',
            ],
            '127.0.0.1',
            'Day-37 create test',
        );

        /** @var SuchakPaymentRequest $paymentRequest */
        $paymentRequest = $result['payment_request'];

        $this->assertSame(64, strlen($result['plain_token']));
        $this->assertSame(route('suchak.payment-requests.show', ['token' => $result['plain_token']], true), $result['public_url']);
        $this->assertSame(hash('sha256', $result['plain_token']), $paymentRequest->request_token_hash);
        $this->assertDatabaseMissing('suchak_payment_requests', [
            'request_token_hash' => $result['plain_token'],
        ]);

        $this->assertSame(SuchakPaymentRequest::STATUS_SENT, $paymentRequest->payment_status);
        $this->assertNotNull($paymentRequest->sent_at);
        $this->assertNull($paymentRequest->opened_at);
        $this->assertSame(SuchakPaymentRequest::VISIBILITY_TERMS_SATISFIED_ONLY, $paymentRequest->payment_detail_visibility_policy);
        $this->assertStringContainsString('Payment collector: Suchak', $paymentRequest->collector_disclosure);
        $this->assertSame('15000.00', $paymentRequest->amount_due);
        $this->assertSame('INR', $paymentRequest->currency);

        $this->assertSame(0, \App\Models\SuchakLedgerEntry::query()->count());
        $this->assertDatabaseHas('suchak_payment_request_events', [
            'payment_request_id' => $paymentRequest->id,
            'event_type' => SuchakPaymentRequestEvent::EVENT_SENT,
            'from_status' => SuchakPaymentRequest::STATUS_DRAFT,
            'to_status' => SuchakPaymentRequest::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $paymentRequest->suchak_account_id,
            'actor_user_id' => $suchakUser->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_PAYMENT_REQUEST_SENT,
            'target_type' => 'suchak_payment_request',
            'target_id' => $paymentRequest->id,
        ]);

        $this->get(route('suchak.payment-requests.show', ['token' => $result['plain_token']]))
            ->assertOk()
            ->assertSee('Day-37 secure request')
            ->assertSee('Payment request only')
            ->assertSee('This page is not a paid receipt', false)
            ->assertDontSee('secret-upi@bank');

        $opened = $paymentRequest->fresh();
        $this->assertSame(SuchakPaymentRequest::STATUS_OPENED, $opened->payment_status);
        $this->assertNotNull($opened->opened_at);
        $this->assertNull($opened->cancelled_at);
        $this->assertNull($opened->expired_at);

        $this->assertDatabaseHas('suchak_payment_request_events', [
            'payment_request_id' => $paymentRequest->id,
            'event_type' => SuchakPaymentRequestEvent::EVENT_OPENED,
            'from_status' => SuchakPaymentRequest::STATUS_SENT,
            'to_status' => SuchakPaymentRequest::STATUS_OPENED,
        ]);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'action_type' => SuchakActivityLog::ACTION_PAYMENT_REQUEST_OPENED,
            'target_type' => 'suchak_payment_request',
            'target_id' => $paymentRequest->id,
        ]);
    }

    public function test_terms_and_source_owner_collector_guards_block_unsafe_payment_requests(): void
    {
        [$suchakUser, , , $package, $pendingAgreement, $paymentContext] = $this->agreementFixture(
            SuchakPaymentContext::SOURCE_SUCHAK,
            SuchakPaymentContext::COLLECTOR_SUCHAK,
            false,
        );

        try {
            app(SuchakPaymentRequestService::class)->createAndSend($package, $pendingAgreement, $paymentContext, $suchakUser);
            $this->fail('Pending agreement terms should block payment requests.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Suchak agreement terms must be accepted, bypassed, or not required before sending payment requests.',
                $exception->getMessage(),
            );
        }

        [$platformUser, , , $platformPackage, $platformAgreement, $platformContext] = $this->acceptedAgreementFixture(
            SuchakPaymentContext::SOURCE_PLATFORM,
            SuchakPaymentContext::COLLECTOR_PLATFORM,
        );

        try {
            app(SuchakPaymentRequestService::class)->createAndSend($platformPackage, $platformAgreement, $platformContext, $platformUser);
            $this->fail('Platform-owned customer should not receive direct Suchak payment request.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(SuchakPaymentContext::PLATFORM_DIRECT_PAYMENT_BLOCK_MESSAGE, $exception->getMessage());
        }
    }

    public function test_cancel_and_expire_track_request_state_without_payment_confirmation(): void
    {
        [$suchakUser, , , $package, $agreement, $paymentContext] = $this->acceptedAgreementFixture();
        $service = app(SuchakPaymentRequestService::class);

        $result = $service->createAndSend($package, $agreement, $paymentContext, $suchakUser);
        $cancelled = $service->cancel(
            $result['payment_request'],
            $suchakUser,
            'Customer asked to revise the request.',
            '127.0.0.1',
            'Day-37 cancel test',
        );

        $this->assertSame(SuchakPaymentRequest::STATUS_CANCELLED, $cancelled->payment_status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame($suchakUser->id, $cancelled->cancelled_by_user_id);
        $this->assertSame('Customer asked to revise the request.', $cancelled->cancellation_reason);
        $this->assertDatabaseHas('suchak_payment_request_events', [
            'payment_request_id' => $cancelled->id,
            'event_type' => SuchakPaymentRequestEvent::EVENT_CANCELLED,
            'to_status' => SuchakPaymentRequest::STATUS_CANCELLED,
        ]);
        $this->get(route('suchak.payment-requests.show', ['token' => $result['plain_token']]))
            ->assertGone();

        [$expiryUser, , , $expiryPackage, $expiryAgreement, $expiryContext] = $this->acceptedAgreementFixture();
        $expiryResult = $service->createAndSend(
            $expiryPackage,
            $expiryAgreement,
            $expiryContext,
            $expiryUser,
            ['expires_at' => now()->addMinute()],
        );

        Carbon::setTestNow(now()->addMinutes(2));

        $this->get(route('suchak.payment-requests.show', ['token' => $expiryResult['plain_token']]))
            ->assertGone();

        Carbon::setTestNow();

        $expired = $expiryResult['payment_request']->fresh();
        $this->assertSame(SuchakPaymentRequest::STATUS_EXPIRED, $expired->payment_status);
        $this->assertNotNull($expired->expired_at);
        $this->assertNull($expired->cancelled_at);
        $this->assertDatabaseHas('suchak_payment_request_events', [
            'payment_request_id' => $expired->id,
            'event_type' => SuchakPaymentRequestEvent::EVENT_EXPIRED,
            'to_status' => SuchakPaymentRequest::STATUS_EXPIRED,
        ]);
    }

    public function test_payment_request_records_and_events_remain_non_deletable(): void
    {
        [$suchakUser, , , $package, $agreement, $paymentContext] = $this->acceptedAgreementFixture();
        $paymentRequest = app(SuchakPaymentRequestService::class)->createAndSend(
            $package,
            $agreement,
            $paymentContext,
            $suchakUser,
        )['payment_request'];
        $event = $paymentRequest->events()->firstOrFail();

        try {
            $paymentRequest->delete();
            $this->fail('Suchak payment request delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak payment requests cannot be deleted.', $exception->getMessage());
        }

        try {
            $event->update(['event_note' => 'changed']);
            $this->fail('Suchak payment request event update should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak payment request events are immutable and cannot be modified.', $exception->getMessage());
        }

        try {
            $event->delete();
            $this->fail('Suchak payment request event delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak payment request events are immutable and cannot be deleted.', $exception->getMessage());
        }
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: SuchakCustomerContext, 3: SuchakServicePackage, 4: SuchakCustomerAgreement, 5: SuchakPaymentContext}
     */
    private function acceptedAgreementFixture(
        string $sourceOwner = SuchakPaymentContext::SOURCE_SUCHAK,
        string $collector = SuchakPaymentContext::COLLECTOR_SUCHAK,
    ): array {
        return $this->agreementFixture($sourceOwner, $collector, true);
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: SuchakCustomerContext, 3: SuchakServicePackage, 4: SuchakCustomerAgreement, 5: SuchakPaymentContext}
     */
    private function agreementFixture(
        string $sourceOwner,
        string $collector,
        bool $acceptTerms,
    ): array {
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE],
            [
                'policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Auto publish packages for Day-37 payment request fixture.',
                'is_active' => true,
            ],
        );

        [$suchakUser, $account] = $this->verifiedSuchakActor();
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Day 37 Candidate',
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
            'source_owner' => $sourceOwner,
            'source_type' => $sourceOwner === SuchakPaymentContext::SOURCE_PLATFORM
                ? SuchakCustomerContext::SOURCE_TYPE_PLATFORM_REQUEST
                : SuchakCustomerContext::SOURCE_TYPE_MANUAL,
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
                'package_name' => 'Day-37 Family Coordination',
                'package_description' => 'Structured customer package for payment request test.',
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
                'agreement_title' => 'Day-37 agreement terms',
                'agreement_body' => 'Customer confirms the package scope before payment request.',
            ],
        );

        if ($acceptTerms) {
            $agreement = app(SuchakAgreementService::class)->acceptTerms($agreement, $suchakUser);
        }

        $paymentContext = SuchakPaymentContext::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'matrimony_profile_id' => $profile->id,
            'source_owner' => $sourceOwner,
            'payment_collector' => $collector,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $suchakUser->id,
            'resolution_note' => 'Day-37 payment request fixture.',
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
