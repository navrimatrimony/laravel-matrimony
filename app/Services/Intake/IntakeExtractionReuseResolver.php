<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Services\AiVisionExtractionService;
use App\Services\Ocr\OcrNormalize;
use App\Services\Ocr\OcrQualityEvaluator;
use App\Services\Parsing\ProviderResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Paid AI vision extraction reuse: per-intake cache, global fingerprint cache, historical DB raw_ocr_text.
 * Does not mutate raw_ocr_text. No extra DB columns — historical text is existing raw_ocr_text on peer rows.
 */
class IntakeExtractionReuseResolver
{
    public const CACHE_FLAG_PARSE_INPUT_ONLY = 'intake.parse_job.parse_input_only.';

    public const CACHE_FLAG_FORCE_FRESH_PAID_EXTRACTION = 'intake.parse_job.force_fresh_paid_extraction.';

    private const FINGERPRINT_BEST_PREFIX = 'intake.paid_extract.v1.best.';

    public function __construct(
        private IntakeBiodataIdentityFingerprint $fingerprint,
        private OcrQualityEvaluator $qualityEvaluator,
    ) {}

    public static function flagNextParseJobAsParseInputOnly(int $intakeId): void
    {
        Cache::put(self::CACHE_FLAG_PARSE_INPUT_ONLY.$intakeId, true, now()->addMinutes(10));
    }

    public static function flagNextParseJobAsReExtract(int $intakeId): void
    {
        Cache::put(self::CACHE_FLAG_FORCE_FRESH_PAID_EXTRACTION.$intakeId, true, now()->addMinutes(10));
    }

    public function consumeParseInputOnlyFlag(int $intakeId): bool
    {
        return (bool) Cache::pull(self::CACHE_FLAG_PARSE_INPUT_ONLY.$intakeId, false);
    }

    public function consumeForceFreshPaidExtractionFlag(int $intakeId): bool
    {
        return (bool) Cache::pull(self::CACHE_FLAG_FORCE_FRESH_PAID_EXTRACTION.$intakeId, false);
    }

    public function parseInputTextCacheKey(int $intakeId): string
    {
        return 'intake.parse_input_text.'.$intakeId;
    }

    public function getCachedParseInputText(int $intakeId): ?string
    {
        $v = Cache::get($this->parseInputTextCacheKey($intakeId));

        return is_string($v) && trim($v) !== '' ? $v : null;
    }

    public function putCachedParseInputText(int $intakeId, string $text, bool $fromPaidAiPath): void
    {
        $days = (int) config(
            $fromPaidAiPath ? 'intake.paid_extraction_reuse.parse_input_cache_ttl_days_paid' : 'intake.paid_extraction_reuse.parse_input_cache_ttl_days_default',
            $fromPaidAiPath ? 365 : 7
        );
        $days = max(1, $days);
        Cache::put($this->parseInputTextCacheKey($intakeId), $text, now()->addDays($days));
    }

    /**
     * @return array{text: string, quality_score: float, source_intake_id: int|null}|null
     */
    public function getBestReusablePaidExtract(string $provider, string $identitySourceText): ?array
    {
        $fp = $this->fingerprint->fingerprintForProvider($provider, $identitySourceText);
        if ($fp === null) {
            return null;
        }
        $key = $this->fingerprintBestKey($provider, $fp);
        $payload = Cache::get($key);
        if (! is_array($payload) || ! isset($payload['text']) || ! is_string($payload['text']) || trim($payload['text']) === '') {
            return null;
        }
        $score = isset($payload['quality_score']) && is_numeric($payload['quality_score'])
            ? (float) $payload['quality_score'] : 0.0;

        return [
            'text' => $payload['text'],
            'quality_score' => $score,
            'source_intake_id' => isset($payload['source_intake_id']) ? (int) $payload['source_intake_id'] : null,
        ];
    }

    public function recordSuccessfulPaidExtraction(
        BiodataIntake $intake,
        string $provider,
        string $extractedText,
        float $qualityScore,
    ): void {
        $this->putCachedParseInputText((int) $intake->id, $extractedText, true);

        $fp = $this->fingerprint->fingerprintForProvider($provider, $extractedText);
        if ($fp === null) {
            return;
        }
        $key = $this->fingerprintBestKey($provider, $fp);
        $existing = Cache::get($key);
        $existingScore = is_array($existing) && isset($existing['quality_score']) && is_numeric($existing['quality_score'])
            ? (float) $existing['quality_score'] : -1.0;

        if ($qualityScore + 0.0001 < $existingScore) {
            return;
        }

        $days = max(1, (int) config('intake.paid_extraction_reuse.fingerprint_best_ttl_days', 365));
        Cache::put($key, [
            'text' => $extractedText,
            'quality_score' => $qualityScore,
            'source_intake_id' => (int) $intake->id,
            'recorded_at' => now()->toIso8601String(),
        ], now()->addDays($days));
    }

    public function scoreExtractedText(string $text): float
    {
        $ev = $this->qualityEvaluator->evaluate($text);

        return (float) ($ev['score'] ?? 0.0);
    }

    public static function isPaidAiVisionProvider(string $provider): bool
    {
        $p = strtolower(trim($provider));

        return $p === ProviderResolver::PROVIDER_OPENAI || $p === ProviderResolver::PROVIDER_SARVAM;
    }

    /**
     * Resolve text for paid-vision parse path without calling the API when a safe reuse exists.
     *
     * @return array{
     *   text: string,
     *   call_paid_api: bool,
     *   reused_from: string|null,
     *   reused_source_intake_id: int|null,
     *   text_provenance: string|null,
     *   identity_evidence_score: float|null,
     *   candidates_summary: list<array<string, mixed>>,
     *   winner_quality_score: float|null,
     * }
     */
    public function resolvePaidVisionInput(
        BiodataIntake $intake,
        string $provider,
        AiVisionExtractionService $ai,
        bool $parseInputOnly,
        bool $forceFreshPaidExtraction,
    ): array {
        $identityText = (string) ($intake->raw_ocr_text ?? '');
        $sigCurrent = $this->fingerprint->extractSignals($identityText);
        $candidates = [];
        $candidatesSummary = [];

        $addCandidate = function (
            string $text,
            string $sourceKey,
            string $textProvenance,
            ?int $sourceIntakeId,
            ?float $identityEvidence,
            ?float $precomputedQuality,
        ) use (&$candidates, &$candidatesSummary, $ai): void {
            $t = trim($text);
            if ($t === '') {
                return;
            }
            $qg = $ai->evaluateExtractedTextQuality($t);
            if (empty($qg['ok'])) {
                $candidatesSummary[] = [
                    'source_key' => $sourceKey,
                    'accepted' => false,
                    'reason' => 'ai_vision_quality_gate',
                    'quality_gate_reason' => $qg['reason'] ?? null,
                ];

                return;
            }
            $q = $precomputedQuality ?? $this->scoreExtractedText($t);
            $candidates[] = [
                'text' => $t,
                'quality_score' => $q,
                'source_key' => $sourceKey,
                'text_provenance' => $textProvenance,
                'source_intake_id' => $sourceIntakeId,
                'identity_evidence_score' => $identityEvidence,
            ];
            $candidatesSummary[] = [
                'source_key' => $sourceKey,
                'accepted' => true,
                'quality_score' => $q,
                'source_intake_id' => $sourceIntakeId,
                'identity_evidence_score' => $identityEvidence,
            ];
        };

        if ($forceFreshPaidExtraction && $parseInputOnly) {
            Log::warning('IntakeExtractionReuseResolver: re-extract flag ignored because job is parse-input-only', [
                'intake_id' => $intake->id,
            ]);
            $forceFreshPaidExtraction = false;
        }

        if ($forceFreshPaidExtraction) {
            return [
                'text' => '',
                'call_paid_api' => ! $parseInputOnly,
                'reused_from' => null,
                'reused_source_intake_id' => null,
                'text_provenance' => null,
                'identity_evidence_score' => null,
                'candidates_summary' => [['source_key' => 'force_fresh_paid_extraction', 'accepted' => false, 'note' => 'skip_all_reuse']],
                'winner_quality_score' => null,
            ];
        }

        if (! $forceFreshPaidExtraction) {
            $same = $this->getCachedParseInputText((int) $intake->id);
            if ($same !== null) {
                $addCandidate(
                    $same,
                    'intake_parse_input_cache',
                    'transient_parse_input_cache_same_intake',
                    (int) $intake->id,
                    null,
                    $this->scoreExtractedText($same),
                );
            }
        }

        $fpReuse = $this->getBestReusablePaidExtract($provider, $identityText);
        if ($fpReuse !== null) {
            $addCandidate(
                $fpReuse['text'],
                'identity_fingerprint_cache',
                'prior_paid_extract_fingerprint_cache',
                $fpReuse['source_intake_id'],
                null,
                (float) $fpReuse['quality_score'],
            );
        }

        $limit = max(5, (int) config('intake.paid_extraction_reuse.historical_peer_query_limit', 40));
        foreach ($this->loadHistoricalRawOcrPeers($intake, $sigCurrent, $limit) as $row) {
            $addCandidate(
                $row['text'],
                'historical_intake_raw_ocr',
                'upload_time_ocr_stored_on_peer_intake_row',
                $row['intake_id'],
                $row['identity_evidence_score'],
                null,
            );
        }

        $best = $this->pickBestTextCandidate($candidates);

        if ($best !== null) {
            return [
                'text' => $best['text'],
                'call_paid_api' => false,
                'reused_from' => $best['source_key'],
                'reused_source_intake_id' => $best['source_intake_id'],
                'text_provenance' => $best['text_provenance'],
                'identity_evidence_score' => $best['identity_evidence_score'],
                'candidates_summary' => $candidatesSummary,
                'winner_quality_score' => $best['quality_score'],
            ];
        }

        if ($parseInputOnly) {
            $normOcr = trim(OcrNormalize::normalizeRawTextForParsing($identityText));
            if ($normOcr !== '') {
                $qg = $ai->evaluateExtractedTextQuality($normOcr);
                if (! empty($qg['ok'])) {
                    return [
                        'text' => $normOcr,
                        'call_paid_api' => false,
                        'reused_from' => 'raw_ocr_text_fallback',
                        'reused_source_intake_id' => null,
                        'text_provenance' => 'immutable_raw_ocr_text_current_intake',
                        'identity_evidence_score' => null,
                        'candidates_summary' => array_merge($candidatesSummary, [
                            ['source_key' => 'raw_ocr_text_fallback', 'accepted' => true],
                        ]),
                        'winner_quality_score' => $this->scoreExtractedText($normOcr),
                    ];
                }
            }

            return [
                'text' => '',
                'call_paid_api' => false,
                'reused_from' => null,
                'reused_source_intake_id' => null,
                'text_provenance' => null,
                'identity_evidence_score' => null,
                'candidates_summary' => $candidatesSummary,
                'winner_quality_score' => null,
            ];
        }

        return [
            'text' => '',
            'call_paid_api' => true,
            'reused_from' => null,
            'reused_source_intake_id' => null,
            'text_provenance' => null,
            'identity_evidence_score' => null,
            'candidates_summary' => $candidatesSummary,
            'winner_quality_score' => null,
        ];
    }

    /**
     * @return list<array{text: string, intake_id: int, identity_evidence_score: float}>
     */
    public function loadHistoricalRawOcrPeers(BiodataIntake $current, array $sigCurrent, int $limit): array
    {
        if (($sigCurrent['phone'] ?? '') === '') {
            return [];
        }

        $rows = BiodataIntake::query()
            ->where('id', '!=', (int) $current->id)
            ->whereNotNull('raw_ocr_text')
            ->where('raw_ocr_text', '!=', '')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'raw_ocr_text']);

        $out = [];
        foreach ($rows as $row) {
            $raw = trim((string) $row->raw_ocr_text);
            if ($raw === '' || mb_strlen($raw, 'UTF-8') < 60) {
                continue;
            }
            $peerSig = $this->fingerprint->extractSignals($raw);
            $ev = $this->fingerprint->identityReuseEvidenceScore($sigCurrent, $peerSig);
            if ($ev === null) {
                continue;
            }
            $norm = trim(OcrNormalize::normalizeRawTextForParsing($raw));
            if ($norm === '') {
                continue;
            }
            $out[] = [
                'text' => $norm,
                'intake_id' => (int) $row->id,
                'identity_evidence_score' => $ev,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{text: string, quality_score: float, source_key: string, text_provenance: string, source_intake_id: int|null, identity_evidence_score: float|null}>  $candidates
     * @return array<string, mixed>|null
     */
    private function pickBestTextCandidate(array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            $qa = (float) ($a['quality_score'] ?? 0);
            $qb = (float) ($b['quality_score'] ?? 0);
            if (abs($qa - $qb) > 0.02) {
                return $qb <=> $qa;
            }
            $ra = $this->provenancePreferenceRank((string) ($a['source_key'] ?? ''));
            $rb = $this->provenancePreferenceRank((string) ($b['source_key'] ?? ''));
            if ($ra !== $rb) {
                return $rb <=> $ra;
            }
            $ia = (float) ($a['identity_evidence_score'] ?? 0);
            $ib = (float) ($b['identity_evidence_score'] ?? 0);

            return $ib <=> $ia;
        });

        return $candidates[0];
    }

    private function provenancePreferenceRank(string $sourceKey): int
    {
        return match ($sourceKey) {
            'identity_fingerprint_cache' => 40,
            'intake_parse_input_cache' => 30,
            'historical_intake_raw_ocr' => 10,
            default => 0,
        };
    }

    private function fingerprintBestKey(string $provider, string $fingerprintHash): string
    {
        return self::FINGERPRINT_BEST_PREFIX.strtolower(trim($provider)).'.'.$fingerprintHash;
    }
}
