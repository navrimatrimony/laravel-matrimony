<?php

namespace App\Services\Parsing;

use App\Services\BiodataParserService;
use App\Services\ControlledOptionNormalizer;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic intake snapshot normalizer for controlled fields.
 * Non-destructive: preserve source text, only add safe IDs/canonical forms.
 */
class IntakeControlledFieldNormalizer
{
    public function __construct(
        private ControlledOptionNormalizer $controlled,
        private IntakeParsedSnapshotSkeleton $skeleton,
    ) {
    }

    public function normalizeSnapshot(array $snapshot): array
    {
        $out = $this->skeleton->ensure($snapshot);
        $out['core'] = $this->normalizeCore(is_array($out['core'] ?? null) ? $out['core'] : []);
        $out['horoscope'] = $this->normalizeHoroscopeRows(is_array($out['horoscope'] ?? null) ? $out['horoscope'] : []);
        $out['contacts'] = $this->normalizeContacts(is_array($out['contacts'] ?? null) ? $out['contacts'] : []);
        $out['education_history'] = $this->normalizeEducationRows(is_array($out['education_history'] ?? null) ? $out['education_history'] : []);
        $out['career_history'] = $this->normalizeCareerRows(is_array($out['career_history'] ?? null) ? $out['career_history'] : []);
        [$out['relatives'], $out['relatives_sectioned']] = $this->normalizeRelativesRows(is_array($out['relatives'] ?? null) ? $out['relatives'] : []);

        $matchedCount = 0;
        $unmatchedCritical = [];
        foreach (['gender_id', 'religion_id', 'caste_id', 'sub_caste_id', 'marital_status_id'] as $k) {
            if (! empty($out['core'][$k])) {
                $matchedCount++;
            } else {
                $rawKey = str_replace('_id', '', $k);
                if (trim((string) ($out['core'][$rawKey] ?? '')) !== '') {
                    $unmatchedCritical[] = $k;
                }
            }
        }
        Log::debug('IntakeControlledFieldNormalizer: snapshot normalized', [
            'matched_controlled_core_ids' => $matchedCount,
            'unmatched_critical' => $unmatchedCritical,
        ]);

        return $out;
    }

    public function normalizeCore(array $core): array
    {
        $out = $core;
        $community = trim((string) ($out['community'] ?? $out['community_label'] ?? ''));
        if ($community !== '') {
            $out = $this->applyDeterministicCommunitySplit($out, $community);
        }

        $this->resolveCoreField($out, 'gender', 'gender_id');
        $this->resolveCoreField($out, 'religion', 'religion_id');
        $this->resolveCoreField($out, 'caste', 'caste_id');
        $this->resolveCoreField($out, 'sub_caste', 'sub_caste_id');
        $this->resolveCoreField($out, 'marital_status', 'marital_status_id');
        $this->resolveCoreField($out, 'complexion', 'complexion_id');

        $out = $this->normalizeBloodGroup($out);
        $this->resolveCoreField($out, 'blood_group', 'blood_group_id');

        $this->resolveCoreField($out, 'physical_build', 'physical_build_id');
        $this->resolveCoreField($out, 'mother_tongue', 'mother_tongue_id');
        $this->resolveCoreField($out, 'diet', 'diet_id');

        if (empty($out['smoking_status']) && ! empty($out['smoking'])) {
            $out['smoking_status'] = $out['smoking'];
        }
        if (empty($out['drinking_status']) && ! empty($out['drinking'])) {
            $out['drinking_status'] = $out['drinking'];
        }
        $this->resolveCoreField($out, 'smoking_status', 'smoking_status_id');
        $this->resolveCoreField($out, 'drinking_status', 'drinking_status_id');
        $this->resolveCoreField($out, 'family_type', 'family_type_id');
        $this->resolveCoreField($out, 'income_currency', 'income_currency_id');

        if (empty($out['height_cm'])) {
            $heightText = trim((string) ($out['height'] ?? $out['height_text'] ?? ''));
            if ($heightText !== '') {
                $heightCm = $this->parseHeightCmDeterministic($heightText);
                if ($heightCm !== null) {
                    $out['height_cm'] = $heightCm;
                }
            }
        }

        return $out;
    }

    public function normalizeHoroscopeRows(array $rows): array
    {
        $snapshot = ['horoscope' => $rows];
        $snapshot = $this->controlled->normalizeIntakeHoroscopeSnapshot($snapshot);
        $normalizedRows = is_array($snapshot['horoscope'] ?? null) ? $snapshot['horoscope'] : [];

        foreach ($normalizedRows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $bg = BiodataParserService::sanitizeBloodGroupValue($row['blood_group'] ?? null);
            if ($bg !== null && trim($bg) !== '') {
                $normalizedRows[$i]['blood_group'] = $bg;
            }
        }
        return $normalizedRows;
    }

    public function normalizeContacts(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            if (empty($row['phone_number']) && ! empty($row['number'])) {
                $rows[$i]['phone_number'] = $row['number'];
            }
        }
        return $rows;
    }

    /**
     * Keep every relative row (including repeated same-relation members), preserve honorifics,
     * and build a stable sectioned map for maternal/paternal/other.
     *
     * @return array{0: array<int,array<string,mixed>>, 1: array<string,mixed>}
     */
    public function normalizeRelativesRows(array $rows): array
    {
        $normalized = [];
        $sectioned = $this->skeleton->defaults()['relatives_sectioned'] ?? [
            'maternal' => ['ajol' => [], 'mama' => [], 'mavshi' => [], 'other' => []],
            'paternal' => ['kaka' => [], 'chulte' => [], 'atya' => [], 'other' => []],
            'other' => [],
        ];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $relation = trim((string) ($row['relation_type'] ?? $row['relation'] ?? ''));
            if ($relation === '') {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $notes = trim((string) ($row['notes'] ?? $row['raw_note'] ?? ''));
            if ($name !== '') {
                $name = $this->preserveHonorificName($name);
            } elseif ($notes !== '') {
                $candidate = $this->extractHonorificNameFromNotes($notes);
                if ($candidate !== '') {
                    $name = $candidate;
                }
            }

            $entry = $row;
            $entry['relation_type'] = $relation;
            $entry['name'] = $name;
            $entry['notes'] = $notes !== '' ? $notes : null;
            $entry['address_line'] = trim((string) ($row['address_line'] ?? '')) !== '' ? trim((string) $row['address_line']) : null;
            $entry['contact_number'] = trim((string) ($row['contact_number'] ?? '')) !== '' ? trim((string) $row['contact_number']) : null;

            // No dedup: every row survives, including repeated same-relation relatives.
            $normalized[] = $entry;

            $bucket = $this->relativeBucketFor($relation);
            $side = $bucket['side'];
            $key = $bucket['key'];
            if ($side === 'maternal') {
                $sectioned['maternal'][$key][] = $entry;
            } elseif ($side === 'paternal') {
                $sectioned['paternal'][$key][] = $entry;
            } else {
                $sectioned['other'][] = $entry;
            }
        }

        return [$normalized, $sectioned];
    }

    public function normalizeEducationRows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            if (empty($row['degree_id']) && ! empty($row['degree'])) {
                $m = $this->controlled->findActiveMasterExact('master_degrees', (string) $row['degree']);
                if ($m !== null) {
                    $rows[$i]['degree_id'] = (int) $m['id'];
                }
            }
        }
        return $rows;
    }

    public function normalizeCareerRows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            if (empty($row['employment_type_id']) && ! empty($row['employment_type'])) {
                $m = $this->controlled->findActiveMasterExact('master_employment_types', (string) $row['employment_type']);
                if ($m !== null) {
                    $rows[$i]['employment_type_id'] = (int) $m['id'];
                }
            }
        }
        return $rows;
    }

    private function resolveCoreField(array &$core, string $textKey, string $idKey): void
    {
        if (! empty($core[$idKey]) && is_numeric($core[$idKey])) {
            return;
        }
        $raw = trim((string) ($core[$textKey] ?? ''));
        if ($raw === '') {
            return;
        }
        $resolved = $this->controlled->resolveControlledCoreValue($textKey, $raw);
        if (! empty($resolved['matched']) && ! empty($resolved['id'])) {
            $core[$idKey] = (int) $resolved['id'];
        }
    }

    private function normalizeBloodGroup(array $core): array
    {
        $raw = trim((string) ($core['blood_group'] ?? ''));
        if ($raw === '') {
            return $core;
        }
        $sanitized = BiodataParserService::sanitizeBloodGroupValue($raw);
        if ($sanitized !== null && $sanitized !== '') {
            $core['blood_group'] = $sanitized;
            return $core;
        }

        $t = mb_strtolower($raw, 'UTF-8');
        $t = str_replace(['positive', 'negative', 'plus', 'minus', 've'], ['+', '-', '+', '-', ''], $t);
        $t = preg_replace('/\s+/u', '', $t) ?? $t;
        $map = ['a+' => 'A+', 'a-' => 'A-', 'b+' => 'B+', 'b-' => 'B-', 'ab+' => 'AB+', 'ab-' => 'AB-', 'o+' => 'O+', 'o-' => 'O-'];
        if (isset($map[$t])) {
            $core['blood_group'] = $map[$t];
        }

        return $core;
    }

    private function parseHeightCmDeterministic(string $text): ?float
    {
        if (preg_match('/(\d{1,2})\s*[\'′]\s*(\d{1,2})\s*(?:["″]|in)?/u', $text, $m)) {
            return round((((int) $m[1]) * 12 + (int) $m[2]) * 2.54, 2);
        }
        if (preg_match('/(\d{1,2})\s*(?:ft|feet|foot|फूट)\s*(\d{1,2})\s*(?:in|inch|इंच)?/iu', $text, $m)) {
            return round((((int) $m[1]) * 12 + (int) $m[2]) * 2.54, 2);
        }
        if (preg_match('/^\s*(\d{1,2})\s*-\s*(\d{1,2})\s*$/u', $text, $m)) {
            return round((((int) $m[1]) * 12 + (int) $m[2]) * 2.54, 2);
        }
        return null;
    }

    private function applyDeterministicCommunitySplit(array $core, string $community): array
    {
        $parts = preg_split('/[-|,]+/u', $community) ?: [$community];
        foreach ($parts as $p) {
            $token = trim($p);
            if ($token === '') {
                continue;
            }
            if (empty($core['religion'])) {
                $r = $this->controlled->resolveControlledCoreValue('religion', $token);
                if (! empty($r['matched'])) {
                    $core['religion'] = $token;
                }
            }
            if (empty($core['caste'])) {
                $c = $this->controlled->resolveControlledCoreValue('caste', $token);
                if (! empty($c['matched'])) {
                    $core['caste'] = $token;
                }
            }
            if (empty($core['sub_caste'])) {
                $s = $this->controlled->resolveControlledCoreValue('sub_caste', $token);
                if (! empty($s['matched'])) {
                    $core['sub_caste'] = $token;
                }
            }
        }
        return $core;
    }

    private function preserveHonorificName(string $name): string
    {
        $n = trim($name);
        if ($n === '') {
            return '';
        }
        // Keep prefixes exactly; only collapse accidental OCR multi-spaces.
        $n = preg_replace('/\s+/u', ' ', $n) ?? $n;
        return trim($n);
    }

    private function extractHonorificNameFromNotes(string $notes): string
    {
        if (preg_match('/\b(श्री\.?|सौ\.?|डॉ\.?)\s*[^\n,()]+/u', $notes, $m)) {
            return trim((string) $m[0]);
        }
        return '';
    }

    /**
     * @return array{side:'maternal'|'paternal'|'other', key:string}
     */
    private function relativeBucketFor(string $relationType): array
    {
        $r = mb_strtolower(trim($relationType), 'UTF-8');
        if (str_contains($r, 'मामा') || str_contains($r, 'mama')) {
            return ['side' => 'maternal', 'key' => 'mama'];
        }
        if (str_contains($r, 'मावशी') || str_contains($r, 'mavshi')) {
            return ['side' => 'maternal', 'key' => 'mavshi'];
        }
        if (str_contains($r, 'आजोळ') || str_contains($r, 'ajol') || str_contains($r, 'maternal_address_ajol')) {
            return ['side' => 'maternal', 'key' => 'ajol'];
        }
        if (str_contains($r, 'चुलते') || str_contains($r, 'chulte')) {
            return ['side' => 'paternal', 'key' => 'chulte'];
        }
        if (str_contains($r, 'आत्या') || str_contains($r, 'atya')) {
            return ['side' => 'paternal', 'key' => 'atya'];
        }
        if (str_contains($r, 'काका') || str_contains($r, 'kaka') || str_contains($r, 'paternal')) {
            return ['side' => 'paternal', 'key' => 'kaka'];
        }
        if (str_contains($r, 'maternal')) {
            return ['side' => 'maternal', 'key' => 'other'];
        }
        if (str_contains($r, 'paternal')) {
            return ['side' => 'paternal', 'key' => 'other'];
        }
        return ['side' => 'other', 'key' => 'other'];
    }
}

