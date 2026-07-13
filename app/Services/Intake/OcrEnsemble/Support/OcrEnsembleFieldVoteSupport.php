<?php

namespace App\Services\Intake\OcrEnsemble\Support;

use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Ocr\OcrNormalize;
use App\Support\HeightDisplay;

/**
 * Shared lightweight eligibility + voting helpers for voter and validator.
 */
final class OcrEnsembleFieldVoteSupport
{
    /**
     * @param  array<string, string|null>  $normalizedByEngine
     * @return array<string, string>
     */
    public static function filterEligible(string $fieldKey, array $normalizedByEngine): array
    {
        $eligible = [];
        foreach ($normalizedByEngine as $engineKey => $value) {
            if (! is_string($engineKey) || $value === null || trim($value) === '') {
                continue;
            }
            if (self::isEligible($fieldKey, $value)) {
                $eligible[$engineKey] = $value;
            }
        }

        return $eligible;
    }

    /**
     * @param  array<string, string>  $eligible
     * @return array{engine: string|null, value: string|null, reason: string}
     */
    public static function pickWinner(string $fieldKey, array $eligible, string $voteMode): array
    {
        if ($eligible === []) {
            return ['engine' => null, 'value' => null, 'reason' => 'no_eligible_candidate'];
        }

        if ($voteMode === OcrEnsemblePhase3Constants::VOTE_MODE_SINGLE_ENGINE_PASS_THROUGH) {
            $engine = array_key_first($eligible);

            return [
                'engine' => is_string($engine) ? $engine : null,
                'value' => $engine !== null ? $eligible[$engine] : null,
                'reason' => 'single_engine_pass_through',
            ];
        }

        return self::pickMajorityWinner($fieldKey, $eligible);
    }

    public static function isEligible(string $fieldKey, string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return match ($fieldKey) {
            'full_name' => self::eligibleName($value),
            'date_of_birth' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
            'gender' => in_array($value, ['male', 'female'], true),
            'primary_contact_number' => preg_match('/^[6-9]\d{9}$/', $value) === 1,
            'height' => self::eligibleHeight($value),
            'education', 'occupation', 'religion', 'caste', 'sub_caste',
            'state', 'district', 'taluka', 'village', 'marital_status' => mb_strlen($value, 'UTF-8') >= 2,
            'income' => preg_match('/\d/', $value) === 1,
            default => false,
        };
    }

    /**
     * @param  array<string, string>  $eligible
     * @return array{engine: string|null, value: string|null, reason: string}
     */
    private static function pickMajorityWinner(string $fieldKey, array $eligible): array
    {
        unset($fieldKey);

        $groups = [];
        foreach ($eligible as $engineKey => $value) {
            $canonical = self::canonicalComparisonKey($value);
            $groups[$canonical]['engines'][$engineKey] = $value;
        }

        uasort($groups, static function (array $left, array $right): int {
            return count($right['engines']) <=> count($left['engines']);
        });

        $best = reset($groups);
        if (! is_array($best) || ! isset($best['engines']) || ! is_array($best['engines'])) {
            return ['engine' => null, 'value' => null, 'reason' => 'engine_disagreement'];
        }

        $topCount = count($best['engines']);
        $second = next($groups);
        if (is_array($second) && isset($second['engines']) && count($second['engines']) === $topCount) {
            return ['engine' => null, 'value' => null, 'reason' => 'engine_disagreement'];
        }

        $engine = array_key_first($best['engines']);
        $value = $engine !== null ? $best['engines'][$engine] : null;

        return [
            'engine' => is_string($engine) ? $engine : null,
            'value' => is_string($value) ? $value : null,
            'reason' => $topCount > 1 ? 'majority_vote' : 'single_engine_pass_through',
        ];
    }

    private static function canonicalComparisonKey(string $value): string
    {
        $value = OcrNormalize::normalizeDigits(trim($value));
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private static function eligibleName(string $value): bool
    {
        if (mb_strlen($value, 'UTF-8') < 2 || mb_strlen($value, 'UTF-8') > 80) {
            return false;
        }

        if (preg_match('/^\d+$/u', preg_replace('/\s+/u', '', $value) ?? '') === 1) {
            return false;
        }

        return preg_match('/\p{L}/u', $value) === 1;
    }

    private static function eligibleHeight(string $value): bool
    {
        if (preg_match('/\d/', $value) !== 1) {
            return false;
        }

        if (preg_match('/\(\s*\d+\s*cm\s*\)/ui', $value) === 1) {
            return true;
        }

        return HeightDisplay::parseFeetInchesString($value) !== null
            || preg_match('/^\d{2,3}$/u', trim($value)) === 1;
    }
}
