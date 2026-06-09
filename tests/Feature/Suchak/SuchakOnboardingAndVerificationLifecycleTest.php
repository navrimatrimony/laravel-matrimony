<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminAuditLog;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakVerificationRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuchakOnboardingAndVerificationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_request_suchak_account_without_auto_creation_gate(): void
    {
        $user = User::factory()->create([
            'name' => 'Test Suchak',
            'email' => 'suchak@example.test',
            'mobile' => '9999999999',
        ]);

        $this->actingAs($user)
            ->get(route('suchak.apply.create'))
            ->assertOk()
            ->assertSee('Suchak Account Request', false);

        $this->actingAs($user)
            ->post(route('suchak.apply.store'), [
                'suchak_name' => 'Test Suchak',
                'office_name' => 'Test Bureau',
                'business_type' => SuchakAccount::BUSINESS_TYPE_BUREAU,
                'mobile_number' => '9999999999',
                'whatsapp_number' => '9999999999',
                'email' => 'business@example.test',
                'address_line' => 'Test address',
            ])
            ->assertRedirect(route('suchak.dashboard'));

        $this->assertDatabaseHas('suchak_accounts', [
            'user_id' => $user->id,
            'suchak_name' => 'Test Suchak',
            'business_type' => SuchakAccount::BUSINESS_TYPE_BUREAU,
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);

        $account = $user->fresh()->suchakAccount;

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $user->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_SUCHAK_ONBOARDING_REQUESTED,
            'target_type' => 'suchak_account',
            'target_id' => $account->id,
        ]);
    }

    public function test_guest_cannot_access_suchak_account_request_form(): void
    {
        $this->get(route('suchak.apply.create'))
            ->assertRedirect(route('login'));
    }

    public function test_duplicate_suchak_account_request_does_not_create_second_account(): void
    {
        $user = User::factory()->create();
        SuchakAccount::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('suchak.apply.store'), [
                'suchak_name' => 'Duplicate Suchak',
                'business_type' => SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
            ])
            ->assertRedirect(route('suchak.dashboard'));

        $this->assertSame(1, SuchakAccount::query()->where('user_id', $user->id)->count());
    }

    public function test_admin_can_review_and_approve_pending_suchak_account_with_audit_links(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.index'))
            ->assertOk()
            ->assertSee($account->suchak_name, false);

        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.show', $account))
            ->assertOk()
            ->assertSee($account->suchak_name, false)
            ->assertSee('Admin Actions', false);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.approve', $account), [
                'reason' => 'Verified account details for Day-4.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $account->refresh();

        $this->assertSame(SuchakAccount::VERIFICATION_VERIFIED, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);
        $this->assertNotNull($account->verified_at);

        $adminAuditLog = AdminAuditLog::query()
            ->where('action_type', 'suchak_account_approved')
            ->where('entity_type', 'SuchakAccount')
            ->where('entity_id', $account->id)
            ->first();

        $this->assertNotNull($adminAuditLog);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => SuchakActivityLog::ACTION_ADMIN_AUDIT_LINKED,
            'admin_audit_log_id' => $adminAuditLog->id,
        ]);

        $this->assertDatabaseHas('suchak_verification_records', [
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_OTHER,
            'admin_status' => SuchakVerificationRecord::STATUS_APPROVED,
            'admin_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_reject_pending_suchak_account_with_audit_links(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.reject', $account), [
                'reason' => 'Business identity could not be verified.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $account->refresh();

        $this->assertSame(SuchakAccount::VERIFICATION_REJECTED, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);
        $this->assertNotNull($account->rejected_at);

        $adminAuditLog = AdminAuditLog::query()
            ->where('action_type', 'suchak_account_rejected')
            ->where('entity_id', $account->id)
            ->first();

        $this->assertNotNull($adminAuditLog);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'admin_audit_log_id' => $adminAuditLog->id,
        ]);

        $this->assertDatabaseHas('suchak_verification_records', [
            'suchak_account_id' => $account->id,
            'admin_status' => SuchakVerificationRecord::STATUS_REJECTED,
            'admin_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_suspend_verified_suchak_account_with_audit_links(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.suspend', $account), [
                'reason' => 'Suspending after admin verification review.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $account->refresh();

        $this->assertSame(SuchakAccount::VERIFICATION_SUSPENDED, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);
        $this->assertSame('Suspending after admin verification review.', $account->suspension_reason);
        $this->assertNotNull($account->suspended_at);

        $adminAuditLog = AdminAuditLog::query()
            ->where('action_type', 'suchak_account_suspended')
            ->where('entity_id', $account->id)
            ->first();

        $this->assertNotNull($adminAuditLog);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'admin_audit_log_id' => $adminAuditLog->id,
        ]);
    }

    public function test_admin_cannot_approve_non_pending_suchak_account_on_day_4(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_REJECTED,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.suchak.accounts.show', $account))
            ->post(route('admin.suchak.accounts.approve', $account), [
                'reason' => 'Trying an invalid Day-4 transition.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $this->assertSame(SuchakAccount::VERIFICATION_REJECTED, $account->fresh()->verification_status);
        $this->assertDatabaseMissing('admin_audit_logs', [
            'action_type' => 'suchak_account_approved',
            'entity_id' => $account->id,
        ]);
    }
}
