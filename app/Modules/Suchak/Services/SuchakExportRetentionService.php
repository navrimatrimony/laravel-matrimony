<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBusinessExport;
use App\Models\SuchakCustomerPaymentDocument;
use App\Models\SuchakDispute;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakMonthlyValueReport;
use App\Models\SuchakOfflineCampConversionReport;
use App\Models\SuchakPlanInvoice;
use App\Models\SuchakRetentionArchiveRule;
use App\Models\SuchakRetentionArchiveRun;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakExportRetentionService
{
    public function __construct(
        private readonly SuchakAccessService $accessService,
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakPolicyService $policyService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(SuchakAccount $account, User $actor): array
    {
        $this->accessService->assertOwnerCanOperate(
            $account,
            $actor,
            'Only the owning Suchak account can view business exports.',
            'Only verified Suchak accounts can view business exports.',
        );

        return [
            'export_types' => SuchakBusinessExport::TYPES,
            'retention_policy' => $this->policyService->exportRetentionPolicy(),
            'recent_exports' => SuchakBusinessExport::query()
                ->where('suchak_account_id', $account->id)
                ->latest('generated_at')
                ->limit(20)
                ->get(),
            'retention_rules' => SuchakRetentionArchiveRule::query()
                ->where('rule_status', SuchakRetentionArchiveRule::STATUS_ACTIVE)
                ->orderBy('record_type')
                ->get(),
            'recent_archive_runs' => SuchakRetentionArchiveRun::query()
                ->where(function (Builder $query) use ($account): void {
                    $query->whereNull('suchak_account_id')
                        ->orWhere('suchak_account_id', $account->id);
                })
                ->with('retentionArchiveRule')
                ->latest('completed_at')
                ->limit(20)
                ->get(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createBusinessExport(
        SuchakAccount $account,
        User $actor,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakBusinessExport {
        $this->accessService->assertOwnerCanOperate(
            $account,
            $actor,
            'Only the owning Suchak account can create business exports.',
            'Only verified Suchak accounts can create business exports.',
        );

        if ($this->boolean($attributes['include_private_contact'] ?? false)) {
            throw new InvalidArgumentException('Private contact export requires audited admin approval.');
        }

        return $this->persistBusinessExport(
            $account,
            $actor,
            $attributes,
            false,
            SuchakBusinessExport::SENSITIVE_NOT_REQUESTED,
            null,
            null,
            $ipAddress,
            $userAgent,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createAdminApprovedBusinessExport(
        SuchakAccount $account,
        User $admin,
        array $attributes,
        string $approvalReason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakBusinessExport {
        $this->accessService->assertAdmin($admin, 'Only admins can approve sensitive Suchak business exports.');

        $includePrivateContact = $this->boolean($attributes['include_private_contact'] ?? false);
        $reason = $this->requiredText($approvalReason, 'Sensitive export approval reason is required.', 1000);

        $audit = AuditLogService::log(
            $admin,
            $includePrivateContact ? 'suchak_sensitive_business_export_approved' : 'suchak_business_export_approved',
            'SuchakAccount',
            (int) $account->id,
            $reason,
            false,
        );

        return $this->persistBusinessExport(
            $account,
            $admin,
            $attributes,
            $includePrivateContact,
            $includePrivateContact ? SuchakBusinessExport::SENSITIVE_APPROVED : SuchakBusinessExport::SENSITIVE_NOT_REQUESTED,
            $admin,
            $audit,
            $ipAddress,
            $userAgent,
        );
    }

    public function csvForExport(
        SuchakBusinessExport $export,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): string {
        $export = $export->fresh('suchakAccount') ?? $export;
        $account = $export->suchakAccount;

        if (! $account instanceof SuchakAccount) {
            throw new InvalidArgumentException('Suchak business export account is missing.');
        }

        if ($export->includes_private_contact && ! $this->accessService->isAdmin($actor)) {
            throw new InvalidArgumentException('Private contact exports can only be downloaded by an admin.');
        }

        if (! $this->accessService->isAdmin($actor)) {
            $this->accessService->assertOwnerCanOperate(
                $account,
                $actor,
                'Only the owning Suchak account can download this export.',
                'Only verified Suchak accounts can download business exports.',
            );
        }

        $csv = $this->csv($this->rowsForExport($export));
        $this->recordDownloadActivity($export, $actor, $ipAddress, $userAgent);

        return $csv;
    }

    /**
     * @return Collection<int, SuchakRetentionArchiveRun>
     */
    public function runRetentionArchiveJob(
        ?User $actor = null,
        ?SuchakAccount $account = null,
        int $limit = 50,
    ): Collection {
        if ($actor instanceof User) {
            $this->accessService->assertAdmin($actor, 'Only admins can run Suchak retention archive jobs manually.');
        }

        $runs = collect();
        $rules = SuchakRetentionArchiveRule::query()
            ->where('rule_status', SuchakRetentionArchiveRule::STATUS_ACTIVE)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($rules as $rule) {
            $runs->push($this->runRule($rule, $actor, $account));
        }

        return $runs;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persistBusinessExport(
        SuchakAccount $account,
        User $actor,
        array $attributes,
        bool $includePrivateContact,
        string $sensitiveStatus,
        ?User $approvedByAdmin,
        ?AdminAuditLog $audit,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakBusinessExport {
        $type = $this->allowed($attributes['export_type'] ?? null, SuchakBusinessExport::TYPES, 'Suchak business export type is invalid.');
        $periodStart = $this->dateOrNull($attributes['period_start'] ?? null, 'Suchak export period start is invalid.');
        $periodEnd = $this->dateOrNull($attributes['period_end'] ?? null, 'Suchak export period end is invalid.');

        if ($periodStart instanceof Carbon && $periodEnd instanceof Carbon && $periodEnd->lt($periodStart)) {
            throw new InvalidArgumentException('Suchak export period end must be after start.');
        }

        $rowCount = $this->countForExport($account, $type, $periodStart, $periodEnd);
        $exportKey = $this->exportKey();
        $manifest = [
            'export_type' => $type,
            'export_scope' => SuchakBusinessExport::SCOPE_ACCOUNT_RECORDS,
            'row_count' => $rowCount,
            'period_start' => $periodStart?->toDateString(),
            'period_end' => $periodEnd?->toDateString(),
            'columns' => $this->columnsForType($type, $includePrivateContact),
            'includes_private_contact' => $includePrivateContact,
            'sensitive_access_status' => $sensitiveStatus,
            'retention_policy_key' => SuchakPolicyService::KEY_SUCHAK_EXPORT_RETENTION_POLICY_JSON,
        ];

        return DB::transaction(function () use ($account, $actor, $type, $periodStart, $periodEnd, $rowCount, $exportKey, $manifest, $includePrivateContact, $sensitiveStatus, $approvedByAdmin, $audit, $ipAddress, $userAgent): SuchakBusinessExport {
            $export = SuchakBusinessExport::query()->create([
                'suchak_account_id' => $account->id,
                'export_key' => $exportKey,
                'export_type' => $type,
                'export_scope' => SuchakBusinessExport::SCOPE_ACCOUNT_RECORDS,
                'export_status' => SuchakBusinessExport::STATUS_GENERATED,
                'period_start' => $periodStart?->toDateString(),
                'period_end' => $periodEnd?->toDateString(),
                'row_count' => $rowCount,
                'file_name' => 'suchak-'.$type.'-export-'.strtolower($exportKey).'.csv',
                'export_checksum' => hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR).Str::uuid()),
                'includes_private_contact' => $includePrivateContact,
                'sensitive_access_status' => $sensitiveStatus,
                'requested_by_user_id' => $actor->id,
                'approved_by_admin_user_id' => $approvedByAdmin?->id,
                'admin_audit_log_id' => $audit?->id,
                'manifest_json' => $manifest,
                'generated_at' => now(),
            ]);

            $this->activityLogger->record([
                'suchak_account_id' => $account->id,
                'actor_user_id' => $actor->id,
                'actor_type' => $this->accessService->isAdmin($actor) ? SuchakActivityLog::ACTOR_ADMIN : SuchakActivityLog::ACTOR_SUCHAK,
                'action_type' => SuchakActivityLog::ACTION_BUSINESS_EXPORT_CREATED,
                'target_type' => 'suchak_business_export',
                'target_id' => $export->id,
                'admin_audit_log_id' => $audit?->id,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata_json' => [
                    'export_type' => $type,
                    'row_count' => $rowCount,
                    'includes_private_contact' => $includePrivateContact,
                    'sensitive_access_status' => $sensitiveStatus,
                ],
            ]);

            return $export->fresh(['suchakAccount', 'requestedByUser', 'approvedByAdmin', 'adminAuditLog']);
        });
    }

    private function runRule(
        SuchakRetentionArchiveRule $rule,
        ?User $actor,
        ?SuchakAccount $account,
    ): SuchakRetentionArchiveRun {
        $startedAt = now();
        $cutoff = now()->subDays($rule->archive_after_days)->startOfDay();
        $candidateCount = $this->countRetentionCandidates($rule->record_type, $cutoff, $account);
        $audit = null;

        if ($actor instanceof User) {
            $audit = AuditLogService::log(
                $actor,
                'suchak_retention_archive_rule_run',
                'SuchakRetentionArchiveRule',
                (int) $rule->id,
                'Suchak retention archive rule evaluated without deleting source records.',
                false,
            );
        }

        return DB::transaction(function () use ($rule, $actor, $account, $startedAt, $cutoff, $candidateCount, $audit): SuchakRetentionArchiveRun {
            $run = SuchakRetentionArchiveRun::query()->create([
                'retention_archive_rule_id' => $rule->id,
                'suchak_account_id' => $account?->id,
                'run_key' => $this->runKey(),
                'record_type' => $rule->record_type,
                'run_status' => SuchakRetentionArchiveRun::STATUS_COMPLETED,
                'cutoff_date' => $cutoff->toDateString(),
                'candidate_record_count' => $candidateCount,
                'retained_record_count' => $candidateCount,
                'archived_marker_count' => 0,
                'deleted_record_count' => 0,
                'skipped_record_count' => 0,
                'triggered_by_user_id' => $actor?->id,
                'admin_audit_log_id' => $audit?->id,
                'metrics_json' => [
                    'archive_action' => $rule->archive_action,
                    'retention_days' => $rule->retention_days,
                    'archive_after_days' => $rule->archive_after_days,
                    'source_records_deleted' => false,
                    'account_scoped' => $account instanceof SuchakAccount,
                ],
                'run_note' => 'Retention archive rule evaluated. Source records are retained; no delete mutation is executed.',
                'started_at' => $startedAt,
                'completed_at' => now(),
            ]);

            $this->activityLogger->record([
                'suchak_account_id' => $account?->id,
                'actor_user_id' => $actor?->id,
                'actor_type' => $actor instanceof User ? SuchakActivityLog::ACTOR_ADMIN : SuchakActivityLog::ACTOR_SYSTEM,
                'action_type' => SuchakActivityLog::ACTION_RETENTION_ARCHIVE_RUN_CREATED,
                'target_type' => 'suchak_retention_archive_run',
                'target_id' => $run->id,
                'admin_audit_log_id' => $audit?->id,
                'metadata_json' => [
                    'record_type' => $rule->record_type,
                    'candidate_record_count' => $candidateCount,
                    'deleted_record_count' => 0,
                ],
            ]);

            return $run->fresh(['retentionArchiveRule', 'suchakAccount', 'triggeredByUser', 'adminAuditLog']);
        });
    }

    private function countForExport(SuchakAccount $account, string $type, ?Carbon $start, ?Carbon $end): int
    {
        return match ($type) {
            SuchakBusinessExport::TYPE_LEDGER => $this->withinPeriod(
                SuchakLedgerEntry::query()->where('suchak_account_id', $account->id),
                $start,
                $end,
            )->count(),
            SuchakBusinessExport::TYPE_INVOICE => $this->invoiceCount($account, $start, $end),
            SuchakBusinessExport::TYPE_RECEIPT => $this->withinPeriod(
                SuchakCustomerPaymentDocument::query()
                    ->where('suchak_account_id', $account->id)
                    ->where('document_type', SuchakCustomerPaymentDocument::TYPE_RECEIPT),
                $start,
                $end,
                'issued_at',
            )->count(),
            SuchakBusinessExport::TYPE_REPORT => $this->reportCount($account, $start, $end),
            default => 0,
        };
    }

    private function invoiceCount(SuchakAccount $account, ?Carbon $start, ?Carbon $end): int
    {
        $customerInvoices = $this->withinPeriod(
            SuchakCustomerPaymentDocument::query()
                ->where('suchak_account_id', $account->id)
                ->where('document_type', SuchakCustomerPaymentDocument::TYPE_INVOICE),
            $start,
            $end,
            'issued_at',
        )->count();

        $planInvoices = $this->withinPeriod(
            SuchakPlanInvoice::query()
                ->whereHas('payment', fn (Builder $query) => $query->where('suchak_account_id', $account->id)),
            $start,
            $end,
            'issued_at',
        )->count();

        return $customerInvoices + $planInvoices;
    }

    private function reportCount(SuchakAccount $account, ?Carbon $start, ?Carbon $end): int
    {
        $monthlyReports = $this->withinPeriod(
            SuchakMonthlyValueReport::query()->where('suchak_account_id', $account->id),
            $start,
            $end,
            'generated_at',
        )->count();

        $campReports = $this->withinPeriod(
            SuchakOfflineCampConversionReport::query()->where('suchak_account_id', $account->id),
            $start,
            $end,
            'generated_at',
        )->count();

        return $monthlyReports + $campReports;
    }

    private function countRetentionCandidates(string $recordType, Carbon $cutoff, ?SuchakAccount $account): int
    {
        return match ($recordType) {
            SuchakRetentionArchiveRule::RECORD_LEDGER => $this->accountScope(
                SuchakLedgerEntry::query()->where('created_at', '<=', $cutoff),
                $account,
            )->count(),
            SuchakRetentionArchiveRule::RECORD_INVOICE => $this->retentionInvoiceCount($cutoff, $account),
            SuchakRetentionArchiveRule::RECORD_RECEIPT => $this->accountScope(
                SuchakCustomerPaymentDocument::query()
                    ->where('document_type', SuchakCustomerPaymentDocument::TYPE_RECEIPT)
                    ->where('issued_at', '<=', $cutoff),
                $account,
            )->count(),
            SuchakRetentionArchiveRule::RECORD_DISPUTE => $this->accountScope(
                SuchakDispute::query()->where('created_at', '<=', $cutoff),
                $account,
            )->count(),
            SuchakRetentionArchiveRule::RECORD_REPORT => $this->retentionReportCount($cutoff, $account),
            SuchakRetentionArchiveRule::RECORD_BUSINESS_EXPORT => $this->accountScope(
                SuchakBusinessExport::query()->where('generated_at', '<=', $cutoff),
                $account,
            )->count(),
            default => 0,
        };
    }

    private function retentionInvoiceCount(Carbon $cutoff, ?SuchakAccount $account): int
    {
        $customerInvoices = $this->accountScope(
            SuchakCustomerPaymentDocument::query()
                ->where('document_type', SuchakCustomerPaymentDocument::TYPE_INVOICE)
                ->where('issued_at', '<=', $cutoff),
            $account,
        )->count();

        $planInvoices = SuchakPlanInvoice::query()
            ->where('issued_at', '<=', $cutoff)
            ->when($account instanceof SuchakAccount, function (Builder $query) use ($account): void {
                $query->whereHas('payment', fn (Builder $payment) => $payment->where('suchak_account_id', $account->id));
            })
            ->count();

        return $customerInvoices + $planInvoices;
    }

    private function retentionReportCount(Carbon $cutoff, ?SuchakAccount $account): int
    {
        $monthlyReports = $this->accountScope(
            SuchakMonthlyValueReport::query()->where('generated_at', '<=', $cutoff),
            $account,
        )->count();

        $campReports = $this->accountScope(
            SuchakOfflineCampConversionReport::query()->where('generated_at', '<=', $cutoff),
            $account,
        )->count();

        return $monthlyReports + $campReports;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function rowsForExport(SuchakBusinessExport $export): array
    {
        $account = $export->suchakAccount;
        if (! $account instanceof SuchakAccount) {
            return [];
        }

        return match ($export->export_type) {
            SuchakBusinessExport::TYPE_LEDGER => $this->ledgerRows($export, $account),
            SuchakBusinessExport::TYPE_INVOICE => $this->invoiceRows($export, $account),
            SuchakBusinessExport::TYPE_RECEIPT => $this->receiptRows($export, $account),
            SuchakBusinessExport::TYPE_REPORT => $this->reportRows($export, $account),
            default => [],
        };
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function ledgerRows(SuchakBusinessExport $export, SuchakAccount $account): array
    {
        $rows = [[
            'ledger_entry_id',
            'matrimony_profile_id',
            'entry_type',
            'status',
            'amount',
            'currency',
            'due_date',
            'paid_at',
            'payment_context_id',
            'created_at',
        ]];

        $this->withinPeriod(
            SuchakLedgerEntry::query()->where('suchak_account_id', $account->id)->orderBy('id'),
            $export->period_start,
            $export->period_end,
        )->get()->each(function (SuchakLedgerEntry $entry) use (&$rows): void {
            $rows[] = [
                (string) $entry->id,
                (string) $entry->matrimony_profile_id,
                (string) $entry->entry_type,
                (string) $entry->status,
                (string) $entry->amount,
                (string) $entry->currency,
                optional($entry->due_date)->toDateString() ?? '',
                optional($entry->paid_at)->toDateTimeString() ?? '',
                (string) ($entry->payment_context_id ?? ''),
                optional($entry->created_at)->toDateTimeString() ?? '',
            ];
        });

        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function invoiceRows(SuchakBusinessExport $export, SuchakAccount $account): array
    {
        $rows = [['source', 'document_id', 'document_number', 'fy_label', 'issued_at']];

        $this->withinPeriod(
            SuchakCustomerPaymentDocument::query()
                ->where('suchak_account_id', $account->id)
                ->where('document_type', SuchakCustomerPaymentDocument::TYPE_INVOICE)
                ->orderBy('id'),
            $export->period_start,
            $export->period_end,
            'issued_at',
        )->get()->each(function (SuchakCustomerPaymentDocument $document) use (&$rows): void {
            $rows[] = [
                'customer_payment_document',
                (string) $document->id,
                (string) $document->document_number,
                (string) $document->fy_label,
                optional($document->issued_at)->toDateTimeString() ?? '',
            ];
        });

        $this->withinPeriod(
            SuchakPlanInvoice::query()
                ->whereHas('payment', fn (Builder $query) => $query->where('suchak_account_id', $account->id))
                ->orderBy('id'),
            $export->period_start,
            $export->period_end,
            'issued_at',
        )->get()->each(function (SuchakPlanInvoice $invoice) use (&$rows): void {
            $rows[] = [
                'plan_invoice',
                (string) $invoice->id,
                (string) $invoice->invoice_number,
                (string) $invoice->fy_label,
                optional($invoice->issued_at)->toDateTimeString() ?? '',
            ];
        });

        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function receiptRows(SuchakBusinessExport $export, SuchakAccount $account): array
    {
        $rows = [['document_id', 'document_number', 'fy_label', 'verification_code', 'issued_at']];

        $this->withinPeriod(
            SuchakCustomerPaymentDocument::query()
                ->where('suchak_account_id', $account->id)
                ->where('document_type', SuchakCustomerPaymentDocument::TYPE_RECEIPT)
                ->orderBy('id'),
            $export->period_start,
            $export->period_end,
            'issued_at',
        )->get()->each(function (SuchakCustomerPaymentDocument $document) use (&$rows): void {
            $rows[] = [
                (string) $document->id,
                (string) $document->document_number,
                (string) $document->fy_label,
                (string) ($document->verification_code ?? ''),
                optional($document->issued_at)->toDateTimeString() ?? '',
            ];
        });

        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function reportRows(SuchakBusinessExport $export, SuchakAccount $account): array
    {
        $rows = [['source', 'report_id', 'period', 'status', 'primary_count', 'generated_at']];

        $this->withinPeriod(
            SuchakMonthlyValueReport::query()->where('suchak_account_id', $account->id)->orderBy('id'),
            $export->period_start,
            $export->period_end,
            'generated_at',
        )->get()->each(function (SuchakMonthlyValueReport $report) use (&$rows): void {
            $rows[] = [
                'monthly_value_report',
                (string) $report->id,
                (string) $report->report_month,
                (string) $report->report_status,
                (string) $report->platform_leads_count,
                optional($report->generated_at)->toDateTimeString() ?? '',
            ];
        });

        $this->withinPeriod(
            SuchakOfflineCampConversionReport::query()->where('suchak_account_id', $account->id)->orderBy('id'),
            $export->period_start,
            $export->period_end,
            'generated_at',
        )->get()->each(function (SuchakOfflineCampConversionReport $report) use (&$rows): void {
            $rows[] = [
                'offline_camp_conversion_report',
                (string) $report->id,
                (string) $report->source_tag,
                (string) $report->report_status,
                (string) $report->total_intake_links,
                optional($report->generated_at)->toDateTimeString() ?? '',
            ];
        });

        return $rows;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function csv(array $rows): string
    {
        return collect($rows)
            ->map(fn (array $row): string => collect($row)
                ->map(fn (string $value): string => '"'.str_replace('"', '""', $value).'"')
                ->implode(','))
            ->implode("\n")."\n";
    }

    /**
     * @return array<int, string>
     */
    private function columnsForType(string $type, bool $includePrivateContact): array
    {
        $columns = match ($type) {
            SuchakBusinessExport::TYPE_LEDGER => ['ledger_entry_id', 'matrimony_profile_id', 'entry_type', 'status', 'amount', 'currency', 'due_date', 'paid_at', 'payment_context_id', 'created_at'],
            SuchakBusinessExport::TYPE_INVOICE => ['source', 'document_id', 'document_number', 'fy_label', 'issued_at'],
            SuchakBusinessExport::TYPE_RECEIPT => ['document_id', 'document_number', 'fy_label', 'verification_code', 'issued_at'],
            SuchakBusinessExport::TYPE_REPORT => ['source', 'report_id', 'period', 'status', 'primary_count', 'generated_at'],
            default => [],
        };

        if ($includePrivateContact) {
            $columns[] = 'private_contact_fields_admin_approved';
        }

        return $columns;
    }

    private function recordDownloadActivity(
        SuchakBusinessExport $export,
        User $actor,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        if ($this->accessService->isAdmin($actor) && $export->admin_audit_log_id === null) {
            return;
        }

        $this->activityLogger->record([
            'suchak_account_id' => $export->suchak_account_id,
            'actor_user_id' => $actor->id,
            'actor_type' => $this->accessService->isAdmin($actor) ? SuchakActivityLog::ACTOR_ADMIN : SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_BUSINESS_EXPORT_DOWNLOADED,
            'target_type' => 'suchak_business_export',
            'target_id' => $export->id,
            'admin_audit_log_id' => $this->accessService->isAdmin($actor) ? $export->admin_audit_log_id : null,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'metadata_json' => [
                'export_type' => $export->export_type,
                'includes_private_contact' => $export->includes_private_contact,
            ],
        ]);
    }

    private function exportKey(): string
    {
        do {
            $key = strtoupper(Str::random(18));
        } while (SuchakBusinessExport::query()->where('export_key', $key)->exists());

        return $key;
    }

    private function runKey(): string
    {
        do {
            $key = 'RET-'.strtoupper(Str::random(20));
        } while (SuchakRetentionArchiveRun::query()->where('run_key', $key)->exists());

        return $key;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function allowed(mixed $value, array $allowed, string $message): string
    {
        $normalized = trim((string) ($value ?? ''));
        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    private function requiredText(mixed $value, string $message, int $limit): string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            throw new InvalidArgumentException($message);
        }

        return Str::limit($normalized, $limit, '');
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function dateOrNull(mixed $value, string $message): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException($message);
        }
    }

    private function withinPeriod(Builder $query, ?Carbon $start, ?Carbon $end, string $column = 'created_at'): Builder
    {
        if ($start instanceof Carbon) {
            $query->whereDate($column, '>=', $start->toDateString());
        }

        if ($end instanceof Carbon) {
            $query->whereDate($column, '<=', $end->toDateString());
        }

        return $query;
    }

    private function accountScope(Builder $query, ?SuchakAccount $account): Builder
    {
        if ($account instanceof SuchakAccount) {
            $query->where('suchak_account_id', $account->id);
        }

        return $query;
    }
}
