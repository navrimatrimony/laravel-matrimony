<?php

namespace Tests\Feature\Suchak;

use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPolicy;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakCustomerPaymentService;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPaymentRequestService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SuchakBrowserMobileQaCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_day_61_persona_matrix_renders_or_gates_primary_surfaces_cleanly(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $regularUser = User::factory()->create();
        $verifiedUser = User::factory()->create();
        $pendingUser = User::factory()->create();
        $suspendedUser = User::factory()->create();

        $verifiedAccount = $this->verifiedAccount($verifiedUser, [
            'suchak_name' => 'Day61 Verified Suchak',
            'office_name' => 'Day61 Browser QA Desk',
        ]);
        $pendingAccount = SuchakAccount::factory()->create([
            'user_id' => $pendingUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);
        $suspendedAccount = SuchakAccount::factory()->create([
            'user_id' => $suspendedUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_SUSPENDED,
            'public_status' => SuchakAccount::PUBLIC_INACTIVE,
            'suspended_at' => now(),
            'suspension_reason' => 'Day-61 QA suspended persona.',
        ]);

        $this->assertMobileReadyMarkup(
            $this->actingAs($admin)->withMobileHeaders()->get(route('admin.suchak.dashboard')),
            ['Suchak Admin Dashboard'],
        );
        $this->assertMobileReadyMarkup(
            $this->actingAs($verifiedUser)->withMobileHeaders()->get(route('suchak.dashboard')),
            ['Suchak Dashboard', 'Account status', 'Approved. You can start Suchak work.'],
        );

        $this->actingAs($regularUser)
            ->withMobileHeaders()
            ->get(route('suchak.dashboard'))
            ->assertRedirect(route('dashboard'));

        $this->assertMobileReadyMarkup(
            $this->actingAs($pendingUser)->withMobileHeaders()->get(route('suchak.dashboard')),
            ['Suchak Dashboard', 'Account status', 'Pending. Admin approval is required before customer entry.'],
        );
        $this->actingAs($pendingUser)
            ->withMobileHeaders()
            ->get(route('suchak.search.index'))
            ->assertForbidden();

        $this->assertMobileReadyMarkup(
            $this->actingAs($suspendedUser)->withMobileHeaders()->get(route('suchak.dashboard')),
            ['Suchak Dashboard', 'Account status', 'Pending. Admin approval is required before customer entry.'],
        );
        $this->actingAs($suspendedUser)
            ->withMobileHeaders()
            ->get(route('suchak.search.index'))
            ->assertForbidden();
    }

    public function test_day_61_verified_suchak_operator_surfaces_cover_expanded_engine_links_on_mobile(): void
    {
        $suchakUser = User::factory()->create();
        $this->verifiedAccount($suchakUser, [
            'suchak_name' => 'Day61 Operator Suchak',
            'office_name' => 'Day61 Operations Desk',
        ]);

        $dashboard = $this->actingAs($suchakUser)->withMobileHeaders()->get(route('suchak.dashboard'));
        $this->assertMobileReadyMarkup($dashboard, [
            'Suchak Dashboard',
            'Suchak Quick Links',
        ]);

        $this->assertMobileReadyMarkup(
            $this->actingAs($suchakUser)->withMobileHeaders()->get(route('suchak.training-academy.index')),
            ['Training Academy'],
        );
        $this->assertMobileReadyMarkup(
            $this->actingAs($suchakUser)->withMobileHeaders()->get(route('suchak.offline-camps.index')),
            ['Offline Camps'],
        );
        $this->assertMobileReadyMarkup(
            $this->actingAs($suchakUser)->withMobileHeaders()->get(route('suchak.export-retention.index')),
            ['Export / Retention Center'],
        );
    }

    public function test_day_61_public_customer_and_receipt_surfaces_render_mobile_without_private_leaks(): void
    {
        $suchakUser = User::factory()->create();
        $account = $this->verifiedAccount($suchakUser, [
            'suchak_name' => 'Day61 Public Suchak',
            'office_name' => 'Day61 Public Desk',
            'mobile_number' => '9876543210',
            'email' => 'day61-suchak@example.test',
        ]);
        $profile = $this->activeProfile(['full_name' => 'Day61 Private Candidate']);
        $this->publicMarketplaceFixture($account, $profile);

        $requestResult = $this->paymentRequestFixture($account, $profile, $suchakUser);
        $paymentRequest = $this->withMobileHeaders()->get($requestResult['public_url']);
        $this->assertMobileReadyMarkup($paymentRequest, ['Suchak Payment Request', 'This page is not a paid receipt']);
        $paymentRequest
            ->assertDontSee('9876543210', false)
            ->assertDontSee('day61-suchak@example.test', false)
            ->assertDontSee('DAY61-PRIVATE-UPI-REF', false);

        $paymentResult = app(SuchakCustomerPaymentService::class)->recordManualPayment(
            $requestResult['payment_request'],
            $suchakUser,
            [
                'payment_mode' => SuchakCustomerPayment::MODE_UPI,
                'amount_received' => '5000',
                'payment_reference' => 'DAY61-PRIVATE-UPI-REF',
                'proof_note' => 'Bank app confirmation without contact details.',
            ],
        );
        $receipt = $paymentResult['receipt'];

        $marketplaceIndex = $this->withMobileHeaders()->get(route('suchak.marketplace.index'));
        $this->assertMobileReadyMarkup($marketplaceIndex, ['Public Suchak Marketplace', $account->suchak_name]);
        $marketplaceIndex
            ->assertDontSee('9876543210', false)
            ->assertDontSee('day61-suchak@example.test', false)
            ->assertDontSee($profile->full_name, false)
            ->assertDontSee('success rate', false)
            ->assertDontSee('guaranteed match', false);

        $viewer = User::factory()->create();
        $this->activeProfile([
            'user_id' => $viewer->id,
            'full_name' => 'Day61 Marketplace Viewer',
        ]);

        $marketplaceShow = $this->actingAs($viewer)->withMobileHeaders()->get(route('suchak.marketplace.show', $account));
        $this->assertMobileReadyMarkup($marketplaceShow, ['Masked candidate profile', 'Request through platform']);
        $marketplaceShow
            ->assertDontSee('9876543210', false)
            ->assertDontSee('day61-suchak@example.test', false)
            ->assertDontSee($profile->full_name, false)
            ->assertDontSee('DAY61-PRIVATE-UPI-REF', false);

        $customerPortal = $this->withMobileHeaders()->get($requestResult['portal_url']);
        $this->assertMobileReadyMarkup($customerPortal, ['Suchak Customer Portal', 'Package And Terms']);
        $customerPortal
            ->assertDontSee('9876543210', false)
            ->assertDontSee('day61-suchak@example.test', false)
            ->assertDontSee($profile->full_name, false);

        $receiptResponse = $this->withMobileHeaders()->get(route('suchak.receipts.verify', ['code' => $receipt->verification_code]));
        $this->assertMobileReadyMarkup($receiptResponse, ['Receipt verification QR', 'Verified receipt']);
        $receiptResponse
            ->assertSee($receipt->document_number, false)
            ->assertDontSee('DAY61-PRIVATE-UPI-REF', false)
            ->assertDontSee('9876543210', false)
            ->assertDontSee('day61-suchak@example.test', false);
    }

    private function assertMobileReadyMarkup(TestResponse $response, array $requiredText): void
    {
        $response->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('name="viewport"', $html);
        $this->assertStringNotContainsString('Undefined variable', $html);
        $this->assertStringNotContainsString('Stack trace', $html);
        $this->assertStringNotContainsString('Whoops', $html);

        foreach ($requiredText as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->assertMatchesRegularExpression('/\\b(sm|md|lg):/', $html);
        $this->assertTrue(
            str_contains($html, 'overflow-x-auto')
            || str_contains($html, 'flex-col')
            || str_contains($html, 'grid gap'),
            'Day-61 mobile QA requires responsive wrapping or overflow-safe containers.',
        );
    }

    private function withMobileHeaders(): static
    {
        return $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Sec-CH-Viewport-Width' => '390',
        ]);
    }

    private function verifiedAccount(User $user, array $attributes = []): SuchakAccount
    {
        return SuchakAccount::factory()->create(array_merge([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ], $attributes));
    }

    private function paymentRequestFixture(SuchakAccount $account, MatrimonyProfile $profile, User $suchakUser): array
    {
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE],
            [
                'policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Auto publish packages for Day-61 browser/mobile QA fixture.',
                'is_active' => true,
            ],
        );

        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'payer_name' => 'Day-61 customer family',
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
                'package_name' => 'Day-61 Family Coordination',
                'package_description' => 'Structured package for browser/mobile QA.',
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
                'agreement_title' => 'Day-61 agreement terms',
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
            'resolution_note' => 'Day-61 browser/mobile QA payment context.',
        ]);

        return app(SuchakPaymentRequestService::class)->createAndSend(
            $package->fresh(['suchakAccount.user', 'customerContext', 'stages', 'deliverables.servicePackageStage']),
            $agreement->fresh(['suchakAccount', 'customerContext', 'servicePackage', 'stages', 'deliverables']),
            $paymentContext->fresh(['suchakAccount', 'customerContext', 'matrimonyProfile']),
            $suchakUser,
            [
                'request_title' => 'Day-61 secure payment request',
                'request_note' => 'Review agreed terms before payment.',
            ],
        );
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
            'package_name' => 'Day-61 Public Coordination',
            'package_description' => 'Public factual package without claims.',
            'price_amount' => '12000',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $account->user_id,
            'published_at' => now(),
        ]);
    }

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
