<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakActivityLog;
use App\Models\SuchakConsent;
use App\Models\SuchakConsentEvent;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakConsentService
{
    private const CONSENT_TEXT_V1 = 'Suchak can manage profile for marriage matching, show/share biodata for suitable matches, print/share biodata PDF, receive matching requests, contact candidate/family for suitable matches, and handle introduction process. Public users will not see private contact directly; contact will happen through Suchak.';

    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakAccessService $accessService,
        private readonly SuchakPolicyService $policyService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{consent: SuchakConsent, raw_token: string}
     */
    public function requestConsent(
        SuchakProfileRepresentation $representation,
        User $actor,
        array $attributes = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $representation->refresh()->loadMissing('suchakAccount');
        $this->assertSuchakActor($representation, $actor);
        $isRenewal = (bool) ($attributes['_renewal'] ?? false);
        if ($isRenewal) {
            $this->assertRepresentationCanRenewConsent($representation);
        } else {
            $this->assertRepresentationCanRequestConsent($representation);
        }

        $consentType = (string) ($attributes['consent_type'] ?? SuchakConsent::TYPE_ONE_YEAR);
        $consentChannel = (string) ($attributes['consent_channel'] ?? SuchakConsent::CHANNEL_WHATSAPP_DEEP_LINK);
        $this->assertAllowedValue($consentType, SuchakConsent::TYPES, 'Consent type is not allowed.');
        $this->assertConsentTypeAllowedByPolicy($consentType);
        $this->assertAllowedValue($consentChannel, SuchakConsent::CHANNELS, 'Consent channel is not allowed.');

        return DB::transaction(function () use ($representation, $actor, $attributes, $consentType, $consentChannel, $ipAddress, $userAgent, $isRenewal): array {
            $openConsent = SuchakConsent::query()
                ->where('representation_id', $representation->id)
                ->whereIn('consent_status', SuchakConsent::PENDING_ACTION_STATUSES)
                ->lockForUpdate()
                ->first();

            if ($openConsent !== null) {
                throw new InvalidArgumentException('An open consent already exists for this representation.');
            }

            $rawToken = Str::random(64);

            $consent = SuchakConsent::query()->create([
                'suchak_account_id' => $representation->suchak_account_id,
                'matrimony_profile_id' => $representation->matrimony_profile_id,
                'representation_id' => $representation->id,
                'consent_status' => SuchakConsent::STATUS_REQUESTED,
                'consent_type' => $consentType,
                'consent_text_snapshot' => (string) ($attributes['consent_text_snapshot'] ?? self::CONSENT_TEXT_V1),
                'consent_template_version' => SuchakConsent::TEMPLATE_VERSION_V1,
                'consent_given_by_name' => $attributes['consent_given_by_name'] ?? null,
                'relationship_to_candidate' => $attributes['relationship_to_candidate'] ?? null,
                'consent_mobile_number' => $attributes['consent_mobile_number'] ?? null,
                'token_hash' => hash('sha256', $rawToken),
                'token_expires_at' => now()->addDays(SuchakConsent::DEFAULT_TOKEN_EXPIRY_DAYS),
                'otp_attempts' => 0,
                'consent_channel' => $consentChannel,
                'ip_address' => $ipAddress,
                'user_agent' => Str::limit((string) $userAgent, 512, ''),
            ]);

            if (! (bool) ($attributes['_preserve_representation_status'] ?? false)) {
                SuchakProfileRepresentation::query()
                    ->whereKey($representation->id)
                    ->update([
                        'representation_status' => SuchakProfileRepresentation::STATUS_CONSENT_PENDING,
                        'consent_status' => SuchakProfileRepresentation::CONSENT_REQUESTED,
                    ]);
            }

            $this->recordEvent(
                $consent,
                SuchakConsentEvent::EVENT_REQUESTED,
                SuchakConsentEvent::ACTOR_SUCHAK,
                $actor->id,
                $isRenewal ? 'Consent renewal requested.' : null,
            );
            $this->recordActivity(
                $consent,
                $actor,
                $isRenewal ? SuchakActivityLog::ACTION_CONSENT_RENEWED : SuchakActivityLog::ACTION_CONSENT_REQUESTED,
                $isRenewal ? 'consent_renewal_requested' : 'consent_requested',
                $ipAddress,
                $userAgent,
            );

            return [
                'consent' => $consent->fresh(['representation', 'events']),
                'raw_token' => $rawToken,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{consent: SuchakConsent, raw_token: string}
     */
    public function renewConsent(
        SuchakProfileRepresentation $representation,
        User $actor,
        array $attributes = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $attributes['_renewal'] = true;
        $attributes['_preserve_representation_status'] = true;

        return $this->requestConsent($representation, $actor, $attributes, $ipAddress, $userAgent);
    }

    /**
     * @return array{consent: SuchakConsent, raw_token: string}
     */
    public function resendConsent(
        SuchakConsent $consent,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $consent->refresh()->loadMissing('suchakAccount');
        $this->assertConsentSuchakActor($consent, $actor);
        $this->assertConsentCanReceiveOtp($consent);

        return DB::transaction(function () use ($consent, $actor, $ipAddress, $userAgent): array {
            $rawToken = Str::random(64);

            SuchakConsent::query()
                ->whereKey($consent->id)
                ->update([
                    'token_hash' => hash('sha256', $rawToken),
                    'token_expires_at' => now()->addDays(SuchakConsent::DEFAULT_TOKEN_EXPIRY_DAYS),
                ]);

            $updated = $consent->fresh(['representation', 'events']);
            $this->recordEvent($updated, SuchakConsentEvent::EVENT_REQUESTED, SuchakConsentEvent::ACTOR_SUCHAK, $actor->id, 'Consent request resent.');
            $this->recordActivity($updated, $actor, SuchakActivityLog::ACTION_CONSENT_REQUESTED, 'consent_request_resent', $ipAddress, $userAgent);

            return [
                'consent' => $updated,
                'raw_token' => $rawToken,
            ];
        });
    }

    public function recordOtpSent(
        SuchakConsent $consent,
        string $otp,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakConsent
    {
        $this->assertOtpFormat($otp);
        $consent->refresh()->loadMissing('suchakAccount');
        $this->assertConsentSuchakActor($consent, $actor);
        $this->assertConsentCanReceiveOtp($consent);

        return DB::transaction(function () use ($consent, $otp, $actor, $ipAddress, $userAgent): SuchakConsent {
            SuchakConsent::query()
                ->whereKey($consent->id)
                ->update([
                    'consent_status' => SuchakConsent::STATUS_OTP_SENT,
                    'otp_hash' => Hash::make($otp),
                    'last_otp_sent_at' => now(),
                ]);

            $updated = $consent->fresh();
            $this->recordEvent($updated, SuchakConsentEvent::EVENT_OTP_SENT, SuchakConsentEvent::ACTOR_SUCHAK, $actor->id);
            $this->recordActivity($updated, $actor, SuchakActivityLog::ACTION_CONSENT_OTP_SENT, 'consent_otp_sent', $ipAddress, $userAgent);

            return $updated;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function verifyOtpAndAccept(SuchakConsent $consent, string $otp, array $attributes = []): SuchakConsent
    {
        $this->assertOtpFormat($otp);
        $consent->refresh()->loadMissing('representation');
        $this->assertConsentCanBeAccepted($consent);

        if ($consent->otp_hash === null || ! Hash::check($otp, $consent->otp_hash)) {
            SuchakConsent::query()
                ->whereKey($consent->id)
                ->update(['otp_attempts' => $consent->otp_attempts + 1]);

            throw new InvalidArgumentException('Invalid OTP for Suchak consent.');
        }

        $validFrom = now();
        $validUntil = $this->validUntilFor($consent->consent_type, $validFrom);

        return DB::transaction(function () use ($consent, $attributes, $validFrom, $validUntil): SuchakConsent {
            SuchakConsent::query()
                ->whereKey($consent->id)
                ->update([
                    'consent_status' => SuchakConsent::STATUS_ACCEPTED,
                    'consent_given_by_name' => $attributes['consent_given_by_name'] ?? $consent->consent_given_by_name,
                    'relationship_to_candidate' => $attributes['relationship_to_candidate'] ?? $consent->relationship_to_candidate,
                    'consent_mobile_number' => $attributes['consent_mobile_number'] ?? $consent->consent_mobile_number,
                    'accepted_at' => $validFrom,
                    'used_at' => $validFrom,
                    'otp_verified_at' => $validFrom,
                    'valid_from' => $validFrom,
                    'valid_until' => $validUntil,
                ]);

            SuchakProfileRepresentation::query()
                ->whereKey($consent->representation_id)
                ->update([
                    'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
                    'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
                    'first_verified_consent_at' => $consent->representation->first_verified_consent_at ?? $validFrom,
                    'consent_verified_at' => $validFrom,
                    'consent_valid_until' => $validUntil,
                    'revoked_at' => null,
                ]);

            $updated = $consent->fresh(['representation']);
            $this->recordEvent($updated, SuchakConsentEvent::EVENT_OTP_VERIFIED, SuchakConsentEvent::ACTOR_CANDIDATE, null);
            $this->recordEvent($updated, SuchakConsentEvent::EVENT_CONSENT_ACCEPTED, SuchakConsentEvent::ACTOR_CANDIDATE, null);
            $this->recordActivity($updated, null, SuchakActivityLog::ACTION_CONSENT_VERIFIED, 'consent_verified');

            return $updated;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function acceptManualProof(
        SuchakConsent $consent,
        User $actor,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakConsent {
        $consent->refresh()->loadMissing(['representation', 'suchakAccount']);
        $this->assertConsentSuchakActor($consent, $actor);
        $this->assertManualAcceptanceAllowed($consent);

        $evidenceNote = trim((string) ($attributes['evidence_note'] ?? ''));
        if (mb_strlen($evidenceNote) < 10) {
            throw new InvalidArgumentException('Manual consent evidence note is required.');
        }

        $validFrom = now();
        $validUntil = $this->validUntilFor($consent->consent_type, $validFrom);

        return DB::transaction(function () use ($consent, $actor, $attributes, $evidenceNote, $validFrom, $validUntil, $ipAddress, $userAgent): SuchakConsent {
            SuchakConsent::query()
                ->whereKey($consent->id)
                ->update([
                    'consent_status' => SuchakConsent::STATUS_ACCEPTED,
                    'consent_given_by_name' => $attributes['consent_given_by_name'] ?? $consent->consent_given_by_name,
                    'relationship_to_candidate' => $attributes['relationship_to_candidate'] ?? $consent->relationship_to_candidate,
                    'consent_mobile_number' => $attributes['consent_mobile_number'] ?? $consent->consent_mobile_number,
                    'accepted_at' => $validFrom,
                    'used_at' => $validFrom,
                    'valid_from' => $validFrom,
                    'valid_until' => $validUntil,
                ]);

            SuchakProfileRepresentation::query()
                ->whereKey($consent->representation_id)
                ->update([
                    'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
                    'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
                    'first_verified_consent_at' => $consent->representation->first_verified_consent_at ?? $validFrom,
                    'consent_verified_at' => $validFrom,
                    'consent_valid_until' => $validUntil,
                    'revoked_at' => null,
                ]);

            $updated = $consent->fresh(['representation']);
            $this->recordEvent($updated, SuchakConsentEvent::EVENT_CONSENT_ACCEPTED, SuchakConsentEvent::ACTOR_SUCHAK, $actor->id, $evidenceNote);
            $this->recordActivity($updated, $actor, SuchakActivityLog::ACTION_CONSENT_VERIFIED, 'consent_manual_proof_accepted', $ipAddress, $userAgent);

            return $updated;
        });
    }

    public function revoke(SuchakConsent $consent, User $actor, ?string $reason = null): SuchakConsent
    {
        $consent->refresh()->loadMissing('suchakAccount');
        $this->assertConsentSuchakActor($consent, $actor);

        if ($consent->consent_status === SuchakConsent::STATUS_REVOKED) {
            throw new InvalidArgumentException('Suchak consent is already revoked.');
        }

        return DB::transaction(function () use ($consent, $actor, $reason): SuchakConsent {
            SuchakConsent::query()
                ->whereKey($consent->id)
                ->update([
                    'consent_status' => SuchakConsent::STATUS_REVOKED,
                    'revoked_at' => now(),
                    'revocation_reason' => $reason,
                ]);

            SuchakProfileRepresentation::query()
                ->whereKey($consent->representation_id)
                ->update([
                    'representation_status' => SuchakProfileRepresentation::STATUS_REVOKED,
                    'consent_status' => SuchakProfileRepresentation::CONSENT_REVOKED,
                    'revoked_at' => now(),
                ]);

            $updated = $consent->fresh(['representation']);
            $this->recordEvent($updated, SuchakConsentEvent::EVENT_CONSENT_REVOKED, SuchakConsentEvent::ACTOR_SUCHAK, $actor->id, $reason);
            $this->recordActivity($updated, $actor, SuchakActivityLog::ACTION_CONSENT_REVOKED, 'consent_revoked');

            return $updated;
        });
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function assertAllowedValue(string $value, array $allowed, string $message): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }
    }

    private function assertSuchakActor(SuchakProfileRepresentation $representation, User $actor): void
    {
        if (! $this->accessService->canOperate($representation->suchakAccount)) {
            throw new InvalidArgumentException('Only verified Suchak accounts can request consent.');
        }

        if ((int) $representation->suchakAccount->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Only the representation Suchak actor can request consent.');
        }
    }

    private function assertConsentSuchakActor(SuchakConsent $consent, User $actor): void
    {
        if (! $this->accessService->canOperate($consent->suchakAccount)) {
            throw new InvalidArgumentException('Only verified Suchak accounts can manage consent.');
        }

        if ((int) $consent->suchakAccount->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Only the consent Suchak actor can manage consent.');
        }
    }

    private function assertRepresentationCanRequestConsent(SuchakProfileRepresentation $representation): void
    {
        if (! in_array($representation->representation_status, [
            SuchakProfileRepresentation::STATUS_PENDING,
            SuchakProfileRepresentation::STATUS_CONSENT_PENDING,
        ], true)) {
            throw new InvalidArgumentException('Consent can only be requested for pending Suchak representations.');
        }

        if ($representation->revoked_at !== null || $representation->candidate_deactivated_at !== null) {
            throw new InvalidArgumentException('Revoked or deactivated representations cannot request consent.');
        }
    }

    private function assertRepresentationCanRenewConsent(SuchakProfileRepresentation $representation): void
    {
        if ($representation->representation_status !== SuchakProfileRepresentation::STATUS_ACTIVE || ! $representation->hasValidConsent()) {
            throw new InvalidArgumentException('Consent renewal requires active representation with valid consent.');
        }

        if ($representation->revoked_at !== null || $representation->candidate_deactivated_at !== null) {
            throw new InvalidArgumentException('Revoked or deactivated representations cannot renew consent.');
        }
    }

    private function assertConsentCanReceiveOtp(SuchakConsent $consent): void
    {
        if (! in_array($consent->consent_status, [
            SuchakConsent::STATUS_REQUESTED,
            SuchakConsent::STATUS_LINK_OPENED,
            SuchakConsent::STATUS_OTP_SENT,
        ], true)) {
            throw new InvalidArgumentException('OTP can only be sent for open consent requests.');
        }

        if ($consent->isTokenExpired()) {
            throw new InvalidArgumentException('Consent token has expired.');
        }
    }

    private function assertManualAcceptanceAllowed(SuchakConsent $consent): void
    {
        if (! in_array($consent->consent_status, [
            SuchakConsent::STATUS_REQUESTED,
            SuchakConsent::STATUS_LINK_OPENED,
            SuchakConsent::STATUS_OTP_SENT,
            SuchakConsent::STATUS_OTP_VERIFIED,
        ], true)) {
            throw new InvalidArgumentException('Manual consent can only be accepted for open consent requests.');
        }

        if (! in_array($consent->consent_channel, [
            SuchakConsent::CHANNEL_OFFLINE_PROOF,
            SuchakConsent::CHANNEL_ADMIN_ASSISTED,
        ], true)) {
            throw new InvalidArgumentException('Manual consent proof requires offline or admin-assisted channel.');
        }

        if ($consent->isTokenExpired()) {
            throw new InvalidArgumentException('Consent token has expired.');
        }
    }

    private function assertConsentCanBeAccepted(SuchakConsent $consent): void
    {
        if (! in_array($consent->consent_status, [
            SuchakConsent::STATUS_OTP_SENT,
            SuchakConsent::STATUS_OTP_VERIFIED,
        ], true)) {
            throw new InvalidArgumentException('Consent can only be accepted after OTP is sent.');
        }

        if ($consent->isTokenExpired()) {
            throw new InvalidArgumentException('Consent token has expired.');
        }

        if ($consent->otp_attempts >= SuchakConsent::MAX_OTP_ATTEMPTS) {
            throw new InvalidArgumentException('OTP attempt limit exceeded for Suchak consent.');
        }
    }

    private function assertOtpFormat(string $otp): void
    {
        if (! preg_match('/^[0-9]{6}$/', $otp)) {
            throw new InvalidArgumentException('Consent OTP must be a 6 digit number.');
        }
    }

    private function validUntilFor(string $consentType, mixed $validFrom): mixed
    {
        return match ($consentType) {
            SuchakConsent::TYPE_ONE_YEAR => $validFrom->copy()->addMonths($this->policyService->consentValidityMonths()),
            SuchakConsent::TYPE_TWO_YEAR => $validFrom->copy()->addYears(2),
            SuchakConsent::TYPE_UNTIL_REVOKED => null,
            default => throw new InvalidArgumentException('Consent type is not allowed.'),
        };
    }

    private function assertConsentTypeAllowedByPolicy(string $consentType): void
    {
        if ($consentType === SuchakConsent::TYPE_TWO_YEAR && ! $this->policyService->allowsTwoYearConsent()) {
            throw new InvalidArgumentException('Two year Suchak consent is disabled by policy.');
        }

        if ($consentType === SuchakConsent::TYPE_UNTIL_REVOKED && ! $this->policyService->allowsUntilRevokedConsent()) {
            throw new InvalidArgumentException('Until-revoked Suchak consent is disabled by policy.');
        }
    }

    private function recordEvent(
        SuchakConsent $consent,
        string $eventType,
        string $actorType,
        ?int $actorId,
        ?string $eventNote = null,
    ): SuchakConsentEvent {
        return SuchakConsentEvent::query()->create([
            'consent_id' => $consent->id,
            'event_type' => $eventType,
            'event_note' => $eventNote,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'created_at' => now(),
        ]);
    }

    private function recordActivity(
        SuchakConsent $consent,
        ?User $actor,
        string $actionType,
        string $context,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $consent->suchak_account_id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actor === null ? SuchakActivityLog::ACTOR_SYSTEM : SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => $actionType,
            'target_type' => 'suchak_consent',
            'target_id' => $consent->id,
            'matrimony_profile_id' => $consent->matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => Str::limit((string) $userAgent, 512, ''),
            'metadata_json' => [
                'context' => $context,
                'representation_id' => $consent->representation_id,
                'consent_status' => $consent->consent_status,
            ],
        ]);
    }
}
