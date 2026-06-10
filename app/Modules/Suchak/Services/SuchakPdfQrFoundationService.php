<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBiodataExport;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakQrToken;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class SuchakPdfQrFoundationService
{
    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakCandidateMaskingService $candidateMaskingService,
        private readonly SuchakAccessService $accessService,
        private readonly SuchakLimitService $limitService,
        private readonly SuchakQrCodeImageService $qrCodeImageService,
    ) {}

    /**
     * @return array{export: SuchakBiodataExport, qr_token: SuchakQrToken, raw_qr_token: string, qr_url_path: string, pdf_file_path: string}
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

        $storedFilePath = null;

        try {
            return DB::transaction(function () use ($representation, $actor, $filePath, $ipAddress, $userAgent, &$storedFilePath): array {
                /** @var SuchakProfileRepresentation $lockedRepresentation */
                $lockedRepresentation = SuchakProfileRepresentation::query()
                    ->whereKey($representation->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedRepresentation->loadMissing(['suchakAccount', 'matrimonyProfile']);
                /** @var SuchakAccount $lockedAccount */
                $lockedAccount = SuchakAccount::query()
                    ->whereKey($lockedRepresentation->suchak_account_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedRepresentation->setRelation('suchakAccount', $lockedAccount);
                $this->assertExportAllowed($lockedRepresentation, $actor);

                $oldQrTokens = SuchakQrToken::query()
                    ->where('representation_id', $lockedRepresentation->id)
                    ->whereNull('revoked_at')
                    ->lockForUpdate()
                    ->get();

                $export = SuchakBiodataExport::query()->create([
                    'suchak_account_id' => $lockedRepresentation->suchak_account_id,
                    'matrimony_profile_id' => $lockedRepresentation->matrimony_profile_id,
                    'representation_id' => $lockedRepresentation->id,
                    'export_type' => SuchakBiodataExport::TYPE_BIODATA_PDF,
                    'file_path' => null,
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

                $storedFilePath = $filePath ?: $this->defaultExportFilePath($export);
                $pdfBinary = $this->renderBiodataPdf(
                    $export,
                    $qrToken,
                    $lockedRepresentation,
                    $rawToken,
                );

                if (! Storage::disk('local')->put($storedFilePath, $pdfBinary)) {
                    throw new InvalidArgumentException('Unable to store Suchak biodata PDF.');
                }

                SuchakBiodataExport::query()
                    ->whereKey($export->id)
                    ->update(['file_path' => $storedFilePath]);

                $export = $export->fresh(['qrTokens']);
                $this->revokeReplacedQrTokens($oldQrTokens, $qrToken, $actor, $ipAddress, $userAgent);
                $this->recordExportActivity($export, $actor, $ipAddress, $userAgent);
                $this->recordQrActivity($qrToken, SuchakActivityLog::ACTION_QR_GENERATED, 'qr_token_created', $actor, $ipAddress, $userAgent);

                return [
                    'export' => $export,
                    'qr_token' => $qrToken->fresh(['export']),
                    'raw_qr_token' => $rawToken,
                    'qr_url_path' => '/r/'.$rawToken,
                    'pdf_file_path' => $storedFilePath,
                ];
            });
        } catch (Throwable $exception) {
            if ($storedFilePath !== null && Storage::disk('local')->exists($storedFilePath)) {
                Storage::disk('local')->delete($storedFilePath);
            }

            throw $exception;
        }
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

        if ($qrToken->isRevoked()) {
            $this->recordQrActivity($qrToken, SuchakActivityLog::ACTION_QR_SCANNED, 'qr_token_revoked', null, $ipAddress, $userAgent);

            throw new InvalidArgumentException('QR token has been revoked.');
        }

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

    public function markExportDownloaded(
        SuchakBiodataExport $export,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakBiodataExport {
        $export->loadMissing('suchakAccount');
        $this->assertExportOwnerCanOperate($export, $actor);

        if (! is_string($export->file_path) || trim($export->file_path) === '') {
            throw new InvalidArgumentException('Suchak biodata PDF file is not available.');
        }

        if (! Storage::disk('local')->exists($export->file_path)) {
            throw new InvalidArgumentException('Suchak biodata PDF file is not available.');
        }

        SuchakBiodataExport::query()
            ->whereKey($export->id)
            ->update(['downloaded_at' => now()]);

        $freshExport = $export->fresh(['suchakAccount']);
        $this->recordExportLifecycleActivity($freshExport, $actor, SuchakActivityLog::ACTION_PDF_DOWNLOADED, 'biodata_pdf_downloaded', $ipAddress, $userAgent);

        return $freshExport;
    }

    public function markExportShared(
        SuchakBiodataExport $export,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakBiodataExport {
        $export->loadMissing('suchakAccount');
        $this->assertExportOwnerCanOperate($export, $actor);

        SuchakBiodataExport::query()
            ->whereKey($export->id)
            ->update(['shared_at' => now()]);

        $freshExport = $export->fresh(['suchakAccount']);
        $this->recordExportLifecycleActivity($freshExport, $actor, SuchakActivityLog::ACTION_PDF_SHARED, 'biodata_pdf_shared', $ipAddress, $userAgent);

        return $freshExport;
    }

    public function revokeQrToken(
        SuchakQrToken $qrToken,
        User $actor,
        string $reason = 'manual_revoke',
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakQrToken {
        $qrToken->loadMissing('suchakAccount');
        $this->assertQrTokenOwnerCanOperate($qrToken, $actor);

        if (! $qrToken->isRevoked()) {
            SuchakQrToken::query()
                ->whereKey($qrToken->id)
                ->update([
                    'revoked_at' => now(),
                    'revoked_reason' => $this->safeQrRevocationReason($reason),
                    'updated_at' => now(),
                ]);
        }

        $freshQrToken = $qrToken->fresh(['suchakAccount']);
        $this->recordQrActivity($freshQrToken, SuchakActivityLog::ACTION_QR_REVOKED, 'qr_token_manual_revoke', $actor, $ipAddress, $userAgent);

        return $freshQrToken;
    }

    private function renderBiodataPdf(
        SuchakBiodataExport $export,
        SuchakQrToken $qrToken,
        SuchakProfileRepresentation $representation,
        string $rawToken,
    ): string {
        $representation->loadMissing(['suchakAccount', 'matrimonyProfile']);
        $qrUrl = url('/r/'.$rawToken);
        $candidateSummary = $this->candidateMaskingService->maskedSummary(
            $representation->matrimonyProfile,
            $representation,
        );

        return Pdf::loadView('suchak.pdf.branded-biodata', [
            'candidateSummary' => $candidateSummary,
            'export' => $export,
            'generatedAt' => now(),
            'qrImageDataUri' => $this->qrCodeImageService->svgDataUri($qrUrl),
            'qrToken' => $qrToken,
            'qrUrl' => $qrUrl,
            'suchakAccount' => $representation->suchakAccount,
        ])->output();
    }

    private function defaultExportFilePath(SuchakBiodataExport $export): string
    {
        return 'suchak/biodata-exports/'.$export->suchak_account_id.'/biodata-export-'.$export->id.'.pdf';
    }

    /**
     * @param  iterable<SuchakQrToken>  $oldQrTokens
     */
    private function revokeReplacedQrTokens(
        iterable $oldQrTokens,
        SuchakQrToken $replacementQrToken,
        User $actor,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        foreach ($oldQrTokens as $oldQrToken) {
            SuchakQrToken::query()
                ->whereKey($oldQrToken->id)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revoked_reason' => 'regenerated',
                    'replaced_by_token_id' => $replacementQrToken->id,
                    'updated_at' => now(),
                ]);

            $this->recordQrActivity(
                $oldQrToken->fresh(),
                SuchakActivityLog::ACTION_QR_REVOKED,
                'qr_token_replaced_by_regeneration',
                $actor,
                $ipAddress,
                $userAgent,
            );
        }
    }

    private function assertExportOwnerCanOperate(SuchakBiodataExport $export, User $actor): void
    {
        $this->accessService->assertOwnerCanOperate(
            $export->suchakAccount,
            $actor,
            'Only the export Suchak actor can operate this PDF/QR export.',
            'Only verified Suchak accounts can operate PDF/QR exports.',
        );
    }

    private function assertQrTokenOwnerCanOperate(SuchakQrToken $qrToken, User $actor): void
    {
        $this->accessService->assertOwnerCanOperate(
            $qrToken->suchakAccount,
            $actor,
            'Only the QR Suchak actor can operate this QR token.',
            'Only verified Suchak accounts can operate QR tokens.',
        );
    }

    private function safeQrRevocationReason(string $reason): string
    {
        $reason = trim($reason);

        return Str::limit($reason !== '' ? $reason : 'manual_revoke', 120, '');
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

        $this->limitService->assertPdfExportAllowed($representation->suchakAccount);
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

    private function recordExportLifecycleActivity(
        SuchakBiodataExport $export,
        User $actor,
        string $actionType,
        string $context,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $export->suchak_account_id,
            'actor_user_id' => $actor->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => $actionType,
            'target_type' => 'suchak_biodata_export',
            'target_id' => $export->id,
            'matrimony_profile_id' => $export->matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => Str::limit((string) $userAgent, 512, ''),
            'metadata_json' => [
                'context' => $context,
                'representation_id' => $export->representation_id,
                'export_type' => $export->export_type,
                'downloaded_at' => $export->downloaded_at?->toIso8601String(),
                'shared_at' => $export->shared_at?->toIso8601String(),
            ],
        ]);
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
                'revoked_at' => $qrToken->revoked_at?->toIso8601String(),
                'revoked_reason' => $qrToken->revoked_reason,
                'replaced_by_token_id' => $qrToken->replaced_by_token_id,
                'scan_count' => $qrToken->scan_count,
            ],
        ]);
    }
}
