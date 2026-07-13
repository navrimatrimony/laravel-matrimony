<?php

namespace App\Services\Intake\OcrEnsemble\Support;

use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Ocr\OcrNormalize;
use Illuminate\Support\Carbon;

/**
 * Header labels and body deduplication patterns for parse input assembly.
 */
final class OcrEnsembleParseInputAssemblySupport
{
    public const MIN_ASSEMBLED_TEXT_LENGTH = 20;

    /**
     * @var list<array{0: string, 1: string}>
     */
    public const HEADER_FIELDS = [
        ['full_name', 'मुलाचे नाव'],
        ['date_of_birth', 'जन्म तारीख'],
        ['gender', 'लिंग'],
        ['primary_contact_number', 'मोबाईल'],
        ['height', 'उंची'],
        ['education', 'शिक्षण'],
        ['occupation', 'नोकरी'],
        ['income', 'वेतन'],
        ['religion', 'धर्म'],
        ['caste', 'जात'],
        ['sub_caste', 'उपजात'],
        ['state', 'राज्य'],
        ['district', 'जिल्हा'],
        ['taluka', 'तालुका'],
        ['village', 'गाव'],
        ['marital_status', 'वैवाहिक स्थिती'],
    ];

    /**
     * @var array<string, string>
     */
    public const BODY_LABEL_PATTERNS = [
        'full_name' => 'मुलाचे\s+नां?व|मुलीचे\s+नां?व|वधूचे\s+नां?व|वराचे\s+नां?व',
        'date_of_birth' => 'जन्म\s*तारीख|जन्मतारीख|जन्म\s*दिनांक|जन्मदि',
        'gender' => 'लिंग|gender',
        'primary_contact_number' => 'मोबाईल|मोबाइल|मोबा\.?|मो\.?\s*नं\.?|संपर्क|mobile|phone',
        'height' => 'उंची|ऊंची|height',
        'education' => 'शिक्षण|शैक्षणिक|education',
        'occupation' => 'नोकरी|नौकरी|व्यवसाय|occupation|profession|designation|job',
        'income' => 'वेतन|उत्पन्न|वार्षिक\s+उत्पन्न|income|annual\s+income|पगार',
        'religion' => 'धर्म|religion',
        'caste' => 'जात|caste',
        'sub_caste' => 'उपजात|sub\s*caste',
        'state' => 'राज्य|state',
        'district' => 'जिल्हा|district',
        'taluka' => 'तालुका|taluka',
        'village' => 'गाव|village|जन्म\s+स्थळ|जन्म\s+ठिकाण',
        'marital_status' => 'वैवाहिक\s+स्थिती|वैवाहिक|marital\s+status|विवाह',
    ];

    public static function resolvedValue(FieldResolutionEnvelope $envelope, string $fieldKey): ?string
    {
        $record = $envelope->fields[$fieldKey] ?? null;
        if (! $record instanceof FieldResolutionFieldRecord) {
            return null;
        }

        if ($record->status !== OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED) {
            return null;
        }

        $final = trim((string) ($record->final ?? ''));

        return $final === '' ? null : $final;
    }

    public static function formatFieldValueForParser(string $fieldKey, string $value): string
    {
        return match ($fieldKey) {
            'date_of_birth' => self::formatDobForParser($value),
            'gender' => self::formatGenderForParser($value),
            'marital_status' => self::formatMaritalForParser($value),
            default => trim($value),
        };
    }

    public static function formatDobForParser(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $matches) === 1) {
            return sprintf('%02d/%02d/%04d', (int) $matches[3], (int) $matches[2], (int) $matches[1]);
        }

        if (preg_match('/^(\d{1,2})\s*[\/.\-]\s*(\d{1,2})\s*[\/.\-]\s*(\d{2,4})$/', trim($value), $matches) === 1) {
            $year = (int) $matches[3];
            if ($year < 100) {
                $year += $year >= 50 ? 1900 : 2000;
            }

            return sprintf('%02d/%02d/%04d', (int) $matches[1], (int) $matches[2], $year);
        }

        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            return trim($value);
        }
    }

    public static function formatGenderForParser(string $value): string
    {
        return match (strtolower(trim($value))) {
            'male' => 'पुरुष',
            'female' => 'स्त्री',
            default => trim($value),
        };
    }

    public static function formatMaritalForParser(string $value): string
    {
        $key = strtolower(str_replace(['-', ' '], '_', trim($value)));

        return match ($key) {
            'never_married', 'unmarried', 'single' => 'अविवाहित',
            'married' => 'विवाहित',
            'divorced' => 'घटस्फोटित',
            'widowed' => 'विधवा',
            default => trim($value),
        };
    }

    /**
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        $parts = preg_split('/\R+/u', $text) ?: [];
        $lines = [];
        foreach ($parts as $part) {
            $line = trim(preg_replace('/\s+/u', ' ', (string) $part) ?? '');
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public static function lineMatchesResolvedField(string $fieldKey, string $line): bool
    {
        $pattern = self::BODY_LABEL_PATTERNS[$fieldKey] ?? null;
        if ($pattern === null) {
            return false;
        }

        return preg_match('/(?:^|[\s,*•\-])(?:'.$pattern.')(?:\s*[:：\-–—.]|\s+)/ui', $line) === 1
            || preg_match('/(?:'.$pattern.')\s*[:：]?\s*$/ui', $line) === 1;
    }

    public static function normalizeBodyText(string $primaryOcrText): string
    {
        return OcrNormalize::normalizeRawTextForParsing($primaryOcrText);
    }
}
