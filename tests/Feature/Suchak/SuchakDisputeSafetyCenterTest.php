<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminAuditLog;
use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBiodataExport;
use App\Models\SuchakConsent;
use App\Models\SuchakConsentEvent;
use App\Models\SuchakDispute;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakQrToken;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAccessService;
use App\Modules\Suchak\Services\SuchakPdfQrFoundationService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakDisputeSafetyCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
    }

    public function test_admin_can_open_review_and_close_suchak_dispute_with_audit(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $account, $profile, $representation] = $this->activeRepresentationFixture();

        $this->actingAs($admin)
            ->get(route('admin.suchak.safety.index'))
            ->assertOk()
            ->assertSee('Suchak Safety Center', false)
            ->assertSee('Open Dispute / Abuse Case', false)
            ->assertSee('Freeze / Pause Controls', false)
            ->assertSee('Representation Revoke Controls', false)
            ->assertSee(route('admin.suchak.safety.disputes.store'), false);

        $this->actingAs($admin)
            ->post(route('admin.suchak.safety.disputes.store'), [
                'suchak_account_id' => $account->id,
                'representation_id' => $representation->id,
                'dispute_type' => SuchakDispute::TYPE_ABUSE_REPORT,
                'priority' => SuchakDispute::PRIORITY_URGENT,
                'summary' => 'Candidate reported abusive Suchak handling.',
                'evidence_summary' => 'Call log and written complaint reviewed by admin.',
            ])
            ->assertRedirect(route('admin.suchak.safety.index'))
            ->assertSessionHas('success', 'Suchak dispute opened.');

        $dispute = SuchakDispute::query()->firstOrFail();

        $this->assertSame($account->id, $dispute->suchak_account_id);
        $this->assertSame($profile->id, $dispute->matrimony_profile_id);
        $this->assertSame($representation->id, $dispute->representation_id);
        $this->assertSame($admin->id, $dispute->opened_by_user_id);
        $this->assertSame(SuchakDispute::TYPE_ABUSE_REPORT, $dispute->dispute_type);
        $this->assertSame(SuchakDispute::STATUS_OPEN, $dispute->status);

        $openedAudit = AdminAuditLog::query()
            ->where('action_type', 'suchak_dispute_opened')
            ->where('entity_type', 'SuchakDispute')
            ->where('entity_id', $dispute->id)
            ->first();
        $this->assertNotNull($openedAudit);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => SuchakActivityLog::ACTION_DISPUTE_OPENED,
            'target_type' => 'suchak_dispute',
            'target_id' => $dispute->id,
            'admin_audit_log_id' => $openedAudit->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.safety.disputes.review', $dispute), [
                'review_note' => 'Admin started safety review.',
            ])
            ->assertRedirect(route('admin.suchak.safety.index'))
            ->assertSessionHas('success', 'Suchak dispute moved under review.');

        $this->assertSame(SuchakDispute::STATUS_UNDER_REVIEW, $dispute->fresh()->status);
        $this->assertSame($admin->id, $dispute->fresh()->assigned_admin_user_id);

        $this->actingAs($admin)
            ->post(route('admin.suchak.safety.disputes.close', $dispute), [
                'resolution_status' => SuchakDispute::STATUS_RESOLVED,
                'resolution_note' => 'Safety issue resolved with account action and evidence retained.',
            ])
            ->assertRedirect(route('admin.suchak.safety.index'))
            ->assertSessionHas('success', 'Suchak dispute closed.');

        $dispute->refresh();

        $this->assertSame(SuchakDispute::STATUS_RESOLVED, $dispute->status);
        $this->assertNotNull($dispute->resolved_at);
        $this->assertSame('Safety issue resolved with account action and evidence retained.', $dispute->resolution_note);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action_type' => 'suchak_dispute_under_review',
            'entity_type' => 'SuchakDispute',
            'entity_id' => $dispute->id,
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action_type' => 'suchak_dispute_closed',
            'entity_type' => 'SuchakDispute',
            'entity_id' => $dispute->id,
        ]);
    }

    public function test_admin_freeze_pause_and_resume_controls_block_relevant_suchak_actions(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $account] = $this->activeRepresentationFixture();
        $access = app(SuchakAccessService::class);

        $this->assertTrue($access->canOperate($account));
        $this->assertTrue($access->canPubliclyRoute($account));

        $this->actingAs($admin)
            ->post(route('admin.suchak.safety.accounts.pause', $account), [
                'reason' => 'Pausing public Suchak routing during safety review.',
            ])
            ->assertRedirect(route('admin.suchak.safety.index'));

        $account->refresh();
        $this->assertSame(SuchakAccount::VERIFICATION_VERIFIED, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_INACTIVE, $account->public_status);
        $this->assertTrue($access->canOperate($account));
        $this->assertFalse($access->canPubliclyRoute($account));

        $this->actingAs($admin)
            ->post(route('admin.suchak.safety.accounts.resume', $account), [
                'reason' => 'Resuming public Suchak routing after safety review.',
            ])
            ->assertRedirect(route('admin.suchak.safety.index'));

        $account->refresh();
        $this->assertSame(SuchakAccount::PUBLIC_ACTIVE, $account->public_status);
        $this->assertTrue($access->canPubliclyRoute($account));

        $this->actingAs($admin)
            ->post(route('admin.suchak.safety.accounts.freeze', $account), [
                'reason' => 'Freezing Suchak account during urgent abuse investigation.',
            ])
            ->assertRedirect(route('admin.suchak.safety.index'));

        $account->refresh();
        $this->assertSame(SuchakAccount::VERIFICATION_SUSPENDED, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);
        $this->assertFalse($access->canOperate($account));
        $this->assertFalse($access->canPubliclyRoute($account));

        $this->actingAs($admin)
            ->post(route('admin.suchak.safety.accounts.unfreeze', $account), [
                'reason' => 'Unfreezing Suchak after admin safety review cleared issue.',
            ])
            ->assertRedirect(route('admin.suchak.safety.index'));

        $account->refresh();
        $this->assertSame(SuchakAccount::VERIFICATION_VERIFIED, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);
        $this->assertTrue($access->canOperate($account));
        $this->assertFalse($access->canPubliclyRoute($account));

        foreach (['suchak_public_status_changed', 'suchak_account_suspended', 'suchak_account_reactivated'] as $actionType) {
            $this->assertDatabaseHas('admin_audit_logs', [
                'action_type' => $actionType,
                'entity_type' => 'SuchakAccount',
                'entity_id' => $account->id,
            ]);
        }
    }

    public function test_admin_revoke_representation_preserves_history_and_blocks_qr_access(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$suchakUser, $account, $profile, $representation, $consent] = $this->activeRepresentationFixture();
        $dispute = SuchakDispute::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'dispute_type' => SuchakDispute::TYPE_CONSENT_CONFLICT,
            'status' => SuchakDispute::STATUS_UNDER_REVIEW,
        ]);
        $export = SuchakBiodataExport::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'generated_by_user_id' => $suchakUser->id,
        ]);
        $rawToken = Str::random(64);
        $qrToken = SuchakQrToken::factory()->create([
            'token_hash' => hash('sha256', $rawToken),
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'export_id' => $export->id,
            'expires_at' => now()->addDays(30),
            'scan_count' => 0,
            'revoked_at' => null,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.safety.representations.revoke', $representation), [
                'reason' => 'Candidate withdrew authority from Suchak representation.',
                'dispute_id' => $dispute->id,
            ])
            ->assertRedirect(route('admin.suchak.safety.index'))
            ->assertSessionHas('success', 'Suchak representation revoked.');

        $representation->refresh();
        $consent->refresh();
        $qrToken->refresh();

        $this->assertSame(SuchakProfileRepresentation::STATUS_REVOKED, $representation->representation_status);
        $this->assertSame(SuchakProfileRepresentation::CONSENT_REVOKED, $representation->consent_status);
        $this->assertNotNull($representation->revoked_at);
        $this->assertFalse($representation->hasValidConsent());
        $this->assertFalse($representation->isPubliclyVisible());

        $this->assertSame(SuchakConsent::STATUS_REVOKED, $consent->consent_status);
        $this->assertNotNull($consent->revoked_at);
        $this->assertSame('Candidate withdrew authority from Suchak representation.', $consent->revocation_reason);
        $this->assertDatabaseHas('suchak_consent_events', [
            'consent_id' => $consent->id,
            'event_type' => SuchakConsentEvent::EVENT_CONSENT_REVOKED,
            'actor_type' => SuchakConsentEvent::ACTOR_ADMIN,
            'actor_id' => $admin->id,
        ]);

        $this->assertNotNull($qrToken->revoked_at);
        $this->assertSame('admin_representation_revoke', $qrToken->revoked_reason);
        $this->assertDatabaseHas('suchak_biodata_exports', ['id' => $export->id]);
        $this->assertDatabaseHas('suchak_disputes', ['id' => $dispute->id]);
        $this->assertSame('Safety Test Candidate', $profile->fresh()->full_name);

        $adminAudit = AdminAuditLog::query()
            ->where('action_type', 'suchak_representation_revoked')
            ->where('entity_type', 'SuchakProfileRepresentation')
            ->where('entity_id', $representation->id)
            ->first();
        $this->assertNotNull($adminAudit);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => SuchakActivityLog::ACTION_REPRESENTATION_REVOKED,
            'target_type' => 'suchak_profile_representation',
            'target_id' => $representation->id,
            'admin_audit_log_id' => $adminAudit->id,
        ]);

        try {
            app(SuchakPdfQrFoundationService::class)->scanQrToken($rawToken);

            $this->fail('Admin-revoked representation should block QR access.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('QR token has been revoked.', $exception->getMessage());
        }

        $this->assertSame(1, $qrToken->fresh()->scan_count);
    }

    public function test_suchak_disputes_cannot_be_deleted(): void
    {
        $dispute = SuchakDispute::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Suchak dispute records cannot be deleted.');

        $dispute->delete();
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: MatrimonyProfile, 3: SuchakProfileRepresentation, 4: SuchakConsent}
     */
    private function activeRepresentationFixture(): array
    {
        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'suchak_name' => 'Safety Test Suchak',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Safety Test Candidate',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $leafId]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $leafId, null, true, false);
        }

        $profile->update([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);
        $consent = SuchakConsent::factory()->create([
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

        return [$suchakUser, $account, $profile, $representation, $consent];
    }
}
