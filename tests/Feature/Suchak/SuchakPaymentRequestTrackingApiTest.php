<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakActivityLog;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPayment;
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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuchakPaymentRequestTrackingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracking_feed_lists_scoped_requests_with_summary_search_and_filter(): void
    {
        [$suchakUser, $account] = $this->verifiedSuchakActor();

        // Request A: opened by the customer (SENT -> OPENED), 15000 due.
        $requestA = $this->buildPaymentRequest($suchakUser, $account, 'Aarav Kulkarni', '9876500011', '15000', open: true);
        // Request B: still only sent, 5000 due.
        $requestB = $this->buildPaymentRequest($suchakUser, $account, 'Priya Deshmukh', '9876500022', '5000', open: false);

        // Another account's request must never leak into this feed.
        [$otherUser, $otherAccount] = $this->verifiedSuchakActor();
        $this->buildPaymentRequest($otherUser, $otherAccount, 'Someone Else', '9000000000', '9999', open: false);

        Sanctum::actingAs($suchakUser);

        $response = $this->getJson('/api/v1/suchak/payment-requests')->assertOk();

        $response->assertJsonPath('data.account_id', $account->id);
        $items = collect($response->json('data.payment_requests'));
        $this->assertCount(2, $items, 'Feed must be strictly scoped to the authenticated account.');

        $itemA = $items->firstWhere('id', $requestA->id);
        $this->assertSame('Aarav Kulkarni', $itemA['customer_name']);
        $this->assertSame('9876500011', $itemA['customer_mobile']);
        $this->assertSame('15000.00', $itemA['amount']);
        $this->assertSame(SuchakPaymentRequest::STATUS_OPENED, $itemA['status']);
        $this->assertTrue($itemA['opened']);
        $this->assertFalse($itemA['paid']);
        $this->assertNotNull($itemA['plan_name']);

        $itemB = $items->firstWhere('id', $requestB->id);
        $this->assertFalse($itemB['opened']);

        // Summary = outstanding totals for the account (both still unpaid).
        $response->assertJsonPath('data.summary.pending_count', 2);
        $response->assertJsonPath('data.summary.total_amount_due', '20000.00');
        $response->assertJsonPath('data.pagination.total', 2);

        // Search by name.
        $byName = $this->getJson('/api/v1/suchak/payment-requests?search=Priya')->assertOk();
        $this->assertCount(1, $byName->json('data.payment_requests'));
        $this->assertSame($requestB->id, $byName->json('data.payment_requests.0.id'));

        // Search by mobile (falls back to the linked account mobile).
        $byMobile = $this->getJson('/api/v1/suchak/payment-requests?search=9876500011')->assertOk();
        $this->assertCount(1, $byMobile->json('data.payment_requests'));
        $this->assertSame($requestA->id, $byMobile->json('data.payment_requests.0.id'));

        // filter=pending returns both; filter=paid returns none yet.
        $this->assertCount(2, $this->getJson('/api/v1/suchak/payment-requests?filter=pending')->json('data.payment_requests'));
        $this->assertCount(0, $this->getJson('/api/v1/suchak/payment-requests?filter=paid')->json('data.payment_requests'));
    }

    public function test_tracking_feed_dedupes_repeated_requests_to_one_row_per_customer(): void
    {
        [$suchakUser, $account] = $this->verifiedSuchakActor();

        // One customer reminded repeatedly: THREE payment requests, same context.
        $first = $this->buildPaymentRequest($suchakUser, $account, 'Sana Shaikh', '9876511111', '4000', open: false);
        $this->sendAnotherRequestForSameCustomer($suchakUser, $first, '4000');
        // Latest reminder carries a different amount so we can prove the row/summary
        // use the LATEST request's amount, never a running total.
        $latest = $this->sendAnotherRequestForSameCustomer($suchakUser, $first, '7000');

        // A second, distinct customer with a single request.
        $this->buildPaymentRequest($suchakUser, $account, 'Vivek Rao', '9876522222', '3000', open: false);

        Sanctum::actingAs($suchakUser);

        // ---- Deduped list: one row per customer, represented by the latest request.
        $response = $this->getJson('/api/v1/suchak/payment-requests')->assertOk();
        $items = collect($response->json('data.payment_requests'));

        $this->assertSame(4, SuchakPaymentRequest::query()->count(), 'All four requests still persist.');
        $this->assertCount(2, $items, 'Each customer appears once, however many reminders were sent.');
        $response->assertJsonPath('data.pagination.total', 2);

        $sana = $items->firstWhere('customer_name', 'Sana Shaikh');
        $this->assertNotNull($sana, 'The reminded customer must still be present, once.');
        $this->assertSame($latest->id, $sana['id'], 'The row is the customer\'s latest request (highest id).');
        $this->assertSame('7000.00', $sana['amount'], 'The row carries the latest amount, not a sum of reminders.');
        $this->assertSame('9876511111', $sana['customer_mobile']);

        // ---- Summary counts the reminded customer ONCE, amount ONCE (latest): 7000 + 3000.
        $response->assertJsonPath('data.summary.pending_count', 2);
        $response->assertJsonPath('data.summary.total_amount_due', '10000.00');

        // ---- Latest request paid => the whole customer is paid. The older, still-sent
        //      reminders must NOT keep the customer in the pending set (the over-count bug).
        $this->postJson("/api/v1/suchak/payment-requests/{$latest->id}/mark-paid", [
            'amount' => 7000,
            'payment_mode' => SuchakCustomerPayment::MODE_CASH,
            'note' => 'Cash collected at the office counter.',
        ])->assertOk();

        $afterPaid = $this->getJson('/api/v1/suchak/payment-requests')->assertOk();
        $this->assertCount(2, $afterPaid->json('data.payment_requests'), 'Still one row per customer.');
        $afterPaid->assertJsonPath('data.summary.pending_count', 1);
        $afterPaid->assertJsonPath('data.summary.total_amount_due', '3000.00');

        $paidOnly = $this->getJson('/api/v1/suchak/payment-requests?filter=paid')->assertOk();
        $this->assertCount(1, $paidOnly->json('data.payment_requests'));
        $this->assertSame('Sana Shaikh', $paidOnly->json('data.payment_requests.0.customer_name'));

        $pendingOnly = $this->getJson('/api/v1/suchak/payment-requests?filter=pending')->assertOk();
        $this->assertCount(1, $pendingOnly->json('data.payment_requests'));
        $this->assertSame('Vivek Rao', $pendingOnly->json('data.payment_requests.0.customer_name'));
    }

    public function test_mark_paid_with_note_then_reverse_paid_with_reason_reflects_and_audits(): void
    {
        [$suchakUser, $account] = $this->verifiedSuchakActor();
        $request = $this->buildPaymentRequest($suchakUser, $account, 'Rohan Patil', '9876500033', '15000', open: true);

        Sanctum::actingAs($suchakUser);

        // MARK PAID with an optional note (cash, full amount).
        $markPaid = $this->postJson("/api/v1/suchak/payment-requests/{$request->id}/mark-paid", [
            'amount' => 15000,
            'payment_mode' => SuchakCustomerPayment::MODE_CASH,
            'note' => 'Cash collected at the office counter.',
        ])->assertOk();

        $this->assertSame(SuchakPaymentRequest::STATUS_PAID, $request->fresh()->payment_status);

        // The note-when-marking is persisted on the recorded payment.
        $payment = SuchakCustomerPayment::query()
            ->where('payment_request_id', $request->id)
            ->firstOrFail();
        $this->assertSame('Cash collected at the office counter.', $payment->collection_note);
        $this->assertSame(SuchakCustomerPayment::STATUS_PAID, $payment->payment_status);

        // The feed now reflects paid=true.
        $paidFeed = $this->getJson('/api/v1/suchak/payment-requests?filter=paid')->assertOk();
        $this->assertCount(1, $paidFeed->json('data.payment_requests'));
        $this->assertTrue($paidFeed->json('data.payment_requests.0.paid'));
        $paidFeed->assertJsonPath('data.summary.pending_count', 0);
        $paidFeed->assertJsonPath('data.summary.total_amount_due', '0.00');

        // REVERSE PAID requires a non-empty reason.
        $this->postJson("/api/v1/suchak/payment-requests/{$request->id}/reverse-paid", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        // With a reason it reverses the paid mark back to an active state.
        $this->postJson("/api/v1/suchak/payment-requests/{$request->id}/reverse-paid", [
            'reason' => 'Cheque bounced; payment did not clear.',
        ])->assertOk()->assertJsonPath('data.paid', false);

        $this->assertSame(SuchakPaymentRequest::STATUS_OPENED, $request->fresh()->payment_status);

        // Reason is captured on the immutable event trail and the audit log.
        $this->assertDatabaseHas('suchak_payment_request_events', [
            'payment_request_id' => $request->id,
            'event_type' => SuchakPaymentRequestEvent::EVENT_PAID_REVERSED,
            'from_status' => SuchakPaymentRequest::STATUS_PAID,
            'to_status' => SuchakPaymentRequest::STATUS_OPENED,
            'event_note' => 'Cheque bounced; payment did not clear.',
        ]);

        $auditLog = SuchakActivityLog::query()
            ->where('action_type', SuchakActivityLog::ACTION_PAYMENT_REQUEST_PAID_REVERSED)
            ->where('target_id', $request->id)
            ->firstOrFail();
        $this->assertSame('Cheque bounced; payment did not clear.', $auditLog->metadata_json['reason']);

        // Feed reflects the reversal: no longer paid, back in the pending total.
        $reopened = $this->getJson('/api/v1/suchak/payment-requests')->assertOk();
        $this->assertFalse($reopened->json('data.payment_requests.0.paid'));
        $reopened->assertJsonPath('data.summary.pending_count', 1);
        $reopened->assertJsonPath('data.summary.total_amount_due', '15000.00');
    }

    public function test_reverse_paid_is_scoped_to_the_owning_account(): void
    {
        [$ownerUser, $ownerAccount] = $this->verifiedSuchakActor();
        $request = $this->buildPaymentRequest($ownerUser, $ownerAccount, 'Meera Joshi', '9876500044', '5000', open: true);

        [$strangerUser] = $this->verifiedSuchakActor();
        Sanctum::actingAs($strangerUser);

        $this->postJson("/api/v1/suchak/payment-requests/{$request->id}/reverse-paid", [
            'reason' => 'Not my request.',
        ])->assertStatus(404);
    }

    /**
     * Build a full, sendable payment request graph for a candidate and return
     * the SENT (optionally OPENED) SuchakPaymentRequest.
     */
    private function buildPaymentRequest(
        User $suchakUser,
        SuchakAccount $account,
        string $candidateName,
        string $candidateMobile,
        string $amount,
        bool $open,
    ): SuchakPaymentRequest {
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE],
            [
                'policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Auto publish packages for tracking API test.',
                'is_active' => true,
            ],
        );

        $candidateUser = User::factory()->create(['mobile' => $candidateMobile]);
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $candidateUser->id,
            'full_name' => $candidateName,
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
            'source_owner' => SuchakPaymentContext::SOURCE_SUCHAK,
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
                'package_name' => 'Family Coordination Plan',
                'package_description' => 'Structured customer package for tracking test.',
                'price_amount' => $amount,
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
                'agreement_title' => 'Agreement terms',
                'agreement_body' => 'Customer confirms the package scope before payment.',
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
            'resolution_note' => 'Tracking test fixture.',
        ]);

        $service = app(SuchakPaymentRequestService::class);
        $result = $service->createAndSend(
            $package->fresh(['suchakAccount.user', 'customerContext', 'stages', 'deliverables.servicePackageStage']),
            $agreement->fresh(['suchakAccount', 'customerContext', 'servicePackage', 'stages', 'deliverables']),
            $paymentContext->fresh(['suchakAccount', 'customerContext', 'matrimonyProfile']),
            $suchakUser,
        );

        if ($open) {
            $service->openPublicRequest($result['plain_token'], '127.0.0.1', 'tracking-test');
        }

        return $result['payment_request']->fresh();
    }

    /**
     * Send another payment request against the SAME customer (same package,
     * agreement, and payment context as an existing request) — i.e. a repeat
     * reminder to a customer who already has an open request. Returns the newly
     * created, SENT SuchakPaymentRequest.
     */
    private function sendAnotherRequestForSameCustomer(
        User $suchakUser,
        SuchakPaymentRequest $existing,
        string $amount,
    ): SuchakPaymentRequest {
        $existing->loadMissing(['servicePackage', 'customerAgreement', 'paymentContext']);

        $result = app(SuchakPaymentRequestService::class)->createAndSend(
            $existing->servicePackage,
            $existing->customerAgreement,
            $existing->paymentContext,
            $suchakUser,
            ['amount_due' => $amount],
        );

        return $result['payment_request']->fresh();
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
            'registration_completed_at' => now(),
        ]);

        return [$user->fresh(), $account];
    }
}
