<?php

namespace App\Services\Intake;

use App\Jobs\ParseIntakeJob;
use App\Models\AdminSetting;
use App\Models\BiodataIntake;
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
    ) {}

    public function createForUser(int $userId, ?UploadedFile $file, ?string $rawText): BiodataIntake
    {
        $prepared = $this->prepare($userId, $file, $rawText);
        $intake = $this->persistPrepared($userId, $prepared);
        $this->dispatchParseIfEnabled($intake);

        return $intake;
    }

    /**
     * @return array{file_path: string|null, original_filename: string|null, raw_ocr_text: string, reused_paid_extraction_text?: bool, reused_from_intake_id?: int}
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
        ];
    }

    /**
     * @param  array{file_path: string|null, original_filename: string|null, raw_ocr_text: string, reused_paid_extraction_text?: bool, reused_from_intake_id?: int}  $prepared
     */
    public function persistPrepared(int $userId, array $prepared): BiodataIntake
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
