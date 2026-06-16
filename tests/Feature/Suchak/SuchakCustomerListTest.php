<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuchakCustomerListTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_customers_tab_shows_compact_customer_list_with_profile_details(): void
    {
        $user = User::factory()->create(['mobile' => '2222222299']);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
        ]);

        $profile = MatrimonyProfile::factory()->create([
            'user_id' => User::factory()->create()->id,
            'full_name' => 'Customer List Candidate',
            'date_of_birth' => '1998-06-15',
        ]);

        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.dashboard', ['dashboard_tab' => 'profiles']))
            ->assertOk()
            ->assertSee('Customer list', false)
            ->assertSee('Profile status', false)
            ->assertSee('Consent status', false)
            ->assertSee('Customer List Candidate', false)
            ->assertSee('#'.$profile->id, false)
            ->assertSee('View', false)
            ->assertSee('Edit profile', false)
            ->assertSee(route('suchak.representations.profile-form', $representation), false)
            ->assertSee('Manage', false)
            ->assertSee('manage_representation='.$representation->id, false)
            ->assertDontSee('Customer management', false)
            ->assertDontSee('Manage selected customer', false);
    }

    public function test_manage_customer_view_is_separate_from_customer_list(): void
    {
        $user = User::factory()->create(['mobile' => '2222222277']);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
        ]);

        $profile = MatrimonyProfile::factory()->create([
            'user_id' => User::factory()->create()->id,
            'full_name' => 'Managed Customer Candidate',
            'date_of_birth' => '1997-06-15',
        ]);

        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.dashboard', [
                'dashboard_tab' => 'profiles',
                'manage_representation' => $representation->id,
            ]))
            ->assertOk()
            ->assertSee('Customer details', false)
            ->assertSee('Back to customer list', false)
            ->assertSee('Managed Customer Candidate', false)
            ->assertSee('CRM Notes & Follow-ups', false)
            ->assertSee('Ledger & Customer Payments', false)
            ->assertDontSee('Customer list', false)
            ->assertDontSee('All your customers in one place', false)
            ->assertDontSee('Incoming Collaborations', false);
    }

    public function test_pending_intake_appears_in_customer_list_with_review_action(): void
    {
        $user = User::factory()->create(['mobile' => '2222222288']);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
        ]);

        $intake = \App\Models\BiodataIntake::query()->create([
            'uploaded_by' => $user->id,
            'raw_ocr_text' => 'Candidate biodata',
            'intake_status' => 'uploaded',
            'parse_status' => 'parsed',
            'approved_by_user' => false,
            'intake_locked' => false,
            'snapshot_schema_version' => 1,
            'parsed_json' => [
                'core' => [
                    'full_name' => 'Pending Intake Candidate',
                    'gender' => 'female',
                    'date_of_birth' => '1999-03-10',
                ],
                'addresses' => [
                    ['address_line' => 'Flat 1, Sangli', 'type' => 'current'],
                ],
            ],
        ]);

        SuchakBiodataIntakeLink::query()->create([
            'suchak_account_id' => $account->id,
            'biodata_intake_id' => $intake->id,
            'matrimony_profile_id' => null,
            'source_status' => SuchakBiodataIntakeLink::STATUS_REVIEW_PENDING,
            'created_by_user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.dashboard', ['dashboard_tab' => 'profiles']))
            ->assertOk()
            ->assertSee('Pending Intake Candidate', false)
            ->assertSee('Intake #'.$intake->id, false)
            ->assertSee('Review', false);
    }
}
