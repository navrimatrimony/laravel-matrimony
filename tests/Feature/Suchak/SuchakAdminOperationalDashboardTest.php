<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBiodataExport;
use App\Models\SuchakConsent;
use App\Models\SuchakConsentEvent;
use App\Models\SuchakDispute;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakQrToken;
use App\Models\SuchakSubscription;
use App\Models\SuchakVerificationRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuchakAdminOperationalDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_inspect_operational_state_with_source_links_and_masked_evidence(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $suchakUser = User::factory()->create(['email' => 'day27-suchak@example.test']);

        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'suchak_name' => 'Day 27 Operational Suchak',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'mobile_number' => '9876543210',
            'whatsapp_number' => '9876543210',
        ]);

        SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);
        SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_SUSPENDED,
            'public_status' => SuchakAccount::PUBLIC_INACTIVE,
        ]);
        SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_ARCHIVED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);

        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Sensitive Day 27 Candidate',
            'address_line' => 'Private Day 27 Lane',
        ]);
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
        ]);

        $consent = SuchakConsent::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'consent_status' => SuchakConsent::STATUS_ACCEPTED,
            'consent_given_by_name' => 'Sensitive Consent Giver',
            'consent_mobile_number' => '9876543210',
            'accepted_at' => now(),
            'valid_from' => now(),
            'valid_until' => now()->addDays(10),
        ]);
        SuchakConsentEvent::factory()->create([
            'consent_id' => $consent->id,
            'event_type' => SuchakConsentEvent::EVENT_CONSENT_ACCEPTED,
            'event_note' => 'Private consent note with 9876543210.',
            'actor_type' => SuchakConsentEvent::ACTOR_SUCHAK,
            'created_at' => now(),
        ]);

        SuchakVerificationRecord::factory()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_IDENTITY,
            'document_path' => 'private/day27-document.pdf',
            'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
            'remarks' => 'Private verification remarks.',
        ]);

        SuchakSubscription::factory()->create([
            'suchak_account_id' => $account->id,
            'status' => SuchakSubscription::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(5),
        ]);

        SuchakLedgerEntry::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'status' => SuchakLedgerEntry::STATUS_DUE,
            'due_date' => now()->subDay()->toDateString(),
            'amount' => '2500.00',
            'note' => 'Private ledger note with 9876543210.',
        ]);

        SuchakDispute::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'dispute_type' => SuchakDispute::TYPE_ABUSE_REPORT,
            'status' => SuchakDispute::STATUS_OPEN,
            'priority' => SuchakDispute::PRIORITY_URGENT,
            'summary' => 'Sensitive dispute summary with 9876543210.',
            'evidence_summary' => 'Private dispute evidence.',
        ]);

        $export = SuchakBiodataExport::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'generated_by_user_id' => $suchakUser->id,
            'downloaded_at' => now(),
            'shared_at' => now(),
            'created_at' => now(),
        ]);
        SuchakQrToken::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'export_id' => $export->id,
            'expires_at' => now()->addDays(6),
            'scan_count' => 3,
            'last_scanned_at' => now(),
        ]);
        SuchakActivityLog::factory()->create([
            'suchak_account_id' => $account->id,
            'actor_user_id' => $suchakUser->id,
            'action_type' => SuchakActivityLog::ACTION_PDF_GENERATED,
            'target_type' => 'suchak_biodata_export',
            'target_id' => $export->id,
            'metadata_json' => ['private_phone' => '9876543210'],
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.suchak.dashboard'));

        $response->assertOk()
            ->assertSee('Registrations & Approvals', false)
            ->assertSee('Consent Health', false)
            ->assertSee('Payment & Subscription Health', false)
            ->assertSee('Disputes & Abuse', false)
            ->assertSee('PDF / QR Activity', false)
            ->assertSee('Evidence Timeline', false)
            ->assertSee('Day 27 Operational Suchak', false)
            ->assertSee(route('admin.suchak.accounts.show', $account), false)
            ->assertSee(route('admin.suchak.accounts.index', ['verification_status' => SuchakAccount::VERIFICATION_PENDING]), false)
            ->assertSee('Open source', false)
            ->assertSee('Suchak Biodata Export #'.$export->id, false)
            ->assertSee('Consent #'.$consent->id, false)
            ->assertDontSee('9876543210', false)
            ->assertDontSee('Sensitive Day 27 Candidate', false)
            ->assertDontSee('Private Day 27 Lane', false)
            ->assertDontSee('Sensitive Consent Giver', false)
            ->assertDontSee('private/day27-document.pdf', false)
            ->assertDontSee('Private verification remarks.', false)
            ->assertDontSee('Private ledger note', false)
            ->assertDontSee('Sensitive dispute summary', false)
            ->assertDontSee('Private dispute evidence', false);
    }
}
