<?php

namespace App\Services\Intake;

use App\Jobs\ParseIntakeJob;
use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Services\OcrService;
use App\Services\Parsing\ParserStrategyResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Smalot\PdfParser\Parser as PdfParser;

class IntakeCreationService
{
    public function __construct(
        private readonly OcrService $ocrService,
        private readonly ParserStrategyResolver $parserStrategyResolver,
        private readonly IntakeOcrAttemptRecorder $ocrAttemptRecorder,
    ) {}

    public function createForUser(int $userId, ?UploadedFile $file, ?string $rawText): BiodataIntake
    {
        $prepared = $this->prepare($userId, $file, $rawText);
        $intake = $this->persistPrepared($userId, $prepared);
        $this->dispatchParseIfEnabled($intake);

        return $intake;
    }

    /**
     * Bulk file upload only — uses ensemble Phase 1 when flag is on; legacy prepare() when off.
     *
     * @return array{file_path: string|null, original_filename: string|null, raw_ocr_text: string, upload_ocr_debug?: array<string, mixed>|null, reused_paid_extraction_text?: bool, reused_from_intake_id?: int, ensemble_phase1?: bool, ensemble_phase1_skipped?: bool, ensemble_skip_reason?: string, preprocessing_version?: string}
     */
    public function prepareForBulkFile(?int $userId, UploadedFile $file): array
    {
        if (! app(IntakeOcrEnsembleGate::class)->isEnabled()) {
            return $this->prepare($userId, $file, null);
        }

        return $this->prepareUploadedFileForBulkEnsemble($userId, $file);
    }

    /**
     * @return array{file_path: string|null, original_filename: string|null, raw_ocr_text: string, upload_ocr_debug?: array<string, mixed>|null, reused_paid_extraction_text?: bool, reused_from_intake_id?: int, ensemble_phase1?: bool, ensemble_phase1_skipped?: bool, ensemble_skip_reason?: string, preprocessing_version?: string}
     */
    private function prepareUploadedFileForBulkEnsemble(?int $userId, UploadedFile $file): array
    {
        $this->enforceRateLimits($userId);

        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        $this->validateFileLimits($file, $extension, $userId);

        $path = $file->store('intakes');
        $reused = $this->reusableTextFromExactPreviousUpload($userId, $path);
        if ($reused !== null) {
            return [
                'file_path' => $path,
                'original_filename' => $originalName,
                'raw_ocr_text' => $reused['text'],
                'reused_paid_extraction_text' => $reused['paid_text'],
                'reused_from_intake_id' => $reused['intake_id'],
                'ensemble_phase1_skipped' => true,
                'ensemble_skip_reason' => 'reused_transcript',
            ];
        }

        try {
            $extracted = app(IntakeOcrEnsemblePhase1Service::class)->extractFromStoredFile($path, $originalName);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'file' => __('intake.ocr_extraction_failed').' '.$e->getMessage(),
            ]);
        }

        return [
            'file_path' => $path,
            'original_filename' => $originalName,
            'raw_ocr_text' => (string) ($extracted['text'] ?? ''),
            'upload_ocr_debug' => is_array($extracted['debug'] ?? null) ? $extracted['debug'] : [],
            'ensemble_phase1' => true,
            'preprocessing_version' => (string) ($extracted['preprocessing_version'] ?? IntakeOcrEnsemblePhase1Service::PREPROCESSING_VERSION),
        ];
    }

    /**
     * @return array{file_path: string|null, original_filename: string|null, raw_ocr_text: string, upload_ocr_debug?: array<string, mixed>|null, reused_paid_extraction_text?: bool, reused_from_intake_id?: int}
     */
    public function prepare(?int $userId, ?UploadedFile $file, ?string $rawText): array
    {
        $this->enforceRateLimits($userId);

        if ($file === null) {
            return [
                'file_path' => null,
                'original_filename' => null,
                'raw_ocr_text' => (string) $rawText,
            ];
        }

        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        $this->validateFileLimits($file, $extension, $userId);

        $path = $file->store('intakes');
        $reused = $this->reusableTextFromExactPreviousUpload($userId, $path);
        if ($reused !== null) {
            return [
                'file_path' => $path,
                'original_filename' => $originalName,
                'raw_ocr_text' => $reused['text'],
                'reused_paid_extraction_text' => $reused['paid_text'],
                'reused_from_intake_id' => $reused['intake_id'],
            ];
        }

        try {
            $extractedText = $this->ocrService->extractTextFromPath($path, $originalName);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'file' => __('intake.ocr_extraction_failed').' '.$e->getMessage(),
            ]);
        }

        return [
            'file_path' => $path,
            'original_filename' => $originalName,
            'raw_ocr_text' => (string) $extractedText,
            'upload_ocr_debug' => $this->ocrService->getLastExtractTextFromPathDebug(),
        ];
    }

    /**
     * @param  array{file_path: string|null, original_filename: string|null, raw_ocr_text: string, upload_ocr_debug?: array<string, mixed>|null, reused_paid_extraction_text?: bool, reused_from_intake_id?: int}  $prepared
     */
    public function persistPrepared(int $userId, array $prepared): BiodataIntake
    {
        return $this->persistPreparedWithUploader($userId, $prepared);
    }

    /**
     * @param  array{file_path: string|null, original_filename: string|null, raw_ocr_text: string, upload_ocr_debug?: array<string, mixed>|null, reused_paid_extraction_text?: bool, reused_from_intake_id?: int}  $prepared
     */
    public function persistPreparedForUnclaimedBulk(array $prepared): BiodataIntake
    {
        return $this->persistPreparedWithUploader(null, $prepared);
    }

    /**
     * @param  array{file_path: string|null, original_filename: string|null, raw_ocr_text: string, upload_ocr_debug?: array<string, mixed>|null, reused_paid_extraction_text?: bool, reused_from_intake_id?: int}  $prepared
     */
    private function persistPreparedWithUploader(?int $userId, array $prepared): BiodataIntake
    {
        return DB::transaction(function () use ($userId, $prepared): BiodataIntake {
            $rawText = $prepared['raw_ocr_text'];

            $intake = BiodataIntake::create([
                'uploaded_by' => $userId,
                'file_path' => $prepared['file_path'],
                'original_filename' => $prepared['original_filename'],
                'raw_ocr_text' => $rawText,
                'intake_status' => 'uploaded',
                'parse_status' => 'pending',
                'parser_version' => $this->parserStrategyResolver->resolveActiveMode(),
                'content_hash' => hash('sha256', $rawText),
                'approved_by_user' => false,
                'intake_locked' => false,
                'snapshot_schema_version' => 1,
            ]);

            if (! empty($prepared['reused_paid_extraction_text'])) {
                app(IntakeExtractionReuseResolver::class)
                    ->putCachedParseInputText((int) $intake->id, $rawText, true);
            }

            $this->recordCreateTimeOcrAttempt($intake, $prepared);

            return $intake;
        });
    }

    public function dispatchParseIfEnabled(BiodataIntake $intake): void
    {
        if (AdminSetting::getBool('intake_auto_parse_enabled', true)) {
            ParseIntakeJob::dispatch($intake->id);
        }
    }

    private function enforceRateLimits(?int $userId): void
    {
        if ($userId !== null) {
            $dailyLimit = (int) AdminSetting::getValue('intake_max_daily_per_user', '0');
            if ($dailyLimit > 0) {
                $todayCount = BiodataIntake::where('uploaded_by', $userId)
                    ->whereDate('created_at', today())
                    ->count();
                if ($todayCount >= $dailyLimit) {
                    throw ValidationException::withMessages([
                        'file' => __('intake.daily_limit_reached_try_tomorrow'),
                    ]);
                }
            }

            $monthlyLimit = (int) AdminSetting::getValue('intake_max_monthly_per_user', '0');
            if ($monthlyLimit > 0) {
                $monthCount = BiodataIntake::where('uploaded_by', $userId)
                    ->whereYear('created_at', now()->year)
                    ->whereMonth('created_at', now()->month)
                    ->count();
                if ($monthCount >= $monthlyLimit) {
                    throw ValidationException::withMessages([
                        'file' => __('intake.monthly_limit_reached'),
                    ]);
                }
            }
        }

        $globalDailyCap = (int) AdminSetting::getValue('intake_global_daily_cap', '0');
        if ($globalDailyCap > 0 && BiodataIntake::whereDate('created_at', today())->count() >= $globalDailyCap) {
            Log::warning('Intake global daily cap hit', [
                'user_id' => $userId,
                'cap' => $globalDailyCap,
            ]);

            throw ValidationException::withMessages([
                'file' => __('intake.global_cap_try_tomorrow'),
            ]);
        }
    }

    private function validateFileLimits(UploadedFile $file, string $extension, ?int $userId): void
    {
        $maxPdfMb = (int) AdminSetting::getValue('intake_max_pdf_mb', '10');
        if ($extension === 'pdf' && $maxPdfMb > 0) {
            $sizeBytes = $file->getSize();
            if ($sizeBytes !== null && $sizeBytes > ($maxPdfMb * 1024 * 1024)) {
                throw ValidationException::withMessages([
                    'file' => __('intake.pdf_too_large', ['max_mb' => $maxPdfMb]),
                ]);
            }
        }

        $maxPdfPages = (int) AdminSetting::getValue('intake_max_pdf_pages', '8');
        if ($extension === 'pdf' && $maxPdfPages > 0) {
            try {
                $pdf = (new PdfParser)->parseFile($file->getRealPath());
                $pages = $pdf->getPages();
                $pageCount = is_array($pages) ? count($pages) : 0;
                if ($pageCount > $maxPdfPages) {
                    throw ValidationException::withMessages([
                        'file' => __('intake.pdf_too_many_pages', ['max_pages' => $maxPdfPages]),
                    ]);
                }
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::warning('Failed to count PDF pages for intake', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

    }

    /**
     * @param  array{file_path: string|null, original_filename: string|null, raw_ocr_text: string, upload_ocr_debug?: array<string, mixed>|null, reused_paid_extraction_text?: bool, reused_from_intake_id?: int}  $prepared
     */
    private function recordCreateTimeOcrAttempt(BiodataIntake $intake, array $prepared): void
    {
        $filePath = trim((string) ($prepared['file_path'] ?? ''));
        if ($filePath === '') {
            return;
        }

        $engine = BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR;
        $source = 'server_upload';
        $selectedReason = 'upload_time_native_ocr';
        if (! empty($prepared['reused_from_intake_id'])) {
            $engine = BiodataIntakeOcrAttempt::ENGINE_REUSED_TRANSCRIPT;
            $source = 'duplicate_upload_reuse';
            $selectedReason = ! empty($prepared['reused_paid_extraction_text'])
                ? 'duplicate_upload_reused_paid_transcript'
                : 'duplicate_upload_reused_raw_ocr_text';
        }

        $imageHash = null;
        $absolutePath = storage_path('app/private/'.$filePath);
        if (is_file($absolutePath) && is_readable($absolutePath)) {
            $hash = @hash_file('sha256', $absolutePath);
            $imageHash = is_string($hash) && $hash !== '' ? $hash : null;
        }

        $debug = is_array($prepared['upload_ocr_debug'] ?? null) ? $prepared['upload_ocr_debug'] : [];
        $ensembleMeta = [];
        if (! empty($prepared['ensemble_phase1'])) {
            $ensembleMeta = [
                'ensemble_pipeline' => IntakeOcrEnsemblePhase1Service::PIPELINE_VERSION,
                'ensemble_enabled' => true,
                'ensemble_phase1' => true,
            ];
            if ($selectedReason === 'upload_time_native_ocr') {
                $selectedReason = 'ensemble_phase1_bulk_upload';
            }
        } elseif (app(IntakeOcrEnsembleGate::class)->isEnabled()) {
            $ensembleMeta = [
                'ensemble_pipeline' => IntakeOcrEnsemblePhase1Service::PIPELINE_VERSION,
                'ensemble_enabled' => true,
                'ensemble_phase1_skipped' => true,
                'ensemble_skip_reason' => (string) ($prepared['ensemble_skip_reason'] ?? 'reused_transcript'),
            ];
        }
        $preprocessingVersion = ! empty($prepared['preprocessing_version'])
            ? (string) $prepared['preprocessing_version']
            : (is_string($debug['preset_resolved'] ?? null) ? $debug['preset_resolved'] : null);
        if (! empty($prepared['ensemble_phase1']) && $preprocessingVersion === null) {
            $preprocessingVersion = IntakeOcrEnsemblePhase1Service::PREPROCESSING_VERSION;
        }
        $this->ocrAttemptRecorder->record($intake, [
            'engine' => $engine,
            'source' => $source,
            'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
            'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
            'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
            'raw_text' => $prepared['raw_ocr_text'],
            'image_hash' => $imageHash,
            'layout_meta_json' => array_filter([
                'original_width' => $debug['original_width'] ?? null,
                'original_height' => $debug['original_height'] ?? null,
                'derived_width' => $debug['derived_width'] ?? null,
                'derived_height' => $debug['derived_height'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            'engine_meta_json' => array_filter(array_merge($debug, $ensembleMeta, [
                'reused_from_intake_id' => $prepared['reused_from_intake_id'] ?? null,
                'reused_paid_extraction_text' => $prepared['reused_paid_extraction_text'] ?? null,
            ]), static fn (mixed $value): bool => $value !== null),
            'parser_version' => $intake->parser_version,
            'preprocessing_version' => $preprocessingVersion,
            'is_primary' => true,
            'selected_policy' => IntakeOcrAttemptRecorder::SELECTION_POLICY_VERSION,
            'selected_reason' => $selectedReason,
            'selected_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        ]);
    }

    /**
     * Reuse text only when the uploaded file bytes exactly match a previous
     * intake owned by the same user. This avoids a second paid extraction while
     * still creating a new immutable intake row for the new upload.
     *
     * @return array{text: string, intake_id: int, paid_text: bool}|null
     */
    private function reusableTextFromExactPreviousUpload(?int $userId, string $storagePath): ?array
    {
        if ($userId === null || $storagePath === '') {
            return null;
        }

        $fullPath = storage_path('app/private/'.$storagePath);
        if (! is_file($fullPath) || ! is_readable($fullPath)) {
            return null;
        }

        $currentHash = @hash_file('sha256', $fullPath);
        if (! is_string($currentHash) || $currentHash === '') {
            return null;
        }

        $limit = max(5, (int) config('intake.paid_extraction_reuse.historical_peer_query_limit', 40));
        $peers = BiodataIntake::query()
            ->where('uploaded_by', $userId)
            ->whereNotNull('file_path')
            ->where('file_path', '!=', '')
            ->latest()
            ->limit($limit)
            ->get(['id', 'file_path', 'raw_ocr_text', 'last_parse_input_text', 'ai_calls_used']);

        foreach ($peers as $peer) {
            $peerPath = trim((string) $peer->file_path);
            if ($peerPath === '') {
                continue;
            }

            $peerFullPath = storage_path('app/private/'.$peerPath);
            if (! is_file($peerFullPath) || ! is_readable($peerFullPath)) {
                continue;
            }

            $peerHash = @hash_file('sha256', $peerFullPath);
            if (! is_string($peerHash) || ! hash_equals($currentHash, $peerHash)) {
                continue;
            }

            $paidText = trim((string) $peer->last_parse_input_text);
            if ((int) $peer->ai_calls_used > 0 && mb_strlen($paidText, 'UTF-8') >= 20) {
                Log::info('IntakeCreationService: reused paid extraction text for duplicate upload', [
                    'user_id' => $userId,
                    'source_intake_id' => (int) $peer->id,
                ]);

                return [
                    'text' => $paidText,
                    'intake_id' => (int) $peer->id,
                    'paid_text' => true,
                ];
            }

            $rawText = trim((string) $peer->raw_ocr_text);
            if (mb_strlen($rawText, 'UTF-8') >= 20) {
                Log::info('IntakeCreationService: reused raw OCR text for duplicate upload', [
                    'user_id' => $userId,
                    'source_intake_id' => (int) $peer->id,
                ]);

                return [
                    'text' => $rawText,
                    'intake_id' => (int) $peer->id,
                    'paid_text' => false,
                ];
            }
        }

        return null;
    }
}
