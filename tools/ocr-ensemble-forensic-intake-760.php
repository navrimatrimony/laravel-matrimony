<?php

/**
 * OCR Ensemble v1.0 — READ-ONLY forensic for Intake #760 (Case A/B/C/D).
 *
 * Run on the validation server only:
 *   php tools/ocr-ensemble-forensic-intake-760.php
 *
 * Rules: no writes, no migrations, no config changes.
 * Mobile digits are masked in output.
 */

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = (int) ($argv[1] ?? 760);
$i = BiodataIntake::query()->find($id);

if (! $i) {
    echo "INTAKE_NOT_FOUND id={$id}\n";
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

echo "=== INTAKE {$id} ===\n";
echo 'parse_status='.($i->parse_status ?? '')."\n";
echo 'raw_ocr_len='.mb_strlen((string) ($i->raw_ocr_text ?? ''))."\n";
echo 'last_parse_input_len='.mb_strlen((string) ($i->last_parse_input_text ?? ''))."\n";
echo 'last_parse_input_prefix='.$mask(mb_substr((string) ($i->last_parse_input_text ?? ''), 0, 400))."\n";
echo 'has_fr='.(is_array($i->field_resolution_json) && $i->field_resolution_json !== [] ? 'yes' : 'no')."\n";

echo "\n=== OCR ATTEMPTS ===\n";
$rows = BiodataIntakeOcrAttempt::query()
    ->where('intake_id', $id)
    ->orderBy('id')
    ->get(['id', 'engine', 'status', 'is_primary', 'source', 'raw_text', 'engine_meta_json']);

foreach ($rows as $r) {
    echo 'id='.$r->id
        .'|engine='.$r->engine
        .'|status='.$r->status
        .'|primary='.(int) $r->is_primary
        .'|source='.($r->source ?? '')
        ."\n";

    if ($r->engine === BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION) {
        echo 'sarvam_raw_prefix='.$mask(mb_substr((string) $r->raw_text, 0, 300))."\n";
        $meta = is_array($r->engine_meta_json) ? $r->engine_meta_json : [];
        echo 'trigger_fields='.json_encode($meta['trigger_field_names'] ?? null, JSON_UNESCAPED_UNICODE)."\n";
        echo 'response_fields='.$mask(json_encode(data_get($meta, 'response.fields'), JSON_UNESCAPED_UNICODE))."\n";
        echo 'response_ok='.json_encode(data_get($meta, 'response.ok'))."\n";
        echo 'response_outcome='.json_encode(data_get($meta, 'response.outcome'))."\n";
    }
}

echo "\n=== FIELD RESOLUTION (trigger fields) ===\n";
$fr = is_array($i->field_resolution_json) ? $i->field_resolution_json : [];
$keys = ['full_name', 'date_of_birth', 'primary_contact_number', 'religion'];

foreach ($keys as $k) {
    $f = is_array($fr['fields'][$k] ?? null) ? $fr['fields'][$k] : [];
    echo $k.'|final='.$mask((string) ($f['final'] ?? ''))
        .'|source='.($f['source'] ?? '')
        .'|winning='.($f['winning_engine'] ?? '')
        .'|reason='.($f['reason'] ?? '')
        ."\n";
    echo $k.'|candidates='.$mask(json_encode($f['candidates'] ?? [], JSON_UNESCAPED_UNICODE))."\n";
    echo $k.'|validator='.json_encode($f['validator'] ?? null, JSON_UNESCAPED_UNICODE)."\n";
    echo $k.'|merge='.$mask(json_encode($f['merge'] ?? null, JSON_UNESCAPED_UNICODE))."\n";
}

echo "\n=== CLASSIFICATION HINTS (auto) ===\n";
$hasSarvamAttempt = $rows->contains(
    static fn ($r): bool => $r->engine === BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION
        && $r->status === BiodataIntakeOcrAttempt::STATUS_SUCCESS
);

$winnerFields = [];
$mergePresentFields = [];
$candidateSarvamFields = [];
$responseFieldCount = 0;

foreach ($keys as $k) {
    $f = is_array($fr['fields'][$k] ?? null) ? $fr['fields'][$k] : [];
    $source = (string) ($f['source'] ?? '');
    $winning = (string) ($f['winning_engine'] ?? '');
    $validatorCode = (string) data_get($f, 'validator.code', '');
    $candidates = is_array($f['candidates'] ?? null) ? $f['candidates'] : [];

    if ($source === 'sarvam_judge' || $winning === 'sarvam_ai_vision' || $validatorCode === 'sarvam_judge_accepted') {
        $winnerFields[] = $k;
    }
    if (is_array($f['merge'] ?? null) && $f['merge'] !== []) {
        $mergePresentFields[] = $k;
    }
    if (array_key_exists('sarvam_ai_vision', $candidates) || array_key_exists('sarvam', $candidates)) {
        $candidateSarvamFields[] = $k;
    }
}

$sarvamRow = $rows->first(
    static fn ($r): bool => $r->engine === BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION
);
if ($sarvamRow) {
    $meta = is_array($sarvamRow->engine_meta_json) ? $sarvamRow->engine_meta_json : [];
    $rf = data_get($meta, 'response.fields');
    if (is_array($rf)) {
        $responseFieldCount = count($rf);
    }
}

echo 'has_sarvam_attempt='.($hasSarvamAttempt ? 'yes' : 'no')."\n";
echo 'response_field_count='.$responseFieldCount."\n";
echo 'winner_fields='.json_encode($winnerFields)."\n";
echo 'merge_present_fields='.json_encode($mergePresentFields)."\n";
echo 'candidate_sarvam_fields='.json_encode($candidateSarvamFields)."\n";
echo "\nManual: compare Correct Candidate Sarvam column vs winner_fields above.\n";
echo "Case A: attempt yes, winner_fields empty, candidates empty → by design\n";
echo "Case B: winner_fields non-empty but UI Sarvam column empty → UI bug\n";
echo "Case C: attempt yes, response_field_count=0 → thin Sarvam output\n";
echo "Case D: response+merge accepted, but product hides non-winning Sarvam cells → UX limitation\n";
echo "\n=== DONE ===\n";
