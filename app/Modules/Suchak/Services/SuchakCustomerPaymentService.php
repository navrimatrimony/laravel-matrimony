<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakCustomerPaymentDocument;
use App\Models\SuchakCustomerPaymentEvent;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakCustomerPaymentService
{
    public function __construct(
        private readonly SuchakAccessService $accessService,
        private readonly SuchakAgreementService $agreementService,
        private readonly SuchakPaymentCollectorResolver $paymentCollectorResolver,
        private readonly SuchakActivityLogger $activityLogger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{customer_payment: SuchakCustomerPayment, invoice: SuchakCustomerPaymentDocument, receipt: ?SuchakCustomerPaymentDocument, receipt_verification_url: ?string}
     */
    public function recordManualPayment(
        SuchakPaymentRequest $paymentRequest,
        User $actor,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $paymentRequest->refresh()->loadMissing([
            'suchakAccount',
            'customerContext',
            'servicePackage',
            'customerAgreement',
            'paymentContext',
        ]);

        $this->accessService->assertOwnerCanOperate(
            $paymentRequest->suchakAccount,
            $actor,
            'Only the owning Suchak account can record customer payments.',
            'Only verified Suchak accounts can record customer payments.',
        );
        $this->assertPaymentRequestReady($paymentRequest);

        /** @var SuchakCustomerAgreement $agreement */
        $agreement = $paymentRequest->customerAgreement;
        $this->agreementService->assertAgreementAllowsPaymentRequest($agreement);
        $this->paymentCollectorResolver->assertAllowsDirectSuchakCollection($paymentRequest->paymentContext);

        $mode = $this->allowedValue(
            $attributes['payment_mode'] ?? null,
            SuchakCustomerPayment::PAYMENT_MODES,
            'Suchak customer payment mode is invalid.',
        );
        $amountDue = $this->requiredAmount($attributes['amount_due'] ?? $paymentRequest->amount_due ?? $agreement->price_amount);
        $amountReceived = $this->receivedAmount($attributes['amount_received'] ?? null);
        if ((float) $amountReceived > (float) $amountDue) {
            throw new InvalidArgumentException('Suchak customer payment received amount cannot exceed amount due.');
        }

        $currency = $this->currency($attributes['currency'] ?? $paymentRequest->currency ?? $agreement->currency ?? 'INR');
        $paymentReference = $this->limitedText($attributes['payment_reference'] ?? null, 160);
        $proofDocumentPath = $this->limitedText($attributes['proof_document_path'] ?? null, 500);
        $proofNote = $this->limitedText($attributes['proof_note'] ?? null, 1000);
        $collectionNote = $this->limitedText($attributes['collection_note'] ?? null, 1000);
        foreach ([$paymentReference, $proofDocumentPath, $proofNote, $collectionNote] as $text) {
            if ($text !== null) {
                $this->assertNoPrivateContactText($text);
            }
        }

        $paymentReceivedAt = $this->paymentReceivedAt($attributes['payment_received_at'] ?? null, $amountReceived);
        $paymentStatus = $this->paymentStatus($amountDue, $amountReceived);
        $proofStatus = $this->proofStatus($mode, $amountReceived, $paymentReference, $proofDocumentPath, $collectionNote);
        $balanceAmount = number_format(max(0, (float) $amountDue - (float) $amountReceived), 2, '.', '');

        return DB::transaction(function () use (
            $paymentRequest,
            $actor,
            $mode,
            $amountDue,
            $amountReceived,
            $balanceAmount,
            $currency,
            $paymentReceivedAt,
            $paymentReference,
            $proofStatus,
            $proofDocumentPath,
            $proofNote,
            $collectionNote,
            $paymentStatus,
            $ipAddress,
            $userAgent,
        ): array {
            $customerPayment = SuchakCustomerPayment::query()->create([
                'suchak_account_id' => $paymentRequest->suchak_account_id,
                'customer_context_id' => $paymentRequest->customer_context_id,
                'service_package_id' => $paymentRequest->service_package_id,
                'customer_agreement_id' => $paymentRequest->customer_agreement_id,
                'payment_context_id' => $paymentRequest->payment_context_id,
                'payment_request_id' => $paymentRequest->id,
                'recorded_by_user_id' => $actor->id,
                'collection_channel' => SuchakCustomerPayment::CHANNEL_SUCHAK_DIRECT,
                'payment_mode' => $mode,
                'payment_status' => $paymentStatus,
                'amount_due' => $amountDue,
                'amount_received' => $amountReceived,
                'balance_amount' => $balanceAmount,
                'currency' => $currency,
                'payment_received_at' => $paymentReceivedAt,
                'payment_reference' => $paymentReference,
                'proof_status' => $proofStatus,
                'proof_document_path' => $proofDocumentPath,
                'proof_note' => $proofNote,
                'collection_note' => $collectionNote,
            ]);

            $ledgerEntry = $this->createLedgerEntry($customerPayment, $paymentRequest, $paymentReceivedAt, $actor);
            $customerPayment->forceFill(['ledger_entry_id' => $ledgerEntry->id])->save();

            $fromRequestStatus = $paymentRequest->payment_status;
            $paymentRequest->forceFill(['payment_status' => $this->requestStatusForPayment($paymentStatus)])->save();

            $fresh = $customerPayment->fresh($this->paymentRelations());
            $this->recordPaymentEvent(
                $fresh,
                SuchakCustomerPaymentEvent::EVENT_PAYMENT_RECORDED,
                $actor,
                null,
                $fresh->payment_status,
                'Suchak customer manual payment recorded.',
            );
            $this->recordPaymentActivity(
                $fresh,
                SuchakActivityLog::ACTION_CUSTOMER_PAYMENT_RECORDED,
                'customer_payment_recorded',
                $actor,
                $ipAddress,
                $userAgent,
                [
                    'payment_request_from_status' => $fromRequestStatus,
                    'payment_request_to_status' => $paymentRequest->payment_status,
                ],
            );

            $invoice = $this->issueDocument($fresh, $actor, SuchakCustomerPaymentDocument::TYPE_INVOICE, null);
            $receipt = null;
            if ((float) $fresh->amount_received > 0.0) {
                $receipt = $this->issueDocument($fresh, $actor, SuchakCustomerPaymentDocument::TYPE_RECEIPT, $this->uniqueReceiptVerificationCode());
            }

            $fresh = $fresh->fresh($this->paymentRelations());

            return [
                'customer_payment' => $fresh,
                'invoice' => $invoice,
                'receipt' => $receipt,
                'receipt_verification_url' => $receipt?->verification_code === null
                    ? null
                    : $this->receiptVerificationUrl($receipt->verification_code),
            ];
        });
    }

    public function receiptByVerificationCode(string $verificationCode): SuchakCustomerPaymentDocument
    {
        $code = trim($verificationCode);
        if (! preg_match('/^[A-Za-z0-9]{32}$/', $code)) {
            throw new InvalidArgumentException('Suchak receipt verification code is invalid.');
        }

        /** @var SuchakCustomerPaymentDocument|null $document */
        $document = SuchakCustomerPaymentDocument::query()
            ->where('document_type', SuchakCustomerPaymentDocument::TYPE_RECEIPT)
            ->where('verification_code', $code)
            ->with([
                'customerPayment.suchakAccount',
                'customerPayment.customerContext',
                'customerPayment.servicePackage',
                'customerPayment.customerAgreement',
            ])
            ->first();

        if ($document === null) {
            throw new InvalidArgumentException('Suchak receipt verification code was not found.');
        }

        return $document;
    }

    public function receiptVerificationUrl(string $verificationCode): string
    {
        return route('suchak.receipts.verify', ['code' => $verificationCode], true);
    }

    private function assertPaymentRequestReady(SuchakPaymentRequest $paymentRequest): void
    {
        if (! in_array($paymentRequest->payment_status, [
            SuchakPaymentRequest::STATUS_SENT,
            SuchakPaymentRequest::STATUS_OPENED,
            SuchakPaymentRequest::STATUS_PENDING,
            SuchakPaymentRequest::STATUS_PARTIALLY_PAID,
        ], true)) {
            throw new InvalidArgumentException('Only active Suchak payment requests can record customer payments.');
        }

        if ($paymentRequest->hasExpired()) {
            throw new InvalidArgumentException('Expired Suchak payment requests cannot record customer payments.');
        }

        if ($paymentRequest->customer_context_id === null || $paymentRequest->payment_context_id === null) {
            throw new InvalidArgumentException('Suchak customer payments require structured payment request context.');
        }

        $paymentContext = $paymentRequest->paymentContext;
        if (! $paymentContext instanceof SuchakPaymentContext || $paymentContext->context_status !== SuchakPaymentContext::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Suchak customer payment context must be active.');
        }
    }

    private function createLedgerEntry(
        SuchakCustomerPayment $payment,
        SuchakPaymentRequest $paymentRequest,
        ?Carbon $paymentReceivedAt,
        User $actor,
    ): SuchakLedgerEntry {
        $paymentRequest->loadMissing('paymentContext');
        $ledgerAmount = (float) $payment->amount_received > 0.0
            ? $payment->amount_received
            : $payment->amount_due;
        $status = (float) $payment->amount_received > 0.0
            ? SuchakLedgerEntry::STATUS_PAID
            : SuchakLedgerEntry::STATUS_DUE;

        return SuchakLedgerEntry::query()->create([
            'suchak_account_id' => $payment->suchak_account_id,
            'matrimony_profile_id' => $paymentRequest->paymentContext->matrimony_profile_id,
            'pipeline_id' => $paymentRequest->paymentContext->pipeline_id,
            'collaboration_request_id' => $paymentRequest->paymentContext->collaboration_request_id,
            'payment_context_id' => $payment->payment_context_id,
            'entry_type' => SuchakLedgerEntry::TYPE_CUSTOMER_PAYMENT_RECORDED,
            'amount' => $ledgerAmount,
            'currency' => $payment->currency,
            'status' => $status,
            'due_date' => null,
            'paid_at' => $status === SuchakLedgerEntry::STATUS_PAID ? $paymentReceivedAt : null,
            'note' => 'Linked Suchak customer payment #'.$payment->id.' recorded by user #'.$actor->id.'.',
        ]);
    }

    private function issueDocument(
        SuchakCustomerPayment $payment,
        User $actor,
        string $documentType,
        ?string $verificationCode,
    ): SuchakCustomerPaymentDocument {
        [$fyLabel, $nextSeq] = $this->nextDocumentSequence($documentType);
        $number = $this->documentPrefix($documentType).'/'.$fyLabel.'/'.str_pad((string) $nextSeq, 6, '0', STR_PAD_LEFT);

        $document = SuchakCustomerPaymentDocument::query()->create([
            'customer_payment_id' => $payment->id,
            'suchak_account_id' => $payment->suchak_account_id,
            'customer_context_id' => $payment->customer_context_id,
            'document_type' => $documentType,
            'document_number' => $number,
            'fy_label' => $fyLabel,
            'sequence_no' => $nextSeq,
            'verification_code' => $verificationCode,
            'issued_by_user_id' => $actor->id,
            'issued_at' => now(),
        ]);

        $fresh = $payment->fresh($this->paymentRelations());
        $this->recordPaymentEvent(
            $fresh,
            SuchakCustomerPaymentEvent::EVENT_PAYMENT_DOCUMENT_ISSUED,
            $actor,
            $fresh->payment_status,
            $fresh->payment_status,
            $documentType.' document issued: '.$number,
        );
        $this->recordPaymentActivity(
            $fresh,
            SuchakActivityLog::ACTION_CUSTOMER_PAYMENT_DOCUMENT_ISSUED,
            'customer_payment_document_issued',
            $actor,
            null,
            null,
            [
                'document_type' => $documentType,
                'document_number' => $number,
                'has_verification_code' => $verificationCode !== null,
            ],
        );

        return $document->fresh(['customerPayment']);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function nextDocumentSequence(string $documentType): array
    {
        $now = now();
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');
        $fyStart = $month >= 4 ? $year : ($year - 1);
        $fyEnd = $fyStart + 1;
        $fyLabel = substr((string) $fyStart, -2).'-'.substr((string) $fyEnd, -2);

        $lastSeq = (int) SuchakCustomerPaymentDocument::query()
            ->where('document_type', $documentType)
            ->where('fy_label', $fyLabel)
            ->lockForUpdate()
            ->max('sequence_no');

        return [$fyLabel, $lastSeq + 1];
    }

    private function documentPrefix(string $documentType): string
    {
        return match ($documentType) {
            SuchakCustomerPaymentDocument::TYPE_INVOICE => 'SUCHAK-CUST-INV',
            SuchakCustomerPaymentDocument::TYPE_RECEIPT => 'SUCHAK-CUST-RCP',
            default => throw new InvalidArgumentException('Suchak customer payment document type is invalid.'),
        };
    }

    private function requestStatusForPayment(string $paymentStatus): string
    {
        return match ($paymentStatus) {
            SuchakCustomerPayment::STATUS_PAID => SuchakPaymentRequest::STATUS_PAID,
            SuchakCustomerPayment::STATUS_PARTIALLY_PAID => SuchakPaymentRequest::STATUS_PARTIALLY_PAID,
            default => SuchakPaymentRequest::STATUS_PENDING,
        };
    }

    private function paymentStatus(string $amountDue, string $amountReceived): string
    {
        if ((float) $amountReceived <= 0.0) {
            return SuchakCustomerPayment::STATUS_PENDING;
        }

        if ((float) $amountReceived < (float) $amountDue) {
            return SuchakCustomerPayment::STATUS_PARTIALLY_PAID;
        }

        return SuchakCustomerPayment::STATUS_PAID;
    }

    private function proofStatus(
        string $mode,
        string $amountReceived,
        ?string $paymentReference,
        ?string $proofDocumentPath,
        ?string $collectionNote,
    ): string {
        if ((float) $amountReceived <= 0.0) {
            return SuchakCustomerPayment::PROOF_NOT_REQUIRED;
        }

        if ($mode === SuchakCustomerPayment::MODE_CASH) {
            if ($collectionNote === null) {
                throw new InvalidArgumentException('Cash Suchak customer payment requires a collection note.');
            }

            return SuchakCustomerPayment::PROOF_NOT_REQUIRED;
        }

        if ($paymentReference === null && $proofDocumentPath === null) {
            throw new InvalidArgumentException('UPI, bank transfer, and cheque Suchak customer payments require proof reference or proof document path.');
        }

        return SuchakCustomerPayment::PROOF_SUBMITTED;
    }

    private function requiredAmount(mixed $value): string
    {
        if ($value === null || $value === '' || ! is_numeric($value) || (float) $value <= 0) {
            throw new InvalidArgumentException('Suchak customer payment amount due must be a positive number.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function receivedAmount(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException('Suchak customer payment received amount must be a non-negative number.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function currency(mixed $value): string
    {
        $currency = strtoupper(trim((string) ($value ?? 'INR')));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Suchak customer payment currency must be a three-letter code.');
        }

        return $currency;
    }

    private function paymentReceivedAt(mixed $value, string $amountReceived): ?Carbon
    {
        if ((float) $amountReceived <= 0.0) {
            return null;
        }

        $receivedAt = $value === null || $value === ''
            ? now()
            : Carbon::parse($value);

        if ($receivedAt->isFuture()) {
            throw new InvalidArgumentException('Suchak customer payment received date cannot be in the future.');
        }

        return $receivedAt;
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
            throw new InvalidArgumentException('Suchak customer payment records must not store private contact details.');
        }
    }

    private function uniqueReceiptVerificationCode(): string
    {
        do {
            $code = Str::random(32);
        } while (SuchakCustomerPaymentDocument::query()->where('verification_code', $code)->exists());

        return $code;
    }

    private function recordPaymentEvent(
        SuchakCustomerPayment $payment,
        string $eventType,
        User $actor,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $note,
    ): void {
        SuchakCustomerPaymentEvent::query()->create([
            'customer_payment_id' => $payment->id,
            'suchak_account_id' => $payment->suchak_account_id,
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

    /**
     * @param  array<string, mixed>  $extra
     */
    private function recordPaymentActivity(
        SuchakCustomerPayment $payment,
        string $actionType,
        string $context,
        User $actor,
        ?string $ipAddress,
        ?string $userAgent,
        array $extra = [],
    ): void {
        $payment->loadMissing('paymentContext');

        $this->activityLogger->record([
            'suchak_account_id' => $payment->suchak_account_id,
            'actor_user_id' => $actor->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => $actionType,
            'target_type' => 'suchak_customer_payment',
            'target_id' => $payment->id,
            'matrimony_profile_id' => $payment->paymentContext?->matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => array_merge([
                'context' => $context,
                'customer_context_id' => $payment->customer_context_id,
                'service_package_id' => $payment->service_package_id,
                'customer_agreement_id' => $payment->customer_agreement_id,
                'payment_context_id' => $payment->payment_context_id,
                'payment_request_id' => $payment->payment_request_id,
                'ledger_entry_id' => $payment->ledger_entry_id,
                'collection_channel' => $payment->collection_channel,
                'payment_mode' => $payment->payment_mode,
                'payment_status' => $payment->payment_status,
                'proof_status' => $payment->proof_status,
                'amount_due' => $payment->amount_due,
                'amount_received' => $payment->amount_received,
                'balance_amount' => $payment->balance_amount,
                'currency' => $payment->currency,
            ], $extra),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function paymentRelations(): array
    {
        return [
            'suchakAccount',
            'customerContext',
            'servicePackage',
            'customerAgreement',
            'paymentContext',
            'paymentRequest',
            'ledgerEntry',
            'documents',
            'events',
        ];
    }
}
