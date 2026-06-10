<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakActivityLog;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerOverdueServiceAction;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakCustomerPaymentCorrection;
use App\Models\SuchakCustomerPaymentCorrectionEvent;
use App\Models\SuchakLedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakCustomerPaymentCorrectionService
{
    public function __construct(
        private readonly SuchakAccessService $accessService,
        private readonly SuchakPaymentCollectorResolver $paymentCollectorResolver,
        private readonly SuchakActivityLogger $activityLogger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function requestRefund(
        SuchakCustomerPayment $payment,
        User $actor,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerPaymentCorrection {
        $payment = $this->preparedPayment($payment, $actor);
        $amount = $this->correctionAmount(
            $attributes['amount'] ?? null,
            $this->remainingRefundableAmount($payment),
            'Suchak customer refund amount is invalid.',
            'Suchak customer refund amount cannot exceed remaining received amount.',
        );
        $reason = $this->requiredText($attributes['reason'] ?? null, 'Suchak customer refund reason is required.', 1000);

        return DB::transaction(function () use ($payment, $actor, $amount, $reason, $ipAddress, $userAgent): SuchakCustomerPaymentCorrection {
            $correction = SuchakCustomerPaymentCorrection::query()->create([
                'customer_payment_id' => $payment->id,
                'suchak_account_id' => $payment->suchak_account_id,
                'customer_context_id' => $payment->customer_context_id,
                'payment_request_id' => $payment->payment_request_id,
                'correction_type' => SuchakCustomerPaymentCorrection::TYPE_REFUND,
                'correction_status' => SuchakCustomerPaymentCorrection::STATUS_REQUESTED,
                'amount' => $amount,
                'currency' => $payment->currency,
                'reason' => $reason,
                'requested_by_user_id' => $actor->id,
                'requested_at' => now(),
            ]);

            $fresh = $correction->fresh($this->correctionRelations());
            $this->recordCorrectionEvent(
                $fresh,
                SuchakCustomerPaymentCorrectionEvent::EVENT_REFUND_REQUESTED,
                $actor,
                null,
                SuchakCustomerPaymentCorrection::STATUS_REQUESTED,
                $reason,
            );
            $this->recordCorrectionActivity(
                $fresh,
                SuchakActivityLog::ACTION_CUSTOMER_REFUND_REQUESTED,
                'customer_refund_requested',
                $actor,
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    public function approveRefund(
        SuchakCustomerPaymentCorrection $refund,
        User $actor,
        ?string $statusNote = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerPaymentCorrection {
        $refund = $this->preparedCorrection($refund, $actor, SuchakCustomerPaymentCorrection::TYPE_REFUND);

        if ($refund->correction_status !== SuchakCustomerPaymentCorrection::STATUS_REQUESTED) {
            throw new InvalidArgumentException('Only requested Suchak customer refunds can be approved.');
        }

        return DB::transaction(function () use ($refund, $actor, $statusNote, $ipAddress, $userAgent): SuchakCustomerPaymentCorrection {
            /** @var SuchakCustomerPaymentCorrection $locked */
            $locked = SuchakCustomerPaymentCorrection::query()
                ->whereKey($refund->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->correction_status !== SuchakCustomerPaymentCorrection::STATUS_REQUESTED) {
                throw new InvalidArgumentException('Only requested Suchak customer refunds can be approved.');
            }

            $fromStatus = $locked->correction_status;
            $locked->forceFill([
                'correction_status' => SuchakCustomerPaymentCorrection::STATUS_APPROVED,
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
                'status_note' => $this->limitedText($statusNote, 1000),
            ])->save();

            $fresh = $locked->fresh($this->correctionRelations());
            $this->recordCorrectionEvent(
                $fresh,
                SuchakCustomerPaymentCorrectionEvent::EVENT_REFUND_APPROVED,
                $actor,
                $fromStatus,
                SuchakCustomerPaymentCorrection::STATUS_APPROVED,
                $fresh->status_note,
            );
            $this->recordCorrectionActivity(
                $fresh,
                SuchakActivityLog::ACTION_CUSTOMER_REFUND_APPROVED,
                'customer_refund_approved',
                $actor,
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function markRefundPaid(
        SuchakCustomerPaymentCorrection $refund,
        User $actor,
        array $attributes = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerPaymentCorrection {
        $refund = $this->preparedCorrection($refund, $actor, SuchakCustomerPaymentCorrection::TYPE_REFUND);

        if ($refund->correction_status !== SuchakCustomerPaymentCorrection::STATUS_APPROVED) {
            throw new InvalidArgumentException('Only approved Suchak customer refunds can be marked paid.');
        }

        $statusNote = $this->limitedText($attributes['status_note'] ?? null, 1000);

        return DB::transaction(function () use ($refund, $actor, $statusNote, $ipAddress, $userAgent): SuchakCustomerPaymentCorrection {
            /** @var SuchakCustomerPaymentCorrection $locked */
            $locked = SuchakCustomerPaymentCorrection::query()
                ->whereKey($refund->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->correction_status !== SuchakCustomerPaymentCorrection::STATUS_APPROVED) {
                throw new InvalidArgumentException('Only approved Suchak customer refunds can be marked paid.');
            }

            $locked->loadMissing('customerPayment.paymentContext');
            $fromStatus = $locked->correction_status;
            $ledgerEntry = $this->createLedgerEntry(
                $locked->customerPayment,
                SuchakLedgerEntry::TYPE_CUSTOMER_REFUND_PAID,
                SuchakLedgerEntry::STATUS_PAID,
                $locked->amount,
                'Suchak customer refund correction #'.$locked->id.' paid by user #'.$actor->id.'.',
            );

            $locked->forceFill([
                'correction_status' => SuchakCustomerPaymentCorrection::STATUS_PAID,
                'ledger_entry_id' => $ledgerEntry->id,
                'paid_by_user_id' => $actor->id,
                'paid_at' => now(),
                'status_note' => $statusNote,
            ])->save();

            $fresh = $locked->fresh($this->correctionRelations());
            $this->recordCorrectionEvent(
                $fresh,
                SuchakCustomerPaymentCorrectionEvent::EVENT_REFUND_PAID,
                $actor,
                $fromStatus,
                SuchakCustomerPaymentCorrection::STATUS_PAID,
                $fresh->status_note,
            );
            $this->recordCorrectionActivity(
                $fresh,
                SuchakActivityLog::ACTION_CUSTOMER_REFUND_PAID,
                'customer_refund_paid',
                $actor,
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function postWaiver(
        SuchakCustomerPayment $payment,
        User $actor,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerPaymentCorrection {
        $payment = $this->preparedPayment($payment, $actor);
        $amount = $this->correctionAmount(
            $attributes['amount'] ?? null,
            $this->remainingWaivableAmount($payment),
            'Suchak customer waiver amount is invalid.',
            'Suchak customer waiver amount cannot exceed remaining balance.',
        );
        $reason = $this->requiredText($attributes['reason'] ?? null, 'Suchak customer waiver reason is required.', 1000);

        return $this->postImmediateCorrection(
            $payment,
            $actor,
            SuchakCustomerPaymentCorrection::TYPE_WAIVER,
            $amount,
            $reason,
            null,
            SuchakLedgerEntry::TYPE_CUSTOMER_WAIVER_POSTED,
            SuchakLedgerEntry::STATUS_WAIVED,
            SuchakCustomerPaymentCorrectionEvent::EVENT_WAIVER_POSTED,
            SuchakActivityLog::ACTION_CUSTOMER_WAIVER_POSTED,
            'customer_waiver_posted',
            $ipAddress,
            $userAgent,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function issueCreditNote(
        SuchakCustomerPayment $payment,
        User $actor,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerPaymentCorrection {
        $payment = $this->preparedPayment($payment, $actor);
        $amount = $this->correctionAmount(
            $attributes['amount'] ?? null,
            $this->remainingCreditNoteAmount($payment),
            'Suchak customer credit note amount is invalid.',
            'Suchak customer credit note amount cannot exceed remaining received amount.',
        );
        $reason = $this->requiredText($attributes['reason'] ?? null, 'Suchak customer credit note reason is required.', 1000);

        return $this->postImmediateCorrection(
            $payment,
            $actor,
            SuchakCustomerPaymentCorrection::TYPE_CREDIT_NOTE,
            $amount,
            $reason,
            null,
            SuchakLedgerEntry::TYPE_CUSTOMER_CREDIT_NOTE_ISSUED,
            SuchakLedgerEntry::STATUS_ADJUSTED,
            SuchakCustomerPaymentCorrectionEvent::EVENT_CREDIT_NOTE_ISSUED,
            SuchakActivityLog::ACTION_CUSTOMER_CREDIT_NOTE_ISSUED,
            'customer_credit_note_issued',
            $ipAddress,
            $userAgent,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function postReversal(
        SuchakCustomerPayment $payment,
        User $actor,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerPaymentCorrection {
        $payment = $this->preparedPayment($payment, $actor);
        $amount = $this->correctionAmount(
            $attributes['amount'] ?? null,
            $this->remainingReversibleAmount($payment),
            'Suchak customer reversal amount is invalid.',
            'Suchak customer reversal amount cannot exceed remaining received amount.',
        );
        $reason = $this->requiredText($attributes['reason'] ?? null, 'Suchak customer reversal reason is required.', 1000);

        return $this->postImmediateCorrection(
            $payment,
            $actor,
            SuchakCustomerPaymentCorrection::TYPE_REVERSAL,
            $amount,
            $reason,
            null,
            SuchakLedgerEntry::TYPE_CUSTOMER_PAYMENT_REVERSAL,
            SuchakLedgerEntry::STATUS_ADJUSTED,
            SuchakCustomerPaymentCorrectionEvent::EVENT_REVERSAL_POSTED,
            SuchakActivityLog::ACTION_CUSTOMER_PAYMENT_REVERSAL_POSTED,
            'customer_payment_reversal_posted',
            $ipAddress,
            $userAgent,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function openOverdueServiceAction(
        SuchakCustomerPayment $payment,
        User $actor,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerOverdueServiceAction {
        $payment = $this->preparedPayment($payment, $actor);
        $dueAmount = (float) $payment->balance_amount;
        if ($dueAmount <= 0.0 || $payment->payment_status === SuchakCustomerPayment::STATUS_PAID) {
            throw new InvalidArgumentException('Only pending or partially paid Suchak customer payments can open overdue service actions.');
        }

        $actionType = $this->allowedValue(
            $attributes['action_type'] ?? SuchakCustomerOverdueServiceAction::TYPE_PAYMENT_FOLLOWUP,
            SuchakCustomerOverdueServiceAction::TYPES,
            'Suchak customer overdue action type is invalid.',
        );
        $reason = $this->requiredText($attributes['reason'] ?? null, 'Suchak customer overdue action reason is required.', 1000);

        return DB::transaction(function () use ($payment, $actor, $actionType, $reason, $dueAmount, $ipAddress, $userAgent): SuchakCustomerOverdueServiceAction {
            $action = SuchakCustomerOverdueServiceAction::query()->create([
                'customer_payment_id' => $payment->id,
                'suchak_account_id' => $payment->suchak_account_id,
                'customer_context_id' => $payment->customer_context_id,
                'payment_request_id' => $payment->payment_request_id,
                'action_type' => $actionType,
                'action_status' => SuchakCustomerOverdueServiceAction::STATUS_OPEN,
                'action_policy' => SuchakCustomerOverdueServiceAction::POLICY_SUCHAK_SERVICE_ONLY,
                'due_amount' => number_format($dueAmount, 2, '.', ''),
                'currency' => $payment->currency,
                'reason' => $reason,
                'created_by_user_id' => $actor->id,
            ]);

            $fresh = $action->fresh($this->overdueRelations());
            $this->recordOverdueActivity(
                $fresh,
                SuchakActivityLog::ACTION_CUSTOMER_OVERDUE_ACTION_OPENED,
                'customer_overdue_action_opened',
                $actor,
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    public function resolveOverdueServiceAction(
        SuchakCustomerOverdueServiceAction $action,
        User $actor,
        string $resolutionNote,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerOverdueServiceAction {
        $action->refresh()->loadMissing($this->overdueRelations());
        $this->assertOwnerCanCorrect($action->suchakAccount, $actor);
        $note = $this->requiredText($resolutionNote, 'Suchak customer overdue action resolution note is required.', 1000);

        if ($action->action_status !== SuchakCustomerOverdueServiceAction::STATUS_OPEN) {
            throw new InvalidArgumentException('Only open Suchak customer overdue actions can be resolved.');
        }

        return DB::transaction(function () use ($action, $actor, $note, $ipAddress, $userAgent): SuchakCustomerOverdueServiceAction {
            /** @var SuchakCustomerOverdueServiceAction $locked */
            $locked = SuchakCustomerOverdueServiceAction::query()
                ->whereKey($action->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->action_status !== SuchakCustomerOverdueServiceAction::STATUS_OPEN) {
                throw new InvalidArgumentException('Only open Suchak customer overdue actions can be resolved.');
            }

            $locked->forceFill([
                'action_status' => SuchakCustomerOverdueServiceAction::STATUS_RESOLVED,
                'resolved_by_user_id' => $actor->id,
                'resolved_at' => now(),
                'resolution_note' => $note,
            ])->save();

            $fresh = $locked->fresh($this->overdueRelations());
            $this->recordOverdueActivity(
                $fresh,
                SuchakActivityLog::ACTION_CUSTOMER_OVERDUE_ACTION_RESOLVED,
                'customer_overdue_action_resolved',
                $actor,
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    /**
     * @param  array{document_number: string, fy_label: string, sequence_no: int}|null  $document
     */
    private function postImmediateCorrection(
        SuchakCustomerPayment $payment,
        User $actor,
        string $type,
        string $amount,
        string $reason,
        ?array $document,
        string $ledgerType,
        string $ledgerStatus,
        string $eventType,
        string $activityType,
        string $activityContext,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakCustomerPaymentCorrection {
        return DB::transaction(function () use (
            $payment,
            $actor,
            $type,
            $amount,
            $reason,
            $document,
            $ledgerType,
            $ledgerStatus,
            $eventType,
            $activityType,
            $activityContext,
            $ipAddress,
            $userAgent,
        ): SuchakCustomerPaymentCorrection {
            if ($document === null && in_array($type, [
                SuchakCustomerPaymentCorrection::TYPE_CREDIT_NOTE,
                SuchakCustomerPaymentCorrection::TYPE_REVERSAL,
            ], true)) {
                $document = $this->nextCorrectionDocument($type);
            }

            $ledgerEntry = $this->createLedgerEntry(
                $payment,
                $ledgerType,
                $ledgerStatus,
                $amount,
                'Suchak customer '.$type.' correction posted by user #'.$actor->id.'.',
            );

            $correction = SuchakCustomerPaymentCorrection::query()->create([
                'customer_payment_id' => $payment->id,
                'suchak_account_id' => $payment->suchak_account_id,
                'customer_context_id' => $payment->customer_context_id,
                'payment_request_id' => $payment->payment_request_id,
                'ledger_entry_id' => $ledgerEntry->id,
                'correction_type' => $type,
                'correction_status' => SuchakCustomerPaymentCorrection::STATUS_POSTED,
                'amount' => $amount,
                'currency' => $payment->currency,
                'reason' => $reason,
                'document_number' => $document['document_number'] ?? null,
                'fy_label' => $document['fy_label'] ?? null,
                'sequence_no' => $document['sequence_no'] ?? null,
                'posted_by_user_id' => $actor->id,
                'posted_at' => now(),
            ]);

            $fresh = $correction->fresh($this->correctionRelations());
            $this->recordCorrectionEvent(
                $fresh,
                $eventType,
                $actor,
                null,
                SuchakCustomerPaymentCorrection::STATUS_POSTED,
                $reason,
            );
            $this->recordCorrectionActivity(
                $fresh,
                $activityType,
                $activityContext,
                $actor,
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    private function preparedPayment(SuchakCustomerPayment $payment, User $actor): SuchakCustomerPayment
    {
        $payment->refresh()->loadMissing([
            'suchakAccount',
            'customerContext',
            'paymentRequest',
            'paymentContext',
            'ledgerEntry',
            'corrections',
        ]);

        $this->assertOwnerCanCorrect($payment->suchakAccount, $actor);
        $this->paymentCollectorResolver->assertAllowsDirectSuchakCollection($payment->paymentContext);

        return $payment;
    }

    private function preparedCorrection(
        SuchakCustomerPaymentCorrection $correction,
        User $actor,
        string $expectedType,
    ): SuchakCustomerPaymentCorrection {
        $correction->refresh()->loadMissing($this->correctionRelations());
        $this->assertOwnerCanCorrect($correction->suchakAccount, $actor);
        $this->paymentCollectorResolver->assertAllowsDirectSuchakCollection($correction->customerPayment->paymentContext);

        if ($correction->correction_type !== $expectedType) {
            throw new InvalidArgumentException('Suchak customer payment correction type mismatch.');
        }

        return $correction;
    }

    private function assertOwnerCanCorrect(SuchakAccount $account, User $actor): void
    {
        $this->accessService->assertOwnerCanOperate(
            $account,
            $actor,
            'Only the owning Suchak account can manage customer payment corrections.',
            'Only verified Suchak accounts can manage customer payment corrections.',
        );
    }

    private function createLedgerEntry(
        SuchakCustomerPayment $payment,
        string $ledgerType,
        string $ledgerStatus,
        string $amount,
        string $note,
    ): SuchakLedgerEntry {
        $payment->loadMissing('paymentContext');

        return SuchakLedgerEntry::query()->create([
            'suchak_account_id' => $payment->suchak_account_id,
            'matrimony_profile_id' => $payment->paymentContext->matrimony_profile_id,
            'pipeline_id' => $payment->paymentContext->pipeline_id,
            'collaboration_request_id' => $payment->paymentContext->collaboration_request_id,
            'payment_context_id' => $payment->payment_context_id,
            'entry_type' => $ledgerType,
            'amount' => $amount,
            'currency' => $payment->currency,
            'status' => $ledgerStatus,
            'note' => $note,
        ]);
    }

    /**
     * @return array{document_number: string, fy_label: string, sequence_no: int}
     */
    private function nextCorrectionDocument(string $type): array
    {
        [$fyLabel, $nextSeq] = $this->nextCorrectionSequence($type);
        $number = $this->correctionPrefix($type).'/'.$fyLabel.'/'.str_pad((string) $nextSeq, 6, '0', STR_PAD_LEFT);

        return [
            'document_number' => $number,
            'fy_label' => $fyLabel,
            'sequence_no' => $nextSeq,
        ];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function nextCorrectionSequence(string $type): array
    {
        $now = now();
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');
        $fyStart = $month >= 4 ? $year : ($year - 1);
        $fyEnd = $fyStart + 1;
        $fyLabel = substr((string) $fyStart, -2).'-'.substr((string) $fyEnd, -2);

        $lastSeq = (int) SuchakCustomerPaymentCorrection::query()
            ->where('correction_type', $type)
            ->where('fy_label', $fyLabel)
            ->lockForUpdate()
            ->orderByDesc('sequence_no')
            ->value('sequence_no');

        return [$fyLabel, $lastSeq + 1];
    }

    private function correctionPrefix(string $type): string
    {
        return match ($type) {
            SuchakCustomerPaymentCorrection::TYPE_CREDIT_NOTE => 'SUCHAK-CUST-CN',
            SuchakCustomerPaymentCorrection::TYPE_REVERSAL => 'SUCHAK-CUST-REV',
            default => throw new InvalidArgumentException('Suchak customer correction document type is invalid.'),
        };
    }

    private function remainingRefundableAmount(SuchakCustomerPayment $payment): float
    {
        $reservedRefunds = $this->correctionSum($payment, [SuchakCustomerPaymentCorrection::TYPE_REFUND], [
            SuchakCustomerPaymentCorrection::STATUS_REQUESTED,
            SuchakCustomerPaymentCorrection::STATUS_APPROVED,
            SuchakCustomerPaymentCorrection::STATUS_PAID,
        ]);

        return max(0.0, (float) $payment->amount_received - $reservedRefunds);
    }

    private function remainingWaivableAmount(SuchakCustomerPayment $payment): float
    {
        $postedWaivers = $this->correctionSum($payment, [SuchakCustomerPaymentCorrection::TYPE_WAIVER], [
            SuchakCustomerPaymentCorrection::STATUS_POSTED,
        ]);

        return max(0.0, (float) $payment->balance_amount - $postedWaivers);
    }

    private function remainingCreditNoteAmount(SuchakCustomerPayment $payment): float
    {
        $posted = $this->correctionSum($payment, [SuchakCustomerPaymentCorrection::TYPE_CREDIT_NOTE], [
            SuchakCustomerPaymentCorrection::STATUS_POSTED,
        ]);

        return max(0.0, (float) $payment->amount_received - $posted);
    }

    private function remainingReversibleAmount(SuchakCustomerPayment $payment): float
    {
        $posted = $this->correctionSum($payment, [SuchakCustomerPaymentCorrection::TYPE_REVERSAL], [
            SuchakCustomerPaymentCorrection::STATUS_POSTED,
        ]);

        return max(0.0, (float) $payment->amount_received - $posted);
    }

    /**
     * @param  array<int, string>  $types
     * @param  array<int, string>  $statuses
     */
    private function correctionSum(SuchakCustomerPayment $payment, array $types, array $statuses): float
    {
        return (float) SuchakCustomerPaymentCorrection::query()
            ->where('customer_payment_id', $payment->id)
            ->whereIn('correction_type', $types)
            ->whereIn('correction_status', $statuses)
            ->sum('amount');
    }

    private function correctionAmount(mixed $value, float $max, string $invalidMessage, string $maxMessage): string
    {
        if ($value === null || $value === '' || ! is_numeric($value) || (float) $value <= 0.0) {
            throw new InvalidArgumentException($invalidMessage);
        }

        if ($max <= 0.0 || (float) $value > $max) {
            throw new InvalidArgumentException($maxMessage);
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function allowedValue(mixed $value, array $allowed, string $message): string
    {
        $normalized = trim((string) ($value ?? ''));
        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    private function requiredText(mixed $value, string $message, int $limit): string
    {
        $normalized = $this->limitedText($value, $limit);
        if ($normalized === null) {
            throw new InvalidArgumentException($message);
        }
        $this->assertNoPrivateContactText($normalized);

        return $normalized;
    }

    private function limitedText(mixed $value, int $limit): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === ''
            ? null
            : Str::limit($normalized, $limit, '');
    }

    private function assertNoPrivateContactText(string $value): void
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value) === 1
            || preg_match('/(?<!\d)(?:\+?91[\s-]*)?[6-9]\d(?:[\s-]?\d){8}(?!\d)/', $value) === 1) {
            throw new InvalidArgumentException('Suchak customer payment corrections must not store private contact details.');
        }
    }

    private function recordCorrectionEvent(
        SuchakCustomerPaymentCorrection $correction,
        string $eventType,
        User $actor,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $note,
    ): void {
        SuchakCustomerPaymentCorrectionEvent::query()->create([
            'payment_correction_id' => $correction->id,
            'suchak_account_id' => $correction->suchak_account_id,
            'event_type' => $eventType,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'actor_user_id' => $actor->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'event_note' => $note,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function recordCorrectionActivity(
        SuchakCustomerPaymentCorrection $correction,
        string $actionType,
        string $context,
        User $actor,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $correction->loadMissing('customerPayment.paymentContext');

        $this->activityLogger->record([
            'suchak_account_id' => $correction->suchak_account_id,
            'actor_user_id' => $actor->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => $actionType,
            'target_type' => 'suchak_customer_payment_correction',
            'target_id' => $correction->id,
            'matrimony_profile_id' => $correction->customerPayment->paymentContext?->matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => [
                'context' => $context,
                'customer_payment_id' => $correction->customer_payment_id,
                'customer_context_id' => $correction->customer_context_id,
                'payment_request_id' => $correction->payment_request_id,
                'ledger_entry_id' => $correction->ledger_entry_id,
                'correction_type' => $correction->correction_type,
                'correction_status' => $correction->correction_status,
                'amount' => $correction->amount,
                'currency' => $correction->currency,
                'document_number' => $correction->document_number,
            ],
        ]);
    }

    private function recordOverdueActivity(
        SuchakCustomerOverdueServiceAction $action,
        string $actionType,
        string $context,
        User $actor,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $action->loadMissing('customerPayment.paymentContext');

        $this->activityLogger->record([
            'suchak_account_id' => $action->suchak_account_id,
            'actor_user_id' => $actor->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => $actionType,
            'target_type' => 'suchak_customer_overdue_service_action',
            'target_id' => $action->id,
            'matrimony_profile_id' => $action->customerPayment->paymentContext?->matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => [
                'context' => $context,
                'customer_payment_id' => $action->customer_payment_id,
                'customer_context_id' => $action->customer_context_id,
                'payment_request_id' => $action->payment_request_id,
                'action_type' => $action->action_type,
                'action_status' => $action->action_status,
                'action_policy' => $action->action_policy,
                'due_amount' => $action->due_amount,
                'currency' => $action->currency,
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function correctionRelations(): array
    {
        return [
            'suchakAccount',
            'customerContext',
            'customerPayment.paymentContext',
            'paymentRequest',
            'ledgerEntry',
            'events',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function overdueRelations(): array
    {
        return [
            'suchakAccount',
            'customerContext',
            'customerPayment.paymentContext',
            'paymentRequest',
            'createdByUser',
            'resolvedByUser',
        ];
    }
}
