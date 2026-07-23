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
            ->assertSee('Signup incomplete', false);

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
            ->assertSee('Same mobile as another row', false);
    }

    public function test_bulk_approve_uses_the_lifecycle_engine_and_updates_every_selected_account(): void
    {
        $admin = $this->createSuchakSuperAdmin();
        $first = $this->account(['suchak_name' => 'BulkA', 'registration_completed_at' => now()]);
        $second = $this->account(['suchak_name' => 'BulkB', 'registration_completed_at' => now()]);
        $untouched = $this->account(['suchak_name' => 'BulkC', 'registration_completed_at' => now()]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.bulk'), [
                'bulk_action' => 'approve',
                'reason' => 'Documents verified in the bulk review pass.',
                'account_ids' => [$first->id, $second->id],
            ])
            ->assertRedirect();

        $this->assertSame(SuchakAccount::VERIFICATION_VERIFIED, $first->fresh()->verification_status);
        $this->assertSame(SuchakAccount::VERIFICATION_VERIFIED, $second->fresh()->verification_status);
        // Unselected rows must not be touched.
        $this->assertSame(SuchakAccount::VERIFICATION_PENDING, $untouched->fresh()->verification_status);
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
