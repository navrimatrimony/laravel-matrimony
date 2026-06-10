<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakProfileNote;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuchakCrmLedgerOperationalUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_can_create_and_review_notes_followups_and_ledger_entries_from_dashboard(): void
    {
        [$suchakUser, $account, $profile, $representation] = $this->representedProfileFixture();
        $beforeUpdatedAt = $profile->updated_at?->toDateTimeString();

        $this->actingAs($suchakUser)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertSee('CRM Notes & Follow-ups', false)
            ->assertSee('Ledger & Customer Payments', false)
            ->assertSee('Search CRM or ledger', false);

        $this->actingAs($suchakUser)
            ->post(route('suchak.representations.crm-notes.store', $representation), [
                'note_type' => SuchakProfileNote::TYPE_FOLLOW_UP,
                'note_text' => 'Family meeting scheduled for next week.',
                'follow_up_at' => '2026-07-01T10:30',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Suchak CRM note added.');

        $this->assertDatabaseHas('suchak_profile_notes', [
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'note_type' => SuchakProfileNote::TYPE_FOLLOW_UP,
            'note_text' => 'Family meeting scheduled for next week.',
            'visibility' => SuchakProfileNote::VISIBILITY_PRIVATE,
        ]);
        $this->assertSame($beforeUpdatedAt, $profile->fresh()->updated_at?->toDateTimeString());

        $this->actingAs($suchakUser)
            ->post(route('suchak.representations.ledger-entries.store', $representation), [
                'entry_type' => SuchakLedgerEntry::TYPE_REGISTRATION_FEE_EXPECTED,
                'amount' => '1500',
                'currency' => 'INR',
                'status' => SuchakLedgerEntry::STATUS_PAID,
                'due_date' => '2026-07-05',
                'paid_at' => '2026-07-02T11:15',
                'note' => 'Registration fee received in cash.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Suchak ledger entry added.');

        $this->assertDatabaseHas('suchak_ledger_entries', [
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'entry_type' => SuchakLedgerEntry::TYPE_REGISTRATION_FEE_EXPECTED,
            'amount' => '1500.00',
            'currency' => 'INR',
            'status' => SuchakLedgerEntry::STATUS_PAID,
            'note' => 'Registration fee received in cash.',
        ]);
        $this->assertSame($beforeUpdatedAt, $profile->fresh()->updated_at?->toDateTimeString());

        $this->actingAs($suchakUser)
            ->get(route('suchak.dashboard', [
                'note_type' => SuchakProfileNote::TYPE_FOLLOW_UP,
                'ledger_status' => SuchakLedgerEntry::STATUS_PAID,
            ]))
            ->assertOk()
            ->assertSee('Family meeting scheduled for next week.', false)
            ->assertSee('Registration Fee Expected', false)
            ->assertSee('Payment status', false)
            ->assertSee('Paid 2026-07-02 11:15', false);
    }

    public function test_crm_ledger_ui_rejects_private_contact_text(): void
    {
        [$suchakUser, , , $representation] = $this->representedProfileFixture();

        $this->actingAs($suchakUser)
            ->post(route('suchak.representations.crm-notes.store', $representation), [
                'note_type' => SuchakProfileNote::TYPE_CALL,
                'note_text' => 'Call family on 9876543210',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Suchak CRM records must not store private contact details.');

        $this->assertSame(0, SuchakProfileNote::query()->count());

        $this->actingAs($suchakUser)
            ->post(route('suchak.representations.ledger-entries.store', $representation), [
                'entry_type' => SuchakLedgerEntry::TYPE_PAYMENT_REMINDER,
                'currency' => 'INR',
                'status' => SuchakLedgerEntry::STATUS_DUE,
                'note' => 'Email reminder to private@example.com',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Suchak CRM records must not store private contact details.');

        $this->assertSame(0, SuchakLedgerEntry::query()->count());
    }

    public function test_non_owner_suchak_cannot_create_crm_or_ledger_records_for_another_representation(): void
    {
        [, , , $representation] = $this->representedProfileFixture();
        [$otherUser] = $this->representedProfileFixture();

        $this->actingAs($otherUser)
            ->post(route('suchak.representations.crm-notes.store', $representation), [
                'note_type' => SuchakProfileNote::TYPE_GENERAL,
                'note_text' => 'Wrong owner note.',
            ])
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->post(route('suchak.representations.ledger-entries.store', $representation), [
                'entry_type' => SuchakLedgerEntry::TYPE_ADJUSTMENT,
                'currency' => 'INR',
                'status' => SuchakLedgerEntry::STATUS_EXPECTED,
            ])
            ->assertForbidden();

        $this->assertSame(0, SuchakProfileNote::query()->count());
        $this->assertSame(0, SuchakLedgerEntry::query()->count());
    }

    public function test_crm_ledger_dashboard_search_stays_scoped_to_the_logged_in_suchak(): void
    {
        [$suchakUser, $account, $profile, $representation] = $this->representedProfileFixture();
        [$otherUser, $otherAccount, $otherProfile] = $this->representedProfileFixture();

        SuchakProfileNote::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'note_type' => SuchakProfileNote::TYPE_GENERAL,
            'note_text' => 'Private owner follow-up marker.',
        ]);
        SuchakLedgerEntry::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'entry_type' => SuchakLedgerEntry::TYPE_PAYMENT_REMINDER,
            'status' => SuchakLedgerEntry::STATUS_DUE,
            'note' => 'Owner payment marker.',
        ]);
        SuchakProfileNote::factory()->create([
            'suchak_account_id' => $otherAccount->id,
            'matrimony_profile_id' => $otherProfile->id,
            'note_type' => SuchakProfileNote::TYPE_GENERAL,
            'note_text' => 'Other Suchak private marker.',
        ]);

        $this->actingAs($suchakUser)
            ->get(route('suchak.dashboard', ['business_q' => 'marker']))
            ->assertOk()
            ->assertSee('Private owner follow-up marker.', false)
            ->assertSee('Owner payment marker.', false)
            ->assertDontSee('Other Suchak private marker.', false);

        $this->actingAs($otherUser)
            ->get(route('suchak.dashboard', ['business_q' => 'marker']))
            ->assertOk()
            ->assertSee('Other Suchak private marker.', false)
            ->assertDontSee('Private owner follow-up marker.', false);

        $this->assertSame($account->id, $representation->suchak_account_id);
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: MatrimonyProfile, 3: SuchakProfileRepresentation}
     */
    private function representedProfileFixture(): array
    {
        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'CRM UI Candidate',
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'highest_education' => 'B.Com',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
            'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
        ]);

        return [$suchakUser, $account, $profile, $representation];
    }
}
