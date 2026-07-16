<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Models\BiodataIntakeOcrAttempt;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleFieldExtractorInterface;
use App\Services\Intake\OcrEnsemble\Data\OcrEngineFieldCandidatesDto;
use App\Services\Intake\OcrEnsemble\Data\OcrEnsembleExtractionResultDto;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleCommunityExtractor;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleDobNormalizer;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleFieldTextSupport;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleGenderExtractor;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleMobileSelector;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleNameExtractor;
use App\Services\Ocr\OcrNormalize;
use App\Services\Parsing\MarathiOcrFieldRescueService;
use App\Services\Parsing\MarathiSeparatedLabelValueExtractor;
use App\Support\HeightDisplay;

/**
 * Production Phase 3 field extractor — raw OCR text to structured candidates (no voting / no persistence).
 */
final class OcrEnsembleFieldExtractor implements OcrEnsembleFieldExtractorInterface
{
    public function __construct(
        private readonly MarathiOcrFieldRescueService $rescueService,
        private readonly OcrEnsembleCommunityExtractor $communityExtractor,
        private readonly OcrEnsembleDobNormalizer $dobNormalizer,
        private readonly OcrEnsembleMobileSelector $mobileSelector,
        private readonly OcrEnsembleNameExtractor $nameExtractor,
        private readonly OcrEnsembleGenderExtractor $genderExtractor,
    ) {}

    public function extractCandidates(array $attempts): OcrEnsembleExtractionResultDto
    {
        $engines = [];
        foreach ($this->filterUsableAttempts($attempts) as $attempt) {
            $engines[] = $this->extractFromAttempt($attempt);
        }

        return new OcrEnsembleExtractionResultDto($engines);
    }

    public function extractFromText(
        string $text,
        string $engineKey,
        ?int $ocrAttemptId = null,
    ): OcrEngineFieldCandidatesDto {
        $normalizedText = OcrNormalize::normalizeDigits($text);
        $lines = OcrEnsembleFieldTextSupport::lines($normalizedText);

        if ($lines === []) {
            return new OcrEngineFieldCandidatesDto($engineKey, $ocrAttemptId, $this->emptyFieldMap());
        }

        $core = $this->rescueService->rescueCoreFields($lines, []);
        $hints = MarathiSeparatedLabelValueExtractor::extract($lines);

        $hintName = null;
        $hintDob = null;
        $hintMobile = null;

        if (is_array($hints)) {
            $hintName = OcrEnsembleFieldTextSupport::stringOrNull($hints['full_name'] ?? null);
            if (! empty($hints['date_of_birth']) && empty($core['date_of_birth'])) {
                $hintDob = $this->dobNormalizer->normalize((string) $hints['date_of_birth']);
            }
            if (! empty($hints['primary_contact']) && empty($core['primary_contact_number'])) {
                $hintMobile = (string) $hints['primary_contact'];
            }
            if (! empty($hints['highest_education']) && empty($core['highest_education'])) {
                $core['highest_education'] = (string) $hints['highest_education'];
            }
            if (! empty($hints['occupation_raw']) && empty($core['occupation_title'])) {
                $core['occupation_title'] = (string) $hints['occupation_raw'];
            }
        }

        $community = $this->communityExtractor->extract($lines);
        $education = OcrEnsembleFieldTextSupport::stringOrNull($core['highest_education'] ?? null)
            ?? OcrEnsembleFieldTextSupport::extractEducation($lines);
        $occupation = OcrEnsembleFieldTextSupport::stringOrNull($core['occupation_title'] ?? null)
            ?? OcrEnsembleFieldTextSupport::extractOccupation($lines);
        $dob = $this->dobNormalizer->normalize(OcrEnsembleFieldTextSupport::stringOrNull($core['date_of_birth'] ?? null))
            ?? $hintDob
            ?? $this->dobNormalizer->normalizeFromLines($lines);
        $income = OcrEnsembleFieldTextSupport::labelValue($lines, ['उत्पन्न', 'वार्षिक उत्पन्न', 'Income', 'Annual income', 'पगार', 'वेतन']);
        $marital = OcrEnsembleFieldTextSupport::labelValue($lines, ['वैवाहिक स्थिती', 'वैवाहिक', 'Marital status', 'विवाह']);
        $village = OcrEnsembleFieldTextSupport::labelValue($lines, ['गाव', 'Village', 'जन्म स्थळ', 'जन्म ठिकाण'])
            ?? OcrEnsembleFieldTextSupport::stringOrNull($core['birth_place_text'] ?? null);

        $height = null;
        if (isset($core['height_cm']) && is_numeric($core['height_cm'])) {
            $height = HeightDisplay::formatCm((int) round((float) $core['height_cm']));
        }

        $fullName = $this->nameExtractor->extract(
            $lines,
            OcrEnsembleFieldTextSupport::stringOrNull($core['full_name'] ?? null),
            $hintName,
        );

        $fields = [
            'full_name' => $fullName,
            'date_of_birth' => $dob,
            'gender' => $this->genderExtractor->extract(
                $lines,
                OcrEnsembleFieldTextSupport::stringOrNull($core['gender'] ?? null),
                $fullName,
            ),
            'primary_contact_number' => $this->mobileSelector->selectPrimary(
                $lines,
                OcrEnsembleFieldTextSupport::stringOrNull($core['primary_contact_number'] ?? null) ?? $hintMobile,
            ),
            'height' => $height,
            'education' => $education,
            'occupation' => $occupation,
            'income' => $income,
            'religion' => $community['religion'],
            'caste' => $community['caste'],
            'sub_caste' => $community['sub_caste'],
            'state' => OcrEnsembleFieldTextSupport::labelValue($lines, ['राज्य', 'State']),
            'district' => OcrEnsembleFieldTextSupport::labelValue($lines, ['जिल्हा', 'District']),
            'taluka' => OcrEnsembleFieldTextSupport::labelValue($lines, ['तालुका', 'Taluka']),
            'village' => $village,
            'marital_status' => $marital,
        ];

        return new OcrEngineFieldCandidatesDto($engineKey, $ocrAttemptId, $fields);
    }

    /**
     * @return list<BiodataIntakeOcrAttempt>
     */
    public function filterUsableAttempts(array $attempts): array
    {
        return array_values(array_filter(
            $attempts,
            static fn (mixed $attempt): bool => $attempt instanceof BiodataIntakeOcrAttempt
                && trim((string) $attempt->raw_text) !== '',
        ));
    }

    private function extractFromAttempt(BiodataIntakeOcrAttempt $attempt): OcrEngineFieldCandidatesDto
    {
        $engineKey = trim((string) $attempt->engine);
        if ($engineKey === '') {
            $engineKey = OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR;
        }

        $ocrAttemptId = $attempt->getKey();

        return $this->extractFromText(
            (string) $attempt->raw_text,
            $engineKey,
            $ocrAttemptId !== null ? (int) $ocrAttemptId : null,
        );
    }

    /**
     * @return array<string, null>
     */
    private function emptyFieldMap(): array
    {
        $fields = [];
        foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $fieldKey) {
            $fields[$fieldKey] = null;
        }

        return $fields;
    }
}
