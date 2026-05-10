<?php

namespace App\Services\Preview;

/**
 * Phase-5 Day-14: Single authority for section typing.
 * Returns structured metadata (type, label, data) per section. No rendering.
 *
 * Education list is built for preview only: legacy {@code education_history} rows if present,
 * otherwise a single synthetic row from {@code core.highest_education} / {@code highest_education_other}
 * (profile no longer uses {@code profile_education}).
 */
class PreviewSectionMapper
{
    private const LIST_SECTIONS = [
        'contacts',
        'children',
        'education',
        'career',
        'addresses',
        'relatives',
        'siblings',
        'property_assets',
        // Phase-5: horoscope is array-of-rows (for shared engine), not scalar.
        'horoscope',
    ];

    private const SECTION_LABELS = [
        'core' => 'Core Details',
        'contacts' => 'Contacts',
        'children' => 'Children',
        'siblings' => 'Siblings',
        'education' => 'Education',
        'career' => 'Career',
        'addresses' => 'Addresses',
        'relatives' => 'Relatives & Family Network',
        'property_summary' => 'Property Summary',
        'property_assets' => 'Property Assets',
        'horoscope' => 'Horoscope',
        'preferences' => 'Preferences',
        'narrative' => 'Narrative',
    ];

    private const SOURCE_KEYS = [
        'core' => 'core',
        'contacts' => 'contacts',
        'children' => 'children',
        'career' => 'career_history',
        'addresses' => 'addresses',
        'relatives' => 'relatives',
        'siblings' => 'siblings',
        'property_summary' => 'property_summary',
        'property_assets' => 'property_assets',
        'horoscope' => 'horoscope',
        'preferences' => 'preferences',
        'narrative' => 'extended_narrative',
    ];

    /** Section keys that store a single value (string/null) rather than array. */
    private const SCALAR_SECTIONS = [];

    public function map(array $parsedJson): array
    {
        $out = [];
        foreach (self::SOURCE_KEYS as $sectionKey => $sourceKey) {
            $data = $parsedJson[$sourceKey] ?? null;
            if (in_array($sectionKey, self::SCALAR_SECTIONS, true)) {
                if (! (is_scalar($data) || $data === null)) {
                    // Best-effort: if AI/parser returned an array, take first non-empty scalar value; otherwise null.
                    $firstScalar = null;
                    if (is_array($data)) {
                        foreach ($data as $value) {
                            if (is_scalar($value) && (string) $value !== '') {
                                $firstScalar = $value;
                                break;
                            }
                        }
                    }
                    $data = $firstScalar;
                }
            } elseif (! is_array($data)) {
                $data = [];
            }
            $out[$sectionKey] = [
                'type' => in_array($sectionKey, self::LIST_SECTIONS, true) ? 'list' : 'object',
                'label' => self::SECTION_LABELS[$sectionKey],
                'data' => $data,
            ];
        }
        $out['education'] = [
            'type' => 'list',
            'label' => self::SECTION_LABELS['education'],
            'data' => self::buildEducationPreviewList($parsedJson),
        ];
        $out['career'] = [
            'type' => 'list',
            'label' => self::SECTION_LABELS['career'],
            'data' => self::buildCareerPreviewList($parsedJson),
        ];

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function buildEducationPreviewList(array $parsedJson): array
    {
        $legacy = $parsedJson['education_history'] ?? [];
        if (! is_array($legacy)) {
            $legacy = [];
        }
        $rows = [];
        foreach ($legacy as $row) {
            if (! is_array($row)) {
                continue;
            }
            $deg = trim((string) ($row['degree'] ?? ''));
            $inst = trim((string) ($row['institution'] ?? ''));
            $spec = trim((string) ($row['specialization'] ?? ''));
            if ($deg === '' && $inst === '' && $spec === '') {
                continue;
            }
            $rows[] = $row;
        }
        if ($rows !== []) {
            return array_values($rows);
        }
        $core = is_array($parsedJson['core'] ?? null) ? $parsedJson['core'] : [];
        $h = trim((string) ($core['highest_education'] ?? ''));
        $ho = trim((string) ($core['highest_education_other'] ?? ''));
        $line = $h;
        if ($ho !== '') {
            $line = $line !== '' ? $line.' — '.$ho : $ho;
        }
        if ($line === '' || $line === '—') {
            return [];
        }

        return [['degree' => $line, 'institution' => null, 'specialization' => null, 'year' => null]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function buildCareerPreviewList(array $parsedJson): array
    {
        $legacy = $parsedJson['career_history'] ?? [];
        if (! is_array($legacy)) {
            $legacy = [];
        }
        $rows = [];
        foreach ($legacy as $row) {
            if (! is_array($row)) {
                continue;
            }
            $designation = trim((string) ($row['designation'] ?? $row['job_title'] ?? $row['role'] ?? ''));
            $company = trim((string) ($row['company'] ?? $row['company_name'] ?? $row['employer'] ?? ''));
            $location = trim((string) ($row['location'] ?? $row['work_location_text'] ?? ''));
            if ($designation === '' && $company === '' && $location === '') {
                continue;
            }
            $rows[] = $row;
        }
        if ($rows !== []) {
            return array_values($rows);
        }
        $core = is_array($parsedJson['core'] ?? null) ? $parsedJson['core'] : [];
        $company = trim((string) ($core['company_name'] ?? ''));
        $occ = trim((string) ($core['occupation_title'] ?? ''));
        $wl = trim((string) ($core['work_location_text'] ?? ''));
        if ($company === '' && $occ === '' && $wl === '') {
            return [];
        }

        return [[
            'designation' => $occ !== '' ? $occ : null,
            'company' => $company !== '' ? $company : null,
            'location' => $wl !== '' ? $wl : null,
        ]];
    }
}
