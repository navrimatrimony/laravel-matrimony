<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerFamilyMember;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakCustomerPaymentDocument;
use App\Models\SuchakCustomerPortalEvent;
use App\Models\SuchakCustomerPortalLink;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPolicy;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakCustomerPaymentService;
use App\Modules\Suchak\Services\SuchakCustomerPortalService;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPaymentRequestService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakCustomerPortalFamilyFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_40_portal_and_family_tables_are_structured_no_delete_records(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_customer_family_members'));
        $this->assertTrue(Schema::hasTable('suchak_customer_portal_links'));
        $this->assertTrue(Schema::hasTable('suchak_customer_portal_events'));

        foreach ([
            'suchak_account_id',
            'customer_context_id',
            'linked_user_id',
            'linked_matrimony_profile_id',
            'member_role',
            'payer_role',
            'relationship_to_candidate',
            'display_name',
            'access_status',
            'added_by_user_id',
            'revoked_by_user_id',
            'revoked_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_customer_family_members', $column), $column);
        }

        foreach ([
            'suchak_account_id',
            'customer_context_id',
            'payment_request_id',
            'customer_family_member_id',
            'issued_by_user_id',
            'token_hash',
            'portal_status',
            'recipient_role',
            'expires_at',
            'opened_at',
            'claimed_at',
            'revoked_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_customer_portal_links', $column), $column);
        }

        foreach ([
            'customer_portal_link_id',
            'suchak_account_id',
            'customer_context_id',
            'event_type',
            'actor_type',
            'from_status',
            'to_status',
            'occurred_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_customer_portal_events', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_customer_family_members', 'contact_number'));
        $this->assertFalse(Schema::hasColumn('suchak_customer_family_members', 'email'));
        $this->assertFalse(Schema::hasColumn('suchak_customer_portal_links', 'plain_token'));
        $this->assertFalse(Schema::hasColumn('suchak_customer_portal_links', 'deleted_at'));
    }

    public function test_payment_request_auto_issues_customer_portal_and_public_page_protects_private_context(): void
    {
        [$suchakUser, $account, $profile, $paymentRequest, $portalToken, $portalUrl] = $this->paymentRequestFixture();

        $member = app(SuchakCustomerPortalService::class)->addFamilyMember(
            $paymentRequest->customerContext,
            $suchakUser,
            [
                'linked_matrimony_profile_id' => $profile->id,
                'member_role' => SuchakCustomerFamilyMember::ROLE_PAYER,
                'payer_role' => SuchakCustomerFamilyMember::PAYER_SHARED,
                'relationship_to_candidate' => 'father',
                'display_name' => 'Candidate Father',
            ],
            '127.0.0.1',
            'Day-40 family member test',
        );

        $paymentResult = app(SuchakCustomerPaymentService::class)->recordManualPayment(
            $paymentRequest->fresh(),
            $suchakUser,
            [
                'payment_mode' => SuchakCustomerPayment::MODE_UPI,
                'amount_received' => '5000',
                'payment_reference' => 'UPI-PORTAL-PRIVATE-001',
                'proof_note' => 'Private proof note for Suchak only.',
            ],
        );

        $receipt = $paymentResult['receipt'];
        $invoice = $paymentResult['invoice'];

        $portalLink = SuchakCustomerPortalLink::query()->firstOrFail();
        $this->assertSame($account->id, $portalLink->suchak_account_id);
        $this->assertSame($paymentRequest->id, $portalLink->payment_request_id);
        $this->assertSame(SuchakCustomerPortalLink::STATUS_ACTIVE, $portalLink->portal_status);
        $this->assertSame(64, strlen((string) $portalLink->token_hash));
        $this->assertDatabaseMissing('suchak_customer_portal_links', ['token_hash' => $portalToken]);

        $this->get($portalUrl)
            ->assertOk()
            ->assertSee('Suchak Customer Portal')
            ->assertSee('Day-40 Family Coordination')
            ->assertSee('Accepted')
            ->assertSee('Partially Paid')
            ->assertSee($invoice->document_number)
            ->assertSee($receipt->document_number)
            ->assertSee('Relationship: father')
            ->assertSee('Payer role: Shared')
            ->assertDontSee('Candidate Father')
            ->assertDontSee($profile->full_name)
            ->assertDontSee('UPI-PORTAL-PRIVATE-001')
            ->assertDontSee('Private proof note');

        $this->assertDatabaseHas('suchak_customer_portal_events', [
            'customer_portal_link_id' => $portalLink->id,
            'event_type' => SuchakCustomerPortalEvent::EVENT_LINK_OPENED,
            'actor_type' => SuchakActivityLog::ACTOR_USER,
        ]);
        $this->assertSame(SuchakCustomerFamilyMember::STATUS_ACTIVE, $member->fresh()->access_status);
    }

    public function test_customer_can_claim_and_revoke_portal_link_without_profile_mutation(): void
    {
        [, , $profile, $paymentRequest, $portalToken, $portalUrl] = $this->paymentRequestFixture();
        $beforeUpdatedAt = $profile->updated_at?->toDateTimeString();

        $this->post(route('suchak.customer-portal.claim', ['token' => $portalToken]), [
            'claimed_name' => 'Portal Claimant',
            'claimed_relationship_to_candidate' => 'father',
        ])->assertRedirect($portalUrl);

        $portalLink = SuchakCustomerPortalLink::query()->firstOrFail();
        $this->assertSame(SuchakCustomerPortalLink::STATUS_CLAIMED, $portalLink->portal_status);
        $this->assertSame('Portal Claimant', $portalLink->claimed_name);
        $this->assertSame('father', $portalLink->claimed_relationship_to_candidate);
        $this->assertSame($beforeUpdatedAt, $profile->fresh()->updated_at?->toDateTimeString());

        $this->get($portalUrl)
            ->assertOk()
            ->assertSee('Claimed')
            ->assertDontSee('Portal Claimant')
            ->assertDontSee($profile->full_name);

        $this->post(route('suchak.customer-portal.revoke', ['token' => $portalToken]), [
            'revoke_reason' => 'Customer no longer wants this portal link active.',
        ])->assertRedirect(route('suchak.home'));

        $portalLink = $portalLink->fresh();
        $this->assertSame(SuchakCustomerPortalLink::STATUS_REVOKED, $portalLink->portal_status);
        $this->assertNotNull($portalLink->revoked_at);
        $this->assertNull($portalLink->revoked_by_user_id);
        $this->assertSame($beforeUpdatedAt, $profile->fresh()->updated_at?->toDateTimeString());

        $this->get($portalUrl)->assertGone();

        $this->assertDatabaseHas('suchak_customer_portal_events', [
            'customer_portal_link_id' => $portalLink->id,
            'event_type' => SuchakCustomerPortalEvent::EVENT_LINK_CLAIMED,
            'actor_type' => SuchakActivityLog::ACTOR_USER,
        ]);
        $this->assertDatabaseHas('suchak_customer_portal_events', [
            'customer_portal_link_id' => $portalLink->id,
            'event_type' => SuchakCustomerPortalEvent::EVENT_LINK_REVOKED,
            'actor_type' => SuchakActivityLog::ACTOR_USER,
        ]);
    }

    public function test_portal_family_records_block_private_contact_text_and_no_delete_mutation(): void
    {
        [$suchakUser, , , $paymentRequest] = $this->paymentRequestFixture();
        $service = app(SuchakCustomerPortalService::class);

        try {
            $service->addFamilyMember($paymentRequest->customerContext, $suchakUser, [
                'member_role' => SuchakCustomerFamilyMember::ROLE_PAYER,
                'payer_role' => SuchakCustomerFamilyMember::PAYER_PRIMARY,
                'relationship_to_candidate' => 'father',
                'display_name' => 'Call 9876543210',
            ]);

            $this->fail('Private contact text should not be stored in customer portal family records.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak customer portal records must not store private contact details.', $exception->getMessage());
        }

        $member = $service->addFamilyMember($paymentRequest->customerContext, $suchakUser, [
            'member_role' => SuchakCustomerFamilyMember::ROLE_PAYER,
            'payer_role' => SuchakCustomerFamilyMember::PAYER_PRIMARY,
            'relationship_to_candidate' => 'father',
            'display_name' => 'Family payer',
        ]);
        $portalLink = SuchakCustomerPortalLink::query()->firstOrFail();
        $event = $portalLink->events()->firstOrFail();

        try {
            $member->delete();

            $this->fail('Suchak customer family members should not be deletable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak customer family members cannot be deleted.', $exception->getMessage());
        }

        try {
            $portalLink->delete();

            $this->fail('Suchak customer portal links should not be deletable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak customer portal links cannot be deleted.', $exception->getMessage());
        }

        try {
            $event->update(['event_note' => 'changed']);

            $this->fail('Suchak customer portal events should be immutable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak customer portal events are immutable and cannot be modified.', $exception->getMessage());
        }

        $revoked = $service->revokeFamilyMember($member, $suchakUser, 'Family payer access revoked.');
        $this->assertSame(SuchakCustomerFamilyMember::STATUS_REVOKED, $revoked->access_status);
        $this->assertNotNull($revoked->revoked_at);
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: MatrimonyProfile, 3: SuchakPaymentRequest, 4: string, 5: string}
     */
    private function paymentRequestFixture(): array
    {
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE],
            [
                'policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Auto publish packages for Day-40 customer portal fixture.',
                'is_active' => true,
            ],
        );

        [$suchakUser, $account] = $this->verifiedSuchakActor();
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Day 40 Candidate',
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
                'package_name' => 'Day-40 Family Coordination',
                'package_description' => 'Structured customer package for mini portal test.',
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
                'agreement_title' => 'Day-40 agreement terms',
                'agreement_body' => 'Customer confirms the package scope before customer portal access.',
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
            'resolution_note' => 'Day-40 customer portal fixture.',
        ]);

        $result = app(SuchakPaymentRequestService::class)->createAndSend(
            $package->fresh(['suchakAccount.user', 'customerContext', 'stages', 'deliverables.servicePackageStage']),
            $agreement->fresh(['suchakAccount', 'customerContext', 'servicePackage', 'stages', 'deliverables']),
            $paymentContext->fresh(['suchakAccount', 'customerContext', 'matrimonyProfile']),
            $suchakUser,
        );

        /** @var SuchakPaymentRequest $paymentRequest */
        $paymentRequest = $result['payment_request'];

        return [
            $suchakUser,
            $account,
            $profile,
            $paymentRequest->fresh(['customerContext', 'customerPortalLinks']),
            $result['plain_portal_token'],
            $result['portal_url'],
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
