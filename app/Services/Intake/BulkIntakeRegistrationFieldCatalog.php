<?php

namespace App\Services\Intake;

/**
 * SSOT: bulk intake user registration fields (not admin list 7-fields).
 *
 * Storage rule: height is always saved as height_cm in approval_snapshot_json.
 * Display rule: height is shown in feet/inches only (see HeightDisplay).
 */
final class BulkIntakeRegistrationFieldCatalog
{
    /** @var list<string> */
    public const REQUIRED_KEYS = [
        'full_name',
        'mobile',
        'date_of_birth',
        'height_cm',
        'gender',
        'mother_tongue',
        'marital_status',
        'religion',
        'caste',
        'location',
        'education',
        'working_with',
        'occupation',
    ];

    /** @var list<string> */
    public const SUMMARY_PREVIEW_KEYS = [
        'full_name',
        'gender',
        'date_of_birth',
        'height_cm',
        'education',
        'location',
        'mobile',
    ];

    /** @var list<string> */
    public const DEFERRED_KEYS = [
        'sub_caste',
        'company_name',
        'annual_income',
        'diet',
        'smoking',
        'drinking',
        'physical_build',
        'spectacles_lens',
        'family_status',
        'family_values',
        'father_name',
        'mother_name',
        'narrative_about_me',
        'nakshatra',
        'rashi',
        'mangal_dosh',
        'partner_preferences',
    ];

    /**
     * @return array<string, string>
     */
    public static function labelsMr(): array
    {
        return [
            'full_name' => 'नाव',
            'mobile' => 'मोबाईल',
            'date_of_birth' => 'जन्मतारीख',
            'height_cm' => 'उंची',
            'gender' => 'लिंग',
            'mother_tongue' => 'मातृभाषा',
            'marital_status' => 'वैवाहिक स्थिती',
            'religion' => 'धर्म',
            'caste' => 'जात',
            'sub_caste' => 'उपजात',
            'location' => 'ठिकाण',
            'education' => 'शिक्षण',
            'working_with' => 'कामाचा प्रकार',
            'occupation' => 'व्यवसाय',
            'company_name' => 'कंपनी',
            'annual_income' => 'उत्पन्न',
        ];
    }

    public static function label(string $key): string
    {
        return self::labelsMr()[$key] ?? str_replace('_', ' ', $key);
    }
}
