<?php

namespace Tests\Feature\Suchak;

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuchakMobileApiAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_me_dashboard_and_customers_adapters_expose_existing_services(): void
    {
        $user = User::factory()->create([
            'mobile' => '9876500011',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'suchak_name' => 'Adapter Suchak',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/suchak/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account.id', $account->id)
            ->assertJsonPath('data.access.can_operate', true)
            ->assertJsonPath('data.payment_identity.is_configured', false)
            ->assertJsonStructure([
                'data' => [
                    'payment_identity' => [
                        'upi_vpa',
                        'payment_qr_path',
                        'payment_qr_url',
                        'payment_qr_updated_at',
                        'is_configured',
                    ],
                    'mvp_surface' => [
                        'nav',
                        'nav_subitems',
                        'dashboard_tabs',
                        'visible_dashboard_tabs',
                    ],
                ],
            ]);

        $this->getJson('/api/v1/suchak/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account_id', $account->id)
            ->assertJsonStructure(['data' => ['worklist', 'generated_at']]);

        $this->getJson('/api/v1/suchak/customers')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account_id', $account->id)
            ->assertJsonStructure(['data' => ['customers']]);

        $this->postJson('/api/v1/suchak/customers/999999/consents', [
            'consent_given_by_name' => 'Test',
            'consent_giver_relation' => 'candidate_self',
            'intended_mobile' => '9876500001',
        ])
            ->assertNotFound()
            ->assertJsonPath('success', false);

        $this->getJson('/api/v1/suchak/customers/999999/payment-request-options')
            ->assertNotFound()
            ->assertJsonPath('success', false);

        $this->postJson('/api/v1/suchak/customers/999999/payment-setup')
            ->assertNotFound()
            ->assertJsonPath('success', false);

        $this->getJson('/api/v1/suchak/search')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'results',
                    'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
                ],
            ]);

        $this->getJson('/api/v1/suchak/collaborations')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account_id', $account->id)
            ->assertJsonStructure(['data' => ['collaborations']]);

        $this->getJson('/api/v1/suchak/payments')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account_id', $account->id)
            ->assertJsonPath('data.track', 'A')
            ->assertJsonStructure(['data' => ['ledger_entries', 'payment_identity', 'open_payment_requests']]);

        $this->getJson('/api/v1/suchak/meetings')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account_id', $account->id)
            ->assertJsonStructure(['data' => ['visits']]);

        $this->getJson('/api/v1/suchak/plans')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['plans']]);

        $this->getJson('/api/v1/suchak/billing')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['status', 'plan_invoices']]);
    }

    public function test_password_reset_forgot_endpoint_is_available(): void
    {
        $this->postJson('/api/v1/auth/password/forgot', [
            'login' => '9999999999',
        ])->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_suchak_intake_adapter_creates_source_link_via_existing_service(): void
    {
        Bus::fake();

        $user = User::factory()->create([
            'mobile' => '9876500013',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/suchak/intakes', [
            'raw_text' => 'Mobile adapter biodata intake text.',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['source_link_id', 'biodata_intake_id', 'source_status']]);

        $intake = BiodataIntake::query()->where('uploaded_by', $user->id)->first();
        $this->assertNotNull($intake);
        $this->assertSame('Mobile adapter biodata intake text.', $intake->raw_ocr_text);

        $this->assertDatabaseHas('suchak_biodata_intake_links', [
            'suchak_account_id' => $account->id,
            'biodata_intake_id' => $intake->id,
            'source_status' => SuchakBiodataIntakeLink::STATUS_INTAKE_UPLOADED,
            'created_by_user_id' => $user->id,
        ]);

        Bus::assertDispatched(ParseIntakeJob::class);
    }

    public function test_suchak_manual_profile_adapter_creates_draft_via_existing_services(): void
    {
        MasterGender::query()->firstOrCreate(
            ['key' => 'male'],
            ['label' => 'Male', 'is_active' => true],
        );
        MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true],
        );

        $user = User::factory()->create([
            'mobile' => '9876500014',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/suchak/manual-profiles/meta')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['genders', 'registering_for_options']]);

        $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Adapter Candidate',
            'candidate_mobile' => '9876500099',
            'candidate_gender' => 'male',
            'registering_for' => 'self',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.outcome', 'created')
            ->assertJsonPath('data.candidate_name', 'Adapter Candidate');

        $profile = MatrimonyProfile::query()->where('full_name', 'Adapter Candidate')->first();
        $this->assertNotNull($profile);

        $this->assertDatabaseHas('suchak_profile_representations', [
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('suchak_customer_contexts', [
            'suchak_account_id' => $account->id,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_CANDIDATE_IDENTIFIED,
        ]);
    }

    public function test_suchak_manual_profile_adapter_requires_confirmation_for_existing_mobile(): void
    {
        MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true],
        );

        $user = User::factory()->create([
            'mobile' => '9876500015',
            'mobile_verified_at' => now(),
        ]);
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        $existingMember = User::factory()->create([
            'mobile' => '9876500088',
            'name' => 'Existing Member',
        ]);
        MatrimonyProfile::factory()->create([
            'user_id' => $existingMember->id,
            'full_name' => 'Existing Member',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Link Attempt',
            'candidate_mobile' => '9876500088',
            'candidate_gender' => 'female',
            'registering_for' => 'parent_guardian',
        ])
            ->assertStatus(409)
            ->assertJsonPath('data.outcome', 'existing_profile_confirmation_required');
    }

    public function test_suchak_payment_identity_track_a_can_be_updated(): void
    {
        $user = User::factory()->create([
            'mobile' => '9876500016',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/suchak/payment-identity')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.track', 'A')
            ->assertJsonPath('data.payment_identity.is_configured', false);

        $this->postJson('/api/v1/suchak/payment-identity', [
            'upi_vpa' => 'suchak@okaxis',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment_identity.upi_vpa', 'suchak@okaxis')
            ->assertJsonPath('data.payment_identity.is_configured', true);

        $this->assertDatabaseHas('suchak_accounts', [
            'id' => $account->id,
            'upi_vpa' => 'suchak@okaxis',
        ]);

        $this->postJson('/api/v1/suchak/payment-identity', [
            'upi_vpa' => 'not-a-vpa',
        ])
            ->assertStatus(422);
    }

    public function test_suchak_mobile_adapters_require_suchak_account(): void
    {
        $user = User::factory()->create([
            'mobile' => '9876500012',
            'mobile_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/suchak/me')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }
}
