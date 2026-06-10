<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\SuchakActivityLog;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPlatformPayout;
use App\Models\SuchakPlatformPayoutDetail;
use App\Models\SuchakPlatformPayoutEvent;
use App\Models\SuchakPayoutHold;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakPlatformPayoutService
{
    public function __construct(
        private readonly SuchakAccessService $accessService,
        private readonly SuchakActivityLogger $activityLogger,
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
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'suchakAccount',
            'customerContext',
            'paymentContext',
            'matrimonyProfile',
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

    private function recordPayoutEvent(
        SuchakPlatformPayout $payout,
        string $eventType,
        User $admin,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $eventNote,
    ): SuchakPlatformPayoutEvent {
        return SuchakPlatformPayoutEvent::query()->create([
            'platform_payout_id' => $payout->id,
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
