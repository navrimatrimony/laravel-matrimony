<?php

namespace Tests\Feature\Suchak;

use App\Models\City;
use App\Models\District;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakCustomerPaymentDocument;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPolicy;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\Taluka;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakCustomerPaymentService;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPaymentRequestService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Modules\Suchak\Services\SuchakWhiteLabelSharingKitService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Suchak\Support\CreatesSuchakAdmin;
use Tests\TestCase;

class SuchakWhiteLabelSharingKitTest extends TestCase
{
    use CreatesSuchakAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableFullSuchakUiSurfaces();
        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_day_52_white_label_assets_are_suchak_scoped_and_contact_safe(): void
    {
        [$suchakUser, $account, $paymentRequest] = $this->paymentRequestFixture();
        $representation = $this->activeRepresentation($account);

        $result = app(SuchakCustomerPaymentService::class)->recordManualPayment(
            $paymentRequest,
            $suchakUser,
            [
                'payment_mode' => SuchakCustomerPayment::MODE_UPI,
                'amount_received' => '5000',
                'payment_reference' => 'UPI-REF-DAY52-001',
                'proof_note' => 'Customer shared bank app confirmation.',
            ],
        );
        $receipt = $result['receipt'];

        $kit = app(SuchakWhiteLabelSharingKitService::class)->assetsFor($account->fresh());
        $assetTypes = collect($kit['assets'])->pluck('type')->all();

        $this->assertTrue($kit['is_publicly_routable']);
        $this->assertSame($account->id, $kit['suchak_account_id']);
        $this->assertContains('whatsapp_profile_card', $assetTypes);
        $this->assertContains('qr_poster', $assetTypes);
        $this->assertContains('office_poster', $assetTypes);
        $this->assertContains('visiting_card_qr', $assetTypes);
        $this->assertContains('receipt_verification_qr', $assetTypes);

        foreach ($kit['assets'] as $asset) {
            $this->assertSame($account->id, $asset['suchak_account_id']);
            $this->assertStringStartsWith('data:image/svg+xml;base64,', $asset['qr_data_uri']);
            $this->assertStringContainsString(SuchakWhiteLabelSharingKitService::POWERED_BY_FOOTER, $asset['share_text']);
        }

        $encoded = json_encode($kit, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('masked-', $encoded);
        $this->assertStringContainsString(route('suchak.marketplace.show', $account, true), $encoded);
        $this->assertStringContainsString(route('suchak.receipts.verify', ['code' => $receipt->verification_code], true), $encoded);
        $this->assertStringNotContainsString('Day52 Sensitive Candidate', $encoded);
        $this->assertStringNotContainsString('9998887777', $encoded);
        $this->assertStringNotContainsString('8887776666', $encoded);
        $this->assertStringNotContainsString('day52-suchak@example.test', $encoded);
        $this->assertStringNotContainsString('UPI-REF-DAY52-001', $encoded);

        $this->actingAs($suchakUser)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertSee('White-label Sharing Kit', false)
            ->assertSee('WhatsApp profile card', false)
            ->assertSee('QR poster', false)
            ->assertSee('Office poster', false)
            ->assertSee('Visiting card QR', false)
            ->assertSee('Receipt verification QR', false)
            ->assertSee('data:image/svg+xml;base64,', false)
            ->assertDontSee('Day52 Sensitive Candidate', false)
            ->assertDontSee('9998887777', false)
            ->assertDontSee('8887776666', false)
            ->assertDontSee('day52-suchak@example.test', false)
            ->assertDontSee('UPI-REF-DAY52-001', false);

        $this->get(route('suchak.receipts.verify', ['code' => $receipt->verification_code]))
            ->assertOk()
            ->assertSee('Receipt verification QR', false)
            ->assertSee('Verified receipt', false)
            ->assertSee(SuchakWhiteLabelSharingKitService::POWERED_BY_FOOTER, false)
            ->assertSee('data:image/svg+xml;base64,', false)
            ->assertDontSee('UPI-REF-DAY52-001', false)
            ->assertDontSee('Day52 Sensitive Candidate', false)
            ->assertDontSee('9998887777', false)
            ->assertDontSee('day52-suchak@example.test', false);

        $this->assertSame($representation->id, $kit['assets'][0]['source_id']);
        $this->assertInstanceOf(SuchakCustomerPaymentDocument::class, $receipt);
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: SuchakPaymentRequest}
     */
    private function paymentRequestFixture(): array
    {
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE],
            [
                'policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Auto publish packages for Day-52 sharing kit fixture.',
                'is_active' => true,
            ],
        );

        [$suchakUser, $account] = $this->verifiedSuchakActor();
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Day52 Customer Candidate',
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
                'package_name' => 'Day-52 Family Coordination',
                'package_description' => 'Structured customer package for sharing kit test.',
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
                'agreement_title' => 'Day-52 agreement terms',
                'agreement_body' => 'Customer confirms the package scope before manual payment.',
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
            'resolution_note' => 'Day-52 sharing kit fixture.',
        ]);

        $requestResult = app(SuchakPaymentRequestService::class)->createAndSend(
            $package->fresh(['suchakAccount.user', 'customerContext', 'stages', 'deliverables.servicePackageStage']),
            $agreement->fresh(['suchakAccount', 'customerContext', 'servicePackage', 'stages', 'deliverables']),
            $paymentContext->fresh(['suchakAccount', 'customerContext', 'matrimonyProfile']),
            $suchakUser,
        );

        return [$suchakUser, $account, $requestResult['payment_request']];
    }

    private function activeRepresentation(SuchakAccount $account): SuchakProfileRepresentation
    {
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Day52 Sensitive Candidate',
            'date_of_birth' => now()->subYears(26)->toDateString(),
            'father_contact_1' => '9998887777',
            'mother_contact_1' => '8887776666',
            'height_cm' => 164,
            'highest_education' => 'Graduate',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $this->activateProfileWithResidence($profile);

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

        $this->insertPrivateContactFixture($profile);

        return $representation->fresh(['suchakAccount', 'matrimonyProfile']);
    }

    private function activateProfileWithResidence(MatrimonyProfile $profile): MatrimonyProfile
    {
        $city = City::query()->where('name', 'Pune City')->firstOrFail();

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
        $city = City::query()->where('name', 'Pune City')->firstOrFail();
        $taluka = Taluka::query()->where('name', 'Haveli')->firstOrFail();
        $district = District::query()->where('name', 'Pune')->firstOrFail();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'suchak_name' => 'Day52 Verified Suchak',
            'office_name' => 'Day52 Family Desk',
            'mobile_number' => '7777777777',
            'email' => 'day52-suchak@example.test',
            'city_id' => $city->id,
            'taluka_id' => $taluka->id,
            'district_id' => $district->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        return [$user, $account];
    }

    private function insertPrivateContactFixture(MatrimonyProfile $profile): void
    {
        $contactRow = [
            'profile_id' => $profile->id,
            'contact_name' => 'Day52 Sensitive Candidate',
            'phone_number' => '9998887777',
            'is_primary' => true,
            'visibility_rule' => 'unlock_only',
            'verified_status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('profile_contacts', 'contact_relation_id')) {
            $contactRow['contact_relation_id'] = null;
        }

        if (Schema::hasColumn('profile_contacts', 'relation_type')) {
            $contactRow['relation_type'] = 'self';
        }

        DB::table('profile_contacts')->insert($contactRow);
    }
}
