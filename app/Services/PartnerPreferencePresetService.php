<?php

namespace App\Services;

/**
 * Deterministic presets for partner preferences. Pure function, no DB access.
 */
class PartnerPreferencePresetService
{
    /**
     * Apply a preset to the given base suggestion/criteria array.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public static function applyPreset(string $preset, array $base): array
    {
        $out = $base;
        $preset = $preset ?: 'custom';

        switch ($preset) {
            case 'traditional':
                $out['preference_preset'] = 'traditional';
                if (isset($out['preferred_age_min'], $out['preferred_age_max'])) {
                    $span = (int) $out['preferred_age_max'] - (int) $out['preferred_age_min'];
                    if ($span > 5) {
                        $mid = (int) (($out['preferred_age_min'] + $out['preferred_age_max']) / 2);
                        $out['preferred_age_min'] = max(18, $mid - 2);
                        $out['preferred_age_max'] = $mid + 2;
                    }
                }
                break;

            case 'balanced':
                $out['preference_preset'] = 'balanced';
                break;

            case 'broad':
                $out['preference_preset'] = 'broad';
                if (isset($out['preferred_age_min'], $out['preferred_age_max'])) {
                    $out['preferred_age_min'] = max(18, (int) $out['preferred_age_min'] - 2);
                    $out['preferred_age_max'] = (int) $out['preferred_age_max'] + 5;
                }
                $out['preferred_religion_ids'] = $out['preferred_religion_ids'] ?? [];
                $out['preferred_caste_ids'] = [];
                break;

            default:
                $out['preference_preset'] = 'custom';
                break;
        }

        return $out;
    }
}

