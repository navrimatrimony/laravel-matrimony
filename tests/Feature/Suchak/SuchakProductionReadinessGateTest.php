<?php

namespace Tests\Feature\Suchak;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SuchakProductionReadinessGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_62_required_routes_schema_and_qa_artifacts_are_present(): void
    {
        foreach ($this->requiredRouteNames() as $routeName) {
            $this->assertTrue(Route::has($routeName), "Missing Day-62 required route [{$routeName}].");
        }

        foreach ($this->requiredTablesAndColumns() as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "Missing Day-62 required table [{$table}].");
            $this->assertFalse(Schema::hasColumn($table, 'deleted_at'), "Day-62 immutable Suchak table [{$table}] must not use soft deletes.");

            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "Missing Day-62 required column [{$table}.{$column}].");
            }
        }

        $this->assertFileContains(base_path('docs/operations/suchak-day61-browser-mobile-qa.md'), [
            'Covered personas:',
            'verified Suchak',
            'pending Suchak',
            'suspended Suchak',
            'public visitor',
            'Real Browser QA Result',
            'Google Chrome headless via Chrome DevTools Protocol',
            'docs/operations/screenshots/day61/chrome-390x844-admin-suchak-dashboard.png',
            'docs/operations/screenshots/day61/chrome-390x844-suchak-export-retention.png',
            'No production code change was required by Day-61 QA.',
        ]);
        $this->assertFileContains(base_path('docs/operations/suchak-day62-production-readiness.md'), [
            'Day-62 - Final Advanced Suchak Production Readiness',
            'The only allowed pending item after Day-62 is live external credentials/provider activation.',
            'real Chrome mobile viewport evidence',
        ]);

        foreach ($this->requiredBrowserQaArtifacts() as $artifact) {
            $path = base_path($artifact);
            $this->assertFileExists($path, "Missing Day-61 browser QA artifact [{$artifact}].");
            $this->assertGreaterThan(0, filesize($path), "Empty Day-61 browser QA artifact [{$artifact}].");
        }
    }

    public function test_day_62_critical_coverage_manifest_points_to_existing_regressions(): void
    {
        foreach ($this->criticalCoverageManifest() as $file => $methods) {
            $path = base_path($file);
            $this->assertFileExists($path, "Missing Day-62 coverage file [{$file}].");

            $content = (string) file_get_contents($path);
            foreach ($methods as $method) {
                $this->assertStringContainsString("function {$method}(", $content, "Missing Day-62 coverage method [{$method}] in [{$file}].");
            }
        }
    }

    public function test_day_62_public_marketplace_copy_and_claim_guards_stay_locked(): void
    {
        $this->assertFileContains(base_path('app/Modules/Suchak/Services/SuchakPublicMarketplaceService.php'), [
            'hasPublicClaimRisk',
            'safePublicText',
            'publicAccountQuery',
        ]);

        foreach ([
            'resources/views/suchak/marketplace/index.blade.php',
            'resources/views/suchak/marketplace/show.blade.php',
        ] as $file) {
            $content = strtolower((string) file_get_contents(base_path($file)));

            foreach ([
                'success rate',
                'guaranteed match',
                'top rated',
                'no. 1',
                'number 1',
                'upi',
                'whatsapp',
                'mobile_number',
                'email',
            ] as $forbiddenText) {
                $this->assertStringNotContainsString($forbiddenText, $content, "Public marketplace file [{$file}] must not expose [{$forbiddenText}].");
            }
        }
    }

    /**
     * @return list<string>
     */
    private function requiredRouteNames(): array
    {
        return [
            'admin.suchak.dashboard',
            'admin.suchak.accounts.index',
            'admin.suchak.plans.index',
            'admin.suchak.safety.index',
            'admin.suchak.retention.index',
            'admin.suchak.academy.index',
            'admin.suchak.payouts.index',
            'suchak.home',
            'suchak.dashboard',
            'suchak.search.index',
            'suchak.collaborations.index',
            'suchak.training-academy.index',
            'suchak.offline-camps.index',
            'suchak.export-retention.index',
            'suchak.marketplace.index',
            'suchak.marketplace.show',
            'suchak.payment-requests.show',
            'suchak.customer-portal.show',
            'suchak.customer-portal.claim',
            'suchak.customer-portal.revoke',
            'suchak.receipts.verify',
            'suchak.direct-payment-complaints.store',
            'suchak.plans.payu.start',
            'suchak.plans.payu.success',
            'suchak.plans.payu.failure',
            'suchak.plans.payu.webhook',
            'matrimony.profile.suchak-requests.store',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function requiredTablesAndColumns(): array
    {
        return [
            'suchak_accounts' => ['verification_status', 'public_status'],
            'suchak_customer_contexts' => ['source_owner', 'customer_lifecycle_status'],
            'suchak_payment_contexts' => ['source_owner', 'payment_collector', 'context_status'],
            'suchak_customer_agreements' => ['terms_status', 'agreement_snapshot_hash'],
            'suchak_payment_requests' => ['payment_status', 'payment_detail_visibility_policy', 'collector_disclosure'],
            'suchak_customer_payments' => ['payment_status', 'payment_mode', 'amount_received'],
            'suchak_customer_payment_documents' => ['document_type', 'document_number', 'verification_code'],
            'suchak_customer_payment_corrections' => ['correction_type', 'correction_status', 'document_number'],
            'suchak_customer_portal_links' => ['portal_status', 'token_hash'],
            'suchak_direct_payment_evidence' => ['evidence_type', 'evidence_note'],
            'suchak_platform_payouts' => ['payout_status', 'platform_event_type', 'net_amount'],
            'suchak_platform_payout_settlements' => ['statement_status', 'statement_month'],
            'suchak_business_exports' => ['export_status', 'sensitive_access_status', 'manifest_json'],
            'suchak_retention_archive_runs' => ['run_status', 'deleted_record_count', 'metrics_json'],
            'suchak_scheduled_job_runs' => ['job_key', 'job_status', 'metrics_json'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function criticalCoverageManifest(): array
    {
        return [
            'tests/Feature/Suchak/SuchakIntegratedQaTest.php' => [
                'test_phase_6_integrated_suchak_flow_preserves_ssot_boundaries',
                'test_phase_6_suchak_route_and_scope_boundaries_remain_explicit',
            ],
            'tests/Feature/Suchak/SuchakRolePrivacySecurityRegressionTest.php' => [
                'test_day_60_role_matrix_protects_admin_suchak_public_and_verified_only_surfaces',
                'test_day_60_payment_matrix_blocks_platform_direct_payment_allows_suchak_payment_and_keeps_receipt_private',
                'test_day_60_payout_admin_only_and_marketplace_privacy_regressions',
            ],
            'tests/Feature/Suchak/SuchakBrowserMobileQaCompletionTest.php' => [
                'test_day_61_persona_matrix_renders_or_gates_primary_surfaces_cleanly',
                'test_day_61_verified_suchak_operator_surfaces_cover_expanded_engine_links_on_mobile',
                'test_day_61_public_customer_and_receipt_surfaces_render_mobile_without_private_leaks',
            ],
            'tests/Feature/Suchak/SuchakCustomerPaymentManualFoundationTest.php' => [
                'test_suchak_records_partial_upi_payment_with_invoice_receipt_qr_and_ledger_link_without_payu',
                'test_full_cash_payment_marks_request_paid_and_direct_records_cannot_be_deleted',
            ],
            'tests/Feature/Suchak/SuchakPaymentCorrectionOverdueFoundationTest.php' => [
                'test_refund_request_approval_paid_lifecycle_preserves_original_payment_and_documents',
                'test_waiver_credit_note_and_reversal_are_separate_correction_records',
            ],
            'tests/Feature/Suchak/SuchakPayoutSettlementWorkflowTest.php' => [
                'test_admin_can_approve_pay_and_regenerate_monthly_settlement_with_reference_and_deductions',
                'test_admin_payout_report_route_exposes_workflow_and_separates_liability_from_revenue',
            ],
            'tests/Feature/Suchak/SuchakPublicMarketplaceTest.php' => [
                'test_public_marketplace_lists_only_verified_public_suchaks_with_factual_cards',
                'test_public_marketplace_hides_claim_risky_service_cards_and_non_public_profiles',
            ],
            'tests/Feature/Suchak/SuchakDirectPaymentTrustProtectionTest.php' => [
                'test_admin_review_can_freeze_payment_ability_and_direct_collection_guard_blocks_requests',
                'test_customer_warnings_are_visible_on_payment_request_and_portal_surfaces',
            ],
            'tests/Feature/Suchak/SuchakExportRetentionBackupRulesTest.php' => [
                'test_day_58_business_exports_are_structured_permissioned_and_audited',
                'test_day_58_sensitive_export_requires_admin_approval_and_retention_job_never_deletes_records',
            ],
            'tests/Feature/Suchak/SuchakScheduledJobsConsolidationTest.php' => [
                'test_day_59_scheduled_job_run_table_is_structured_without_private_contact_columns',
                'test_consolidated_scheduled_jobs_are_due_aware_idempotent_and_do_not_leak_private_contact',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function requiredBrowserQaArtifacts(): array
    {
        return [
            'docs/operations/screenshots/day61/chrome-390x844-admin-suchak-dashboard.png',
            'docs/operations/screenshots/day61/chrome-390x844-admin-suchak-retention.png',
            'docs/operations/screenshots/day61/chrome-390x844-suchak-dashboard.png',
            'docs/operations/screenshots/day61/chrome-390x844-suchak-offline-camps.png',
            'docs/operations/screenshots/day61/chrome-390x844-suchak-export-retention.png',
        ];
    }

    /**
     * @param  list<string>  $requiredText
     */
    private function assertFileContains(string $path, array $requiredText): void
    {
        $this->assertFileExists($path);

        $content = (string) file_get_contents($path);
        foreach ($requiredText as $text) {
            $this->assertStringContainsString($text, $content, "Missing expected Day-62 readiness text [{$text}] in [{$path}].");
        }
    }
}
