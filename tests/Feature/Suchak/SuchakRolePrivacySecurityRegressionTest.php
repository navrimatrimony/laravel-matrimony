<?php

namespace Tests\Feature\Suchak;

use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\Payment;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPlanPayment;
use App\Models\SuchakPolicy;
use App\Models\SuchakPlatformPayout;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakCustomerPaymentService;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use App\Services\RevenueAnalyticsService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class SuchakRolePrivacySecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_day_60_role_matrix_protects_admin_suchak_public_and_verified_only_surfaces(): void
    {
        $regularUser = User::factory()->create();
        $verifiedUser = User::factory()->create();
        $pendingUser = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);

        SuchakAccount::factory()->create([
            'user_id' => $verifiedUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);
        SuchakAccount::factory()->create([
            'user_id' => $pendingUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);

        $this->get(route('admin.suchak.payouts.index'))
            ->assertRedirect(route('login'));
        $this->get(route('suchak.dashboard'))
            ->assertRedirect(route('login'));
        $this->get(route('suchak.marketplace.index'))
            ->assertOk()
            ->assertSee('Public Suchak Marketplace', false);

        $this->actingAs($regularUser)
            ->get(route('admin.suchak.payouts.index'))
            ->assertForbidden();
        $this->actingAs($admin)
            ->get(route('admin.suchak.payouts.index'))
            ->assertOk()
            ->assertSee('Suchak Payouts', false);

        $this->actingAs($regularUser)
            ->get(route('suchak.dashboard'))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($verifiedUser)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertSee('Suchak Dashboard', false);

        $this->actingAs($pendingUser)
            ->get(route('suchak.search.index'))
            ->assertForbidden();
    }

    public function test_day_60_payment_matrix_blocks_platform_direct_payment_allows_suchak_payment_and_keeps_receipt_private(): void
    {
        $suchakUser = User::factory()->create();
        $account = $this->verifiedAccount($suchakUser, [
            'suchak_name' => 'Day60 Private Suchak',
            'office_name' => 'Day60 Privacy Desk',
            'mobile_number' => '9876543210',
            'email' => 'day60-suchak@example.test',
        ]);
        $profile = $this->activeProfile([
            'full_name' => 'Day60 Payment Candidate',
        ]);

        $suchakRequest = $this->paymentRequestFixture(
            $account,
            $profile,
            $suchakUser,
            SuchakPaymentContext::SOURCE_SUCHAK,
            SuchakPaymentContext::COLLECTOR_SUCHAK,
            'day60-suchak-direct-request',
        );
        $platformRequest = $this->paymentRequestFixture(
            $account,
            $profile,
            $suchakUser,
            SuchakPaymentContext::SOURCE_PLATFORM,
            SuchakPaymentContext::COLLECTOR_PLATFORM,
            'day60-platform-owned-request',
        );

        try {
            app(SuchakCustomerPaymentService::class)->recordManualPayment(
                $platformRequest,
                $suchakUser,
                [
                    'payment_mode' => SuchakCustomerPayment::MODE_CASH,
                    'amount_received' => '1000',
                    'collection_note' => 'Attempted direct collection for platform-owned customer.',
                ],
            );
            $this->fail('Platform-owned customer payment should not allow direct Suchak collection.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(SuchakPaymentContext::PLATFORM_DIRECT_PAYMENT_BLOCK_MESSAGE, $exception->getMessage());
        }

        $result = app(SuchakCustomerPaymentService::class)->recordManualPayment(
            $suchakRequest,
            $suchakUser,
            [
                'payment_mode' => SuchakCustomerPayment::MODE_UPI,
                'amount_received' => '5000',
                'payment_reference' => 'DAY60-UPI-PRIVATE-REF',
                'proof_note' => 'Bank app confirmation without private contact.',
            ],
        );

        $payment = $result['customer_payment'];
        $receipt = $result['receipt'];

        $this->assertSame(SuchakCustomerPayment::STATUS_PARTIALLY_PAID, $payment->payment_status);
        $this->assertNotNull($receipt);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, SuchakPlanPayment::query()->count());
        $this->assertSame([], app(RevenueAnalyticsService::class)->getDailyRevenue(now()->subDay(), now()->addDay()));

        $this->get(route('suchak.receipts.verify', ['code' => $receipt->verification_code]))
            ->assertOk()
            ->assertSee($receipt->document_number, false)
            ->assertSee('Receipt verification QR', false)
            ->assertSee('INR 5000.00', false)
            ->assertDontSee('DAY60-UPI-PRIVATE-REF', false)
            ->assertDontSee('9876543210', false)
            ->assertDontSee('day60-suchak@example.test', false)
            ->assertDontSee($suchakRequest->request_token_hash, false);
    }

    public function test_day_60_payout_admin_only_and_marketplace_privacy_regressions(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $nonAdmin = User::factory()->create();
        $suchakUser = User::factory()->create();
        $account = $this->verifiedAccount($suchakUser, [
            'suchak_name' => 'Day60 Marketplace Suchak',
            'office_name' => 'Day60 Public Desk',
            'mobile_number' => '9876543210',
            'email' => 'day60-marketplace@example.test',
        ]);
        $profile = $this->activeProfile([
            'full_name' => 'Day60 Private Marketplace Candidate',
        ]);

        $context = $this->customerContext($account, $profile, $suchakUser, SuchakPaymentContext::SOURCE_PLATFORM);
        $paymentContext = $this->paymentContext(
            $account,
            $profile,
            $context,
            $suchakUser,
            SuchakPaymentContext::SOURCE_PLATFORM,
            SuchakPaymentContext::COLLECTOR_PLATFORM,
        );
        $payout = SuchakPlatformPayout::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $context->id,
            'payment_context_id' => $paymentContext->id,
            'matrimony_profile_id' => $profile->id,
            'platform_event_type' => SuchakPlatformPayout::EVENT_PLATFORM_CUSTOMER_PAYMENT,
            'platform_event_key' => 'day60-platform-payout',
            'payout_reason' => SuchakPlatformPayout::REASON_PLATFORM_CUSTOMER_PAYMENT_REWARD,
            'qualification_source' => SuchakPlatformPayout::SOURCE_PLATFORM_CONFIRMED_EVENT,
            'payout_status' => SuchakPlatformPayout::STATUS_QUALIFIED,
            'amount' => '2500.00',
            'deduction_amount' => '0.00',
            'reversal_amount' => '0.00',
            'net_amount' => '2500.00',
            'currency' => 'INR',
            'liability_recognized_at' => now(),
            'qualified_by_user_id' => $admin->id,
            'qualification_note' => 'Day-60 payout admin-only route fixture.',
        ]);

        $this->actingAs($nonAdmin)
            ->post(route('admin.suchak.payouts.approve', $payout), [
                'deduction_amount' => '0',
                'status_note' => 'Non-admin must not approve payout.',
            ])
            ->assertForbidden();
        $this->assertSame(SuchakPlatformPayout::STATUS_QUALIFIED, $payout->fresh()->payout_status);

        $this->publicMarketplaceFixture($account, $profile);

        $this->get(route('suchak.marketplace.index'))
            ->assertOk()
            ->assertSee($account->suchak_name, false)
            ->assertDontSee('9876543210', false)
            ->assertDontSee('day60-marketplace@example.test', false)
            ->assertDontSee($profile->full_name, false)
            ->assertDontSee('success rate', false)
            ->assertDontSee('guaranteed match', false);

        $viewer = User::factory()->create();
        $this->activeProfile([
            'user_id' => $viewer->id,
            'full_name' => 'Day60 Marketplace Viewer',
        ]);

        $this->actingAs($viewer)
            ->get(route('suchak.marketplace.show', $account))
            ->assertOk()
            ->assertSee('Masked candidate profile', false)
            ->assertSee('Request through platform', false)
            ->assertDontSee('9876543210', false)
            ->assertDontSee('day60-marketplace@example.test', false)
            ->assertDontSee($profile->full_name, false)
            ->assertDontSee('DAY60-UPI-PRIVATE-REF', false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function verifiedAccount(User $user, array $attributes = []): SuchakAccount
    {
        return SuchakAccount::factory()->create(array_merge([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ], $attributes));
    }

    private function paymentRequestFixture(
        SuchakAccount $account,
        MatrimonyProfile $profile,
        User $suchakUser,
        string $sourceOwner,
        string $collector,
        string $tokenKey,
    ): SuchakPaymentRequest {
        $customerContext = $this->customerContext($account, $profile, $suchakUser, $sourceOwner);
        $paymentContext = $this->paymentContext($account, $profile, $customerContext, $suchakUser, $sourceOwner, $collector);
        [$package, $agreement] = $this->packageAndAgreement($account, $customerContext, $suchakUser);

        return SuchakPaymentRequest::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'service_package_id' => $package->id,
            'customer_agreement_id' => $agreement->id,
            'payment_context_id' => $paymentContext->id,
            'requested_by_user_id' => $suchakUser->id,
            'request_token_hash' => hash('sha256', $tokenKey),
            'payment_status' => SuchakPaymentRequest::STATUS_SENT,
            'payment_detail_visibility_policy' => SuchakPaymentRequest::VISIBILITY_TERMS_SATISFIED_ONLY,
            'request_title' => 'Day-60 secure request',
            'request_note' => 'Review agreed terms before payment.',
            'amount_due' => '15000.00',
            'currency' => 'INR',
            'collector_disclosure' => 'Payment collector: Suchak. Structured payment request only.',
            'sent_at' => now(),
            'expires_at' => now()->addWeek(),
        ]);
    }

    private function customerContext(
        SuchakAccount $account,
        MatrimonyProfile $profile,
        User $suchakUser,
        string $sourceOwner,
    ): SuchakCustomerContext {
        return SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'payer_name' => 'Day-60 customer family',
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
    }

    private function paymentContext(
        SuchakAccount $account,
        MatrimonyProfile $profile,
        SuchakCustomerContext $customerContext,
        User $suchakUser,
        string $sourceOwner,
        string $collector,
    ): SuchakPaymentContext {
        return SuchakPaymentContext::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'matrimony_profile_id' => $profile->id,
            'source_owner' => $sourceOwner,
            'payment_collector' => $collector,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $suchakUser->id,
            'resolution_note' => 'Day-60 role/privacy regression fixture.',
        ]);
    }

    /**
     * @return array{0: SuchakServicePackage, 1: SuchakCustomerAgreement}
     */
    private function packageAndAgreement(SuchakAccount $account, SuchakCustomerContext $customerContext, User $suchakUser): array
    {
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE],
            [
                'policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Auto publish packages for Day-60 role/privacy regression fixture.',
                'is_active' => true,
            ],
        );

        $package = app(SuchakPackageCatalogService::class)->createCustomPackage(
            $account,
            $suchakUser,
            [
                'package_name' => 'Day-60 Family Coordination',
                'package_description' => 'Structured family coordination package.',
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
                'agreement_title' => 'Day-60 agreement',
                'agreement_body' => 'Customer accepts structured Suchak service terms.',
            ],
        );
        $agreement = app(SuchakAgreementService::class)->acceptTerms($agreement, $suchakUser);

        return [
            $package->fresh(['suchakAccount.user', 'customerContext', 'stages', 'deliverables.servicePackageStage']),
            $agreement->fresh(['suchakAccount', 'customerContext', 'servicePackage', 'stages', 'deliverables']),
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

    private function publicMarketplaceFixture(SuchakAccount $account, MatrimonyProfile $profile): void
    {
        SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'package_name' => 'Day-60 Public Coordination',
            'package_description' => 'Public factual package without success claims.',
            'price_amount' => '12000',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $account->user_id,
            'published_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeProfile(array $attributes = []): MatrimonyProfile
    {
        $city = City::query()->where('name', 'Pune City')->firstOrFail();
        $profile = MatrimonyProfile::factory()->create(array_merge([
            'date_of_birth' => now()->subYears(29)->toDateString(),
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
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ])->save();

        return $profile->fresh();
    }
}
