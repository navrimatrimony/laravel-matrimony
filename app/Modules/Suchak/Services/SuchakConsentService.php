<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminSetting;
use App\Models\SuchakActivityLog;
use App\Models\SuchakConsent;
use App\Models\SuchakConsentEvent;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Services\Messaging\MetaWhatsAppCloudService;
use App\Support\MobileNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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
     * @return array{consent: SuchakConsent, raw_token: string, consent_url: string, message: string}
     */
    public function createSuchakRelayedLinkConsent(
        SuchakProfileRepresentation $representation,
        User $actor,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        return $this->createSecureLinkConsent(
            $representation,
            $actor,
            SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
            array_merge($attributes, [
                'delivery_status' => 'ready_to_send',
            ]),
            $ipAddress,
            $userAgent,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createOfflineProofConsent(
        SuchakProfileRepresentation $representation,
        User $actor,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakConsent {
        $proofDocument = $attributes['proof_document'] ?? null;
        if (! $proofDocument instanceof UploadedFile) {
            throw new InvalidArgumentException('Signed consent proof file is required.');
        }

        $payload = $this->normalizedConsentPayload($attributes, SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF);
        $payload['delivery_status'] = 'proof_uploaded';

        $result = $this->requestConsent(
            $representation,
            $actor,
            $payload,
            $ipAddress,
            $userAgent,
        );

        return $this->acceptManualProof(
            $result['consent'],
            $actor,
            array_merge($payload, $attributes),
            $ipAddress,
            $userAgent,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{consent: SuchakConsent, raw_token: string, consent_url: string, message: string}
     */
    public function createPlatformAssistedLinkConsent(
        SuchakProfileRepresentation $representation,
        User $actor,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        return $this->createSecureLinkConsent(
            $representation,
            $actor,
            SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK,
            array_merge($attributes, [
                'delivery_status' => 'manual_delivery_pending',
            ]),
            $ipAddress,
            $userAgent,
        );
    }

    public function resolvePublicConsentToken(string $token): ?SuchakConsent
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            return null;
        }

        $consent = SuchakConsent::query()
            ->with([
                'suchakAccount',
                'matrimonyProfile.gender',
                'matrimonyProfile.maritalStatus',
                'matrimonyProfile.location.parent.parent.parent',
                'representation',
            ])
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($consent === null) {
            return null;
        }

        if ($consent->consent_status === SuchakConsent::STATUS_REQUESTED
            && ! $consent->isTokenExpired()
            && $consent->public_token_used_at === null
        ) {
            SuchakConsent::query()
                ->whereKey($consent->id)
                ->update(['consent_status' => SuchakConsent::STATUS_LINK_OPENED]);

            $opened = $consent->fresh([
                'suchakAccount',
                'matrimonyProfile.gender',
                'matrimonyProfile.maritalStatus',
                'matrimonyProfile.location.parent.parent.parent',
                'representation',
            ]);
            $this->recordEvent($opened, SuchakConsentEvent::EVENT_WHATSAPP_LINK_OPENED, SuchakConsentEvent::ACTOR_CANDIDATE, null);

            return $opened;
        }

        return $consent;
    }

    public function recordPublicConsentDecision(
        SuchakConsent $consent,
        string $decision,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakConsent {
        if (! in_array($decision, [SuchakConsent::STATUS_ACCEPTED, SuchakConsent::STATUS_REJECTED], true)) {
            throw new InvalidArgumentException('Consent decision is not allowed.');
        }

        $consent->refresh()->loadMissing(['representation', 'suchakAccount']);
        $this->assertPublicDecisionAllowed($consent);

        if ($consent->isTokenExpired()) {
            $this->expireConsent($consent);

            throw new InvalidArgumentException('Consent link has expired.');
        }

        $decidedAt = now();
        $validUntil = $decision === SuchakConsent::STATUS_ACCEPTED
            ? $this->validUntilFor($consent->consent_type, $decidedAt)
            : null;

        return DB::transaction(function () use ($consent, $decision, $decidedAt, $validUntil, $ipAddress, $userAgent): SuchakConsent {
            $common = [
                'consent_status' => $decision,
                'submitted_mobile' => $consent->intended_mobile,
                'mobile_match' => true,
                'used_at' => $decidedAt,
                'public_token_used_at' => $decidedAt,
                'decided_at' => $decidedAt,
                'ip_address' => $ipAddress,
                'user_agent' => Str::limit((string) $userAgent, 512, ''),
            ];

            if ($decision === SuchakConsent::STATUS_ACCEPTED) {
                SuchakConsent::query()
                    ->whereKey($consent->id)
                    ->update($common + [
                        'accepted_at' => $decidedAt,
                        'valid_from' => $decidedAt,
                        'valid_until' => $validUntil,
                    ]);

                SuchakProfileRepresentation::query()
                    ->whereKey($consent->representation_id)
                    ->update([
                        'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
                        'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
                        'first_verified_consent_at' => $consent->representation->first_verified_consent_at ?? $decidedAt,
                        'consent_verified_at' => $decidedAt,
                        'consent_valid_until' => $validUntil,
                        'revoked_at' => null,
                    ]);
            } else {
                SuchakConsent::query()
                    ->whereKey($consent->id)
                    ->update($common + [
                        'rejected_at' => $decidedAt,
                    ]);

                SuchakProfileRepresentation::query()
                    ->whereKey($consent->representation_id)
                    ->update([
                        'representation_status' => SuchakProfileRepresentation::STATUS_REJECTED,
                        'consent_status' => SuchakProfileRepresentation::CONSENT_REJECTED,
                    ]);
            }

            $updated = $consent->fresh(['representation']);
            $eventType = $decision === SuchakConsent::STATUS_ACCEPTED
                ? SuchakConsentEvent::EVENT_CONSENT_ACCEPTED
                : SuchakConsentEvent::EVENT_CONSENT_REJECTED;
            $activityType = $decision === SuchakConsent::STATUS_ACCEPTED
                ? SuchakActivityLog::ACTION_CONSENT_VERIFIED
                : SuchakActivityLog::ACTION_CONSENT_REJECTED;
            $context = $decision === SuchakConsent::STATUS_ACCEPTED
                ? 'public_consent_accepted'
                : 'public_consent_rejected';

            $this->recordEvent($updated, $eventType, SuchakConsentEvent::ACTOR_CANDIDATE, null, 'Decision recorded from secure consent link.');
            $this->recordActivity($updated, null, $activityType, $context, $ipAddress, $userAgent);

            return $updated;
        });
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
        $consentMethod = (string) ($attributes['consent_method'] ?? $this->methodForChannel($consentChannel));
        if ($consentMethod !== '' && ! in_array($consentMethod, SuchakConsent::METHODS, true)) {
            throw new InvalidArgumentException('Consent method is not allowed.');
        }

        return DB::transaction(function () use ($representation, $actor, $attributes, $consentType, $consentChannel, $consentMethod, $ipAddress, $userAgent, $isRenewal): array {
            $openConsent = SuchakConsent::query()
                ->where('representation_id', $representation->id)
                ->whereIn('consent_status', SuchakConsent::PENDING_ACTION_STATUSES)
                ->lockForUpdate()
                ->first();

            if ($openConsent !== null) {
                throw new InvalidArgumentException('An open consent already exists for this representation.');
            }

            $rawToken = Str::random(64);
            $expiresAt = now()->addDays(SuchakConsent::DEFAULT_TOKEN_EXPIRY_DAYS);
            $giverRelation = $attributes['consent_giver_relation']
                ?? $attributes['relationship_to_candidate']
                ?? null;
            $intendedMobile = $attributes['intended_mobile']
                ?? $attributes['consent_mobile_number']
                ?? null;

            $consent = SuchakConsent::query()->create([
                'suchak_account_id' => $representation->suchak_account_id,
                'matrimony_profile_id' => $representation->matrimony_profile_id,
                'representation_id' => $representation->id,
                'consent_status' => SuchakConsent::STATUS_REQUESTED,
                'consent_type' => $consentType,
                'consent_text_snapshot' => (string) ($attributes['consent_text_snapshot'] ?? self::CONSENT_TEXT_V1),
                'consent_template_version' => SuchakConsent::TEMPLATE_VERSION_V1,
                'consent_text_version' => (string) ($attributes['consent_text_version'] ?? SuchakConsent::CONSENT_TEXT_VERSION_V1),
                'consent_given_by_name' => $attributes['consent_given_by_name'] ?? null,
                'relationship_to_candidate' => $giverRelation,
                'consent_giver_relation' => $giverRelation,
                'consent_mobile_number' => $attributes['consent_mobile_number'] ?? $intendedMobile,
                'intended_mobile' => $intendedMobile,
                'submitted_mobile' => $attributes['submitted_mobile'] ?? null,
                'mobile_match' => (bool) ($attributes['mobile_match'] ?? false),
                'token_hash' => hash('sha256', $rawToken),
                'token_expires_at' => $expiresAt,
                'expires_at' => $expiresAt,
                'otp_attempts' => 0,
                'consent_channel' => $consentChannel,
                'consent_method' => $consentMethod,
                'delivery_status' => $attributes['delivery_status'] ?? null,
                'ip_address' => $ipAddress,
                'user_agent' => Str::limit((string) $userAgent, 512, ''),
            ]);

            if (! (bool) ($attributes['_preserve_representation_status'] ?? false)) {
                SuchakProfileRepresentation::query()
                    ->whereKey($representation->id)
                    ->update([
                        'representation_status' => SuchakProfileRepresentation::STATUS_CONSENT_PENDING,
                        'consent_status' => SuchakProfileRepresentation::CONSENT_REQUESTED,
                        'consent_verified_at' => null,
                        'consent_valid_until' => null,
                        'revoked_at' => null,
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
     * @return array{consent: SuchakConsent, raw_token: string, consent_url: string, message: string}
     */
    public function resendConsent(
        SuchakConsent $consent,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $consent->refresh()->loadMissing('suchakAccount');
        $this->assertConsentSuchakActor($consent, $actor);
        $this->assertConsentCanReceiveSecureLink($consent);

        return DB::transaction(function () use ($consent, $actor, $ipAddress, $userAgent): array {
            $rawToken = Str::random(64);
            $expiresAt = now()->addDays(SuchakConsent::DEFAULT_TOKEN_EXPIRY_DAYS);

            SuchakConsent::query()
                ->whereKey($consent->id)
                ->update([
                    'token_hash' => hash('sha256', $rawToken),
                    'token_expires_at' => $expiresAt,
                    'expires_at' => $expiresAt,
                    'public_token_used_at' => null,
                    'used_at' => null,
                ]);

            $updated = $consent->fresh(['representation', 'events', 'suchakAccount', 'matrimonyProfile']);
            $this->recordEvent($updated, SuchakConsentEvent::EVENT_REQUESTED, SuchakConsentEvent::ACTOR_SUCHAK, $actor->id, 'Consent request resent.');
            $this->recordActivity($updated, $actor, SuchakActivityLog::ACTION_CONSENT_REQUESTED, 'consent_request_resent', $ipAddress, $userAgent);
            $consentUrl = $this->publicConsentUrl($rawToken);

            return [
                'consent' => $updated,
                'raw_token' => $rawToken,
                'consent_url' => $consentUrl,
                'message' => $this->secureLinkMessage($updated, $consentUrl),
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
     * Platform-generated OTP flow. The raw OTP is never persisted; in dev mode it
     * is returned once so local/demo flows can be verified without a provider.
     *
     * @return array{consent: SuchakConsent, raw_otp: string|null, delivery: string, suchak_message: string}
     */
    public function issuePlatformOtp(
        SuchakConsent $consent,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $consent->refresh()->loadMissing(['suchakAccount', 'matrimonyProfile']);
        $this->assertConsentSuchakActor($consent, $actor);
        $this->assertConsentCanReceiveOtp($consent);

        $mobile = MobileNumber::normalize($consent->consent_mobile_number);
        if ($mobile === null) {
            throw new InvalidArgumentException('Customer mobile number is required for platform OTP consent.');
        }

        $mode = (string) AdminSetting::getValue('mobile_verification_mode', 'dev_show');
        if ($mode === 'off') {
            throw new InvalidArgumentException('Platform OTP delivery is disabled.');
        }

        $otp = (string) random_int(100000, 999999);
        $delivery = 'dev_show';
        if ($mode !== 'dev_show') {
            /** @var MetaWhatsAppCloudService $whatsapp */
            $whatsapp = app(MetaWhatsAppCloudService::class);
            if (! $whatsapp->isConfiguredForOtp()) {
                throw new InvalidArgumentException('Platform WhatsApp OTP provider is not configured.');
            }

            if (! $whatsapp->sendOtp($mobile, $otp)) {
                throw new InvalidArgumentException('Platform could not send OTP to the customer.');
            }

            $delivery = 'whatsapp';
        }

        $updated = DB::transaction(function () use ($consent, $otp, $delivery, $ipAddress, $userAgent): SuchakConsent {
            SuchakConsent::query()
                ->whereKey($consent->id)
                ->update([
                    'consent_status' => SuchakConsent::STATUS_OTP_SENT,
                    'otp_hash' => Hash::make($otp),
                    'last_otp_sent_at' => now(),
                ]);

            $updated = $consent->fresh(['matrimonyProfile']);
            $note = $delivery === 'dev_show'
                ? 'Platform generated customer OTP in demo mode.'
                : 'Platform sent customer OTP through configured provider.';
            $this->recordEvent($updated, SuchakConsentEvent::EVENT_OTP_SENT, SuchakConsentEvent::ACTOR_SYSTEM, null, $note);
            $this->recordActivity($updated, null, SuchakActivityLog::ACTION_CONSENT_OTP_SENT, 'consent_otp_sent_by_platform', $ipAddress, $userAgent);

            return $updated;
        });

        return [
            'consent' => $updated,
            'raw_otp' => $delivery === 'dev_show' ? $otp : null,
            'delivery' => $delivery,
            'suchak_message' => $this->suchakRelayMessage($updated),
        ];
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

        $proofDocument = $attributes['proof_document'] ?? null;
        if (! $proofDocument instanceof UploadedFile) {
            throw new InvalidArgumentException('Signed consent proof file is required.');
        }

        $proofPath = $proofDocument->store('suchak/consent-proofs/'.$consent->suchak_account_id.'/'.$consent->id, 'local');
        if (! is_string($proofPath) || $proofPath === '') {
            throw new InvalidArgumentException('Unable to store signed consent proof file.');
        }

        $proofNote = implode("\n", array_filter([
            $evidenceNote !== '' ? $evidenceNote : 'Signed consent proof uploaded.',
            'Proof file: '.$proofPath,
            'Original file: '.Str::limit($proofDocument->getClientOriginalName(), 160, ''),
        ]));

        $validFrom = now();
        $validUntil = $this->validUntilFor($consent->consent_type, $validFrom);

        return DB::transaction(function () use ($consent, $actor, $attributes, $proofDocument, $proofPath, $proofNote, $validFrom, $validUntil, $ipAddress, $userAgent): SuchakConsent {
            SuchakConsent::query()
                ->whereKey($consent->id)
                ->update([
                    'consent_status' => SuchakConsent::STATUS_ACCEPTED,
                    'consent_given_by_name' => $attributes['consent_given_by_name'] ?? $consent->consent_given_by_name,
                    'relationship_to_candidate' => $attributes['consent_giver_relation'] ?? $attributes['relationship_to_candidate'] ?? $consent->relationship_to_candidate,
                    'consent_giver_relation' => $attributes['consent_giver_relation'] ?? $attributes['relationship_to_candidate'] ?? $consent->consent_giver_relation,
                    'consent_mobile_number' => $attributes['consent_mobile_number'] ?? $attributes['intended_mobile'] ?? $consent->consent_mobile_number,
                    'intended_mobile' => $attributes['intended_mobile'] ?? $attributes['consent_mobile_number'] ?? $consent->intended_mobile,
                    'submitted_mobile' => $attributes['intended_mobile'] ?? $attributes['consent_mobile_number'] ?? $consent->submitted_mobile,
                    'mobile_match' => false,
                    'accepted_at' => $validFrom,
                    'used_at' => $validFrom,
                    'decided_at' => $validFrom,
                    'valid_from' => $validFrom,
                    'valid_until' => $validUntil,
                    'proof_file_path' => $proofPath,
                    'proof_original_name' => Str::limit($proofDocument->getClientOriginalName(), 160, ''),
                    'proof_uploaded_at' => $validFrom,
                    'delivery_status' => 'proof_uploaded',
                    'ip_address' => $ipAddress,
                    'user_agent' => Str::limit((string) $userAgent, 512, ''),
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
            $this->recordEvent($updated, SuchakConsentEvent::EVENT_CONSENT_ACCEPTED, SuchakConsentEvent::ACTOR_SUCHAK, $actor->id, $proofNote);
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

    public function cancelPendingConsent(SuchakConsent $consent, User $actor, ?string $reason = null): SuchakConsent
    {
        $consent->refresh()->loadMissing(['representation', 'suchakAccount']);
        $this->assertConsentSuchakActor($consent, $actor);

        if (! in_array($consent->consent_status, SuchakConsent::PENDING_ACTION_STATUSES, true)) {
            throw new InvalidArgumentException('Only pending consent requests can be cancelled.');
        }

        return DB::transaction(function () use ($consent, $actor, $reason): SuchakConsent {
            $representation = $consent->representation()->lockForUpdate()->firstOrFail();
            $hasAcceptedConsent = SuchakConsent::query()
                ->where('representation_id', $consent->representation_id)
                ->where('id', '!=', $consent->id)
                ->where('consent_status', SuchakConsent::STATUS_ACCEPTED)
                ->whereNull('revoked_at')
                ->exists();

            SuchakConsent::query()
                ->whereKey($consent->id)
                ->update([
                    'consent_status' => SuchakConsent::STATUS_CANCELLED,
                    'revoked_at' => now(),
                    'revocation_reason' => $reason ?: 'Pending consent request cancelled by Suchak.',
                ]);

            SuchakProfileRepresentation::query()
                ->whereKey($representation->id)
                ->update($hasAcceptedConsent ? [
                    'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
                    'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
                    'revoked_at' => null,
                ] : [
                    'representation_status' => $representation->representation_status === SuchakProfileRepresentation::STATUS_CONSENT_PENDING
                        ? SuchakProfileRepresentation::STATUS_PENDING
                        : $representation->representation_status,
                    'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
                    'consent_verified_at' => null,
                    'consent_valid_until' => null,
                    'revoked_at' => null,
                ]);

            $updated = $consent->fresh(['representation']);
            $this->recordEvent($updated, SuchakConsentEvent::EVENT_CONSENT_CANCELLED, SuchakConsentEvent::ACTOR_SUCHAK, $actor->id, $reason ?: 'Pending consent request cancelled by Suchak.');
            $this->recordActivity($updated, $actor, SuchakActivityLog::ACTION_CONSENT_REVOKED, 'consent_request_cancelled');

            return $updated;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{consent: SuchakConsent, raw_token: string, consent_url: string, message: string}
     */
    private function createSecureLinkConsent(
        SuchakProfileRepresentation $representation,
        User $actor,
        string $method,
        array $attributes,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        $payload = $this->normalizedConsentPayload($attributes, $method);
        $result = $this->requestConsent($representation, $actor, $payload, $ipAddress, $userAgent);
        $consent = $result['consent']->fresh(['representation', 'suchakAccount', 'matrimonyProfile']);
        $consentUrl = $this->publicConsentUrl($result['raw_token']);

        return [
            'consent' => $consent,
            'raw_token' => $result['raw_token'],
            'consent_url' => $consentUrl,
            'message' => $this->secureLinkMessage($consent, $consentUrl),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizedConsentPayload(array $attributes, string $method): array
    {
        $this->assertAllowedValue($method, SuchakConsent::METHODS, 'Consent method is not allowed.');
        $requiresMobile = in_array($method, [
            SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
            SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK,
            SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF,
        ], true);
        $mobile = MobileNumber::normalize((string) ($attributes['intended_mobile'] ?? $attributes['consent_mobile_number'] ?? ''));
        if ($requiresMobile && $mobile === null) {
            throw new InvalidArgumentException('Requested mobile number is required.');
        }

        $giverRelation = trim((string) ($attributes['consent_giver_relation'] ?? $attributes['relationship_to_candidate'] ?? ''));
        $giverName = trim((string) ($attributes['consent_given_by_name'] ?? ''));

        return array_merge($attributes, [
            'consent_type' => (string) ($attributes['consent_type'] ?? SuchakConsent::TYPE_ONE_YEAR),
            'consent_channel' => $method,
            'consent_method' => $method,
            'consent_text_snapshot' => (string) ($attributes['consent_text_snapshot'] ?? self::CONSENT_TEXT_V1),
            'consent_text_version' => SuchakConsent::CONSENT_TEXT_VERSION_V1,
            'consent_given_by_name' => $giverName !== '' ? $giverName : null,
            'relationship_to_candidate' => $giverRelation !== '' ? $giverRelation : null,
            'consent_giver_relation' => $giverRelation !== '' ? $giverRelation : null,
            'consent_mobile_number' => $mobile,
            'intended_mobile' => $mobile,
        ]);
    }

    private function methodForChannel(string $channel): string
    {
        return match ($channel) {
            SuchakConsent::CHANNEL_OFFLINE_PROOF, SuchakConsent::CHANNEL_OFFLINE_SIGNED_PROOF => SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF,
            SuchakConsent::CHANNEL_ADMIN_ASSISTED, SuchakConsent::CHANNEL_PLATFORM_ASSISTED_LINK => SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK,
            default => SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
        };
    }

    private function publicConsentUrl(string $rawToken): string
    {
        return route('suchak.consents.public.show', ['token' => $rawToken]);
    }

    private function secureLinkMessage(SuchakConsent $consent, string $consentUrl): string
    {
        $suchakName = $this->suchakDisplayName($consent);
        $summary = $this->consentCandidateSummary($consent);
        $nameLabel = $summary['name_label'];
        $candidateName = $summary['candidate_name'];
        $age = $summary['age'];
        $privacyParagraph = $this->policyService->consentWhatsappPrivacyParagraph();

        return "नमस्कार,\n\n"
            ."मी {$suchakName}.\n\n"
            ."तुमच्या विवाहस्थळाची माहिती अनुरूप, योग्य आणि चांगल्या स्थळांपर्यंत पुढे पाठवण्यासाठी मला तुमची परवानगी हवी आहे.\n\n"
            ."स्थळाचा थोडक्यात तपशील:\n"
            ."• {$nameLabel}: {$candidateName}\n"
            ."• वय: {$age}\n\n"
            .$privacyParagraph."\n\n"
            ."कृपया पुढील प्रक्रियेसाठी खालील लिंकवर क्लिक करा आणि आपला निर्णय निवडा:\n"
            .$consentUrl;
    }

    /**
     * @return array{name_label: string, candidate_name: string, age: string}
     */
    private function consentCandidateSummary(SuchakConsent $consent): array
    {
        $consent->loadMissing(['matrimonyProfile.gender']);
        $profile = $consent->matrimonyProfile;
        if ($profile === null) {
            return [
                'name_label' => 'उमेदवाराचे नाव',
                'candidate_name' => 'उपलब्ध नाही',
                'age' => 'उपलब्ध नाही',
            ];
        }

        $genderKey = strtolower(trim((string) ($profile->gender?->key ?? '')));

        return [
            'name_label' => match ($genderKey) {
                'female' => 'वधूचे नाव',
                'male' => 'वराचे नाव',
                default => 'उमेदवाराचे नाव',
            },
            'candidate_name' => trim((string) ($profile->full_name ?? '')) ?: 'उपलब्ध नाही',
            'age' => $this->consentCandidateAge($profile->date_of_birth),
        ];
    }

    private function consentCandidateAge(mixed $dateOfBirth): string
    {
        if ($dateOfBirth === null || $dateOfBirth === '') {
            return 'उपलब्ध नाही';
        }

        try {
            $age = Carbon::parse($dateOfBirth)->age;
        } catch (\Throwable) {
            return 'उपलब्ध नाही';
        }

        return $age >= 18 && $age <= 100 ? $age.' वर्षे' : 'उपलब्ध नाही';
    }

    private function suchakDisplayName(SuchakConsent $consent): string
    {
        $account = $consent->suchakAccount;
        $name = trim((string) ($account?->office_name_mr
            ?: $account?->office_name
            ?: $account?->suchak_name_mr
            ?: $account?->suchak_name
            ?: 'Suchak'));

        return $name !== '' ? $name : 'Suchak';
    }

    private function assertPublicDecisionAllowed(SuchakConsent $consent): void
    {
        if (! in_array($consent->consent_method, SuchakConsent::LINK_METHODS, true)
            && ! in_array($consent->consent_channel, [
                SuchakConsent::CHANNEL_SUCHAK_RELAYED_LINK,
                SuchakConsent::CHANNEL_PLATFORM_ASSISTED_LINK,
                SuchakConsent::CHANNEL_WHATSAPP_DEEP_LINK,
                SuchakConsent::CHANNEL_ADMIN_ASSISTED,
            ], true)) {
            throw new InvalidArgumentException('This consent request is not a secure link request.');
        }

        if ($consent->public_token_used_at !== null || $consent->used_at !== null) {
            throw new InvalidArgumentException('Consent link has already been used.');
        }

        if (! in_array($consent->consent_status, [
            SuchakConsent::STATUS_REQUESTED,
            SuchakConsent::STATUS_LINK_OPENED,
        ], true)) {
            throw new InvalidArgumentException('Consent link is no longer active.');
        }
    }

    private function assertConsentCanReceiveSecureLink(SuchakConsent $consent): void
    {
        if (! in_array($consent->consent_method, SuchakConsent::LINK_METHODS, true)
            && ! in_array($consent->consent_channel, [
                SuchakConsent::CHANNEL_SUCHAK_RELAYED_LINK,
                SuchakConsent::CHANNEL_PLATFORM_ASSISTED_LINK,
                SuchakConsent::CHANNEL_WHATSAPP_DEEP_LINK,
                SuchakConsent::CHANNEL_ADMIN_ASSISTED,
            ], true)) {
            throw new InvalidArgumentException('Only secure link consent requests can be resent.');
        }

        if (! in_array($consent->consent_status, [
            SuchakConsent::STATUS_REQUESTED,
            SuchakConsent::STATUS_LINK_OPENED,
        ], true)) {
            throw new InvalidArgumentException('Only open consent links can be resent.');
        }

        if ($consent->isTokenExpired()) {
            throw new InvalidArgumentException('Consent token has expired.');
        }
    }

    private function expireConsent(SuchakConsent $consent): SuchakConsent
    {
        if ($consent->consent_status === SuchakConsent::STATUS_EXPIRED) {
            return $consent;
        }

        return DB::transaction(function () use ($consent): SuchakConsent {
            SuchakConsent::query()
                ->whereKey($consent->id)
                ->update([
                    'consent_status' => SuchakConsent::STATUS_EXPIRED,
                    'decided_at' => now(),
                ]);

            SuchakProfileRepresentation::query()
                ->whereKey($consent->representation_id)
                ->update([
                    'representation_status' => SuchakProfileRepresentation::STATUS_EXPIRED,
                    'consent_status' => SuchakProfileRepresentation::CONSENT_EXPIRED,
                ]);

            $updated = $consent->fresh(['representation']);
            $this->recordEvent($updated, SuchakConsentEvent::EVENT_CONSENT_EXPIRED, SuchakConsentEvent::ACTOR_SYSTEM, null, 'Secure consent link expired.');
            $this->recordActivity($updated, null, SuchakActivityLog::ACTION_CONSENT_EXPIRED, 'public_consent_expired');

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
        if (! $this->accessService->canPrepareCustomers($representation->suchakAccount)) {
            throw new InvalidArgumentException('Only active Suchak accounts can request consent.');
        }

        if ((int) $representation->suchakAccount->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Only the representation Suchak actor can request consent.');
        }
    }

    private function assertConsentSuchakActor(SuchakConsent $consent, User $actor): void
    {
        if (! $this->accessService->canPrepareCustomers($consent->suchakAccount)) {
            throw new InvalidArgumentException('Only active Suchak accounts can manage consent.');
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
            SuchakProfileRepresentation::STATUS_REJECTED,
            SuchakProfileRepresentation::STATUS_EXPIRED,
            SuchakProfileRepresentation::STATUS_REVOKED,
        ], true)) {
            throw new InvalidArgumentException('Consent can only be requested for pending Suchak representations.');
        }

        if ($representation->candidate_deactivated_at !== null) {
            throw new InvalidArgumentException('Deactivated representations cannot request consent.');
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
            SuchakConsent::CHANNEL_OFFLINE_SIGNED_PROOF,
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

    private function suchakRelayMessage(SuchakConsent $consent): string
    {
        $profileLabel = '#'.$consent->matrimony_profile_id;

        return trim("Consent request for matrimony profile {$profileLabel}.\n"
            ."Please read this consent text: {$consent->consent_text_snapshot}\n"
            .'The platform has sent a 6 digit OTP to your registered mobile number. '
            .'If you agree, reply with that OTP to your Suchak so consent can be verified.');
    }
}
