<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use Illuminate\Http\JsonResponse;

/**
 * Public read-only JSON for the Python NudeNet service (thresholds + ignore classes).
 */
class ModerationConfigController extends Controller
{
    private const DEFAULT_NSFW = 0.4;

    private const DEFAULT_REVIEW = 0.53;

    /**
     * @var list<string>
     */
    private const DEFAULT_IGNORE = ['FACE_FEMALE', 'FACE_MALE'];

    public function __invoke(): JsonResponse
    {
        $nsfw = $this->floatOrDefault(
            (string) AdminSetting::getValue('moderation_nsfw_score_min', ''),
            self::DEFAULT_NSFW
        );
        $review = $this->floatOrDefault(
            (string) AdminSetting::getValue('moderation_review_score_min', ''),
            self::DEFAULT_REVIEW
        );
        $ignore = $this->parseIgnoreClasses(
            (string) AdminSetting::getValue('moderation_ignore_classes', '')
        );

        $versionPayload = [
            'nsfw_score_min' => $nsfw,
            'review_score_min' => $review,
            'ignore_classes' => $ignore,
        ];
        $fingerprint = substr(hash('sha256', json_encode($versionPayload, JSON_UNESCAPED_SLASHES)), 0, 12);
        $version = (string) config('moderation.python_config_version', 'v1').'-'.$fingerprint;

        return response()->json([
            'nsfw_score_min' => $nsfw,
            'review_score_min' => $review,
            'ignore_classes' => $ignore,
            'version' => $version,
        ]);
    }

    private function floatOrDefault(string $raw, float $default): float
    {
        $t = trim($raw);
        if ($t === '') {
            return $default;
        }
        if (! is_numeric($t)) {
            return $default;
        }
        $v = (float) $t;
        if ($v < 0.0 || $v > 1.0) {
            return $default;
        }

        return $v;
    }

    /**
     * @return list<string>
     */
    private function parseIgnoreClasses(string $stored): array
    {
        $decoded = json_decode($stored, true);
        if (! is_array($decoded)) {
            return self::DEFAULT_IGNORE;
        }
        $out = [];
        foreach ($decoded as $item) {
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out !== [] ? array_values($out) : self::DEFAULT_IGNORE;
    }
}
