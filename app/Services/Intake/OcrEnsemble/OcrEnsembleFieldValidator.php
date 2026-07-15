<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Models\Location;
use App\Models\LocationAlias;
use App\Services\ControlledOptions\ControlledOptionNormalizer;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleFieldValidatorInterface;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleDobNormalizer;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleFieldVoteSupport;
use App\Services\Ocr\OcrNormalize;
use App\Support\HeightDisplay;
use App\Support\MasterData\MasterDataAliasNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Final authority for field acceptance — master lookups, age rules, business validation.
 */
final class OcrEnsembleFieldValidator implements OcrEnsembleFieldValidatorInterface
{
    public function __construct(
        private readonly ControlledOptionNormalizer $controlledOptions,
        private readonly OcrEnsembleDobNormalizer $dobNormalizer,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function validateField(string $fieldKey, array $normalizedByEngine): array
    {
        $eligible = OcrEnsembleFieldVoteSupport::filterEligible($fieldKey, $normalizedByEngine);
        $voteMode = count($eligible) > 1
            ? OcrEnsemblePhase3Constants::VOTE_MODE_MULTI_ENGINE
            : OcrEnsemblePhase3Constants::VOTE_MODE_SINGLE_ENGINE_PASS_THROUGH;
        $winner = OcrEnsembleFieldVoteSupport::pickWinner($fieldKey, $eligible, $voteMode);

        if ($winner['engine'] === null || $winner['value'] === null) {
            return $this->fail('no_eligible_candidate', null, null, $this->missingCode($fieldKey));
        }

        return $this->validateWinner(
            $fieldKey,
            (string) $winner['engine'],
            (string) $winner['value'],
        );
    }

    /**
     * @return array{passed: bool, code: string, detail: string|null, winning_engine: string|null, final: string|null}
     */
    private function validateWinner(string $fieldKey, string $engineKey, string $value): array
    {
        return match ($fieldKey) {
            'full_name' => $this->validateName($engineKey, $value),
            'date_of_birth' => $this->validateDob($engineKey, $value),
            'gender' => $this->validateGender($engineKey, $value),
            'primary_contact_number' => $this->validateMobile($engineKey, $value),
            'height' => $this->validateHeight($engineKey, $value),
            'education' => $this->validatePlainText($engineKey, $value, 'education_min_length', 2),
            'occupation' => $this->validatePlainText($engineKey, $value, 'occupation_min_length', 2),
            'income' => $this->validateIncome($engineKey, $value),
            'religion' => $this->validateControlled($engineKey, 'religion', $value),
            'caste' => $this->validateControlled($engineKey, 'caste', $value),
            'sub_caste' => $this->validateControlled($engineKey, 'sub_caste', $value),
            'marital_status' => $this->validateControlled($engineKey, 'marital_status', $value),
            'state' => $this->validateLocation($engineKey, 'state', $value),
            'district' => $this->validateLocation($engineKey, 'district', $value),
            'taluka' => $this->validateLocation($engineKey, 'taluka', $value),
            'village' => $this->validateLocation($engineKey, 'village', $value),
            default => $this->fail('unsupported_field', $engineKey, null, 'unsupported_field'),
        };
    }

    /**
     * @return array{passed: bool, code: string, detail: string|null, winning_engine: string|null, final: string|null}
     */
    private function validateName(string $engineKey, string $value): array
    {
        if (mb_strlen($value, 'UTF-8') < 2) {
            return $this->fail('name_min_length', $engineKey, null, 'name_min_length');
        }

        if (preg_match('/^\d+$/u', preg_replace('/\s+/u', '', $value) ?? '') === 1) {
            return $this->fail('name_pure_digits', $engineKey, null, 'name_pure_digits');
        }

        return $this->pass('name_min_length', $engineKey, $value);
    }

    /**
     * @return array{passed: bool, code: string, detail: string|null, winning_engine: string|null, final: string|null}
     */
    private function validateDob(string $engineKey, string $value): array
    {
        $iso = $this->dobNormalizer->normalize($value) ?? $value;
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $matches)) {
            return $this->fail('dob_invalid_format', $engineKey, null, 'dob_invalid_format');
        }

        if (! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return $this->fail('dob_invalid_calendar', $engineKey, null, 'dob_invalid_calendar');
        }

        $age = Carbon::createFromDate((int) $matches[1], (int) $matches[2], (int) $matches[3])->age;
        if ($age < 18 || $age > 80) {
            return $this->fail('dob_age_out_of_range', $engineKey, (string) $age, 'dob_age_out_of_range');
        }

        return $this->pass('dob_age_valid', $engineKey, $iso);
    }

    /**
     * @return array{passed: bool, code: string, detail: string|null, winning_engine: string|null, final: string|null}
     */
    private function validateGender(string $engineKey, string $value): array
    {
        if (! in_array($value, ['male', 'female'], true)) {
            return $this->fail('gender_not_found', $engineKey, null, 'gender_not_found');
        }

        return $this->pass('gender_enum_valid', $engineKey, $value);
    }

    /**
     * @return array{passed: bool, code: string, detail: string|null, winning_engine: string|null, final: string|null}
     */
    private function validateMobile(string $engineKey, string $value): array
    {
        if (preg_match('/^[6-9]\d{9}$/', $value) !== 1) {
            return $this->fail('mobile_regex_invalid', $engineKey, null, 'mobile_regex_invalid');
        }

        return $this->pass('mobile_regex_valid', $engineKey, $value);
    }

    /**
     * @return array{passed: bool, code: string, detail: string|null, winning_engine: string|null, final: string|null}
     */
    private function validateHeight(string $engineKey, string $value): array
    {
        $cm = null;
        if (preg_match('/\(\s*(\d{2,3})\s*cm\s*\)/ui', $value, $matches) === 1) {
            $cm = (int) $matches[1];
        } else {
            $cm = HeightDisplay::parseFeetInchesString($value);
        }

        if ($cm === null || $cm < 120 || $cm > 220) {
            return $this->fail('height_out_of_range', $engineKey, null, 'height_out_of_range');
        }

        return $this->pass('height_in_range', $engineKey, HeightDisplay::formatCm($cm));
    }

    /**
     * @return array{passed: bool, code: string, detail: string|null, winning_engine: string|null, final: string|null}
     */
    private function validatePlainText(string $engineKey, string $value, string $code, int $minLength): array
    {
        if (mb_strlen(trim($value), 'UTF-8') < $minLength) {
            return $this->fail($code, $engineKey, null, $code);
        }

        return $this->pass($code, $engineKey, trim($value));
    }

    /**
     * @return array{passed: bool, code: string, detail: string|null, winning_engine: string|null, final: string|null}
     */
    private function validateIncome(string $engineKey, string $value): array
    {
        $digits = preg_replace('/[^\d]/u', '', OcrNormalize::normalizeDigits($value)) ?? '';
        if ($digits === '' || (int) $digits <= 0) {
            return $this->fail('income_soft_invalid', $engineKey, null, 'income_soft_invalid');
        }

        if ((int) $digits > 100000000) {
            return $this->fail('income_soft_invalid', $engineKey, null, 'income_soft_invalid');
        }

        return $this->pass('income_soft_valid', $engineKey, number_format((int) $digits, 0, '.', ','));
    }

    /**
     * @return array{passed: bool, code: string, detail: string|null, winning_engine: string|null, final: string|null}
     */
    private function validateControlled(string $engineKey, string $logicalField, string $value): array
    {
        $resolved = $this->controlledOptions->resolveControlledCoreValue($logicalField, $value);
        if (empty($resolved['matched'])) {
            return $this->fail($logicalField.'_master_unmatched', $engineKey, $resolved['note'] ?? null, $logicalField.'_master_unmatched');
        }

        $final = trim((string) ($resolved['label'] ?? $resolved['key'] ?? $value));
        if ($final === '') {
            return $this->fail($logicalField.'_master_unmatched', $engineKey, null, $logicalField.'_master_unmatched');
        }

        if ($logicalField === 'marital_status' && ! empty($resolved['key'])) {
            $final = (string) $resolved['key'];
        }

        return $this->pass($logicalField.'_master_match', $engineKey, $final);
    }

    /**
     * @return array{passed: bool, code: string, detail: string|null, winning_engine: string|null, final: string|null}
     */
    private function validateLocation(string $engineKey, string $hierarchy, string $value): array
    {
        $resolved = $this->resolveLocationName($hierarchy, $value);
        if ($resolved === null) {
            return $this->fail('location_master_unmatched', $engineKey, $hierarchy, 'location_master_unmatched');
        }

        return $this->pass('location_master_match', $engineKey, $resolved);
    }

    private function resolveLocationName(string $hierarchy, string $raw): ?string
    {
        if (! Schema::hasTable(Location::geoTable())) {
            return trim($raw) !== '' ? trim($raw) : null;
        }

        $candidates = MasterDataAliasNormalizer::normalizedLookupCandidates($raw);
        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $location = Location::query()
                ->where('hierarchy', $hierarchy)
                ->where(function ($query) use ($candidate): void {
                    $query->whereRaw('LOWER(name) = ?', [$candidate])
                        ->orWhereRaw('LOWER(COALESCE(name_en, "")) = ?', [$candidate])
                        ->orWhereRaw('LOWER(COALESCE(name_mr, "")) = ?', [$candidate]);
                })
                ->first();

            if ($location !== null) {
                return trim((string) ($location->name_en ?: $location->name ?: $location->name_mr));
            }
        }

        if (Schema::hasTable('location_aliases')) {
            foreach ($candidates as $candidate) {
                $alias = LocationAlias::query()
                    ->where('normalized_alias', $candidate)
                    ->where('is_active', true)
                    ->whereHas('location', fn ($query) => $query->where('hierarchy', $hierarchy))
                    ->with('location')
                    ->first();

                if ($alias?->location !== null) {
                    $location = $alias->location;

                    return trim((string) ($location->name_en ?: $location->name ?: $location->name_mr));
                }
            }
        }

        return null;
    }

    private function missingCode(string $fieldKey): string
    {
        return match ($fieldKey) {
            'gender' => 'gender_not_found',
            'primary_contact_number' => 'mobile_regex_invalid',
            // Missing candidate ≠ format failure. Keep dob_invalid_format for validateDob() only.
            'date_of_birth' => 'dob_missing',
            default => 'no_valid_candidate_after_validator',
        };
    }

    /**
     * @return array{passed: bool, code: string, detail: string|null, winning_engine: string|null, final: string|null}
     */
    private function pass(string $code, string $engineKey, string $final): array
    {
        return [
            'passed' => true,
            'code' => $code,
            'detail' => null,
            'winning_engine' => $engineKey,
            'final' => $final,
        ];
    }

    /**
     * @return array{passed: bool, code: string, detail: string|null, winning_engine: string|null, final: string|null}
     */
    private function fail(string $code, ?string $engineKey, ?string $detail, string $detailCode): array
    {
        return [
            'passed' => false,
            'code' => $code,
            'detail' => $detail ?? $detailCode,
            'winning_engine' => $engineKey,
            'final' => null,
        ];
    }
}
