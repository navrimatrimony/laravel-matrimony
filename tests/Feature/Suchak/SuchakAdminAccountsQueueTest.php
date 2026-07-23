<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Suchak\Support\CreatesSuchakAdmin;
use Tests\TestCase;

/**
 * The admin Suchak Accounts screen is a review queue, not a table dump.
 * These cover the parts an operator depends on: being able to tell two rows
 * apart, seeing what is actually reviewable, and acting on many rows at once
 * without a second approval path.
 */
class SuchakAdminAccountsQueueTest extends TestCase
{
    use CreatesSuchakAdmin;
    use RefreshDatabase;

    private function account(array $attributes = []): SuchakAccount
    {
        $user = User::factory()->create();

        return SuchakAccount::factory()->create(array_merge([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ], $attributes));
    }

    public function test_list_shows_mobile_as_identity_not_a_blank_email_line(): void
    {
        $admin = $this->createSuchakSuperAdmin();
        $this->account([
            'suchak_name' => 'Raj',
            'mobile_number' => '9876500123',
            'registration_completed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.index'))
            ->assertOk()
            ->assertSee('Raj', false)
            // The mobile is the identity Suchaks actually register with.
            ->assertSee('9876500123', false);
    }

    public function test_pending_is_split_into_ready_to_review_and_incomplete_signup(): void
    {
        $admin = $this->createSuchakSuperAdmin();
        $this->account([
            'suchak_name' => 'ReadyOne',
            'mobile_number' => '9800000001',
            'registration_completed_at' => now(),
        ]);
        $this->account([
            'suchak_name' => 'HalfDone',
            'mobile_number' => '9800000002',
            'registration_completed_at' => null,
            'onboarding_step' => 'location',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.suchak.accounts.index'));

        $response->assertOk()
            ->assertSee('Ready to review', false)
            // The badge carries the stage, so an abandoned signup reads as
            // "Incomplete · location" rather than a bare status.
            ->assertSee('Incomplete', false);

        // Filtering to ready must exclude the abandoned signup.
        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.index', ['readiness' => 'ready']))
            ->assertOk()
            ->assertSee('ReadyOne', false)
            ->assertDontSee('HalfDone', false);

        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.index', ['readiness' => 'incomplete']))
            ->assertOk()
            ->assertSee('HalfDone', false)
            ->assertDontSee('ReadyOne', false);
    }

    public function test_search_matches_name_and_mobile(): void
    {
        $admin = $this->createSuchakSuperAdmin();
        $this->account(['suchak_name' => 'Sunita Patil', 'mobile_number' => '9811111111']);
        $this->account(['suchak_name' => 'Balaji Shinde', 'mobile_number' => '9822222222']);

        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.index', ['q' => 'Balaji']))
            ->assertOk()
            ->assertSee('Balaji Shinde', false)
            ->assertDontSee('Sunita Patil', false);

        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.index', ['q' => '9811111111']))
            ->assertOk()
            ->assertSee('Sunita Patil', false)
            ->assertDontSee('Balaji Shinde', false);
    }

    public function test_duplicate_mobile_rows_are_flagged_to_the_admin(): void
    {
        $admin = $this->createSuchakSuperAdmin();
        $this->account(['suchak_name' => 'Raj', 'mobile_number' => '9833333333']);
        $this->account(['suchak_name' => 'Raj', 'mobile_number' => '9833333333']);

        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.index'))
            ->assertOk()
            ->assertSee('Same mobile', false);
    }

    /**
     * The live queue held 18 accounts literally named "Suchak". Name-only
     * matching called them all duplicates of each other — pure noise. A shared
     * name with no shared place must never raise a flag.
     */
    public function test_same_name_alone_is_never_treated_as_a_duplicate(): void
    {
        $admin = $this->createSuchakSuperAdmin();
        $this->account(['suchak_name' => 'Suchak', 'mobile_number' => '9844444401', 'city_id' => null]);
        $this->account(['suchak_name' => 'Suchak', 'mobile_number' => '9844444402', 'city_id' => null]);
        $this->account(['suchak_name' => 'Suchak', 'mobile_number' => '9844444403', 'city_id' => null]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.index'))
            ->assertOk()
            ->assertDontSee('Same name', false)
            ->assertDontSee('Same mobile', false);
    }

    public function test_stalled_signups_are_separated_from_ones_still_in_progress(): void
    {
        $admin = $this->createSuchakSuperAdmin();
        $fresh = $this->account([
            'suchak_name' => 'FreshStart',
            'registration_completed_at' => null,
            'created_at' => now()->subDay(),
        ]);
        $stalled = $this->account([
            'suchak_name' => 'LongAbandoned',
            'registration_completed_at' => null,
            'created_at' => now()->subDays(30),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.index', ['readiness' => 'stalled']))
            ->assertOk()
            ->assertSee('LongAbandoned', false)
            ->assertDontSee('FreshStart', false);

        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.index', ['readiness' => 'incomplete']))
            ->assertOk()
            ->assertSee('FreshStart', false)
            ->assertDontSee('LongAbandoned', false);

        $this->assertNotNull($fresh->id);
        $this->assertNotNull($stalled->id);
    }

    /**
     * Approving grants access to real member data, so it must never be possible
     * in bulk (PO decision 2026-07-23). This test exists to stop it coming back.
     */
    public function test_bulk_approve_is_refused(): void
    {
        $admin = $this->createSuchakSuperAdmin();
        $account = $this->account(['suchak_name' => 'NoBulkApprove', 'registration_completed_at' => now()]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.bulk'), [
                'bulk_action' => 'approve',
                'reason' => 'Trying to approve many accounts at once.',
                'account_ids' => [$account->id],
            ])
            ->assertSessionHasErrors('bulk_action');

        $this->assertSame(SuchakAccount::VERIFICATION_PENDING, $account->fresh()->verification_status);
    }

    public function test_bulk_reject_clears_junk_and_leaves_unselected_rows_alone(): void
    {
        $admin = $this->createSuchakSuperAdmin();
        $first = $this->account(['suchak_name' => 'BulkA']);
        $second = $this->account(['suchak_name' => 'BulkB']);
        $untouched = $this->account(['suchak_name' => 'BulkC']);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.bulk'), [
                'bulk_action' => 'reject',
                'reason' => 'Stalled signup cleared during queue cleanup.',
                'account_ids' => [$first->id, $second->id],
            ])
            ->assertRedirect();

        $this->assertSame(SuchakAccount::VERIFICATION_REJECTED, $first->fresh()->verification_status);
        $this->assertSame(SuchakAccount::VERIFICATION_REJECTED, $second->fresh()->verification_status);
        $this->assertSame(SuchakAccount::VERIFICATION_PENDING, $untouched->fresh()->verification_status);
    }

    /** Archive has its own lifecycle rule (verified/suspended only) — bulk must not offer it. */
    public function test_bulk_archive_is_not_offered(): void
    {
        $admin = $this->createSuchakSuperAdmin();
        $account = $this->account();

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.bulk'), [
                'bulk_action' => 'archive',
                'reason' => 'Attempting to archive a pending signup in bulk.',
                'account_ids' => [$account->id],
            ])
            ->assertSessionHasErrors('bulk_action');

        $this->assertSame(SuchakAccount::VERIFICATION_PENDING, $account->fresh()->verification_status);
    }

    public function test_bulk_reject_updates_selected_accounts(): void
    {
        $admin = $this->createSuchakSuperAdmin();
        $account = $this->account(['suchak_name' => 'RejectMe', 'registration_completed_at' => now()]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.bulk'), [
                'bulk_action' => 'reject',
                'reason' => 'Submitted documents did not match the applicant.',
                'account_ids' => [$account->id],
            ])
            ->assertRedirect();

        $this->assertSame(SuchakAccount::VERIFICATION_REJECTED, $account->fresh()->verification_status);
    }

    public function test_bulk_action_requires_a_reason_so_the_activity_log_is_never_blank(): void
    {
        $admin = $this->createSuchakSuperAdmin();
        $account = $this->account(['registration_completed_at' => now()]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.bulk'), [
                'bulk_action' => 'approve',
                'reason' => 'short',
                'account_ids' => [$account->id],
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(SuchakAccount::VERIFICATION_PENDING, $account->fresh()->verification_status);
    }

    public function test_bulk_endpoint_is_closed_to_non_admins(): void
    {
        $account = $this->account();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->post(route('admin.suchak.accounts.bulk'), [
                'bulk_action' => 'approve',
                'reason' => 'Trying to approve without admin rights.',
                'account_ids' => [$account->id],
            ])
            ->assertForbidden();

        $this->assertSame(SuchakAccount::VERIFICATION_PENDING, $account->fresh()->verification_status);
    }
}
