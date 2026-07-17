<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminAuditLog;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBusinessExport;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPolicy;
use App\Models\SuchakRetentionArchiveRule;
use App\Models\SuchakRetentionArchiveRun;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakExportRetentionService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakExportRetentionBackupRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_58_business_exports_are_structured_permissioned_and_audited(): void
    {
        foreach ([
            'suchak_business_exports',
            'suchak_retention_archive_rules',
            'suchak_retention_archive_runs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        foreach (['phone', 'mobile', 'whatsapp', 'email', 'upi', 'deleted_at'] as $forbiddenColumn) {
            $this->assertFalse(Schema::hasColumn('suchak_business_exports', $forbiddenColumn), $forbiddenColumn);
            $this->assertFalse(Schema::hasColumn('suchak_retention_archive_rules', $forbiddenColumn), $forbiddenColumn);
            $this->assertFalse(Schema::hasColumn('suchak_retention_archive_runs', $forbiddenColumn), $forbiddenColumn);
        }

        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_EXPORT_RETENTION_POLICY_JSON,
            'value_type' => SuchakPolicy::TYPE_JSON,
            'is_active' => true,
        ]);

        [$suchakUser, $account] = $this->verifiedSuchakFixture();
        $ledger = $this->ledgerEntry($account);
        $service = app(SuchakExportRetentionService::class);

        $ledgerExport = $service->createBusinessExport($account, $suchakUser, [
            'export_type' => SuchakBusinessExport::TYPE_LEDGER,
        ]);
        $invoiceExport = $service->createBusinessExport($account, $suchakUser, [
            'export_type' => SuchakBusinessExport::TYPE_INVOICE,
        ]);
        $receiptExport = $service->createBusinessExport($account, $suchakUser, [
            'export_type' => SuchakBusinessExport::TYPE_RECEIPT,
        ]);
        $reportExport = $service->createBusinessExport($account, $suchakUser, [
            'export_type' => SuchakBusinessExport::TYPE_REPORT,
        ]);

        $this->assertSame(1, $ledgerExport->row_count);
        $this->assertSame(0, $invoiceExport->row_count);
        $this->assertSame(0, $receiptExport->row_count);
        $this->assertSame(0, $reportExport->row_count);
        $this->assertFalse($ledgerExport->includes_private_contact);
        $this->assertSame(SuchakBusinessExport::SENSITIVE_NOT_REQUESTED, $ledgerExport->sensitive_access_status);
        $this->assertContains('entry_type', $ledgerExport->manifest_json['columns']);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'action_type' => SuchakActivityLog::ACTION_BUSINESS_EXPORT_CREATED,
            'target_type' => 'suchak_business_export',
            'target_id' => $ledgerExport->id,
        ]);

        $this->actingAs($suchakUser)
            ->get(route('suchak.export-retention.index'))
            ->assertOk()
            ->assertSee('Export / Retention Center', false)
            ->assertDontSee($account->mobile_number, false);

        $download = $this->actingAs($suchakUser)
            ->get(route('suchak.export-retention.exports.download', $ledgerExport));

        $download->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(SuchakLedgerEntry::TYPE_REGISTRATION_FEE_EXPECTED, $download->getContent());
        $this->assertStringNotContainsString($account->mobile_number, $download->getContent());
        $this->assertDatabaseHas('suchak_activity_logs', [
            'action_type' => SuchakActivityLog::ACTION_BUSINESS_EXPORT_DOWNLOADED,
            'target_type' => 'suchak_business_export',
            'target_id' => $ledgerExport->id,
        ]);

        $this->assertSame($ledger->id, SuchakLedgerEntry::query()->firstOrFail()->id);
        $this->assertSame(4, SuchakBusinessExport::query()->count());
    }

    public function test_day_58_sensitive_export_requires_admin_approval_and_retention_job_never_deletes_records(): void
    {
        [$suchakUser, $account] = $this->verifiedSuchakFixture();
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $ledger = $this->ledgerEntry($account);
        $ledger->forceFill([
            'created_at' => now()->subYears(2),
            'updated_at' => now()->subYears(2),
        ])->save();

        $service = app(SuchakExportRetentionService::class);

        try {
            $service->createBusinessExport($account, $suchakUser, [
                'export_type' => SuchakBusinessExport::TYPE_LEDGER,
                'include_private_contact' => true,
            ]);
            $this->fail('Private contact export should require admin approval.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Private contact export requires audited admin approval.', $exception->getMessage());
        }

        $sensitiveExport = $service->createAdminApprovedBusinessExport($account, $admin, [
            'export_type' => SuchakBusinessExport::TYPE_LEDGER,
            'include_private_contact' => true,
        ], 'Admin approved sensitive export for compliance review.');

        $this->assertTrue($sensitiveExport->includes_private_contact);
        $this->assertSame(SuchakBusinessExport::SENSITIVE_APPROVED, $sensitiveExport->sensitive_access_status);
        $this->assertNotNull($sensitiveExport->admin_audit_log_id);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action_type' => 'suchak_sensitive_business_export_approved',
            'entity_type' => 'SuchakAccount',
            'entity_id' => $account->id,
        ]);

        $this->actingAs($suchakUser)
            ->get(route('suchak.export-retention.exports.download', $sensitiveExport))
            ->assertForbidden();

        $adminCsv = $service->csvForExport($sensitiveExport, $admin);
        $this->assertStringContainsString(SuchakLedgerEntry::TYPE_REGISTRATION_FEE_EXPECTED, $adminCsv);
        $this->assertSame(1, AdminAuditLog::query()->where('action_type', 'suchak_sensitive_business_export_approved')->count());

        $this->artisan('suchak:retention-archive --limit=10')
            ->expectsOutput('Suchak retention archive rules evaluated: 5')
            ->assertExitCode(0);

        $this->assertGreaterThanOrEqual(5, SuchakRetentionArchiveRun::query()->count());
        $ledgerRun = SuchakRetentionArchiveRun::query()
            ->where('record_type', SuchakRetentionArchiveRule::RECORD_LEDGER)
            ->firstOrFail();
        $this->assertSame(1, $ledgerRun->candidate_record_count);
        $this->assertSame(1, $ledgerRun->retained_record_count);
        $this->assertSame(0, $ledgerRun->deleted_record_count);
        $this->assertFalse($ledgerRun->metrics_json['source_records_deleted']);
        $this->assertSame(1, SuchakLedgerEntry::query()->count());

        try {
            SuchakRetentionArchiveRule::query()->firstOrFail()->delete();
            $this->fail('Retention archive rules must not be deleted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak retention archive rules cannot be deleted.', $exception->getMessage());
        }

        try {
            $ledgerRun->delete();
            $this->fail('Retention archive runs must not be deleted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak retention archive runs cannot be deleted.', $exception->getMessage());
        }
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function verifiedSuchakFixture(): array
    {
        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'suchak_name' => 'Day58 Export Suchak',
            'mobile_number' => '9090909090',
            'email' => 'day58-sensitive@example.test',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        return [$suchakUser, $account];
    }

    private function ledgerEntry(SuchakAccount $account): SuchakLedgerEntry
    {
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Day 58 Export Candidate',
            'date_of_birth' => now()->subYears(27)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);

        return SuchakLedgerEntry::query()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'entry_type' => SuchakLedgerEntry::TYPE_REGISTRATION_FEE_EXPECTED,
            'amount' => '1500.00',
            'currency' => 'INR',
            'status' => SuchakLedgerEntry::STATUS_EXPECTED,
            'due_date' => now()->addDays(7)->toDateString(),
            'note' => 'Day-58 export-safe ledger fixture.',
        ]);
    }
}
