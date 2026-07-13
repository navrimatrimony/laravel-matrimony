<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleFieldNormalizerInterface;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleDobNormalizer;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleFieldTextSupport;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleNameExtractor;
use App\Services\Ocr\OcrNormalize;
use App\Support\HeightDisplay;
use App\Support\MasterData\MasterDataAliasNormalizer;

/**
 * Per-engine canonical normalization before vote + validator.
 */
final class OcrEnsembleFieldNormalizer implements OcrEnsembleFieldNormalizerInterface
{
    public function __construct(
        private readonly OcrEnsembleDobNormalizer $dobNormalizer,
        private readonly OcrEnsembleNameExtractor $nameExtractor,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function normalizeField(string $fieldKey, array $candidatesByEngine): array
    {
        $normalized = [];
        foreach ($candidatesByEngine as $engineKey => $candidate) {
            if (! is_string($engineKey)) {
                continue;
            }
            $normalized[$engineKey] = $this->normalizeValue($fieldKey, $candidate);
        }

        return $normalized;
    }

    private function normalizeValue(string $fieldKey, mixed $candidate): ?string
    {
        $value = OcrEnsembleFieldTextSupport::stringOrNull($candidate);
        if ($value === null) {
            return null;
        }

        return match ($fieldKey) {
            'full_name' => $this->nameExtractor->cleanCandidateName($value),
            'date_of_birth' => $this->dobNormalizer->normalize($value),
            'gender' => $this->normalizeGender($value),
            'primary_contact_number' => $this->normalizeMobile($value),
            'height' => $this->normalizeHeight($value),
            'education', 'occupation' => $this->normalizePlainText($value),
            'income' => $this->normalizeIncome($value),
            'religion', 'caste', 'sub_caste' => $this->normalizeMasterText($value),
            'state', 'district', 'taluka', 'village' => $this->normalizePlainText($value),
            'marital_status' => $this->normalizeMaritalToken($value),
            default => in_array($fieldKey, OcrEnsemblePhase3Constants::STRUCTURED_FIELDS, true)
                ? $this->normalizePlainText($value)
                : null,
        };
    }

    private function normalizeGender(string $value): ?string
    {
        $token = mb_strtolower(trim(OcrNormalize::normalizeDigits($value)), 'UTF-8');
        $map = [
            'male' => 'male',
            'm' => 'male',
            'पुरुष' => 'male',
            'वर' => 'male',
            'female' => 'female',
            'f' => 'female',
            'स्त्री' => 'female',
            'महिला' => 'female',
            'वधू' => 'female',
        ];

        if (isset($map[$token])) {
            return $map[$token];
        }

        $lower = strtolower(trim($value));

        return in_array($lower, ['male', 'female'], true) ? $lower : null;
    }

    private function normalizeMobile(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', OcrNormalize::normalizeDigits($value)) ?? '';
        if (preg_match('/([6-9]\d{9})/', $digits, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function normalizeHeight(string $value): ?string
    {
        $value = $this->normalizePlainText($value);
        if ($value === null) {
            return null;
        }

        if (preg_match('/\(\s*(\d{2,3})\s*cm\s*\)/ui', $value, $matches) === 1) {
            $cm = (int) $matches[1];

            return HeightDisplay::formatCm($cm);
        }

        $cm = HeightDisplay::parseFeetInchesString($value);
        if ($cm !== null) {
            return HeightDisplay::formatCm($cm);
        }

        if (preg_match('/^\d{2,3}$/u', $value) === 1) {
            return HeightDisplay::formatCm((int) $value);
        }

        return $value;
    }

    private function normalizeIncome(string $value): ?string
    {
        $value = OcrNormalize::normalizeDigits(trim($value));
        $digits = preg_replace('/[^\d]/u', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }

        return number_format((int) $digits, 0, '.', ',');
    }

    private function normalizeMasterText(string $value): ?string
    {
        $value = $this->normalizePlainText($value);
        if ($value === null) {
            return null;
        }

        $candidates = MasterDataAliasNormalizer::normalizedLookupCandidates($value);

        return $candidates[0] ?? $value;
    }

    private function normalizeMaritalToken(string $value): ?string
    {
        $value = $this->normalizePlainText($value);
        if ($value === null) {
            return null;
        }

        $token = mb_strtolower(trim(OcrNormalize::normalizeDigits($value)), 'UTF-8');
        $token = preg_replace('/\s+/u', ' ', $token) ?? $token;

        return $token === '' ? null : $token;
    }

    private function normalizePlainText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : $value;
    }
}
