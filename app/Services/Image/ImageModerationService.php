<?php

namespace App\Services\Image;

use App\Models\AdminSetting;
use App\Services\Admin\AdminSettingService;
use Illuminate\Support\Facades\Log;

class ImageModerationService
{
    public function __construct(
        private readonly NudeNetService $nudenet,
        private readonly AiModerationService $ai,
    ) {}

    /**
     * Moderation pipeline for profile photo.
     *
     * @return array{
     *   status:'approved'|'pending_manual'|'rejected',
     *   reason:?string,
     *   meta:array
     * }
     */
    public function moderateProfilePhoto(string $imagePath): array
    {
        $nn = $this->nudenet->detect($imagePath);
        // Fallback (API down / unreadable image): never auto-approve — same as suspicious until a human checks.
        if (! empty($nn['fallback'])) {
            return [
                'status' => 'pending_manual',
                'reason' => 'Automated photo check unavailable — manual review required.',
                'meta' => ['nudenet' => $nn],
            ];
        }
        $apiStatus = strtolower(trim((string) ($nn['raw']['api_status'] ?? $nn['raw']['status'] ?? '')));
        $pipelineConfidence = (float) ($nn['raw']['pipeline_confidence'] ?? $nn['raw']['confidence'] ?? $nn['confidence'] ?? 0.0);

        if ($apiStatus === 'safe') {
            return [
                'status' => 'approved',
                'reason' => null,
                'meta' => [
                    'nudenet' => $nn,
                    'auto_decision' => 'approved_from_safe',
                    'auto_confidence' => round($pipelineConfidence, 4),
                ],
            ];
        }

        if ($apiStatus === 'unsafe') {
            return [
                'status' => 'rejected',
                'reason' => 'Rejected by automated moderation (unsafe).',
                'meta' => [
                    'nudenet' => $nn,
                    'auto_decision' => 'rejected_from_unsafe',
                    'auto_confidence' => round($pipelineConfidence, 4),
                ],
            ];
        }

        if ($apiStatus === 'review') {
            $detections = $nn['raw']['detections'] ?? [];
            $maxButtocksCovered = 0.0;
            $maxBreastCovered = 0.0;
            if (is_array($detections)) {
                foreach ($detections as $det) {
                    if (! is_array($det)) {
                        continue;
                    }
                    $cls = strtoupper(trim((string) ($det['class'] ?? $det['label'] ?? '')));
                    $score = (float) ($det['score'] ?? $det['confidence'] ?? 0.0);
                    if ($cls === 'BUTTOCKS_COVERED') {
                        $maxButtocksCovered = max($maxButtocksCovered, $score);
                    } elseif ($cls === 'FEMALE_BREAST_COVERED') {
                        $maxBreastCovered = max($maxBreastCovered, $score);
                    }
                }
            }

            if ($maxBreastCovered > 0.0) {
                if ($maxBreastCovered < 0.55) {
                    return [
                        'status' => 'approved',
                        'reason' => null,
                        'meta' => [
                            'nudenet' => $nn,
                            'auto_decision' => 'approved_from_review_breast_covered_low',
                            'auto_confidence' => round($pipelineConfidence, 4),
                        ],
                    ];
                }
                if ($maxBreastCovered < 0.75) {
                    return [
                        'status' => 'pending_manual',
                        'reason' => 'Manual review required (breast covered score medium).',
                        'meta' => [
                            'nudenet' => $nn,
                            'auto_decision' => 'pending_from_review_breast_covered_medium',
                            'auto_confidence' => round($pipelineConfidence, 4),
                        ],
                    ];
                }

                return [
                    'status' => 'rejected',
                    'reason' => 'Rejected by automated moderation (breast covered score high).',
                    'meta' => [
                        'nudenet' => $nn,
                        'auto_decision' => 'rejected_from_review_breast_covered_high',
                        'auto_confidence' => round($pipelineConfidence, 4),
                    ],
                ];
            }

            if ($pipelineConfidence >= 0.75) {
                return [
                    'status' => 'rejected',
                    'reason' => 'Rejected by automated moderation (review confidence high).',
                    'meta' => [
                        'nudenet' => $nn,
                        'auto_decision' => 'rejected_from_review_high_confidence',
                        'auto_confidence' => round($pipelineConfidence, 4),
                    ],
                ];
            }
            if ($pipelineConfidence >= 0.60) {
                return [
                    'status' => 'pending_manual',
                    'reason' => 'Manual review required (review confidence medium).',
                    'meta' => [
                        'nudenet' => $nn,
                        'auto_decision' => 'pending_from_review_medium_confidence',
                        'auto_confidence' => round($pipelineConfidence, 4),
                    ],
                ];
            }

            if ($maxButtocksCovered > 0.4 || $maxBreastCovered > 0.5) {
                return [
                    'status' => 'pending_manual',
                    'reason' => 'Manual review required (review low confidence with suggestive detections).',
                    'meta' => [
                        'nudenet' => $nn,
                        'auto_decision' => 'pending_from_review_low_confidence_suggestive',
                        'auto_confidence' => round($pipelineConfidence, 4),
                    ],
                ];
            }

            return [
                'status' => 'approved',
                'reason' => null,
                'meta' => [
                    'nudenet' => $nn,
                    'auto_decision' => 'approved_from_review_low_confidence',
                    'auto_confidence' => round($pipelineConfidence, 4),
                ],
            ];
        }

        if ($nn['safe'] === true) {
            if (AdminSetting::getBool('photo_verify_safe_with_secondary_ai', false)) {
                $provider = (string) AdminSetting::getValue('photo_ai_provider', 'openai');
                $provider = in_array($provider, ['openai', 'sarvam'], true) ? $provider : 'openai';

                $ai = $this->ai->moderate($imagePath, $provider);
                if (($ai['approved'] ?? false) === true) {
                    return [
                        'status' => 'approved',
                        'reason' => null,
                        'meta' => ['nudenet' => $nn, 'secondary_ai' => $ai],
                    ];
                }

                if ($this->isAiModerationUnavailable($ai)) {
                    Log::warning('NudeNet said safe but secondary AI unavailable — sending to manual review', [
                        'reason' => $ai['reason'] ?? null,
                    ]);

                    return [
                        'status' => 'pending_manual',
                        'reason' => 'Secondary AI verification unavailable — manual review required.',
                        'meta' => ['nudenet' => $nn, 'secondary_ai' => $ai],
                    ];
                }

                if (AdminSettingService::isPhotoApprovalRequired()) {
                    return [
                        'status' => 'pending_manual',
                        'reason' => $ai['reason'] ?? 'Secondary AI did not approve this image.',
                        'meta' => ['nudenet' => $nn, 'secondary_ai' => $ai],
                    ];
                }

                return [
                    'status' => 'rejected',
                    'reason' => $ai['reason'] ?? 'Rejected by secondary AI verification.',
                    'meta' => ['nudenet' => $nn, 'secondary_ai' => $ai],
                ];
            }

            return [
                'status' => 'approved',
                'reason' => null,
                'meta' => ['nudenet' => $nn],
            ];
        }

        $mode = (string) AdminSetting::getValue('photo_moderation_mode', 'manual'); // auto|manual
        $mode = in_array($mode, ['auto', 'manual'], true) ? $mode : 'manual';

        if ($mode === 'manual') {
            return [
                'status' => 'pending_manual',
                'reason' => 'Suspicious image (requires manual review).',
                'meta' => ['nudenet' => $nn, 'mode' => $mode],
            ];
        }

        $provider = (string) AdminSetting::getValue('photo_ai_provider', 'openai'); // openai|sarvam
        $provider = in_array($provider, ['openai', 'sarvam'], true) ? $provider : 'openai';

        $ai = $this->ai->moderate($imagePath, $provider);
        if (($ai['approved'] ?? false) === true) {
            return [
                'status' => 'approved',
                'reason' => null,
                'meta' => ['nudenet' => $nn, 'mode' => $mode, 'ai' => $ai],
            ];
        }

        // If the platform already requires human approval, don't mark as "rejected" just because AI wasn't confident.
        if (AdminSettingService::isPhotoApprovalRequired()) {
            return [
                'status' => 'pending_manual',
                'reason' => $ai['reason'] ?? 'Requires manual review.',
                'meta' => ['nudenet' => $nn, 'mode' => $mode, 'ai' => $ai],
            ];
        }

        return [
            'status' => 'rejected',
            'reason' => $ai['reason'] ?? 'Rejected by AI moderation.',
            'meta' => ['nudenet' => $nn, 'mode' => $mode, 'ai' => $ai],
        ];
    }

    /**
     * When secondary verification is enabled but OpenAI/Sarvam is not configured or HTTP fails,
     * we must not treat that as a hard “rejected” nude decision — queue for human review instead.
     *
     * @param  array{approved?:bool, reason?:?string, raw?:array}  $ai
     */
    private function isAiModerationUnavailable(array $ai): bool
    {
        $r = strtolower((string) ($ai['reason'] ?? ''));

        return str_contains($r, 'not configured')
            || str_contains($r, 'failed: http')
            || str_contains($r, 'provider not configured')
            || str_contains($r, 'api key not configured')
            || str_contains($r, 'subscription')
            || str_contains($r, 'image not found for ai moderation');
    }
}
