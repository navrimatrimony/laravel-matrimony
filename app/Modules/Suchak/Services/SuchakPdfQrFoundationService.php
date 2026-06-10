<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakActivityLog;
use App\Models\SuchakBiodataExport;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakQrToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakPdfQrFoundationService
{
    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakCandidateMaskingService $candidateMaskingService,
        private readonly SuchakAccessService $accessService,
        private readonly SuchakLimitService $limitService,
    ) {
    }

    /**
     * @return array{export: SuchakBiodataExport, qr_token: SuchakQrToken, raw_qr_token: string, qr_url_path: string}
     */
    public function createGovernedBiodataPdfExport(
        SuchakProfileRepresentation $representation,
        User $actor,
        ?string $filePath = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $representation->refresh()->loadMissing(['suchakAccount', 'matrimonyProfile']);
        $this->assertExportAllowed($representation, $actor);

        return DB::transaction(function () use ($representation, $actor, $filePath, $ipAddress, $userAgent): array {
            /** @var SuchakProfileRepresentation $lockedRepresentation */
            $lockedRepresentation = SuchakProfileRepresentation::query()
                ->whereKey($representation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRepresentation->loadMissing(['suchakAccount', 'matrimonyProfile']);
            $this->assertExportAllowed($lockedRepresentation, $actor);

            $export = SuchakBiodataExport::query()->create([
                'suchak_account_id' => $lockedRepresentation->suchak_account_id,
                'matrimony_profile_id' => $lockedRepresentation->matrimony_profile_id,
                'representation_id' => $lockedRepresentation->id,
                'export_type' => SuchakBiodataExport::TYPE_BIODATA_PDF,
                'file_path' => $filePath,
                'generated_by_user_id' => $actor->id,
            ]);

            $rawToken = $this->generateUniqueRawQrToken();

            $qrToken = SuchakQrToken::query()->create([
                'token_hash' => hash('sha256', $rawToken),
                'suchak_account_id' => $lockedRepresentation->suchak_account_id,
                'matrimony_profile_id' => $lockedRepresentation->matrimony_profile_id,
                'representation_id' => $lockedRepresentation->id,
                'export_id' => $export->id,
                'expires_at' => now()->addDays($this->qrTokenExpiryDays()),
                'scan_count' => 0,
            ]);

            $this->recordExportActivity($export, $actor, $ipAddress, $userAgent);
            $this->recordQrActivity($qrToken, SuchakActivityLog::ACTION_QR_GENERATED, 'qr_token_created', $actor, $ipAddress, $userAgent);

            return [
                'export' => $export->fresh(['qrTokens']),
                'qr_token' => $qrToken->fresh(['export']),
                'raw_qr_token' => $rawToken,
                'qr_url_path' => '/r/'.$rawToken,
            ];
        });
    }

    /**
     * @return array{qr_token: SuchakQrToken, candidate_summary: array<string, mixed>}
     */
    public function scanQrToken(
        string $rawToken,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        if ($rawToken === '') {
            throw new InvalidArgumentException('QR token is invalid.');
        }

        /** @var SuchakQrToken|null $qrToken */
        $qrToken = SuchakQrToken::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        if ($qrToken === null) {
            throw new InvalidArgumentException('QR token is invalid.');
        }

        $qrToken->incrementScan();
        $qrToken->loadMissing(['matrimonyProfile', 'representation.suchakAccount']);

        if ($qrToken->isExpired()) {
            $this->recordQrActivity($qrToken, SuchakActivityLog::ACTION_QR_SCANNED, 'qr_token_expired', null, $ipAddress, $userAgent);

            throw new InvalidArgumentException('QR token has expired.');
        }

        $this->assertQrTokenStillAllowed($qrToken);

        $this->recordQrActivity($qrToken, SuchakActivityLog::ACTION_QR_SCANNED, 'qr_token_scanned', null, $ipAddress, $userAgent);

        return [
            'qr_token' => $qrToken->fresh(['representation', 'matrimonyProfile']),
            'candidate_summary' => $this->candidateMaskingService->maskedSummary(
                $qrToken->matrimonyProfile,
                $qrToken->representation,
            ),
        ];
    }

    private function assertExportAllowed(SuchakProfileRepresentation $representation, User $actor): void
    {
        if (! $this->accessService->canOperate($representation->suchakAccount)) {
            throw new InvalidArgumentException('Only verified Suchak accounts can create governed PDF/QR exports.');
        }

        if ((int) $representation->suchakAccount->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Only the representation Suchak actor can create governed PDF/QR exports.');
        }

        if ($representation->representation_status !== SuchakProfileRepresentation::STATUS_ACTIVE || ! $representation->hasValidConsent()) {
            throw new InvalidArgumentException('PDF/QR export requires active representation with valid consent.');
        }
    }

    private function assertQrTokenStillAllowed(SuchakQrToken $qrToken): void
    {
        $representation = $qrToken->representation;
        $representation?->loadMissing('suchakAccount');

        if ($representation === null || ! $this->accessService->canOperate($representation->suchakAccount)) {
            $this->recordQrActivity($qrToken, SuchakActivityLog::ACTION_QR_SCANNED, 'qr_token_blocked_unverified_suchak');

            throw new InvalidArgumentException('QR token is no longer available.');
        }

        if ($representation->representation_status !== SuchakProfileRepresentation::STATUS_ACTIVE || ! $representation->hasValidConsent()) {
            $this->recordQrActivity($qrToken, SuchakActivityLog::ACTION_QR_SCANNED, 'qr_token_blocked_invalid_consent');

            throw new InvalidArgumentException('QR token is no longer available.');
        }
    }

    private function qrTokenExpiryDays(): int
    {
        return $this->limitService->qrTokenExpiryDays();
    }

    private function generateUniqueRawQrToken(): string
    {
        do {
            $rawToken = Str::random(64);
            $tokenHash = hash('sha256', $rawToken);
        } while (SuchakQrToken::query()->where('token_hash', $tokenHash)->exists());

        return $rawToken;
    }

    private function recordExportActivity(
        SuchakBiodataExport $export,
        User $actor,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $export->suchak_account_id,
            'actor_user_id' => $actor->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_PDF_GENERATED,
            'target_type' => 'suchak_biodata_export',
            'target_id' => $export->id,
            'matrimony_profile_id' => $export->matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => Str::limit((string) $userAgent, 512, ''),
            'metadata_json' => [
                'context' => 'biodata_pdf_export_created',
                'representation_id' => $export->representation_id,
                'export_type' => $export->export_type,
                'has_file_path' => $export->file_path !== null,
            ],
        ]);
    }

    private function recordQrActivity(
        SuchakQrToken $qrToken,
        string $actionType,
        string $context,
        ?User $actor = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $qrToken->suchak_account_id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actor === null ? SuchakActivityLog::ACTOR_SYSTEM : SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => $actionType,
            'target_type' => 'suchak_qr_token',
            'target_id' => $qrToken->id,
            'matrimony_profile_id' => $qrToken->matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => Str::limit((string) $userAgent, 512, ''),
            'metadata_json' => [
                'context' => $context,
                'representation_id' => $qrToken->representation_id,
                'export_id' => $qrToken->export_id,
                'expires_at' => $qrToken->expires_at?->toIso8601String(),
                'scan_count' => $qrToken->scan_count,
            ],
        ]);
    }
}
