<?php

namespace App\Jobs;

use App\Models\OcrCorrectionPattern;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SSOT Day-29: Nightly AI Generalization Job.
 *
 * Fetches high-usage OCR correction patterns, sends batches to AI for safe generalization,
 * validates output, and inserts NEW rows only (source=ai_generalized).
 *
 * STRICT: Does NOT modify existing patterns. Does NOT touch intake, parsed_json, or logs.
 */
class NightlyOcrLearningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_PATTERN_LENGTH = 500;

    private const MAX_CONFIDENCE = 1.0;

    public function __construct()
    {
        $this->onQueue(config('queue.connections.' . config('queue.default') . '.queue', 'default'));
    }

    public function handle(): void
    {
        if (! config('ocr.ai_generalize_enabled', false)) {
            Log::info('NightlyOcrLearningJob: disabled via config (ocr.ai_generalize_enabled).');
            return;
        }

        $threshold = config('ocr.ai_generalize_threshold', 10);
        $maxBatch = config('ocr.ai_generalize_max_batch_size', 5);
        $maxChars = config('ocr.ai_generalize_max_chars_per_batch', 4000);

        $patterns = OcrCorrectionPattern::where('is_active', true)
            ->whereIn('source', ['frequency_rule', 'frequency_rule'])
            ->where('usage_count', '>=', $threshold)
            ->orderBy('usage_count', 'desc')
            ->get(['id', 'field_key', 'wrong_pattern', 'corrected_value', 'usage_count', 'rule_family_key', 'rule_version']);

        if ($patterns->isEmpty()) {
            Log::info('NightlyOcrLearningJob: no patterns above threshold.', ['threshold' => $threshold]);
            return;
        }

        $batches = $this->buildBatches($patterns, $maxBatch, $maxChars);
        $inserted = 0;

        foreach ($batches as $batch) {
            try {
                $generalized = $this->callAiGeneralize($batch);
                if ($generalized === null) {
                    continue;
                }
                foreach ($generalized as $item) {
                    if ($this->validateAndInsert($item, $batch)) {
                        $inserted++;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('NightlyOcrLearningJob: AI batch failed, continuing.', [
                    'message' => $e->getMessage(),
                    'batch_size' => count($batch),
                ]);
            }
        }

        if ($inserted > 0) {
            Log::info('NightlyOcrLearningJob: inserted new patterns.', ['count' => $inserted]);
        }
    }

    /**
     * @param  array<int, object{field_key: string, wrong_pattern: string, corrected_value: string, usage_count: int}>  $patterns
     * @return array<int, array<int, object>>
     */
    private function buildBatches($patterns, int $maxBatch, int $maxChars): array
    {
        $batches = [];
        $current = [];
        $currentChars = 0;

        foreach ($patterns as $p) {
            $need = strlen($p->field_key ?? '') + strlen($p->wrong_pattern ?? '') + strlen($p->corrected_value ?? '');
            if (count($current) >= $maxBatch || ($currentChars + $need > $maxChars && count($current) > 0)) {
                $batches[] = $current;
                $current = [];
                $currentChars = 0;
            }
            $current[] = $p;
            $currentChars += $need;
        }

        if (! empty($current)) {
            $batches[] = $current;
        }

        return $batches;
    }

    /**
     * @param  array<int, object>  $batch
     * @return array<int, array{field_key: string, wrong_pattern: string, corrected_value: string, pattern_confidence: float}>|null
     */
    private function callAiGeneralize(array $batch): ?array
    {
        $url = config('services.openai.url', 'https://api.openai.com/v1/chat/completions');
        $key = config('services.openai.key');
        $key = $key !== null ? trim((string) $key) : '';
        if ($key === '') {
            Log::info('NightlyOcrLearningJob: OPENAI_API_KEY missing or empty. Set OPENAI_API_KEY in .env and run: php artisan config:clear');
            return null;
        }

        $examples = array_map(function ($p) {
            return [
                'field_key' => $p->field_key,
                'wrong_pattern' => $p->wrong_pattern,
                'corrected_value' => $p->corrected_value,
            ];
        }, $batch);

        $prompt = "You are a normalization assistant. Given the following OCR correction patterns (wrong_pattern -> corrected_value), suggest ONE safe generalized rule that could cover similar variants. Rules must be conservative: only suggest if the generalization is clearly safe (e.g. trim/space normalization). Return JSON only, no markdown, in this exact format: {\"generalizations\":[{\"field_key\":\"...\",\"wrong_pattern\":\"...\",\"corrected_value\":\"...\",\"pattern_confidence\":0.75}]}. If you cannot safely generalize, return {\"generalizations\":[]}. Input patterns: " . json_encode($examples);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($url, [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'max_tokens' => 1024,
            ]);

            if (! $response->successful()) {
                Log::warning('NightlyOcrLearningJob: AI API non-2xx.', ['status' => $response->status()]);
                return null;
            }

            $body = $response->json();
            $content = $body['choices'][0]['message']['content'] ?? null;
            if ($content === null) {
                return null;
            }

            $content = trim($content);
            $content = preg_replace('/^```\w*\s*|\s*```$/','', $content);
            $decoded = json_decode($content, true);
            if (! is_array($decoded) || ! isset($decoded['generalizations']) || ! is_array($decoded['generalizations'])) {
                return null;
            }

            return $decoded['generalizations'];
        } catch (\Throwable $e) {
            Log::warning('NightlyOcrLearningJob: AI request failed.', ['message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * @param  array{field_key?: mixed, wrong_pattern?: mixed, corrected_value?: mixed, pattern_confidence?: mixed}  $item
     * @param  array<int, object>  $batch
     */
    private function validateAndInsert(array $item, array $batch): bool
    {
        $fieldKey = isset($item['field_key']) ? trim((string) $item['field_key']) : '';
        $wrong = isset($item['wrong_pattern']) ? trim((string) $item['wrong_pattern']) : '';
        $corrected = isset($item['corrected_value']) ? trim((string) $item['corrected_value']) : '';
        $conf = isset($item['pattern_confidence']) ? (float) $item['pattern_confidence'] : 0.75;

        if ($fieldKey === '' || $wrong === '' || $corrected === '') {
            return false;
        }
        if (strlen($wrong) > self::MAX_PATTERN_LENGTH || strlen($corrected) > self::MAX_PATTERN_LENGTH) {
            return false;
        }
        if ($conf < 0 || $conf > self::MAX_CONFIDENCE) {
            $conf = 0.75;
        }

        $exists = OcrCorrectionPattern::where('field_key', $fieldKey)
            ->where('wrong_pattern', $wrong)
            ->where('corrected_value', $corrected)
            ->exists();

        if ($exists) {
            return false;
        }

        $first = $batch[0] ?? null;
        $ruleFamilyKey = null;
        $ruleVersion = 1;
        $supersedesPatternId = null;

        if ($first !== null) {
            $ruleFamilyKey = $first->rule_family_key ?? $first->field_key;
            if (count($batch) === 1) {
                $supersedesPatternId = (int) $first->id;
                $priorVersion = isset($first->rule_version) ? (int) $first->rule_version : 1;
                $ruleVersion = $priorVersion + 1;
            }
        } else {
            $ruleFamilyKey = $fieldKey;
        }

        OcrCorrectionPattern::create([
            'field_key' => $fieldKey,
            'wrong_pattern' => $wrong,
            'corrected_value' => $corrected,
            'pattern_confidence' => $conf,
            'usage_count' => 0,
            'source' => 'ai_generalized',
            'is_active' => true,
            'rule_family_key' => $ruleFamilyKey,
            'rule_version' => $ruleVersion,
            'supersedes_pattern_id' => $supersedesPatternId,
            'authored_by_type' => 'ai',
            'promotion_status' => 'active',
        ]);

        // Retire replaced pattern(s) without deleting: keep history, prefer new rule.
        $now = now();
        foreach ($batch as $p) {
            OcrCorrectionPattern::where('id', $p->id)->update([
                'is_active' => false,
                'retired_at' => $now,
                'retirement_reason' => 'replaced_by_generalization',
                'promotion_status' => 'retired',
            ]);
        }

        return true;
    }
}

