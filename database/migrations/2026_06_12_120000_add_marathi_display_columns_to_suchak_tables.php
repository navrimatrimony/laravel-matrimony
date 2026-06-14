<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, array<string, string>>
     */
    private array $columnsByTable = [
        'suchak_accounts' => [
            'suchak_name_mr' => 'string',
            'office_name_mr' => 'string',
            'address_line_mr' => 'text',
        ],
        'suchak_verification_records' => [
            'remarks_mr' => 'text',
        ],
        'suchak_policies' => [
            'description_mr' => 'text',
        ],
        'suchak_consents' => [
            'consent_text_snapshot_mr' => 'text',
            'revocation_reason_mr' => 'text',
        ],
        'suchak_consent_events' => [
            'event_note_mr' => 'text',
        ],
        'suchak_commission_agreements' => [
            'agreement_text_snapshot_mr' => 'text',
        ],
        'suchak_plans' => [
            'name_mr' => 'string:120',
            'description_mr' => 'text',
        ],
        'suchak_disputes' => [
            'summary_mr' => 'text',
            'evidence_summary_mr' => 'text',
            'resolution_note_mr' => 'text',
        ],
        'suchak_plan_payments' => [
            'plan_name_mr' => 'string:120',
            'product_info_mr' => 'string:120',
        ],
        'suchak_package_templates' => [
            'template_name_mr' => 'string:160',
            'template_description_mr' => 'text',
        ],
        'suchak_package_template_stages' => [
            'stage_name_mr' => 'string:160',
            'stage_description_mr' => 'text',
        ],
        'suchak_package_template_deliverables' => [
            'deliverable_name_mr' => 'string:160',
            'deliverable_description_mr' => 'text',
        ],
        'suchak_service_packages' => [
            'package_name_mr' => 'string:160',
            'package_description_mr' => 'text',
            'rejection_reason_mr' => 'text',
        ],
        'suchak_service_package_stages' => [
            'stage_name_mr' => 'string:160',
            'stage_description_mr' => 'text',
        ],
        'suchak_service_package_deliverables' => [
            'deliverable_name_mr' => 'string:160',
            'deliverable_description_mr' => 'text',
        ],
        'suchak_customer_agreements' => [
            'package_name_mr' => 'string:160',
            'package_description_mr' => 'text',
            'agreement_title_mr' => 'string:160',
            'agreement_body_mr' => 'text',
            'invoice_note_mr' => 'text',
            'decline_reason_mr' => 'text',
            'bypass_reason_mr' => 'text',
        ],
        'suchak_customer_agreement_stages' => [
            'stage_name_mr' => 'string:160',
            'stage_description_mr' => 'text',
        ],
        'suchak_customer_agreement_deliverables' => [
            'deliverable_name_mr' => 'string:160',
            'deliverable_description_mr' => 'text',
        ],
        'suchak_payment_requests' => [
            'request_title_mr' => 'string:160',
            'request_note_mr' => 'text',
            'collector_disclosure_mr' => 'text',
            'cancellation_reason_mr' => 'text',
        ],
        'suchak_campaign_rules' => [
            'campaign_name_mr' => 'string:160',
        ],
        'suchak_campaign_qualifications' => [
            'qualification_note_mr' => 'text',
        ],
        'suchak_loyalty_tier_snapshots' => [
            'tier_label_mr' => 'string:120',
        ],
        'suchak_monthly_value_reports' => [
            'unsupported_claims_note_mr' => 'text',
        ],
        'suchak_retention_offers' => [
            'offer_note_mr' => 'text',
            'response_note_mr' => 'text',
        ],
        'suchak_feature_suspensions' => [
            'reason_mr' => 'text',
            'release_reason_mr' => 'text',
        ],
        'suchak_workflow_reminders' => [
            'message_copy_mr' => 'text',
        ],
        'suchak_workflow_timeline_events' => [
            'event_title_mr' => 'string:160',
            'event_summary_mr' => 'text',
        ],
        'suchak_retention_archive_rules' => [
            'rule_name_mr' => 'string:160',
        ],
        'suchak_training_modules' => [
            'module_title_mr' => 'string:160',
            'summary_mr' => 'text',
            'content_outline_mr' => 'text',
        ],
        'suchak_training_completions' => [
            'completion_note_mr' => 'text',
        ],
        'suchak_training_certificates' => [
            'certificate_note_mr' => 'text',
            'revocation_note_mr' => 'text',
        ],
        'suchak_message_templates' => [
            'template_title_mr' => 'string:160',
            'body_text_mr' => 'text',
            'usage_guidance_mr' => 'text',
        ],
        'suchak_offline_camps' => [
            'camp_name_mr' => 'string:160',
            'location_label_mr' => 'string:160',
            'privacy_note_mr' => 'text',
        ],
        'suchak_offline_camp_intake_links' => [
            'link_note_mr' => 'text',
        ],
        'suchak_offline_camp_package_assignments' => [
            'assignment_note_mr' => 'text',
        ],
        'suchak_offline_camp_conversion_reports' => [
            'report_note_mr' => 'text',
        ],
        'suchak_retention_archive_runs' => [
            'run_note_mr' => 'text',
        ],
        'suchak_contact_numbers' => [
            'label_mr' => 'string:80',
        ],
    ];

    public function up(): void
    {
        foreach ($this->columnsByTable as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns): void {
                foreach ($columns as $column => $definition) {
                    if (Schema::hasColumn($tableName, $column)) {
                        continue;
                    }

                    $this->addNullableColumn($table, $column, $definition);
                }
            });
        }

        $this->backfillKnownMarathiLabels();
    }

    public function down(): void
    {
        foreach (array_reverse($this->columnsByTable) as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns): void {
                foreach (array_reverse(array_keys($columns)) as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function addNullableColumn(Blueprint $table, string $column, string $definition): void
    {
        if (str_starts_with($definition, 'string:')) {
            $table->string($column, (int) substr($definition, strlen('string:')))->nullable();

            return;
        }

        if ($definition === 'text') {
            $table->text($column)->nullable();

            return;
        }

        $table->string($column)->nullable();
    }

    private function backfillKnownMarathiLabels(): void
    {
        if (Schema::hasTable('suchak_policies') && Schema::hasColumn('suchak_policies', 'description_mr')) {
            $policyDescriptions = [
                'suchak_upload_daily_limit' => 'सूचकासाठी दररोजच्या upload मर्यादेची policy.',
                'suchak_active_profile_limit_by_plan' => 'सूचक plan नुसार active customer profile मर्यादा.',
                'suchak_request_action_sla_hours' => 'सूचक request action साठी SLA तास.',
                'suchak_package_publish_approval_mode' => 'सूचक customer package publish approval policy.',
                'suchak_terms_policy_mode' => 'सूचक customer agreement terms policy mode.',
                'suchak_payment_detail_visibility_policy' => 'सूचक payment request मधील direct payment detail visibility policy.',
            ];

            foreach ($policyDescriptions as $key => $descriptionMr) {
                DB::table('suchak_policies')
                    ->where('policy_key', $key)
                    ->where(fn ($query) => $query->whereNull('description_mr')->orWhere('description_mr', ''))
                    ->update(['description_mr' => $descriptionMr]);
            }
        }

        if (Schema::hasTable('suchak_plans') && Schema::hasColumn('suchak_plans', 'name_mr')) {
            $planNames = [
                'suchak-starter' => ['सूचक Starter', 'नवीन सूचकांसाठी basic working plan.'],
                'suchak-professional' => ['सूचक Professional', 'नियमित customer follow-up आणि uploads साठी plan.'],
                'suchak-bureau' => ['सूचक Bureau', 'bureau team आणि जास्त customer workload साठी plan.'],
                'suchak-enterprise' => ['सूचक Enterprise', 'मोठ्या office किंवा organization साठी managed plan.'],
            ];

            foreach ($planNames as $slug => [$nameMr, $descriptionMr]) {
                DB::table('suchak_plans')
                    ->where('slug', $slug)
                    ->update([
                        'name_mr' => $nameMr,
                        'description_mr' => DB::raw("COALESCE(NULLIF(description_mr, ''), ".$this->quote($descriptionMr).')'),
                    ]);
            }
        }

        if (Schema::hasTable('suchak_retention_archive_rules') && Schema::hasColumn('suchak_retention_archive_rules', 'rule_name_mr')) {
            $archiveRules = [
                'suchak_ledger_7_year_retain' => 'सूचक ledger retention',
                'suchak_invoice_7_year_retain' => 'सूचक invoice retention',
                'suchak_receipt_7_year_retain' => 'सूचक receipt retention',
                'suchak_dispute_legal_hold_retain' => 'सूचक dispute legal-hold retention',
                'suchak_report_7_year_retain' => 'सूचक report retention',
            ];

            foreach ($archiveRules as $key => $nameMr) {
                DB::table('suchak_retention_archive_rules')
                    ->where('rule_key', $key)
                    ->where(fn ($query) => $query->whereNull('rule_name_mr')->orWhere('rule_name_mr', ''))
                    ->update(['rule_name_mr' => $nameMr]);
            }
        }
    }

    private function quote(string $value): string
    {
        return DB::connection()->getPdo()->quote($value);
    }
};
