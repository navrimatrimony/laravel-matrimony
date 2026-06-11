<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerPortalLink;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakFeatureSuspension;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPaymentRequestEvent;
use App\Models\SuchakServicePackage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakPaymentRequestService
{
    public function __construct(
        private readonly SuchakAccessService $accessService,
        private readonly SuchakAgreementService $agreementService,
        private readonly SuchakPaymentCollectorResolver $paymentCollectorResolver,
        private readonly SuchakPolicyService $policyService,
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakCustomerPortalService $customerPortalService,
        private readonly SuchakQualityControlService $qualityControlService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{payment_request: SuchakPaymentRequest, public_url: string, plain_token: string, portal_url: string, plain_portal_token: string}
     */
    public function createAndSend(
        SuchakServicePackage $package,
        SuchakCustomerAgreement $agreement,
        SuchakPaymentContext $paymentContext,
        User $actor,
        array $attributes = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $package->refresh()->loadMissing(['suchakAccount.user', 'customerContext']);
        $agreement->refresh()->loadMissing(['servicePackage', 'customerContext', 'suchakAccount']);
        $paymentContext->refresh()->loadMissing(['suchakAccount', 'customerContext', 'matrimonyProfile']);

        $this->accessService->assertOwnerCanOperate(
            $package->suchakAccount,
            $actor,
            'Only the owning Suchak account can send payment requests.',
            'Only verified Suchak accounts can send payment requests.',
        );
        $this->qualityControlService->assertFeatureAvailable($package->suchakAccount, SuchakFeatureSuspension::FEATURE_PAYMENT);
        $this->assertRequestScope($package, $agreement, $paymentContext);
        $this->agreementService->assertAgreementAllowsPaymentRequest($agreement);
        $this->paymentCollectorResolver->assertAllowsDirectSuchakCollection($paymentContext);

        [$plainToken, $tokenHash] = $this->uniqueTokenPair();

        $request = DB::transaction(function () use ($package, $agreement, $paymentContext, $actor, $attributes, $tokenHash, $ipAddress, $userAgent): SuchakPaymentRequest {
            $paymentRequest = SuchakPaymentRequest::query()->create([
                'suchak_account_id' => $package->suchak_account_id,
                'customer_context_id' => $agreement->customer_context_id,
                'service_package_id' => $package->id,
                'customer_agreement_id' => $agreement->id,
                'payment_context_id' => $paymentContext->id,
                'requested_by_user_id' => $actor->id,
                'request_token_hash' => $tokenHash,
                'payment_status' => SuchakPaymentRequest::STATUS_DRAFT,
                'payment_detail_visibility_policy' => $this->policyService->paymentDetailVisibilityPolicy(),
                'request_title' => $this->requiredText(
                    $attributes['request_title'] ?? 'Payment request for '.$agreement->package_name,
                    'Suchak payment request title is required.',
                    160,
                ),
                'request_note' => $this->limitedText($attributes['request_note'] ?? null, 1000),
                'amount_due' => $this->amountDue($attributes['amount_due'] ?? $agreement->price_amount),
                'currency' => $this->currency($attributes['currency'] ?? $agreement->currency),
                'collector_disclosure' => $this->collectorDisclosure($paymentContext),
                'expires_at' => $this->futureExpiry($attributes['expires_at'] ?? now()->addDays(7)),
            ]);

            $this->recordEvent(
                $paymentRequest,
                SuchakPaymentRequestEvent::EVENT_CREATED,
                SuchakActivityLog::ACTOR_SUCHAK,
                $actor,
                null,
                SuchakPaymentRequest::STATUS_DRAFT,
                'Payment request draft created.',
            );
            $this->recordActivity(
                $paymentRequest,
                SuchakActivityLog::ACTION_PAYMENT_REQUEST_CREATED,
                'payment_request_created',
                SuchakActivityLog::ACTOR_SUCHAK,
                $actor,
                $ipAddress,
                $userAgent,
            );

            $paymentRequest->forceFill([
                'payment_status' => SuchakPaymentRequest::STATUS_SENT,
                'sent_at' => now(),
            ])->save();

            $fresh = $paymentRequest->fresh($this->requestRelations());
            $this->recordEvent(
                $fresh,
                SuchakPaymentRequestEvent::EVENT_SENT,
                SuchakActivityLog::ACTOR_SUCHAK,
                $actor,
                SuchakPaymentRequest::STATUS_DRAFT,
                SuchakPaymentRequest::STATUS_SENT,
                'Secure payment request link sent.',
            );
            $this->recordActivity(
                $fresh,
                SuchakActivityLog::ACTION_PAYMENT_REQUEST_SENT,
                'payment_request_sent',
                SuchakActivityLog::ACTOR_SUCHAK,
                $actor,
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });

        $portal = $this->customerPortalService->issuePaymentPortalLink(
            $request,
            $actor,
            [
                'recipient_role' => SuchakCustomerPortalLink::RECIPIENT_PAYER,
                'recipient_label' => 'Customer family',
                'expires_at' => $request->expires_at ?? now()->addDays(14),
            ],
            $ipAddress,
            $userAgent,
        );

        return [
            'payment_request' => $request,
            'public_url' => $this->publicUrl($plainToken),
            'plain_token' => $plainToken,
            'portal_url' => $portal['portal_url'],
            'plain_portal_token' => $portal['plain_token'],
        ];
    }

    public function openPublicRequest(
        string $plainToken,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakPaymentRequest {
        $tokenHash = hash('sha256', $plainToken);

        /** @var SuchakPaymentRequest|null $paymentRequest */
        $paymentRequest = SuchakPaymentRequest::query()
            ->where('request_token_hash', $tokenHash)
            ->with($this->requestRelations())
            ->first();

        if ($paymentRequest === null) {
            throw new InvalidArgumentException('Suchak payment request link is invalid.');
        }

        $this->accessService->assertCanOperate(
            $paymentRequest->suchakAccount,
            'Suchak payment request is not available.',
        );

        if ($paymentRequest->payment_status === SuchakPaymentRequest::STATUS_CANCELLED) {
            throw new InvalidArgumentException('Suchak payment request has been cancelled.');
        }

        if ($paymentRequest->payment_status === SuchakPaymentRequest::STATUS_EXPIRED || $paymentRequest->hasExpired()) {
            $this->expire($paymentRequest);

            throw new InvalidArgumentException('Suchak payment request link has expired.');
        }

        if (! $paymentRequest->isOpenable()) {
            throw new InvalidArgumentException('Suchak payment request is not open for customer viewing.');
        }

        if ($paymentRequest->payment_status === SuchakPaymentRequest::STATUS_SENT) {
            return DB::transaction(function () use ($paymentRequest, $ipAddress, $userAgent): SuchakPaymentRequest {
                /** @var SuchakPaymentRequest $locked */
                $locked = SuchakPaymentRequest::query()
                    ->whereKey($paymentRequest->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->payment_status !== SuchakPaymentRequest::STATUS_SENT) {
                    return $locked->fresh($this->requestRelations());
                }

                $locked->forceFill([
                    'payment_status' => SuchakPaymentRequest::STATUS_OPENED,
                    'opened_at' => $locked->opened_at ?? now(),
                ])->save();

                $fresh = $locked->fresh($this->requestRelations());
                $this->recordEvent(
                    $fresh,
                    SuchakPaymentRequestEvent::EVENT_OPENED,
                    SuchakActivityLog::ACTOR_USER,
                    null,
                    SuchakPaymentRequest::STATUS_SENT,
                    SuchakPaymentRequest::STATUS_OPENED,
                    'Payment request secure link opened by customer.',
                );
                $this->recordActivity(
                    $fresh,
                    SuchakActivityLog::ACTION_PAYMENT_REQUEST_OPENED,
                    'payment_request_opened',
                    SuchakActivityLog::ACTOR_USER,
                    null,
                    $ipAddress,
                    $userAgent,
                );

                return $fresh;
            });
        }

        return $paymentRequest->fresh($this->requestRelations());
    }

    public function cancel(
        SuchakPaymentRequest $paymentRequest,
        User $actor,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakPaymentRequest {
        $paymentRequest->refresh()->loadMissing($this->requestRelations());
        $reason = $this->requiredText($reason, 'Suchak payment request cancellation reason is required.', 1000);

        $this->accessService->assertOwnerCanOperate(
            $paymentRequest->suchakAccount,
            $actor,
            'Only the owning Suchak account can cancel payment requests.',
            'Only verified Suchak accounts can cancel payment requests.',
        );

        return DB::transaction(function () use ($paymentRequest, $actor, $reason, $ipAddress, $userAgent): SuchakPaymentRequest {
            /** @var SuchakPaymentRequest $locked */
            $locked = SuchakPaymentRequest::query()
                ->whereKey($paymentRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($locked->payment_status, [
                SuchakPaymentRequest::STATUS_CANCELLED,
                SuchakPaymentRequest::STATUS_EXPIRED,
            ], true)) {
                throw new InvalidArgumentException('Only active Suchak payment requests can be cancelled.');
            }

            $fromStatus = $locked->payment_status;
            $locked->forceFill([
                'payment_status' => SuchakPaymentRequest::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->id,
                'cancellation_reason' => $reason,
            ])->save();

            $fresh = $locked->fresh($this->requestRelations());
            $this->recordEvent(
                $fresh,
                SuchakPaymentRequestEvent::EVENT_CANCELLED,
                SuchakActivityLog::ACTOR_SUCHAK,
                $actor,
                $fromStatus,
                SuchakPaymentRequest::STATUS_CANCELLED,
                $reason,
            );
            $this->recordActivity(
                $fresh,
                SuchakActivityLog::ACTION_PAYMENT_REQUEST_CANCELLED,
                'payment_request_cancelled',
                SuchakActivityLog::ACTOR_SUCHAK,
                $actor,
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    public function expire(SuchakPaymentRequest $paymentRequest): SuchakPaymentRequest
    {
        $paymentRequest->refresh()->loadMissing($this->requestRelations());

        return DB::transaction(function () use ($paymentRequest): SuchakPaymentRequest {
            /** @var SuchakPaymentRequest $locked */
            $locked = SuchakPaymentRequest::query()
                ->whereKey($paymentRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->payment_status === SuchakPaymentRequest::STATUS_EXPIRED) {
                return $locked->fresh($this->requestRelations());
            }

            if ($locked->payment_status === SuchakPaymentRequest::STATUS_CANCELLED) {
                return $locked->fresh($this->requestRelations());
            }

            $fromStatus = $locked->payment_status;
            $locked->forceFill([
                'payment_status' => SuchakPaymentRequest::STATUS_EXPIRED,
                'expired_at' => now(),
            ])->save();

            $fresh = $locked->fresh($this->requestRelations());
            $this->recordEvent(
                $fresh,
                SuchakPaymentRequestEvent::EVENT_EXPIRED,
                SuchakActivityLog::ACTOR_SYSTEM,
                null,
                $fromStatus,
                SuchakPaymentRequest::STATUS_EXPIRED,
                'Payment request expired before receipt/payment confirmation.',
            );
            $this->recordActivity(
                $fresh,
                SuchakActivityLog::ACTION_PAYMENT_REQUEST_EXPIRED,
                'payment_request_expired',
                SuchakActivityLog::ACTOR_SYSTEM,
                null,
                null,
                null,
            );

            return $fresh;
        });
    }

    public function publicUrl(string $plainToken): string
    {
        return route('suchak.payment-requests.show', ['token' => $plainToken], true);
    }

    private function assertRequestScope(
        SuchakServicePackage $package,
        SuchakCustomerAgreement $agreement,
        SuchakPaymentContext $paymentContext,
    ): void {
        if (! $package->isPublished()) {
            throw new InvalidArgumentException('Only published Suchak service packages can send payment requests.');
        }

        if ((int) $agreement->service_package_id !== (int) $package->id) {
            throw new InvalidArgumentException('Suchak agreement must belong to the selected package.');
        }

        if ((int) $agreement->suchak_account_id !== (int) $package->suchak_account_id
            || (int) $paymentContext->suchak_account_id !== (int) $package->suchak_account_id) {
            throw new InvalidArgumentException('Suchak payment request scope must belong to one Suchak account.');
        }

        if ($agreement->customer_context_id === null || $package->customer_context_id === null || $paymentContext->customer_context_id === null) {
            throw new InvalidArgumentException('Suchak payment requests require a structured customer context.');
        }

        if ((int) $agreement->customer_context_id !== (int) $package->customer_context_id
            || (int) $paymentContext->customer_context_id !== (int) $package->customer_context_id) {
            throw new InvalidArgumentException('Suchak payment request customer context mismatch.');
        }

        if ($paymentContext->context_status !== SuchakPaymentContext::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Suchak payment context must be active before sending payment requests.');
        }

        $customerContext = $package->customerContext;
        if (! $customerContext instanceof SuchakCustomerContext) {
            throw new InvalidArgumentException('Suchak payment requests require a structured customer context.');
        }

        if ($customerContext->candidate_matrimony_profile_id !== null
            && (int) $paymentContext->matrimony_profile_id !== (int) $customerContext->candidate_matrimony_profile_id) {
            throw new InvalidArgumentException('Suchak payment request profile must match the customer context.');
        }

        if ($paymentContext->source_owner !== $customerContext->source_owner) {
            throw new InvalidArgumentException('Suchak payment source owner must match the customer context source owner.');
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function uniqueTokenPair(): array
    {
        do {
            $plainToken = Str::random(64);
            $tokenHash = hash('sha256', $plainToken);
        } while (SuchakPaymentRequest::query()->where('request_token_hash', $tokenHash)->exists());

        return [$plainToken, $tokenHash];
    }

    private function collectorDisclosure(SuchakPaymentContext $paymentContext): string
    {
        if ($paymentContext->source_owner === SuchakPaymentContext::SOURCE_COLLABORATION) {
            return 'Payment collector: Suchak. This request is for a collaboration-owned customer context and is collected by the disclosed Suchak account.';
        }

        return 'Payment collector: Suchak. This request is collected by the disclosed Suchak account, not by a public marketplace listing.';
    }

    private function amountDue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException('Suchak payment request amount must be a non-negative number.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function currency(mixed $value): ?string
    {
        $normalized = strtoupper(trim((string) ($value ?? '')));

        if ($normalized === '') {
            return null;
        }

        if (! preg_match('/^[A-Z]{3}$/', $normalized)) {
            throw new InvalidArgumentException('Suchak payment request currency must be a three-letter ISO code.');
        }

        return $normalized;
    }

    private function futureExpiry(mixed $value): Carbon
    {
        $expiresAt = $value instanceof Carbon ? $value : Carbon::parse($value);

        if ($expiresAt->isPast()) {
            throw new InvalidArgumentException('Suchak payment request expiry must be in the future.');
        }

        return $expiresAt;
    }

    private function requiredText(mixed $value, string $message, int $limit): string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            throw new InvalidArgumentException($message);
        }

        return Str::limit($normalized, $limit, '');
    }

    private function limitedText(mixed $value, int $limit): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : Str::limit($normalized, $limit, '');
    }

    private function recordEvent(
        SuchakPaymentRequest $paymentRequest,
        string $eventType,
        string $actorType,
        ?User $actor,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $note,
    ): void {
        SuchakPaymentRequestEvent::query()->create([
            'payment_request_id' => $paymentRequest->id,
            'suchak_account_id' => $paymentRequest->suchak_account_id,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_user_id' => $actor?->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'event_note' => $note,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function recordActivity(
        SuchakPaymentRequest $paymentRequest,
        string $actionType,
        string $context,
        string $actorType,
        ?User $actor,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $paymentRequest->loadMissing('paymentContext');

        $this->activityLogger->record([
            'suchak_account_id' => $paymentRequest->suchak_account_id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actorType,
            'action_type' => $actionType,
            'target_type' => 'suchak_payment_request',
            'target_id' => $paymentRequest->id,
            'matrimony_profile_id' => $paymentRequest->paymentContext?->matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => [
                'context' => $context,
                'customer_context_id' => $paymentRequest->customer_context_id,
                'service_package_id' => $paymentRequest->service_package_id,
                'customer_agreement_id' => $paymentRequest->customer_agreement_id,
                'payment_context_id' => $paymentRequest->payment_context_id,
                'payment_status' => $paymentRequest->payment_status,
                'payment_detail_visibility_policy' => $paymentRequest->payment_detail_visibility_policy,
                'amount_due' => $paymentRequest->amount_due,
                'currency' => $paymentRequest->currency,
                'sent_at' => $paymentRequest->sent_at?->toIso8601String(),
                'opened_at' => $paymentRequest->opened_at?->toIso8601String(),
                'expires_at' => $paymentRequest->expires_at?->toIso8601String(),
                'cancelled_at' => $paymentRequest->cancelled_at?->toIso8601String(),
                'expired_at' => $paymentRequest->expired_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function requestRelations(): array
    {
        return [
            'suchakAccount',
            'customerContext',
            'servicePackage.stages',
            'servicePackage.deliverables.servicePackageStage',
            'customerAgreement.stages',
            'customerAgreement.deliverables',
            'paymentContext',
            'requestedByUser',
            'cancelledByUser',
            'events',
        ];
    }
}
