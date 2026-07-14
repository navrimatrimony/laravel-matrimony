<?php

/**
 * READ-ONLY: dump engine_meta_json shape for one OCR attempt.
 *
 * Usage (validation server):
 *   php tools/ocr-ensemble-inspect-attempt-meta.php 240
 */

use App\Models\BiodataIntakeOcrAttempt;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$attemptId = (int) ($argv[1] ?? 0);
if ($attemptId <= 0) {
    echo "Usage: php tools/ocr-ensemble-inspect-attempt-meta.php <attempt_id>\n";
    exit(1);
}

$a = BiodataIntakeOcrAttempt::query()->find($attemptId);
if (! $a) {
    echo "ATTEMPT_NOT_FOUND id={$attemptId}\n";
    exit(0);
}

$mask = static function (?string $value): string {
    $value = (string) $value;
    if ($value === '') {
        return '';
    }

    return (string) preg_replace_callback(
        '/\b(\d{2})\d{6}(\d{2})\b/',
        static fn (array $m): string => $m[1].'******'.$m[2],
        $value
    );
};

$meta = $a->engine_meta_json;
$metaIsArray = is_array($meta);
$topKeys = $metaIsArray ? array_keys($meta) : [];

$response = $metaIsArray ? ($meta['response'] ?? null) : null;
$responseIsArray = is_array($response);
$responseKeys = $responseIsArray ? array_keys($response) : [];

$fields = $responseIsArray ? ($response['fields'] ?? null) : null;
$trigger = $metaIsArray ? ($meta['trigger_field_names'] ?? null) : null;
$phase = $metaIsArray ? ($meta['phase'] ?? null) : null;
$source = $a->source;
$raw = (string) ($a->raw_text ?? '');
$rawLooksHtml = str_contains(strtolower($raw), '<table') || str_contains(strtolower($raw), '<html');
$rawLooksFieldLines = (bool) preg_match('/\b(full_name|date_of_birth|primary_contact_number|religion)\s*:/u', $raw);

echo "=== ATTEMPT {$attemptId} ===\n";
echo 'intake_id='.$a->intake_id."\n";
echo 'engine='.$a->engine."\n";
echo 'status='.$a->status."\n";
echo 'source='.($source ?? 'null')."\n";
echo 'is_primary='.(int) $a->is_primary."\n";
echo 'parser_version='.($a->parser_version ?? 'null')."\n";
echo 'prompt_version='.($a->prompt_version ?? 'null')."\n";
echo 'raw_text_len='.mb_strlen($raw)."\n";
echo 'raw_text_prefix='.$mask(mb_substr($raw, 0, 400))."\n";
echo 'raw_looks_html='.($rawLooksHtml ? 'yes' : 'no')."\n";
echo 'raw_looks_phase4_field_lines='.($rawLooksFieldLines ? 'yes' : 'no')."\n";

echo "\n=== META SHAPE ===\n";
echo 'engine_meta_json_type='.gettype($meta)."\n";
echo 'engine_meta_json_null='.($meta === null ? 'yes' : 'no')."\n";
echo 'top_level_keys='.json_encode($topKeys, JSON_UNESCAPED_UNICODE)."\n";
echo 'phase='.json_encode($phase, JSON_UNESCAPED_UNICODE)."\n";
echo "1. response_object_exists=".($responseIsArray ? 'yes' : 'no')."\n";
echo 'response_keys='.json_encode($responseKeys, JSON_UNESCAPED_UNICODE)."\n";
echo "2. fields_exists=".(is_array($fields) ? 'yes' : 'no')."\n";
echo 'fields_count='.(is_array($fields) ? count($fields) : 0)."\n";
echo "3. trigger_field_names_exists=".($trigger !== null ? 'yes' : 'no')."\n";
echo 'trigger_field_names='.json_encode($trigger, JSON_UNESCAPED_UNICODE)."\n";
echo "4. response_ok_exists=".($responseIsArray && array_key_exists('ok', $response) ? 'yes' : 'no')."\n";
echo 'response_ok='.json_encode($responseIsArray ? ($response['ok'] ?? null) : null)."\n";
echo "5. response_outcome_exists=".($responseIsArray && array_key_exists('outcome', $response) ? 'yes' : 'no')."\n";
echo 'response_outcome='.json_encode($responseIsArray ? ($response['outcome'] ?? null) : null)."\n";

echo "\n=== FULL META (masked) ===\n";
if ($metaIsArray) {
    echo $mask(json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n";
} else {
    echo "null_or_non_array\n";
}

echo "\n=== CLASSIFICATION ===\n";
if ($source === 'phase4_sarvam_judge' && $responseIsArray && is_array($fields)) {
    echo "Path: Phase4 persist shape (expected)\n";
    echo "Then ask why FR merge is empty / why fields empty.\n";
} elseif ($source === 'phase4_sarvam_judge' && ! $responseIsArray) {
    echo "Path: Phase4 source but response missing → recorder/schema truncation or persist bug\n";
} elseif ($rawLooksHtml || ($source !== 'phase4_sarvam_judge' && $source !== null)) {
    echo "Path: NON-Phase4 Sarvam/AI-vision attempt (or document OCR HTML)\n";
    echo "Badge true because engine=sarvam_ai_vision success.\n";
    echo "Phase5 column empty expected: no Phase4 FR merge / no FR candidates.\n";
} elseif ($meta === null || $topKeys === []) {
    echo "Path: empty engine_meta_json → storage gap\n";
} else {
    echo "Path: unknown shape — inspect top_level_keys above\n";
}

echo "\nChatGPT Options:\n";
echo "A) response.fields exists under expected path → forensic script bug\n";
echo "B) response exists under another key → schema mismatch\n";
echo "C) only metadata + raw_text, no structured response → recorder omitted judge response\n";
echo "D) client returned no structured fields\n";
echo "E) NEW: attempt is NOT from Phase4 judge (different source / HTML raw_text)\n";
echo "\n=== DONE ===\n";
