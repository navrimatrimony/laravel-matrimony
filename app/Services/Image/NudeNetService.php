<?php

namespace App\Services\Image;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class NudeNetService
{
    /** @var list<string> */
    private const HYBRID_NSFW_CLASSES = [
        'FEMALE_BREAST_EXPOSED',
        'FEMALE_GENITALIA_EXPOSED',
        'MALE_GENITALIA_EXPOSED',
        'ANUS_EXPOSED',
    ];

    /** @var list<string> */
    private const HYBRID_RISKY_CLASSES = [
        'FEMALE_BREAST_COVERED',
        'BUTTOCKS_EXPOSED',
        'BELLY_EXPOSED',
        'FEMALE_GENITALIA_COVERED',
        'MALE_BREAST_EXPOSED',
    ];

    private const HYBRID_ARMPITS_REVIEW_MIN = 0.52;

    /** @var list<string> */
    private const HYBRID_FACE_IGNORE = [
        'FACE_FEMALE',
        'FACE_MALE',
    ];

    private const HYBRID_NSFW_MIN = 0.4;

    private const HYBRID_REVIEW_MIN = 0.53;

    /**
     * @return array{safe:bool,confidence:float,raw:array,fallback?:bool}
     */
    public function detect(string $imagePath): array
    {
        if (! is_file($imagePath)) {
            throw new \RuntimeException('NudeNet detect: image not found.');
        }

        $url = (string) config('services.nudenet.url', 'http://127.0.0.1:8000/detect');
        $timeout = (int) config('services.nudenet.timeout', 15);

        Log::info('NudeNet request', ['path' => $imagePath, 'url' => $url]);

        try {
            $binary = file_get_contents($imagePath);
            if ($binary === false || $binary === '') {
                Log::warning('NudeNet request skipped: empty or unreadable file', ['path' => $imagePath]);

                return $this->fallbackResponse('unreadable_file');
            }

            $response = Http::timeout($timeout)
                ->attach('file', $binary, basename($imagePath))
                ->post($url);
        } catch (Throwable $e) {
            Log::warning('NudeNet request failed', [
                'path' => $imagePath,
                'message' => $e->getMessage(),
            ]);

            return $this->fallbackResponse('exception');
        }

        Log::info('NudeNet response', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (! $response->ok()) {
            return $this->fallbackResponse('http_'.$response->status());
        }

        $json = $response->json();
        if (! is_array($json)) {
            return $this->fallbackResponse('invalid_json');
        }

        $interpreted = self::interpretDetectorJson($json);
        $topStatus = $json['api_status'] ?? $json['status'] ?? null;
        $topLevelSafe = is_string($topStatus)
            ? (strtolower(trim($topStatus)) === 'safe')
            : (bool) ($json['safe'] ?? false);
        if ($topLevelSafe && ! $interpreted['safe']) {
            Log::warning('NudeNet: overriding safe=true using detections/unsafe flags', [
                'path' => $imagePath,
                'keys' => array_keys($json),
            ]);
        }

        return [
            'safe' => $interpreted['safe'],
            'confidence' => $interpreted['confidence'],
            'raw' => $json,
        ];
    }

    /**
     * Normalize different NudeNet / wrapper JSON shapes. Many services wrongly set safe:true while
     * still returning detections[] or unsafe=true.
     *
     * @return array{safe: bool, confidence: float}
     */
    public static function interpretDetectorJson(array $json): array
    {
        $status = $json['api_status'] ?? $json['status'] ?? null;
        if (is_string($status)) {
            $status = strtolower(trim($status));
            $safe = $status === 'safe';
            $confidence = (float) ($json['pipeline_confidence'] ?? $json['confidence'] ?? 0.0);

            $unsafeVal = $json['unsafe'] ?? null;
            if ($unsafeVal === true || $unsafeVal === 1 || $unsafeVal === '1'
                || (is_string($unsafeVal) && strtolower($unsafeVal) === 'true')) {
                $safe = false;
            }

            return [
                'safe' => $safe,
                'confidence' => $confidence,
            ];
        }

        $rows = self::collectDetectionRows($json);
        if ($rows !== []) {
            $hybrid = self::classifyHybridFromDetectionRows($rows);

            return [
                'safe' => $hybrid['safe'],
                'confidence' => $hybrid['confidence'],
            ];
        }

        $hasTopLevelSafeKey = array_key_exists('safe', $json);
        $safe = $hasTopLevelSafeKey ? (bool) $json['safe'] : true;
        $confidence = (float) ($json['pipeline_confidence'] ?? $json['confidence'] ?? 0.0);

        $unsafeVal = $json['unsafe'] ?? null;
        if ($unsafeVal === true || $unsafeVal === 1 || $unsafeVal === '1'
            || (is_string($unsafeVal) && strtolower($unsafeVal) === 'true')) {
            $safe = false;
        }

        return [
            'safe' => $safe,
            'confidence' => $confidence,
        ];
    }

    /**
     * Same 3-state rules as the FastAPI nudenet `classify_image` helper (face-only never flags review/unsafe).
     *
     * @param  list<array{class: string, score: float}>  $rows
     * @return array{safe: bool, confidence: float, status: string}
     */
    public static function classifyHybridFromDetectionRows(array $rows): array
    {
        if ($rows === []) {
            return ['safe' => true, 'confidence' => 1.0, 'status' => 'safe'];
        }

        $explicitScores = [];
        foreach ($rows as $row) {
            $cls = $row['class'];
            $score = $row['score'];
            if (in_array($cls, self::HYBRID_NSFW_CLASSES, true) && $score > self::HYBRID_NSFW_MIN) {
                $explicitScores[] = $score;
            }
        }
        if ($explicitScores !== []) {
            $max = max($explicitScores);

            return ['safe' => false, 'confidence' => round($max, 4), 'status' => 'unsafe'];
        }

        $riskyScores = [];
        foreach ($rows as $row) {
            $cls = $row['class'];
            $score = $row['score'];
            if (in_array($cls, self::HYBRID_RISKY_CLASSES, true) && $score > self::HYBRID_NSFW_MIN) {
                $riskyScores[] = $score;
            } elseif ($cls === 'ARMPITS_EXPOSED' && $score > self::HYBRID_ARMPITS_REVIEW_MIN) {
                $riskyScores[] = $score;
            }
        }

        $highBodyScores = [];
        foreach ($rows as $row) {
            $cls = $row['class'];
            $score = $row['score'];
            if (! in_array($cls, self::HYBRID_FACE_IGNORE, true) && $score > self::HYBRID_REVIEW_MIN) {
                $highBodyScores[] = $score;
            }
        }

        if ($riskyScores !== [] || $highBodyScores !== []) {
            $combined = array_merge($riskyScores, $highBodyScores);
            $max = max($combined);

            return ['safe' => false, 'confidence' => round($max, 4), 'status' => 'review'];
        }

        $rest = [];
        foreach ($rows as $row) {
            if (! in_array($row['class'], self::HYBRID_FACE_IGNORE, true)) {
                $rest[] = $row['score'];
            }
        }
        if ($rest === []) {
            return ['safe' => true, 'confidence' => 1.0, 'status' => 'safe'];
        }

        $peak = max($rest);
        $margin = max(0.0, self::HYBRID_REVIEW_MIN - $peak);
        $conf = round(min(1.0, 0.5 + $margin), 4);

        return ['safe' => true, 'confidence' => $conf, 'status' => 'safe'];
    }

    /**
     * @return list<array{class: string, score: float}>
     */
    private static function collectDetectionRows(array $json): array
    {
        $lists = [];
        foreach (['detections', 'predictions', 'results'] as $k) {
            if (isset($json[$k]) && is_array($json[$k])) {
                $lists[] = $json[$k];
            }
        }
        if (isset($json['data']) && is_array($json['data'])) {
            foreach (['detections', 'predictions'] as $k) {
                if (isset($json['data'][$k]) && is_array($json['data'][$k])) {
                    $lists[] = $json['data'][$k];
                }
            }
        }

        $out = [];
        foreach ($lists as $list) {
            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $label = strtoupper(trim((string) ($row['class'] ?? $row['label'] ?? '')));
                $score = (float) ($row['score'] ?? $row['confidence'] ?? $row['prob'] ?? 0);
                if ($label === '') {
                    continue;
                }
                $out[] = ['class' => $label, 'score' => $score];
            }
        }

        return $out;
    }

    /**
     * @return array{safe:bool,confidence:float,raw:array,fallback:bool}
     */
    private function fallbackResponse(string $reason): array
    {
        return [
            'safe' => true,
            'confidence' => 0.0,
            'fallback' => true,
            'raw' => ['fallback_reason' => $reason],
        ];
    }
}
