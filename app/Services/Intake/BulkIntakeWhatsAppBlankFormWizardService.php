<?php

namespace App\Services\Intake;

use App\Support\HeightDisplay;
use App\Support\MobileNumber;
use Carbon\Carbon;

/**
 * Field-by-field WhatsApp blank registration form (blueprint escape option 3).
 */
class BulkIntakeWhatsAppBlankFormWizardService
{
    public const MAX_FAILURES_PER_FIELD = 3;

    /**
     * @return list<string>
     */
    public function fieldKeys(): array
    {
        return BulkIntakeRegistrationFieldCatalog::REQUIRED_KEYS;
    }

    public function totalFields(): int
    {
        return count($this->fieldKeys());
    }

    public function promptForField(string $fieldKey, int $index, int $total): string
    {
        $label = BulkIntakeRegistrationFieldCatalog::label($fieldKey);
        $step = $index + 1;
        $hint = $this->hintForField($fieldKey);

        $lines = [
            "पायरी {$step}/{$total} — {$label}",
            '',
            $hint,
        ];

        return implode("\n", $lines);
    }

    /**
     * @return array{valid: bool, normalized: string|null, error: string|null}
     */
    public function validateField(string $fieldKey, string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [
                'valid' => false,
                'normalized' => null,
                'error' => 'माहिती रिकामी आहे. कृपया पुन्हा पाठवा.',
            ];
        }

        return match ($fieldKey) {
            'full_name' => $this->validateFullName($value),
            'mobile' => $this->validateMobile($value),
            'date_of_birth' => $this->validateDateOfBirth($value),
            'height_cm' => $this->validateHeight($value),
            'gender' => $this->validateGender($value),
            default => $this->validateGenericText($fieldKey, $value),
        };
    }

    /**
     * @param  array<string, mixed>  $core
     */
    public function applyToCore(array &$core, string $fieldKey, string $normalized): void
    {
        $normalized = trim($normalized);
        if ($normalized === '') {
            return;
        }

        match ($fieldKey) {
            'full_name' => $core['full_name'] = $normalized,
            'mobile' => $core['primary_contact_number'] = $normalized,
            'date_of_birth' => $core['date_of_birth'] = $normalized,
            'height_cm' => $this->applyHeight($core, $normalized),
            'gender' => $core['gender'] = $normalized,
            'location' => $this->applyLocation($core, $normalized),
            'education' => $core['highest_education'] = $normalized,
            'mother_tongue' => $core['mother_tongue'] = $normalized,
            'marital_status' => $core['marital_status'] = $normalized,
            'religion' => $core['religion'] = $normalized,
            'caste' => $core['caste'] = $normalized,
            'working_with' => $core['working_with'] = $normalized,
            'occupation' => $core['occupation'] = $normalized,
            default => null,
        };
    }

    private function hintForField(string $fieldKey): string
    {
        return match ($fieldKey) {
            'full_name' => 'पूर्ण नाव एका संदेशात लिहून पाठवा.',
            'mobile' => 'मोबाईल नंबर १० अंकी पाठवा.',
            'date_of_birth' => 'जन्मतारीख दिन-महिना-वर्ष मध्ये पाठवा (उदा. 09-09-1987).',
            'height_cm' => 'उंची पाठवा (उदा. 5 ft 8 in किंवा 173 cm).',
            'gender' => 'लिंग पाठवा (पुरुष / स्त्री).',
            'mother_tongue' => 'मातृभाषा पाठवा (उदा. मराठी).',
            'marital_status' => 'वैवाहिक स्थिती पाठवा (उदा. अविवाहित).',
            'religion' => 'धर्म पाठवा.',
            'caste' => 'जात पाठवा.',
            'location' => 'राहण्याचे ठिकाण पाठवा (गाव, तालुका, जिल्हा).',
            'education' => 'शिक्षण पाठवा.',
            'working_with' => 'कामाचा प्रकार पाठवा (उदा. खासगी नोकरी / स्वतःचा व्यवसाय).',
            'occupation' => 'व्यवसाय / नोकरी पाठवा.',
            default => 'योग्य माहिती एका संदेशात लिहून पाठवा.',
        };
    }

    /**
     * @return array{valid: bool, normalized: string|null, error: string|null}
     */
    private function validateFullName(string $value): array
    {
        if (mb_strlen($value) < 2) {
            return [
                'valid' => false,
                'normalized' => null,
                'error' => 'नाव खूप लहान आहे. पूर्ण नाव पुन्हा पाठवा.',
            ];
        }

        return ['valid' => true, 'normalized' => $value, 'error' => null];
    }

    /**
     * @return array{valid: bool, normalized: string|null, error: string|null}
     */
    private function validateMobile(string $value): array
    {
        $normalized = MobileNumber::normalize($value);
        if ($normalized === null) {
            return [
                'valid' => false,
                'normalized' => null,
                'error' => 'मोबाईल १० अंकी असावा. पुन्हा पाठवा.',
            ];
        }

        return ['valid' => true, 'normalized' => $normalized, 'error' => null];
    }

    /**
     * @return array{valid: bool, normalized: string|null, error: string|null}
     */
    private function validateDateOfBirth(string $value): array
    {
        $parsed = $this->parseDateOfBirth($value);
        if ($parsed === null) {
            return [
                'valid' => false,
                'normalized' => null,
                'error' => 'जन्मतारीख समजली नाही. 09-09-1987 असे पाठवा.',
            ];
        }

        $age = $parsed->age;
        if ($age < 18) {
            return [
                'valid' => false,
                'normalized' => null,
                'error' => 'वय १८ वर्षांपेक्षा जास्त असावे.',
            ];
        }

        return ['valid' => true, 'normalized' => $parsed->format('Y-m-d'), 'error' => null];
    }

    /**
     * @return array{valid: bool, normalized: string|null, error: string|null}
     */
    private function validateHeight(string $value): array
    {
        $parsed = HeightDisplay::parseFeetInchesString($value);
        if ($parsed !== null) {
            return ['valid' => true, 'normalized' => (string) $parsed, 'error' => null];
        }

        if (preg_match('/(\d+)\s*ft\s*(\d+)/i', $value, $matches) === 1) {
            $feet = (int) $matches[1];
            $inches = (int) $matches[2];
            $cm = (int) round(($feet * 12 + $inches) * 2.54);
            if ($cm >= 120 && $cm <= 220) {
                return ['valid' => true, 'normalized' => (string) $cm, 'error' => null];
            }
        }

        $digits = preg_replace('/\D+/', '', $value);
        if (is_string($digits) && $digits !== '') {
            $cm = (int) $digits;
            if ($cm >= 120 && $cm <= 220) {
                return ['valid' => true, 'normalized' => (string) $cm, 'error' => null];
            }
        }

        return [
            'valid' => false,
            'normalized' => null,
            'error' => 'उंची समजली नाही. 5 ft 8 in किंवा 173 cm पाठवा.',
        ];
    }

    /**
     * @return array{valid: bool, normalized: string|null, error: string|null}
     */
    private function validateGender(string $value): array
    {
        $token = mb_strtolower(trim($value));
        $normalized = match ($token) {
            'male', 'm', 'पुरुष', 'purush', 'पुरूष' => 'male',
            'female', 'f', 'स्त्री', 'stri', 'स्त्री.', 'मुलगी', 'mulgi' => 'female',
            default => null,
        };

        if ($normalized === null) {
            return [
                'valid' => false,
                'normalized' => null,
                'error' => 'लिंग “पुरुष” किंवा “स्त्री” असे पाठवा.',
            ];
        }

        return ['valid' => true, 'normalized' => $normalized, 'error' => null];
    }

    /**
     * @return array{valid: bool, normalized: string|null, error: string|null}
     */
    private function validateGenericText(string $fieldKey, string $value): array
    {
        if (mb_strlen($value) < 2) {
            $label = BulkIntakeRegistrationFieldCatalog::label($fieldKey);

            return [
                'valid' => false,
                'normalized' => null,
                'error' => "{$label} खूप लहान आहे. पुन्हा पाठवा.",
            ];
        }

        return ['valid' => true, 'normalized' => $value, 'error' => null];
    }

    private function parseDateOfBirth(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'j-n-Y', 'd-m-y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
                if ($parsed instanceof Carbon) {
                    return $parsed;
                }
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function applyHeight(array &$core, string $normalized): void
    {
        if (ctype_digit($normalized)) {
            $core['height_cm'] = (int) $normalized;

            return;
        }

        $parsed = HeightDisplay::parseFeetInchesString($normalized);
        if ($parsed !== null) {
            $core['height_cm'] = $parsed;
        }
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function applyLocation(array &$core, string $value): void
    {
        $core['city_text'] = $value;
        $core['city'] = $value;
        $core['address_line'] = $value;
    }
}
