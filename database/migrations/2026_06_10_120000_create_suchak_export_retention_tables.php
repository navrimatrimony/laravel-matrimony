<?php

use App\Models\SuchakBusinessExport;
use App\Models\SuchakPolicy;
use App\Models\SuchakRetentionArchiveRule;
use App\Models\SuchakRetentionArchiveRun;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_business_exports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('export_key', 64);
            $table->string('export_type', 40);
            $table->string('export_scope', 80)->default(SuchakBusinessExport::SCOPE_ACCOUNT_RECORDS);
            $table->string('export_status', 32)->default(SuchakBusinessExport::STATUS_GENERATED);
            $table->string('source_type', 96)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('file_name', 180);
            $table->string('export_checksum', 64);
            $table->boolean('includes_private_contact')->default(false);
            $table->string('sensitive_access_status', 32)->default(SuchakBusinessExport::SENSITIVE_NOT_REQUESTED);
            $table->unsignedBigInteger('requested_by_user_id');
            $table->unsignedBigInteger('approved_by_admin_user_id')->nullable();
            $table->unsignedBigInteger('admin_audit_log_id')->nullable();
            $table->json('manifest_json');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique('export_key', 'sk_business_exports_key_unique');
            $table->index('suchak_account_id', 'sk_business_exports_account_idx');
            $table->index('export_type', 'sk_business_exports_type_idx');
            $table->index('export_status', 'sk_business_exports_status_idx');
            $table->index('sensitive_access_status', 'sk_business_exports_sensitive_idx');
            $table->index('requested_by_user_id', 'sk_business_exports_requester_idx');
            $table->index('approved_by_admin_user_id', 'sk_business_exports_admin_idx');
            $table->index('generated_at', 'sk_business_exports_generated_idx');
            $table->index(['suchak_account_id', 'export_type', 'generated_at'], 'sk_business_exports_account_type_idx');

            $table->foreign('suchak_account_id', 'sk_business_exports_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('requested_by_user_id', 'sk_business_exports_requester_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by_admin_user_id', 'sk_business_exports_admin_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('admin_audit_log_id', 'sk_business_exports_audit_fk')->references('id')->on('admin_audit_logs')->restrictOnDelete();
        });

        Schema::create('suchak_retention_archive_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('rule_key', 96);
            $table->string('rule_name', 160);
            $table->string('record_type', 48);
            $table->unsignedInteger('retention_days');
            $table->unsignedInteger('archive_after_days');
            $table->string('archive_action', 40)->default(SuchakRetentionArchiveRule::ACTION_RETAIN_AUDITED);
            $table->string('rule_status', 32)->default(SuchakRetentionArchiveRule::STATUS_ACTIVE);
            $table->boolean('requires_admin_export_approval')->default(false);
            $table->string('policy_key', 160)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('admin_audit_log_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestamps();

            $table->unique('rule_key', 'sk_retention_archive_rules_key_unique');
            $table->index('record_type', 'sk_retention_archive_rules_type_idx');
            $table->index('rule_status', 'sk_retention_archive_rules_status_idx');
            $table->index('created_by_user_id', 'sk_retention_archive_rules_creator_idx');

            $table->foreign('created_by_user_id', 'sk_retention_archive_rules_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('admin_audit_log_id', 'sk_retention_archive_rules_audit_fk')->references('id')->on('admin_audit_logs')->restrictOnDelete();
        });

        Schema::create('suchak_retention_archive_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('retention_archive_rule_id');
            $table->unsignedBigInteger('suchak_account_id')->nullable();
            $table->string('run_key', 96);
            $table->string('record_type', 48);
            $table->string('run_status', 32)->default(SuchakRetentionArchiveRun::STATUS_COMPLETED);
            $table->date('cutoff_date');
            $table->unsignedInteger('candidate_record_count')->default(0);
            $table->unsignedInteger('retained_record_count')->default(0);
            $table->unsignedInteger('archived_marker_count')->default(0);
            $table->unsignedInteger('deleted_record_count')->default(0);
            $table->unsignedInteger('skipped_record_count')->default(0);
            $table->unsignedBigInteger('triggered_by_user_id')->nullable();
            $table->unsignedBigInteger('admin_audit_log_id')->nullable();
            $table->json('metrics_json');
            $table->text('run_note')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->unique('run_key', 'sk_retention_archive_runs_key_unique');
            $table->index('retention_archive_rule_id', 'sk_retention_archive_runs_rule_idx');
            $table->index('suchak_account_id', 'sk_retention_archive_runs_account_idx');
            $table->index('record_type', 'sk_retention_archive_runs_type_idx');
            $table->index('run_status', 'sk_retention_archive_runs_status_idx');
            $table->index('cutoff_date', 'sk_retention_archive_runs_cutoff_idx');
            $table->index('triggered_by_user_id', 'sk_retention_archive_runs_actor_idx');

            $table->foreign('retention_archive_rule_id', 'sk_retention_archive_runs_rule_fk')->references('id')->on('suchak_retention_archive_rules')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_retention_archive_runs_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('triggered_by_user_id', 'sk_retention_archive_runs_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('admin_audit_log_id', 'sk_retention_archive_runs_audit_fk')->references('id')->on('admin_audit_logs')->restrictOnDelete();
        });

        $now = now();

        DB::table('suchak_retention_archive_rules')->insert([
            [
                'rule_key' => 'suchak_ledger_7_year_retain',
                'rule_name' => 'Suchak ledger retention',
                'record_type' => SuchakRetentionArchiveRule::RECORD_LEDGER,
                'retention_days' => 2555,
                'archive_after_days' => 365,
                'archive_action' => SuchakRetentionArchiveRule::ACTION_RETAIN_AUDITED,
                'rule_status' => SuchakRetentionArchiveRule::STATUS_ACTIVE,
                'requires_admin_export_approval' => false,
                'policy_key' => SuchakPolicyService::KEY_SUCHAK_EXPORT_RETENTION_POLICY_JSON,
                'effective_from' => $now->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'rule_key' => 'suchak_invoice_7_year_retain',
                'rule_name' => 'Suchak invoice retention',
                'record_type' => SuchakRetentionArchiveRule::RECORD_INVOICE,
                'retention_days' => 2555,
                'archive_after_days' => 365,
                'archive_action' => SuchakRetentionArchiveRule::ACTION_RETAIN_AUDITED,
                'rule_status' => SuchakRetentionArchiveRule::STATUS_ACTIVE,
                'requires_admin_export_approval' => false,
                'policy_key' => SuchakPolicyService::KEY_SUCHAK_EXPORT_RETENTION_POLICY_JSON,
                'effective_from' => $now->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'rule_key' => 'suchak_receipt_7_year_retain',
                'rule_name' => 'Suchak receipt retention',
                'record_type' => SuchakRetentionArchiveRule::RECORD_RECEIPT,
                'retention_days' => 2555,
                'archive_after_days' => 365,
                'archive_action' => SuchakRetentionArchiveRule::ACTION_RETAIN_AUDITED,
                'rule_status' => SuchakRetentionArchiveRule::STATUS_ACTIVE,
                'requires_admin_export_approval' => false,
                'policy_key' => SuchakPolicyService::KEY_SUCHAK_EXPORT_RETENTION_POLICY_JSON,
                'effective_from' => $now->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'rule_key' => 'suchak_dispute_legal_hold_retain',
                'rule_name' => 'Suchak dispute legal-hold retention',
                'record_type' => SuchakRetentionArchiveRule::RECORD_DISPUTE,
                'retention_days' => 3650,
                'archive_after_days' => 365,
                'archive_action' => SuchakRetentionArchiveRule::ACTION_LEGAL_HOLD,
                'rule_status' => SuchakRetentionArchiveRule::STATUS_ACTIVE,
                'requires_admin_export_approval' => true,
                'policy_key' => SuchakPolicyService::KEY_SUCHAK_EXPORT_RETENTION_POLICY_JSON,
                'effective_from' => $now->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'rule_key' => 'suchak_report_7_year_retain',
                'rule_name' => 'Suchak report retention',
                'record_type' => SuchakRetentionArchiveRule::RECORD_REPORT,
                'retention_days' => 2555,
                'archive_after_days' => 365,
                'archive_action' => SuchakRetentionArchiveRule::ACTION_RETAIN_AUDITED,
                'rule_status' => SuchakRetentionArchiveRule::STATUS_ACTIVE,
                'requires_admin_export_approval' => false,
                'policy_key' => SuchakPolicyService::KEY_SUCHAK_EXPORT_RETENTION_POLICY_JSON,
                'effective_from' => $now->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('suchak_policies')->upsert([
            [
                'policy_key' => SuchakPolicyService::KEY_SUCHAK_EXPORT_RETENTION_POLICY_JSON,
                'policy_value' => json_encode(SuchakPolicyService::DEFAULT_SUCHAK_EXPORT_RETENTION_POLICY, JSON_UNESCAPED_SLASHES),
                'value_type' => SuchakPolicy::TYPE_JSON,
                'description' => 'Suchak export, backup, and retention policy for Day-58 audited business exports.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['policy_key'], ['policy_value', 'value_type', 'description', 'is_active', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('suchak_policies')
            ->where('policy_key', SuchakPolicyService::KEY_SUCHAK_EXPORT_RETENTION_POLICY_JSON)
            ->delete();

        Schema::dropIfExists('suchak_retention_archive_runs');
        Schema::dropIfExists('suchak_retention_archive_rules');
        Schema::dropIfExists('suchak_business_exports');
    }
};
