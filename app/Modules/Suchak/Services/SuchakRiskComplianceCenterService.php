<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakCustomerPaymentCorrection;
use App\Models\SuchakDispute;
use App\Models\SuchakGrowthAttribution;
use App\Models\SuchakQrToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SuchakRiskComplianceCenterService
{
    private const LIMIT = 8;

    /**
     * @return array{generated_at: Carbon, stats: array<string, int>, panels: array<int, array<string, mixed>>}
     */
    public function summary(): array
    {
        $panels = [
            $this->termsBypassPanel(),
            $this->refundPanel(),
            $this->cashProofPanel(),
            $this->directPaymentComplaintPanel(),
            $this->qrPdfAbusePanel(),
            $this->growthSuspicionPanel(),
        ];

        return [
            'generated_at' => now(),
            'stats' => collect($panels)
                ->mapWithKeys(fn (array $panel): array => [$panel['key'] => (int) $panel['count']])
                ->all(),
            'panels' => $panels,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function termsBypassPanel(): array
    {
        $query = SuchakCustomerAgreement::query()
            ->with('suchakAccount')
            ->where('terms_status', SuchakCustomerAgreement::TERMS_BYPASSED);

        $records = (clone $query)
            ->latest('bypassed_at')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (SuchakCustomerAgreement $agreement): array => $this->record(
                'Customer Agreement',
                (int) $agreement->id,
                $agreement->suchakAccount,
                $agreement->terms_status,
                $agreement->bypassed_at,
                $this->safeSummary($agreement->bypass_reason ?: $agreement->invoice_note ?: 'Terms bypassed.'),
                ['terms_status' => $agreement->terms_status],
            ));

        return $this->panel(
            'high_bypass',
            'High Bypass Dashboard',
            (clone $query)->count(),
            'high',
            'Customer agreement terms bypassed before payment flow.',
            route('admin.suchak.safety.index'),
            $records,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function refundPanel(): array
    {
        $query = SuchakCustomerPaymentCorrection::query()
            ->with('suchakAccount')
            ->where('correction_type', SuchakCustomerPaymentCorrection::TYPE_REFUND)
            ->whereIn('correction_status', [
                SuchakCustomerPaymentCorrection::STATUS_REQUESTED,
                SuchakCustomerPaymentCorrection::STATUS_APPROVED,
                SuchakCustomerPaymentCorrection::STATUS_PAID,
            ]);

        $records = (clone $query)
            ->latest('requested_at')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (SuchakCustomerPaymentCorrection $correction): array => $this->record(
                'Payment Correction',
                (int) $correction->id,
                $correction->suchakAccount,
                $correction->correction_status,
                $correction->requested_at,
                $this->safeSummary($correction->reason ?: 'Refund correction requires admin tracking.'),
                [
                    'amount' => trim($correction->currency.' '.$correction->amount),
                    'correction_type' => $correction->correction_type,
                ],
            ));

        return $this->panel(
            'high_refund',
            'High Refund Dashboard',
            (clone $query)->count(),
            'high',
            'Refund requests and paid refunds that need evidence review.',
            route('admin.suchak.safety.index'),
            $records,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function cashProofPanel(): array
    {
        $query = SuchakCustomerPayment::query()
            ->with('suchakAccount')
            ->whereNotIn('payment_status', [
                SuchakCustomerPayment::STATUS_CANCELLED,
                SuchakCustomerPayment::STATUS_FAILED,
            ])
            ->where(function (Builder $risk): void {
                $risk->where('payment_mode', SuchakCustomerPayment::MODE_CASH)
                    ->orWhereIn('proof_status', [
                        SuchakCustomerPayment::PROOF_REQUIRED,
                        SuchakCustomerPayment::PROOF_SUBMITTED,
                        SuchakCustomerPayment::PROOF_REJECTED,
                    ]);
            });

        $records = (clone $query)
            ->latest('payment_received_at')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (SuchakCustomerPayment $payment): array => $this->record(
                'Customer Payment',
                (int) $payment->id,
                $payment->suchakAccount,
                $payment->proof_status,
                $payment->payment_received_at ?? $payment->created_at,
                'Cash or proof-sensitive customer payment requires verification.',
                [
                    'payment_mode' => $payment->payment_mode,
                    'payment_status' => $payment->payment_status,
                    'amount' => trim($payment->currency.' '.$payment->amount_received),
                ],
            ));

        return $this->panel(
            'cash_proof_risk',
            'Cash / Proof Risk',
            (clone $query)->count(),
            'medium',
            'Cash, pending proof, submitted proof, or rejected proof payment records.',
            route('admin.suchak.safety.index'),
            $records,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function directPaymentComplaintPanel(): array
    {
        $query = SuchakDispute::query()
            ->with(['suchakAccount', 'directPaymentEvidence'])
            ->where('dispute_type', SuchakDispute::TYPE_DIRECT_PAYMENT_REQUEST)
            ->whereIn('status', [SuchakDispute::STATUS_OPEN, SuchakDispute::STATUS_UNDER_REVIEW]);

        $records = (clone $query)
            ->latest('opened_at')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (SuchakDispute $dispute): array => $this->record(
                'Dispute',
                (int) $dispute->id,
                $dispute->suchakAccount,
                $dispute->status,
                $dispute->opened_at,
                $this->safeSummary($dispute->summary),
                [
                    'priority' => $dispute->priority,
                    'evidence_records' => (string) $dispute->directPaymentEvidence->count(),
                ],
            ));

        return $this->panel(
            'direct_payment_complaint_queue',
            'Direct Payment Complaint Queue',
            (clone $query)->count(),
            'urgent',
            'Customer-reported direct payment requests and related evidence records.',
            route('admin.suchak.safety.index', [
                'dispute_type' => SuchakDispute::TYPE_DIRECT_PAYMENT_REQUEST,
                'status' => SuchakDispute::STATUS_OPEN,
            ]),
            $records,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function qrPdfAbusePanel(): array
    {
        $query = SuchakQrToken::query()
            ->with('suchakAccount')
            ->where(function (Builder $risk): void {
                $risk->where('scan_count', '>=', 5)
                    ->orWhereNotNull('revoked_at');
            });

        $records = (clone $query)
            ->latest('last_scanned_at')
            ->latest('revoked_at')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (SuchakQrToken $token): array => $this->record(
                'QR Token',
                (int) $token->id,
                $token->suchakAccount,
                $token->revoked_at ? 'revoked' : 'high_scan',
                $token->last_scanned_at ?? $token->revoked_at ?? $token->created_at,
                $token->revoked_reason ?: 'QR token has high scan activity.',
                [
                    'scan_count' => (string) $token->scan_count,
                    'export_id' => (string) $token->export_id,
                ],
            ));

        return $this->panel(
            'qr_pdf_abuse_signals',
            'QR / PDF Abuse Signals',
            (clone $query)->count(),
            'medium',
            'QR/PDF records with high scan activity or revocation evidence.',
            route('admin.suchak.safety.index'),
            $records,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function growthSuspicionPanel(): array
    {
        $query = SuchakGrowthAttribution::query()
            ->with('suchakAccount')
            ->whereIn('attribution_source', [
                SuchakGrowthAttribution::SOURCE_COUPON_CODE,
                SuchakGrowthAttribution::SOURCE_REFERRAL_CODE,
            ])
            ->where(function (Builder $risk): void {
                $risk->where('fraud_status', SuchakGrowthAttribution::FRAUD_REVIEW_REQUIRED)
                    ->orWhere('attribution_status', SuchakGrowthAttribution::STATUS_REVIEW_REQUIRED)
                    ->orWhereNotNull('fraud_flags');
            });

        $records = (clone $query)
            ->latest('attributed_at')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (SuchakGrowthAttribution $attribution): array => $this->record(
                'Growth Attribution',
                (int) $attribution->id,
                $attribution->suchakAccount,
                $attribution->fraud_status,
                $attribution->attributed_at,
                $this->safeSummary($attribution->attribution_note ?: 'Coupon/referral attribution requires fraud review.'),
                [
                    'source' => $attribution->attribution_source,
                    'attribution_status' => $attribution->attribution_status,
                    'key' => $attribution->coupon_code ?: $attribution->referral_code ?: $attribution->attribution_key,
                ],
            ));

        return $this->panel(
            'coupon_referral_suspicious_signals',
            'Coupon / Referral Suspicious Signals',
            (clone $query)->count(),
            'medium',
            'Coupon and referral attributions flagged for review.',
            route('admin.suchak.safety.index'),
            $records,
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function panel(
        string $key,
        string $title,
        int $count,
        string $severity,
        string $description,
        string $queueUrl,
        Collection $records,
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'count' => $count,
            'severity' => $severity,
            'description' => $description,
            'queue_url' => $queueUrl,
            'records' => $records->values()->all(),
        ];
    }

    /**
     * @param  array<string, string>  $badges
     * @return array<string, mixed>
     */
    private function record(
        string $sourceType,
        int $sourceId,
        ?SuchakAccount $account,
        string $status,
        ?Carbon $occurredAt,
        string $summary,
        array $badges = [],
    ): array {
        return [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_label' => $sourceType.' #'.$sourceId,
            'suchak_account_id' => $account?->id,
            'suchak_name' => $account?->suchak_name ?: 'Unknown Suchak',
            'account_url' => $account ? route('admin.suchak.accounts.show', $account) : null,
            'status' => $this->label($status),
            'occurred_at' => $occurredAt,
            'summary' => $summary,
            'badges' => collect($badges)
                ->filter(fn (?string $value): bool => filled($value))
                ->map(fn (string $value, string $label): array => [
                    'label' => $this->label($label),
                    'value' => $this->label($value),
                ])
                ->values()
                ->all(),
        ];
    }

    private function safeSummary(?string $value): string
    {
        return Str::limit(trim((string) $value), 180, '');
    }

    private function label(string $value): string
    {
        return Str::headline($value);
    }
}
