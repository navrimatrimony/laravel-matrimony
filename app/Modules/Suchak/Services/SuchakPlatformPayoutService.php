<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\SuchakActivityLog;
use App\Models\SuchakAccount;
use App\Models\SuchakFeatureSuspension;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPlatformPayout;
use App\Models\SuchakPlatformPayoutDetail;
use App\Models\SuchakPlatformPayoutEvent;
use App\Models\SuchakPlatformPayoutSettlement;
use App\Models\SuchakPlatformPayoutSettlementLine;
use App\Models\SuchakPayoutHold;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakPlatformPayoutService
{
    public function __construct(
        private readonly SuchakAccessService $accessService,
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakQualityControlService $qualityControlService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function qualifyFromPlatformEvent(
        SuchakPaymentContext $paymentContext,
        User $admin,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakPlatformPayout {
        $this->accessService->assertAdmin($admin, 'Only admins can qualify platform-to-Suchak payouts.');
        $paymentContext = $paymentContext->fresh(['suchakAccount', 'customerContext', 'matrimonyProfile']);
        $this->assertPlatformContext($paymentContext);
        $this->qualityControlService->assertFeatureAvailable($paymentContext->suchakAccount, SuchakFeatureSuspension::FEATURE_PAYOUT);

        $eventType = $this->requiredAllowedValue(
            $attributes['platform_event_type'] ?? null,
            SuchakPlatformPayout::PLATFORM_EVENT_TYPES,
            'Suchak platform payout event type is invalid.',
        );
        $eventKey = $this->requiredText($attributes['platform_event_key'] ?? null, 'Suchak platform payout event key is required.', 160);
        $payoutReason = $this->requiredAllowedValue(
            $attributes['payout_reason'] ?? null,
            SuchakPlatformPayout::REASONS,
            'Suchak platform payout reason is invalid.',
        );
        $qualificationSource = $this->requiredAllowedValue(
            $attributes['qualification_source'] ?? SuchakPlatformPayout::SOURCE_PLATFORM_CONFIRMED_EVENT,
            SuchakPlatformPayout::QUALIFICATION_SOURCES,
            'Suchak platform payout qualification source must be platform-confirmed.',
        );
        $amount = $this->requiredAmount($attributes['amount'] ?? null);
        $currency = $this->currency($attributes['currency'] ?? 'INR');
        $qualificationNote = $this->requiredText($attributes['qualification_note'] ?? null, 'Suchak platform payout qualification note is required.', 1000);
        $detailPayload = $this->initialDetailPayload($attributes['payout_details'] ?? [], $admin);
        $status = $this->statusForVerification(
            $paymentContext->suchak_account_id,
            $paymentContext->customer_context_id,
            $paymentContext->id,
            $detailPayload['verification_status'],
            $holdReason,
        );

        return DB::transaction(function () use (
            $paymentContext,
            $admin,
            $eventType,
            $eventKey,
            $payoutReason,
            $qualificationSource,
            $amount,
            $currency,
            $qualificationNote,
            $detailPayload,
            $status,
            $holdReason,
            $ipAddress,
            $userAgent,
        ): SuchakPlatformPayout {
            $this->qualityControlService->assertFeatureAvailable($paymentContext->suchakAccount, SuchakFeatureSuspension::FEATURE_PAYOUT);

            $existing = SuchakPlatformPayout::query()
                ->where('suchak_account_id', $paymentContext->suchak_account_id)
                ->where('platform_event_type', $eventType)
                ->where('platform_event_key', $eventKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof SuchakPlatformPayout) {
                throw new InvalidArgumentException('Suchak platform payout already exists for this platform event.');
            }

            $payout = SuchakPlatformPayout::query()->create([
                'suchak_account_id' => $paymentContext->suchak_account_id,
                'customer_context_id' => $paymentContext->customer_context_id,
                'payment_context_id' => $paymentContext->id,
                'matrimony_profile_id' => $paymentContext->matrimony_profile_id,
                'platform_event_type' => $eventType,
                'platform_event_key' => $eventKey,
                'payout_reason' => $payoutReason,
                'qualification_source' => $qualificationSource,
                'payout_status' => $status,
                'amount' => $amount,
                'currency' => $currency,
                'liability_recognized_at' => now(),
                'qualified_by_user_id' => $admin->id,
                'qualification_note' => $qualificationNote,
                'hold_reason' => $holdReason,
            ]);

            SuchakPlatformPayoutDetail::query()->create(array_merge($detailPayload, [
                'platform_payout_id' => $payout->id,
                'suchak_account_id' => $payout->suchak_account_id,
            ]));

            $fresh = $payout->fresh($this->relations());
            $this->recordPayoutEvent(
                $fresh,
                SuchakPlatformPayoutEvent::EVENT_QUALIFIED,
                $admin,
                null,
                $fresh->payout_status,
                $qualificationNote,
            );

            if ($fresh->payout_status === SuchakPlatformPayout::STATUS_ON_HOLD) {
                $this->recordPayoutEvent(
                    $fresh,
                    SuchakPlatformPayoutEvent::EVENT_STATUS_HELD,
                    $admin,
                    null,
                    $fresh->payout_status,
                    $fresh->hold_reason,
                );
            }

            $adminAuditLog = $this->writeAdminAuditLog(
                $admin,
                'suchak_platform_payout_qualified',
                $fresh,
                $qualificationNote,
                [],
                [
                    'payout_status' => $fresh->payout_status,
                    'amount' => $fresh->amount,
                    'currency' => $fresh->currency,
                    'platform_event_type' => $fresh->platform_event_type,
                    'platform_event_key' => $fresh->platform_event_key,
                    'payout_reason' => $fresh->payout_reason,
                    'qualification_source' => $fresh->qualification_source,
                ],
            );
            $this->recordActivity(
                $fresh,
                $admin,
                $adminAuditLog,
                SuchakActivityLog::ACTION_PLATFORM_PAYOUT_QUALIFIED,
                'platform_payout_qualified',
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function verifyPayoutDetails(
        SuchakPlatformPayout $payout,
        User $admin,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakPlatformPayout {
        $this->accessService->assertAdmin($admin, 'Only admins can verify Suchak platform payout details.');
        $verificationStatus = $this->requiredAllowedValue(
            $attributes['verification_status'] ?? null,
            SuchakPlatformPayoutDetail::VERIFICATION_STATUSES,
            'Suchak platform payout detail verification status is invalid.',
        );
        $verificationNote = $this->requiredText($attributes['verification_note'] ?? null, 'Suchak platform payout detail verification note is required.', 1000);

        return DB::transaction(function () use ($payout, $admin, $attributes, $verificationStatus, $verificationNote, $ipAddress, $userAgent): SuchakPlatformPayout {
            /** @var SuchakPlatformPayout $locked */
            $locked = SuchakPlatformPayout::query()
                ->with(['paymentContext', 'details'])
                ->whereKey($payout->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($locked->payout_status, [
                SuchakPlatformPayout::STATUS_APPROVED,
                SuchakPlatformPayout::STATUS_PAID,
                SuchakPlatformPayout::STATUS_CANCELLED,
                SuchakPlatformPayout::STATUS_REVERSED,
            ], true)) {
                throw new InvalidArgumentException('Finalized Suchak platform payouts cannot have payout details changed.');
            }

            $detail = SuchakPlatformPayoutDetail::query()
                ->where('platform_payout_id', $locked->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $detail instanceof SuchakPlatformPayoutDetail) {
                $detail = SuchakPlatformPayoutDetail::query()->create([
                    'platform_payout_id' => $locked->id,
                    'suchak_account_id' => $locked->suchak_account_id,
                    'payout_method' => SuchakPlatformPayoutDetail::METHOD_MANUAL_REVIEW,
                    'verification_status' => SuchakPlatformPayoutDetail::STATUS_PENDING,
                    'created_by_user_id' => $admin->id,
                ]);
            }

            $detail->forceFill($this->verificationDetailPayload($detail, $attributes, $verificationStatus, $verificationNote, $admin))->save();

            $fromStatus = $locked->payout_status;
            $toStatus = $this->statusForVerification(
                $locked->suchak_account_id,
                $locked->customer_context_id,
                $locked->payment_context_id,
                $verificationStatus,
                $holdReason,
            );
            $locked->forceFill([
                'payout_status' => $toStatus,
                'hold_reason' => $holdReason,
                'status_note' => $verificationNote,
            ])->save();

            $fresh = $locked->fresh($this->relations());
            $this->recordPayoutEvent(
                $fresh,
                SuchakPlatformPayoutEvent::EVENT_DETAILS_UPDATED,
                $admin,
                $fromStatus,
                $fresh->payout_status,
                $verificationNote,
            );

            if ($fresh->payout_status === SuchakPlatformPayout::STATUS_ON_HOLD && $fromStatus !== $fresh->payout_status) {
                $this->recordPayoutEvent(
                    $fresh,
                    SuchakPlatformPayoutEvent::EVENT_STATUS_HELD,
                    $admin,
                    $fromStatus,
                    $fresh->payout_status,
                    $fresh->hold_reason,
                );
            }

            $adminAuditLog = $this->writeAdminAuditLog(
                $admin,
                'suchak_platform_payout_details_updated',
                $fresh,
                $verificationNote,
                [
                    'payout_status' => $fromStatus,
                ],
                [
                    'payout_status' => $fresh->payout_status,
                    'verification_status' => $verificationStatus,
                    'hold_reason' => $fresh->hold_reason,
                ],
            );
            $this->recordActivity(
                $fresh,
                $admin,
                $adminAuditLog,
                SuchakActivityLog::ACTION_PLATFORM_PAYOUT_DETAILS_UPDATED,
                'platform_payout_details_updated',
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function approvePayout(
        SuchakPlatformPayout $payout,
        User $admin,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakPlatformPayout {
        $this->accessService->assertAdmin($admin, 'Only admins can approve Suchak platform payouts.');
        $statusNote = $this->requiredText($attributes['status_note'] ?? null, 'Suchak platform payout approval note is required.', 1000);
        $deductionAmount = $this->boundedMoney($attributes['deduction_amount'] ?? 0, 'Suchak platform payout deduction amount is invalid.');

        return DB::transaction(function () use ($payout, $admin, $statusNote, $deductionAmount, $ipAddress, $userAgent): SuchakPlatformPayout {
            /** @var SuchakPlatformPayout $locked */
            $locked = SuchakPlatformPayout::query()
                ->with(['details', 'suchakAccount'])
                ->whereKey($payout->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->payout_status !== SuchakPlatformPayout::STATUS_QUALIFIED) {
                throw new InvalidArgumentException('Only qualified Suchak platform payouts can be approved.');
            }

            $this->qualityControlService->assertFeatureAvailable($locked->suchakAccount, SuchakFeatureSuspension::FEATURE_PAYOUT);

            $this->assertLatestDetailVerified($locked);
            $holdReason = $this->activePayoutHoldReason($locked->suchak_account_id, $locked->customer_context_id, $locked->payment_context_id);
            if ($holdReason !== null) {
                throw new InvalidArgumentException($holdReason);
            }

            if ((float) $deductionAmount > (float) $locked->amount) {
                throw new InvalidArgumentException('Suchak platform payout deduction cannot exceed payout amount.');
            }

            $fromStatus = $locked->payout_status;
            $netAmount = number_format(max(0, (float) $locked->amount - (float) $deductionAmount), 2, '.', '');
            $locked->forceFill([
                'payout_status' => SuchakPlatformPayout::STATUS_APPROVED,
                'deduction_amount' => $deductionAmount,
                'reversal_amount' => '0.00',
                'net_amount' => $netAmount,
                'approved_by_user_id' => $admin->id,
                'approved_at' => now(),
                'status_note' => $statusNote,
            ])->save();

            $fresh = $locked->fresh($this->relations());
            $this->recordPayoutEvent(
                $fresh,
                SuchakPlatformPayoutEvent::EVENT_APPROVED,
                $admin,
                $fromStatus,
                $fresh->payout_status,
                $statusNote,
            );

            $adminAuditLog = $this->writeAdminAuditLog(
                $admin,
                'suchak_platform_payout_approved',
                $fresh,
                $statusNote,
                ['payout_status' => $fromStatus],
                [
                    'payout_status' => $fresh->payout_status,
                    'deduction_amount' => $fresh->deduction_amount,
                    'net_amount' => $fresh->net_amount,
                ],
            );
            $this->recordActivity(
                $fresh,
                $admin,
                $adminAuditLog,
                SuchakActivityLog::ACTION_PLATFORM_PAYOUT_APPROVED,
                'platform_payout_approved',
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function markPayoutPaid(
        SuchakPlatformPayout $payout,
        User $admin,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakPlatformPayout {
        $this->accessService->assertAdmin($admin, 'Only admins can mark Suchak platform payouts paid.');
        $referenceNumber = $this->payoutReferenceNumber($attributes['payout_reference_number'] ?? null);
        $referenceNote = $this->nullableLimitedText($attributes['payout_reference_note'] ?? null, 1000);
        $paidAt = $this->paidAt($attributes['paid_at'] ?? null);

        return DB::transaction(function () use ($payout, $admin, $referenceNumber, $referenceNote, $paidAt, $ipAddress, $userAgent): SuchakPlatformPayout {
            /** @var SuchakPlatformPayout $locked */
            $locked = SuchakPlatformPayout::query()
                ->with(['details', 'suchakAccount'])
                ->whereKey($payout->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->payout_status !== SuchakPlatformPayout::STATUS_APPROVED) {
                throw new InvalidArgumentException('Only approved Suchak platform payouts can be marked paid.');
            }

            $this->qualityControlService->assertFeatureAvailable($locked->suchakAccount, SuchakFeatureSuspension::FEATURE_PAYOUT);

            $this->assertUniqueReferenceNumber($referenceNumber, $locked->id);
            $fromStatus = $locked->payout_status;
            $locked->forceFill([
                'payout_status' => SuchakPlatformPayout::STATUS_PAID,
                'paid_by_user_id' => $admin->id,
                'paid_at' => $paidAt,
                'payout_reference_number' => $referenceNumber,
                'payout_reference_note' => $referenceNote,
                'status_note' => $referenceNote,
            ])->save();

            $fresh = $locked->fresh($this->relations());
            $this->recordPayoutEvent(
                $fresh,
                SuchakPlatformPayoutEvent::EVENT_PAID,
                $admin,
                $fromStatus,
                $fresh->payout_status,
                $referenceNote,
            );

            $settlement = $this->rebuildMonthlySettlementStatement(
                $fresh->suchakAccount,
                $admin,
                $paidAt->format('Y-m'),
            );

            $fresh = $fresh->fresh($this->relations());
            $adminAuditLog = $this->writeAdminAuditLog(
                $admin,
                'suchak_platform_payout_paid',
                $fresh,
                $referenceNote ?: 'Suchak platform payout marked paid.',
                ['payout_status' => $fromStatus],
                [
                    'payout_status' => $fresh->payout_status,
                    'payout_reference_number' => $fresh->payout_reference_number,
                    'settlement_statement_id' => $settlement->id,
                ],
            );
            $this->recordActivity(
                $fresh,
                $admin,
                $adminAuditLog,
                SuchakActivityLog::ACTION_PLATFORM_PAYOUT_PAID,
                'platform_payout_paid',
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function reversePayout(
        SuchakPlatformPayout $payout,
        User $admin,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakPlatformPayout {
        $this->accessService->assertAdmin($admin, 'Only admins can reverse Suchak platform payouts.');
        $reason = $this->requiredText($attributes['reversal_reason'] ?? null, 'Suchak platform payout reversal reason is required.', 1000);

        return DB::transaction(function () use ($payout, $admin, $reason, $ipAddress, $userAgent): SuchakPlatformPayout {
            /** @var SuchakPlatformPayout $locked */
            $locked = SuchakPlatformPayout::query()
                ->with('suchakAccount')
                ->whereKey($payout->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->payout_status !== SuchakPlatformPayout::STATUS_PAID) {
                throw new InvalidArgumentException('Only paid Suchak platform payouts can be reversed.');
            }

            $fromStatus = $locked->payout_status;
            $reversalAmount = number_format((float) ($locked->net_amount ?? $locked->amount), 2, '.', '');
            $locked->forceFill([
                'payout_status' => SuchakPlatformPayout::STATUS_REVERSED,
                'reversal_amount' => $reversalAmount,
                'net_amount' => '0.00',
                'reversed_by_user_id' => $admin->id,
                'reversed_at' => now(),
                'status_note' => $reason,
            ])->save();

            $fresh = $locked->fresh($this->relations());
            $this->recordPayoutEvent(
                $fresh,
                SuchakPlatformPayoutEvent::EVENT_REVERSED,
                $admin,
                $fromStatus,
                $fresh->payout_status,
                $reason,
            );

            if ($fresh->paid_at !== null) {
                $this->rebuildMonthlySettlementStatement(
                    $fresh->suchakAccount,
                    $admin,
                    $fresh->paid_at->format('Y-m'),
                );
                $fresh = $fresh->fresh($this->relations());
            }

            $adminAuditLog = $this->writeAdminAuditLog(
                $admin,
                'suchak_platform_payout_reversed',
                $fresh,
                $reason,
                ['payout_status' => $fromStatus],
                [
                    'payout_status' => $fresh->payout_status,
                    'reversal_amount' => $fresh->reversal_amount,
                    'net_amount' => $fresh->net_amount,
                ],
            );
            $this->recordActivity(
                $fresh,
                $admin,
                $adminAuditLog,
                SuchakActivityLog::ACTION_PLATFORM_PAYOUT_REVERSED,
                'platform_payout_reversed',
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    public function cancelPayout(
        SuchakPlatformPayout $payout,
        User $admin,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakPlatformPayout {
        $this->accessService->assertAdmin($admin, 'Only admins can cancel Suchak platform payouts.');
        $reason = $this->requiredText($reason, 'Suchak platform payout cancellation reason is required.', 1000);

        return DB::transaction(function () use ($payout, $admin, $reason, $ipAddress, $userAgent): SuchakPlatformPayout {
            /** @var SuchakPlatformPayout $locked */
            $locked = SuchakPlatformPayout::query()
                ->whereKey($payout->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($locked->payout_status, [
                SuchakPlatformPayout::STATUS_PAID,
                SuchakPlatformPayout::STATUS_REVERSED,
                SuchakPlatformPayout::STATUS_CANCELLED,
            ], true)) {
                throw new InvalidArgumentException('Paid, reversed, or already-cancelled Suchak platform payouts cannot be cancelled.');
            }

            $fromStatus = $locked->payout_status;
            $locked->forceFill([
                'payout_status' => SuchakPlatformPayout::STATUS_CANCELLED,
                'cancelled_by_user_id' => $admin->id,
                'cancelled_at' => now(),
                'net_amount' => '0.00',
                'status_note' => $reason,
            ])->save();

            $fresh = $locked->fresh($this->relations());
            $this->recordPayoutEvent(
                $fresh,
                SuchakPlatformPayoutEvent::EVENT_CANCELLED,
                $admin,
                $fromStatus,
                $fresh->payout_status,
                $reason,
            );

            $adminAuditLog = $this->writeAdminAuditLog(
                $admin,
                'suchak_platform_payout_cancelled',
                $fresh,
                $reason,
                ['payout_status' => $fromStatus],
                ['payout_status' => $fresh->payout_status],
            );
            $this->recordActivity(
                $fresh,
                $admin,
                $adminAuditLog,
                SuchakActivityLog::ACTION_PLATFORM_PAYOUT_CANCELLED,
                'platform_payout_cancelled',
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    public function generateMonthlySettlementStatement(
        SuchakAccount $account,
        User $admin,
        string $statementMonth,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakPlatformPayoutSettlement {
        $this->accessService->assertAdmin($admin, 'Only admins can generate Suchak payout settlement statements.');
        $account = $account->fresh() ?? $account;
        $this->qualityControlService->assertFeatureAvailable($account, SuchakFeatureSuspension::FEATURE_PAYOUT);

        return DB::transaction(function () use ($account, $admin, $statementMonth, $ipAddress, $userAgent): SuchakPlatformPayoutSettlement {
            $this->qualityControlService->assertFeatureAvailable($account, SuchakFeatureSuspension::FEATURE_PAYOUT);
            $settlement = $this->rebuildMonthlySettlementStatement($account, $admin, $statementMonth);
            $adminAuditLog = AuditLogService::log(
                $admin,
                'suchak_platform_payout_settlement_generated',
                class_basename($settlement),
                $settlement->id,
                'Suchak platform payout settlement generated for '.$settlement->statement_month.'.',
                false,
            );
            $this->recordSettlementActivity($settlement, $admin, $adminAuditLog, $ipAddress, $userAgent);

            return $settlement;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function adminReportBundle(?string $statementMonth = null): array
    {
        $month = $statementMonth === null ? now()->format('Y-m') : $this->normalizeStatementMonth($statementMonth);
        [$periodStart, $periodEnd] = $this->periodForStatementMonth($month);

        $statusSummary = [];
        foreach (SuchakPlatformPayout::STATUSES as $status) {
            $query = SuchakPlatformPayout::query()->where('payout_status', $status);
            $statusSummary[$status] = [
                'count' => (clone $query)->count(),
                'gross_amount' => number_format((float) (clone $query)->sum('amount'), 2, '.', ''),
                'net_amount' => number_format((float) (clone $query)->sum('net_amount'), 2, '.', ''),
            ];
        }

        $paidThisMonth = SuchakPlatformPayout::query()
            ->whereIn('payout_status', [SuchakPlatformPayout::STATUS_PAID, SuchakPlatformPayout::STATUS_REVERSED])
            ->whereBetween('paid_at', [$periodStart, $periodEnd]);

        return [
            'statement_month' => $month,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'status_summary' => $statusSummary,
            'liability' => [
                'qualified_count' => $statusSummary[SuchakPlatformPayout::STATUS_QUALIFIED]['count'] ?? 0,
                'approved_count' => $statusSummary[SuchakPlatformPayout::STATUS_APPROVED]['count'] ?? 0,
                'on_hold_count' => $statusSummary[SuchakPlatformPayout::STATUS_ON_HOLD]['count'] ?? 0,
                'approved_net_amount' => $statusSummary[SuchakPlatformPayout::STATUS_APPROVED]['net_amount'] ?? '0.00',
                'paid_month_net_amount' => number_format((float) (clone $paidThisMonth)->sum('net_amount'), 2, '.', ''),
                'reversed_month_amount' => number_format((float) (clone $paidThisMonth)->sum('reversal_amount'), 2, '.', ''),
                'source' => 'suchak_platform_payouts_only',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'suchakAccount',
            'customerContext',
            'paymentContext',
            'matrimonyProfile',
            'settlementStatement',
            'details',
            'events',
        ];
    }

    private function assertPlatformContext(SuchakPaymentContext $paymentContext): void
    {
        if ($paymentContext->context_status !== SuchakPaymentContext::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Only active payment contexts can qualify platform-to-Suchak payouts.');
        }

        if ($paymentContext->source_owner !== SuchakPaymentContext::SOURCE_PLATFORM
            || $paymentContext->payment_collector !== SuchakPaymentContext::COLLECTOR_PLATFORM) {
            throw new InvalidArgumentException('Only platform-collected payment contexts can qualify platform-to-Suchak payouts.');
        }
    }

    /**
     * @param  array<string, mixed>|mixed  $attributes
     * @return array<string, mixed>
     */
    private function initialDetailPayload(mixed $attributes, User $admin): array
    {
        $attributes = is_array($attributes) ? $attributes : [];
        $verificationStatus = $this->requiredAllowedValue(
            $attributes['verification_status'] ?? SuchakPlatformPayoutDetail::STATUS_PENDING,
            SuchakPlatformPayoutDetail::VERIFICATION_STATUSES,
            'Suchak platform payout detail verification status is invalid.',
        );

        return [
            'payout_method' => $this->requiredAllowedValue(
                $attributes['payout_method'] ?? SuchakPlatformPayoutDetail::METHOD_MANUAL_REVIEW,
                SuchakPlatformPayoutDetail::METHODS,
                'Suchak platform payout method is invalid.',
            ),
            'payout_detail_reference' => $this->nullableLimitedText($attributes['payout_detail_reference'] ?? null, 500),
            'beneficiary_name' => $this->nullableLimitedText($attributes['beneficiary_name'] ?? null, 160),
            'account_last_four' => $this->accountLastFour($attributes['account_last_four'] ?? null),
            'ifsc_code' => $this->ifscCode($attributes['ifsc_code'] ?? null),
            'upi_handle_masked' => $this->maskedUpi($attributes['upi_handle_masked'] ?? null),
            'verification_status' => $verificationStatus,
            'verification_note' => $this->nullableLimitedText($attributes['verification_note'] ?? null, 1000),
            'created_by_user_id' => $admin->id,
            'verified_by_user_id' => $verificationStatus === SuchakPlatformPayoutDetail::STATUS_VERIFIED ? $admin->id : null,
            'verified_at' => $verificationStatus === SuchakPlatformPayoutDetail::STATUS_VERIFIED ? now() : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function verificationDetailPayload(
        SuchakPlatformPayoutDetail $detail,
        array $attributes,
        string $verificationStatus,
        string $verificationNote,
        User $admin,
    ): array {
        return [
            'payout_method' => $this->requiredAllowedValue(
                $attributes['payout_method'] ?? $detail->payout_method,
                SuchakPlatformPayoutDetail::METHODS,
                'Suchak platform payout method is invalid.',
            ),
            'payout_detail_reference' => $this->nullableLimitedText($attributes['payout_detail_reference'] ?? $detail->payout_detail_reference, 500),
            'beneficiary_name' => $this->nullableLimitedText($attributes['beneficiary_name'] ?? $detail->beneficiary_name, 160),
            'account_last_four' => $this->accountLastFour($attributes['account_last_four'] ?? $detail->account_last_four),
            'ifsc_code' => $this->ifscCode($attributes['ifsc_code'] ?? $detail->ifsc_code),
            'upi_handle_masked' => $this->maskedUpi($attributes['upi_handle_masked'] ?? $detail->upi_handle_masked),
            'verification_status' => $verificationStatus,
            'verification_note' => $verificationNote,
            'verified_by_user_id' => $verificationStatus === SuchakPlatformPayoutDetail::STATUS_VERIFIED ? $admin->id : null,
            'verified_at' => $verificationStatus === SuchakPlatformPayoutDetail::STATUS_VERIFIED ? now() : null,
        ];
    }

    private function statusForVerification(
        int $suchakAccountId,
        ?int $customerContextId,
        ?int $paymentContextId,
        string $verificationStatus,
        ?string &$holdReason,
    ): string {
        $holdReason = $this->activePayoutHoldReason($suchakAccountId, $customerContextId, $paymentContextId);
        if ($holdReason !== null) {
            return SuchakPlatformPayout::STATUS_ON_HOLD;
        }

        if ($verificationStatus === SuchakPlatformPayoutDetail::STATUS_VERIFIED) {
            return SuchakPlatformPayout::STATUS_QUALIFIED;
        }

        $holdReason = match ($verificationStatus) {
            SuchakPlatformPayoutDetail::STATUS_REJECTED => 'Suchak payout details verification was rejected.',
            SuchakPlatformPayoutDetail::STATUS_ON_HOLD => 'Suchak payout details are on hold for admin review.',
            default => 'Suchak payout details verification is pending.',
        };

        return SuchakPlatformPayout::STATUS_ON_HOLD;
    }

    private function activePayoutHoldReason(int $suchakAccountId, ?int $customerContextId, ?int $paymentContextId): ?string
    {
        $hold = SuchakPayoutHold::query()
            ->where('suchak_account_id', $suchakAccountId)
            ->where('hold_status', SuchakPayoutHold::STATUS_ACTIVE)
            ->where(function ($query) use ($customerContextId, $paymentContextId): void {
                $query->where(function ($accountScope): void {
                    $accountScope
                        ->whereNull('customer_context_id')
                        ->whereNull('payment_context_id');
                });

                if ($customerContextId !== null) {
                    $query->orWhere('customer_context_id', $customerContextId);
                }

                if ($paymentContextId !== null) {
                    $query->orWhere('payment_context_id', $paymentContextId);
                }
            })
            ->latest('id')
            ->first();

        return $hold instanceof SuchakPayoutHold
            ? 'Suchak payout is held because an active payment-risk review exists.'
            : null;
    }

    private function assertLatestDetailVerified(SuchakPlatformPayout $payout): void
    {
        $payout->loadMissing('details');
        $detail = $payout->latestDetail();
        if (! $detail instanceof SuchakPlatformPayoutDetail
            || $detail->verification_status !== SuchakPlatformPayoutDetail::STATUS_VERIFIED) {
            throw new InvalidArgumentException('Suchak platform payout details must be verified before approval.');
        }
    }

    private function rebuildMonthlySettlementStatement(
        SuchakAccount $account,
        User $admin,
        string $statementMonth,
    ): SuchakPlatformPayoutSettlement {
        $statementMonth = $this->normalizeStatementMonth($statementMonth);
        [$periodStart, $periodEnd] = $this->periodForStatementMonth($statementMonth);

        $payouts = SuchakPlatformPayout::query()
            ->where('suchak_account_id', $account->id)
            ->whereIn('payout_status', [SuchakPlatformPayout::STATUS_PAID, SuchakPlatformPayout::STATUS_REVERSED])
            ->whereBetween('paid_at', [$periodStart, $periodEnd])
            ->orderBy('paid_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($payouts->isEmpty()) {
            throw new InvalidArgumentException('No paid or reversed Suchak platform payouts exist for this settlement month.');
        }

        $currency = (string) ($payouts->first()?->currency ?? 'INR');
        $totals = [
            'gross_amount' => 0.0,
            'deduction_amount' => 0.0,
            'reversal_amount' => 0.0,
            'net_amount' => 0.0,
        ];
        $linePayloads = [];

        foreach ($payouts as $payout) {
            $grossAmount = (float) $payout->amount;
            $deductionAmount = (float) ($payout->deduction_amount ?? 0);
            $reversalAmount = (float) ($payout->reversal_amount ?? 0);
            $netAmount = (float) ($payout->net_amount ?? max(0, $grossAmount - $deductionAmount - $reversalAmount));
            $linePayload = [
                'platform_payout_id' => $payout->id,
                'gross_amount' => number_format($grossAmount, 2, '.', ''),
                'deduction_amount' => number_format($deductionAmount, 2, '.', ''),
                'reversal_amount' => number_format($reversalAmount, 2, '.', ''),
                'net_amount' => number_format($netAmount, 2, '.', ''),
                'currency' => $payout->currency,
                'payout_status' => $payout->payout_status,
                'payout_reference_number' => $payout->payout_reference_number,
            ];
            $linePayloads[] = $linePayload;

            $totals['gross_amount'] += $grossAmount;
            $totals['deduction_amount'] += $deductionAmount;
            $totals['reversal_amount'] += $reversalAmount;
            $totals['net_amount'] += $netAmount;
        }

        $statementHash = hash('sha256', json_encode([
            'suchak_account_id' => $account->id,
            'statement_month' => $statementMonth,
            'lines' => $linePayloads,
            'totals' => $totals,
        ], JSON_UNESCAPED_SLASHES));

        $statement = SuchakPlatformPayoutSettlement::query()->updateOrCreate(
            [
                'suchak_account_id' => $account->id,
                'statement_month' => $statementMonth,
            ],
            [
                'statement_number' => $this->statementNumber($account, $statementMonth),
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'statement_status' => SuchakPlatformPayoutSettlement::STATUS_GENERATED,
                'payout_count' => $payouts->count(),
                'gross_amount' => number_format($totals['gross_amount'], 2, '.', ''),
                'deduction_amount' => number_format($totals['deduction_amount'], 2, '.', ''),
                'reversal_amount' => number_format($totals['reversal_amount'], 2, '.', ''),
                'net_amount' => number_format($totals['net_amount'], 2, '.', ''),
                'currency' => $currency,
                'statement_hash' => $statementHash,
                'generated_by_admin_user_id' => $admin->id,
                'generated_at' => now(),
            ],
        );

        foreach ($payouts as $payout) {
            SuchakPlatformPayoutSettlementLine::query()->updateOrCreate(
                [
                    'settlement_statement_id' => $statement->id,
                    'platform_payout_id' => $payout->id,
                ],
                [
                    'suchak_account_id' => $payout->suchak_account_id,
                    'line_type' => SuchakPlatformPayoutSettlementLine::TYPE_PAYOUT,
                    'gross_amount' => number_format((float) $payout->amount, 2, '.', ''),
                    'deduction_amount' => number_format((float) ($payout->deduction_amount ?? 0), 2, '.', ''),
                    'reversal_amount' => number_format((float) ($payout->reversal_amount ?? 0), 2, '.', ''),
                    'net_amount' => number_format((float) ($payout->net_amount ?? 0), 2, '.', ''),
                    'currency' => $payout->currency,
                    'line_note' => 'Payout #'.$payout->id.' included in '.$statementMonth.' settlement.',
                ],
            );

            if ((int) ($payout->settlement_statement_id ?? 0) !== (int) $statement->id) {
                $payout->forceFill(['settlement_statement_id' => $statement->id])->save();
            }
        }

        $fresh = $statement->fresh(['suchakAccount', 'lines.platformPayout', 'payouts']);
        foreach ($payouts as $payout) {
            $this->recordPayoutEvent(
                $payout->fresh($this->relations()),
                SuchakPlatformPayoutEvent::EVENT_SETTLEMENT_REGENERATED,
                $admin,
                $payout->payout_status,
                $payout->payout_status,
                'Settlement '.$fresh->statement_number.' regenerated.',
                $fresh,
            );
        }

        return $fresh;
    }

    private function normalizeStatementMonth(string $statementMonth): string
    {
        $month = trim($statementMonth);
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new InvalidArgumentException('Suchak payout settlement month must use YYYY-MM format.');
        }

        return Carbon::createFromFormat('Y-m-d', $month.'-01')->format('Y-m');
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodForStatementMonth(string $statementMonth): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $statementMonth.'-01')->startOfDay();

        return [$start->copy()->startOfMonth(), $start->copy()->endOfMonth()->endOfDay()];
    }

    private function statementNumber(SuchakAccount $account, string $statementMonth): string
    {
        return 'SPS-'.str_pad((string) $account->id, 6, '0', STR_PAD_LEFT).'-'.str_replace('-', '', $statementMonth);
    }

    private function recordPayoutEvent(
        SuchakPlatformPayout $payout,
        string $eventType,
        User $admin,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $eventNote,
        ?SuchakPlatformPayoutSettlement $settlement = null,
    ): SuchakPlatformPayoutEvent {
        return SuchakPlatformPayoutEvent::query()->create([
            'platform_payout_id' => $payout->id,
            'settlement_statement_id' => $settlement?->id,
            'suchak_account_id' => $payout->suchak_account_id,
            'event_type' => $eventType,
            'actor_type' => SuchakPlatformPayoutEvent::ACTOR_ADMIN,
            'actor_user_id' => $admin->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'event_note' => $eventNote,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function recordActivity(
        SuchakPlatformPayout $payout,
        User $admin,
        AdminAuditLog $adminAuditLog,
        string $actionType,
        string $context,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $payout->suchak_account_id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => $actionType,
            'target_type' => 'suchak_platform_payout',
            'target_id' => $payout->id,
            'matrimony_profile_id' => $payout->matrimony_profile_id,
            'admin_audit_log_id' => $adminAuditLog->id,
            'ip_address' => $ipAddress,
            'user_agent' => Str::limit((string) $userAgent, 512, ''),
            'metadata_json' => [
                'context' => $context,
                'payout_status' => $payout->payout_status,
                'payout_reason' => $payout->payout_reason,
                'platform_event_type' => $payout->platform_event_type,
                'platform_event_key' => $payout->platform_event_key,
                'qualification_source' => $payout->qualification_source,
                'customer_context_id' => $payout->customer_context_id,
                'payment_context_id' => $payout->payment_context_id,
            ],
        ]);
    }

    private function recordSettlementActivity(
        SuchakPlatformPayoutSettlement $settlement,
        User $admin,
        AdminAuditLog $adminAuditLog,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $settlement->suchak_account_id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => SuchakActivityLog::ACTION_PLATFORM_PAYOUT_SETTLEMENT_GENERATED,
            'target_type' => 'suchak_platform_payout_settlement',
            'target_id' => $settlement->id,
            'admin_audit_log_id' => $adminAuditLog->id,
            'ip_address' => $ipAddress,
            'user_agent' => Str::limit((string) $userAgent, 512, ''),
            'metadata_json' => [
                'context' => 'platform_payout_settlement_generated',
                'statement_number' => $settlement->statement_number,
                'statement_month' => $settlement->statement_month,
                'statement_hash' => $settlement->statement_hash,
                'payout_count' => $settlement->payout_count,
                'net_amount' => $settlement->net_amount,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $oldValue
     * @param  array<string, mixed>  $newValue
     */
    private function writeAdminAuditLog(
        User $admin,
        string $actionType,
        SuchakPlatformPayout $payout,
        string $reason,
        array $oldValue,
        array $newValue,
    ): AdminAuditLog {
        return AuditLogService::log(
            $admin,
            $actionType,
            class_basename($payout),
            $payout->id,
            trim($reason).' | old='.json_encode($oldValue).' | new='.json_encode($newValue),
            false,
        );
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function requiredAllowedValue(mixed $value, array $allowed, string $message): string
    {
        $normalized = trim((string) ($value ?? ''));
        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    private function requiredText(mixed $value, string $message, int $limit): string
    {
        $text = $this->nullableLimitedText($value, $limit);
        if ($text === null) {
            throw new InvalidArgumentException($message);
        }

        return $text;
    }

    private function nullableLimitedText(mixed $value, int $limit): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : Str::limit($trimmed, $limit, '');
    }

    private function requiredAmount(mixed $value): string
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            throw new InvalidArgumentException('Suchak platform payout amount is invalid.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function boundedMoney(mixed $value, string $message): string
    {
        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException($message);
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function payoutReferenceNumber(mixed $value): string
    {
        $reference = $this->requiredText($value, 'Suchak platform payout reference number is required.', 160);
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{2,159}$/', $reference)) {
            throw new InvalidArgumentException('Suchak platform payout reference number format is invalid.');
        }

        return $reference;
    }

    private function assertUniqueReferenceNumber(string $referenceNumber, int $exceptPayoutId): void
    {
        $exists = SuchakPlatformPayout::query()
            ->where('payout_reference_number', $referenceNumber)
            ->where('id', '<>', $exceptPayoutId)
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException('Suchak platform payout reference number is already used.');
        }
    }

    private function paidAt(mixed $value): Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            return now();
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Suchak platform payout paid date is invalid.');
        }
    }

    private function currency(mixed $value): string
    {
        $currency = strtoupper(trim((string) ($value ?? 'INR')));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Suchak platform payout currency is invalid.');
        }

        return $currency;
    }

    private function accountLastFour(mixed $value): ?string
    {
        $text = $this->nullableLimitedText($value, 4);
        if ($text === null) {
            return null;
        }

        if (! preg_match('/^\d{4}$/', $text)) {
            throw new InvalidArgumentException('Suchak payout account last four digits are invalid.');
        }

        return $text;
    }

    private function ifscCode(mixed $value): ?string
    {
        $text = $this->nullableLimitedText($value, 16);
        if ($text === null) {
            return null;
        }

        $text = strtoupper($text);
        if (! preg_match('/^[A-Z0-9]{6,16}$/', $text)) {
            throw new InvalidArgumentException('Suchak payout IFSC code is invalid.');
        }

        return $text;
    }

    private function maskedUpi(mixed $value): ?string
    {
        $text = $this->nullableLimitedText($value, 160);
        if ($text === null) {
            return null;
        }

        if (str_contains($text, '@') && ! str_contains($text, '*')) {
            throw new InvalidArgumentException('Suchak payout UPI handle must be masked before storage.');
        }

        return $text;
    }
}
