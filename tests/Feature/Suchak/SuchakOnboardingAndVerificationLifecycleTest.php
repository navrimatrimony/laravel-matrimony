<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminAuditLog;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakVerificationRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SuchakOnboardingAndVerificationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_separate_suchak_registration_page_is_available_without_creating_account_on_get(): void
    {
        $this->get(route('suchak.register.info'))
            ->assertOk()
            ->assertSee('Suchak Registration', false)
            ->assertSee('Register and send OTP', false);

        $this->assertDatabaseCount('suchak_accounts', 0);
    }

    public function test_guest_can_register_separate_suchak_account_and_reaches_otp_step(): void
    {
        Storage::fake('local');

        $this->post(route('suchak.register.store'), [
            'suchak_name' => 'Ganesh Suchak',
            'office_name' => 'Ganesh Marriage Bureau',
            'business_type' => SuchakAccount::BUSINESS_TYPE_BUREAU,
            'mobile_number' => '9876543210',
            'whatsapp_number' => '9876543210',
            'email' => 'ganesh-suchak@example.test',
            'address_line' => 'Pune office',
            'identity_document' => UploadedFile::fake()->create('identity-proof.pdf', 128, 'application/pdf'),
            'office_document' => UploadedFile::fake()->create('office-proof.pdf', 128, 'application/pdf'),
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
            ->assertRedirect(route('suchak.register.verify'))
            ->assertSessionHas('suchak_registration_otp_display');

        $this->assertAuthenticated();

        $user = User::query()->where('mobile', '9876543210')->firstOrFail();
        $account = SuchakAccount::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertNull($user->mobile_verified_at);
        $this->assertNull($user->matrimonyProfile);
        $this->assertSame(SuchakAccount::VERIFICATION_PENDING, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);

        $verificationRecords = SuchakVerificationRecord::query()
            ->where('suchak_account_id', $account->id)
            ->get()
            ->keyBy('verification_type');

        $this->assertCount(2, $verificationRecords);
        $this->assertSame(SuchakVerificationRecord::STATUS_PENDING, $verificationRecords[SuchakVerificationRecord::TYPE_IDENTITY]->admin_status);
        $this->assertSame(SuchakVerificationRecord::STATUS_PENDING, $verificationRecords[SuchakVerificationRecord::TYPE_OFFICE]->admin_status);
        $this->assertNotEmpty($verificationRecords[SuchakVerificationRecord::TYPE_IDENTITY]->document_path);
        $this->assertNotEmpty($verificationRecords[SuchakVerificationRecord::TYPE_OFFICE]->document_path);
        Storage::disk('local')->assertExists($verificationRecords[SuchakVerificationRecord::TYPE_IDENTITY]->document_path);
        Storage::disk('local')->assertExists($verificationRecords[SuchakVerificationRecord::TYPE_OFFICE]->document_path);

        $otpPayload = Cache::get('suchak_registration_otp:'.$user->id);
        $this->assertIsArray($otpPayload);
        $this->assertArrayHasKey('hash', $otpPayload);
        $this->assertNotSame(session('suchak_registration_otp_display'), $otpPayload['hash']);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $user->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_SUCHAK_ONBOARDING_REQUESTED,
        ]);
    }

    public function test_bureau_suchak_registration_requires_office_document(): void
    {
        Storage::fake('local');

        $this->post(route('suchak.register.store'), [
            'suchak_name' => 'Missing Office Proof',
            'office_name' => 'Missing Proof Bureau',
            'business_type' => SuchakAccount::BUSINESS_TYPE_BUREAU,
            'mobile_number' => '9876543209',
            'address_line' => 'Pune office',
            'identity_document' => UploadedFile::fake()->create('identity-proof.pdf', 128, 'application/pdf'),
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
            ->assertSessionHasErrors('office_document');

        $this->assertDatabaseCount('suchak_accounts', 0);
    }

    public function test_suchak_registration_otp_verification_marks_mobile_verified_without_profile(): void
    {
        $user = User::factory()->create([
            'mobile' => '9876543211',
            'mobile_verified_at' => null,
        ]);
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'mobile_number' => '9876543211',
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
        ]);

        Cache::put('suchak_registration_otp:'.$user->id, [
            'hash' => Hash::make('123456'),
            'attempts' => 0,
            'mobile' => '9876543211',
        ], 600);

        $this->actingAs($user)
            ->post(route('suchak.register.verify.submit'), [
                'otp' => '123456',
            ])
            ->assertRedirect(route('suchak.register.status'));

        $this->assertNotNull($user->fresh()->mobile_verified_at);
        $this->assertNull($user->fresh()->matrimonyProfile);
        $this->assertNull(Cache::get('suchak_registration_otp:'.$user->id));
    }

    public function test_suchak_applicant_can_view_registration_status_with_kyc_records(): void
    {
        $user = User::factory()->create([
            'mobile' => '9876543214',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'mobile_number' => '9876543214',
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);
        SuchakVerificationRecord::factory()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_IDENTITY,
            'document_path' => 'suchak/verification-documents/'.$account->id.'/identity-proof.pdf',
            'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.register.status'))
            ->assertOk()
            ->assertSee('Suchak Request Status', false)
            ->assertSee('Mobile OTP', false)
            ->assertSee('Verified', false)
            ->assertSee('KYC Documents', false)
            ->assertSee('Uploaded', false)
            ->assertDontSee('suchak/verification-documents/'.$account->id.'/identity-proof.pdf', false);
    }

    public function test_authenticated_regular_user_cannot_post_separate_suchak_registration(): void
    {
        $user = User::factory()->create([
            'mobile' => '9876543212',
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->post(route('suchak.register.store'), [
                'suchak_name' => 'Wrong Suchak Upgrade',
                'business_type' => SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
                'mobile_number' => '9876543213',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertRedirect(route('suchak.register.info'));

        $this->assertDatabaseCount('suchak_accounts', 0);
    }

    public function test_authenticated_regular_user_cannot_apply_to_become_suchak(): void
    {
        $user = User::factory()->create([
            'name' => 'Regular Member',
            'email' => 'regular-member@example.test',
            'mobile' => '9999999999',
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.apply.create'))
            ->assertRedirect(route('suchak.register.info'));

        $this->actingAs($user)
            ->post(route('suchak.apply.store'), [
                'suchak_name' => 'Wrong Upgrade Attempt',
                'office_name' => 'Test Bureau',
                'business_type' => SuchakAccount::BUSINESS_TYPE_BUREAU,
                'mobile_number' => '9999999999',
                'whatsapp_number' => '9999999999',
                'email' => 'business@example.test',
                'address_line' => 'Test address',
            ])
            ->assertRedirect(route('suchak.register.info'));

        $this->assertFalse($user->fresh()->is_admin);
        $this->assertNull($user->fresh()->suchakAccount);
        $this->assertDatabaseCount('suchak_accounts', 0);
        $this->assertDatabaseMissing('suchak_activity_logs', [
            'actor_user_id' => $user->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_SUCHAK_ONBOARDING_REQUESTED,
        ]);
    }

    public function test_guest_apply_route_is_still_auth_protected(): void
    {
        $this->get(route('suchak.apply.create'))
            ->assertRedirect(route('login'));
    }

    public function test_apply_route_does_not_attach_existing_suchak_account_to_regular_upgrade_flow(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('suchak.apply.store'), [
                'suchak_name' => 'Blocked Suchak',
                'business_type' => SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
            ])
            ->assertRedirect(route('suchak.register.info'));

        $this->assertSame(0, SuchakAccount::query()->where('user_id', $user->id)->count());
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

    public function test_admin_can_archive_verified_suchak_account_with_audit_links(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.archive', $account), [
                'reason' => 'Archiving after Suchak office closure.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $account->refresh();

        $this->assertSame(SuchakAccount::VERIFICATION_ARCHIVED, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);
        $this->assertNotNull($account->archived_at);

        $adminAuditLog = AdminAuditLog::query()
            ->where('action_type', 'suchak_account_archived')
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

    public function test_admin_can_reactivate_suspended_suchak_account_to_verified_hidden(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_SUSPENDED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => now()->subDay(),
            'suspended_at' => now(),
            'suspension_reason' => 'Old suspension reason.',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.reactivate', $account), [
                'reason' => 'Reactivating after admin review cleared issue.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $account->refresh();

        $this->assertSame(SuchakAccount::VERIFICATION_VERIFIED, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);
        $this->assertNull($account->suspended_at);
        $this->assertNull($account->suspension_reason);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action_type' => 'suchak_account_reactivated',
            'entity_type' => 'SuchakAccount',
            'entity_id' => $account->id,
        ]);
    }

    public function test_admin_reactivation_from_archived_reopens_pending_review_without_direct_verified_jump(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_ARCHIVED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => now()->subDays(3),
            'archived_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.reactivate', $account), [
                'reason' => 'Reopening archived Suchak for fresh verification.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $account->refresh();

        $this->assertSame(SuchakAccount::VERIFICATION_PENDING, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);
        $this->assertNull($account->verified_at);
        $this->assertNull($account->archived_at);

        $this->assertDatabaseHas('suchak_verification_records', [
            'suchak_account_id' => $account->id,
            'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
            'admin_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_change_verified_suchak_public_status_with_audit(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.public-status.update', $account), [
                'public_status' => SuchakAccount::PUBLIC_ACTIVE,
                'reason' => 'Making verified Suchak publicly visible.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $this->assertSame(SuchakAccount::PUBLIC_ACTIVE, $account->fresh()->public_status);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.public-status.update', $account), [
                'public_status' => SuchakAccount::PUBLIC_INACTIVE,
                'reason' => 'Temporarily making public listing inactive.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $this->assertSame(SuchakAccount::PUBLIC_INACTIVE, $account->fresh()->public_status);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action_type' => 'suchak_public_status_changed',
            'entity_type' => 'SuchakAccount',
            'entity_id' => $account->id,
        ]);
    }

    public function test_admin_cannot_make_unverified_suchak_public_active(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.suchak.accounts.show', $account))
            ->post(route('admin.suchak.accounts.public-status.update', $account), [
                'public_status' => SuchakAccount::PUBLIC_ACTIVE,
                'reason' => 'Trying to expose unverified Suchak publicly.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->fresh()->public_status);
        $this->assertDatabaseMissing('admin_audit_logs', [
            'action_type' => 'suchak_public_status_changed',
            'entity_id' => $account->id,
        ]);
    }

    public function test_admin_can_review_document_verification_record_status_with_audit_links(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
        ]);
        $record = SuchakVerificationRecord::factory()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_OFFICE,
            'document_path' => 'suchak-documents/office-proof.pdf',
            'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.verification-records.approve', [$account, $record]), [
                'reason' => 'Office document looks valid.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $record->refresh();

        $this->assertSame(SuchakVerificationRecord::STATUS_APPROVED, $record->admin_status);
        $this->assertSame($admin->id, $record->admin_user_id);
        $this->assertNotNull($record->verified_at);
        $this->assertNull($record->rejected_at);

        $adminAuditLog = AdminAuditLog::query()
            ->where('action_type', 'suchak_verification_record_status_changed')
            ->where('entity_type', 'SuchakVerificationRecord')
            ->where('entity_id', $record->id)
            ->first();

        $this->assertNotNull($adminAuditLog);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'target_type' => 'suchak_verification_record',
            'target_id' => $record->id,
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
