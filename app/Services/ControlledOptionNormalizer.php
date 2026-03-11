<?php

namespace App\Services;

use App\Services\ControlledOptions\ControlledOptionEngine;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * Phase-5: Controlled Option Normalizer (SSOT compliant).
 *
 * Responsibility:
 * - Normalize OCR/AI / free-text values for controlled-option fields
 *   into canonical master keys and active row IDs.
 * - Never guess aggressively – ambiguous/unknown stays unmatched.
 *
 * This service is PURE (no writes). Callers decide what to persist.
 */
class ControlledOptionNormalizer
{
    /**
     * Normalize a single controlled-option value against a master table.
     *
     * @param  string       $fieldIdentifier   Logical field id, e.g. 'horoscope.nadi', 'horoscope.gan'
     * @param  string|null  $rawValue         Raw OCR / AI / free-text value
     * @param  string       $masterTable      DB table name (e.g. 'master_nadis')
     * @return array{
     *   matched: bool,
     *   canonical_key: string|null,
     *   matched_master_id: int|null,
     *   normalized_label_en: string|null,
     *   normalized_label_mr: string|null,
     *   source_value: string|null,
     *   confidence_note: string|null
     * }
     */
    public function normalizeValue(string $fieldIdentifier, ?string $rawValue, string $masterTable): array
    {
        $result = [
            'matched' => false,
            'canonical_key' => null,
            'matched_master_id' => null,
            'normalized_label_en' => null,
            'normalized_label_mr' => null,
            'source_value' => $rawValue,
            'confidence_note' => null,
        ];

        if ($rawValue === null) {
            return $result;
        }

        $rawValue = trim((string) $rawValue);
        if ($rawValue === '') {
            return $result;
        }

        // Field-specific synonym maps (explicit and reviewable).
        $synonymConfig = $this->getSynonymConfig($fieldIdentifier);

        // Load active master rows once for this normalization call.
        $masters = DB::table($masterTable)
            ->where('is_active', true)
            ->get(['id', 'key', 'label']);

        if ($masters->isEmpty()) {
            return $result;
        }

        // Strategy:
        // 1) If a synonym map exists → use it to derive canonical_key.
        // 2) Else, fall back to simple key normalization (spaces → underscore, lowercase).
        // 3) Resolve canonical_key to an active master row.

        $canonicalKey = null;
        $confidenceNote = null;

        if ($synonymConfig !== null) {
            $canonicalKey = $this->matchUsingSynonyms($rawValue, $synonymConfig, $confidenceNote);
        }

        if ($canonicalKey === null) {
            [$canonicalKey, $confidenceNote] = $this->fallbackNormalizeToKey($rawValue, $masters);
        }

        // Field-level strict allowlists (e.g. nadi/gan cannot use "other" even if present in masters).
        $allowedKeys = $this->getAllowedCanonicalKeys($fieldIdentifier);
        if ($allowedKeys !== null && $canonicalKey !== null && ! in_array($canonicalKey, $allowedKeys, true)) {
            $canonicalKey = null;
            $confidenceNote = 'disallowed_canonical_key';
        }

        if ($canonicalKey === null) {
            return $result;
        }

        $masterRow = $masters->first(fn ($row) => (string) $row->key === (string) $canonicalKey);
        if (! $masterRow) {
            // Canonical key without active row → treat as unmatched.
            return $result;
        }

        $result['matched'] = true;
        $result['canonical_key'] = (string) $masterRow->key;
        $result['matched_master_id'] = (int) $masterRow->id;

        // English / Marathi labels are resolved via translations when available; fallback to DB label.
        $labels = $this->resolveLocalizedLabels($fieldIdentifier, (string) $masterRow->key, (string) ($masterRow->label ?? ''));
        $result['normalized_label_en'] = $labels['en'];
        $result['normalized_label_mr'] = $labels['mr'];
        $result['confidence_note'] = $confidenceNote;

        return $result;
    }

    /**
     * Normalize all horoscope rows inside an intake snapshot (non-destructive for other sections).
     *
     * - Fills *_id from OCR text where deterministic.
     * - Never overwrites an existing *_id.
     * - Never maps text from one logical field into another (no cross-field guessing).
     *
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    public function normalizeIntakeHoroscopeSnapshot(array $snapshot): array
    {
        if (! isset($snapshot['horoscope']) || ! is_array($snapshot['horoscope'])) {
            return $snapshot;
        }

        $rows = $snapshot['horoscope'];
        $locale = App::getLocale() ?: 'en';
        $engine = app(ControlledOptionEngine::class);

        foreach ($rows as $idx => $row) {
            if (! is_array($row)) {
                continue;
            }

            // Nadi (Adi / Madhya / Antya).
            if (empty($row['nadi_id']) && ! empty($row['nadi'])) {
                $res = $engine->resolveKey('horoscope.nadi', (string) $row['nadi']);
                if ($res['matched']) {
                    $rows[$idx]['nadi_id'] = $res['id'];
                }
            }

            // Gan (Deva / Manav / Rakshasa).
            if (empty($row['gan_id']) && ! empty($row['gan'])) {
                $res = $engine->resolveKey('horoscope.gan', (string) $row['gan']);
                if ($res['matched']) {
                    $rows[$idx]['gan_id'] = $res['id'];
                }
            }

            // Rashi / Nakshatra / Yoni / Mangal Dosh type:
            if (empty($row['rashi_id']) && ! empty($row['rashi'])) {
                $res = $engine->resolveKey('horoscope.rashi', (string) $row['rashi']);
                if ($res['matched']) {
                    $rows[$idx]['rashi_id'] = $res['id'];
                }
            }
            if (empty($row['nakshatra_id']) && ! empty($row['nakshatra'])) {
                $res = $engine->resolveKey('horoscope.nakshatra', (string) $row['nakshatra']);
                if ($res['matched']) {
                    $rows[$idx]['nakshatra_id'] = $res['id'];
                }
            }
            if (empty($row['yoni_id']) && ! empty($row['yoni'])) {
                $res = $engine->resolveKey('horoscope.yoni', (string) $row['yoni']);
                if ($res['matched']) {
                    $rows[$idx]['yoni_id'] = $res['id'];
                }
            }
            if (empty($row['mangal_dosh_type_id']) && ! empty($row['mangal_dosh_type'])) {
                $res = $engine->resolveKey('horoscope.mangal_dosh_type', (string) $row['mangal_dosh_type']);
                if ($res['matched']) {
                    $rows[$idx]['mangal_dosh_type_id'] = $res['id'];
                }
            }
        }

        $snapshot['horoscope'] = $rows;

        return $snapshot;
    }

    /**
     * Explicit synonym configuration for supported fields.
     *
     * Structure: [canonical_key => list<normalized_synonym_token>].
     *
     * @return array<string, array<string, string[]>>|null
     */
    private function getSynonymConfig(string $fieldIdentifier): ?array
    {
        // Nadi: Adi / Madhya / Antya (explicit OCR variants).
        if ($fieldIdentifier === 'horoscope.nadi') {
            return [
                'adi' => [
                    // English / key-like
                    'adi', 'aadi', 'adya', 'adhya',
                    // Common Marathi forms (आदि / आद्य / आध्य)
                    'आदि', 'आद्य', 'आध्य',
                ],
                'madhya' => [
                    'madhya', 'madhy',
                    'मध्य', 'मध्यम',
                ],
                'antya' => [
                    'antya', 'anteya',
                    'अंत्य', 'अंत्या',
                ],
            ];
        }

        // Gan: Deva / Manav / Rakshasa.
        if ($fieldIdentifier === 'horoscope.gan') {
            return [
                'deva' => [
                    'deva', 'dev',
                    'देव', 'देवगण',
                ],
                'manav' => [
                    'manav', 'manushya', 'human',
                    'मनुष्य', 'मानव', 'मनव',
                ],
                'rakshasa' => [
                    'rakshas', 'rakshasa',
                    'राक्षस', 'राक्षसगण',
                ],
            ];
        }

        return null;
    }

    /**
     * Strict canonical key allowlists for specific fields.
     *
     * For example, horoscope.nadi MUST be one of: adi, madhya, antya (no "other").
     *
     * @return string[]|null
     */
    private function getAllowedCanonicalKeys(string $fieldIdentifier): ?array
    {
        if ($fieldIdentifier === 'horoscope.nadi') {
            return ['adi', 'madhya', 'antya'];
        }

        if ($fieldIdentifier === 'horoscope.gan') {
            // Gan is fixed-set for profile usage: Deva / Manav / Rakshasa.
            return ['deva', 'manav', 'rakshasa'];
        }

        return null;
    }

    /**
     * Try to match using synonym lists. Returns canonical_key or null.
     *
     * @param  array<string, string[]>  $synonymConfig
     */
    private function matchUsingSynonyms(string $rawValue, array $synonymConfig, ?string &$confidenceNote): ?string
    {
        $normalized = $this->normalizeForTokenMatching($rawValue);
        if ($normalized === '') {
            return null;
        }

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            $tokens = [$normalized];
        }

        $matches = [];
        foreach ($synonymConfig as $canonical => $synonyms) {
            $synTokens = array_map([$this, 'normalizeForTokenMatching'], $synonyms);
            $synTokens = array_values(array_filter($synTokens));

            foreach ($tokens as $token) {
                if ($token === '') {
                    continue;
                }
                foreach ($synTokens as $syn) {
                    if ($token === $syn) {
                        $matches[] = $canonical;
                        break 2;
                    }
                }
            }
        }

        $unique = array_values(array_unique($matches));
        if (count($unique) === 1) {
            $confidenceNote = 'synonym_match';
            return $unique[0];
        }

        // Ambiguous or no match.
        $confidenceNote = $unique === [] ? 'no_synonym_match' : 'ambiguous_synonym_match';
        return null;
    }

    /**
     * Fallback: normalize to key-style string and try to match master `key` or label.
     *
     * @return array{0: string|null, 1: string|null} [canonical_key, confidence_note]
     */
    private function fallbackNormalizeToKey(string $rawValue, $masters): array
    {
        $trimmed = trim($rawValue);
        if ($trimmed === '') {
            return [null, null];
        }

        // Basic key normalization: lowercase + spaces → underscore.
        $candidateKey = str_replace(' ', '_', mb_strtolower($trimmed, 'UTF-8'));

        $direct = $masters->first(fn ($row) => (string) $row->key === $candidateKey);
        if ($direct) {
            return [(string) $direct->key, 'normalized_key_match'];
        }

        // Try matching by label (case-insensitive, trimmed).
        $candidateLabel = mb_strtolower($trimmed, 'UTF-8');
        $byLabel = $masters->first(function ($row) use ($candidateLabel) {
            $label = mb_strtolower(trim((string) ($row->label ?? '')), 'UTF-8');
            return $label !== '' && $label === $candidateLabel;
        });

        if ($byLabel) {
            return [(string) $byLabel->key, 'label_exact_match'];
        }

        return [null, 'no_master_match'];
    }

    /**
     * Normalize for token comparison (safe for Marathi + English).
     */
    private function normalizeForTokenMatching(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return '';
        }

        // Normalize spaces and remove obvious punctuation noise.
        $v = preg_replace('/\s+/u', ' ', $v);
        $v = trim($v, " \t\n\r\0\x0B,;:|");

        return $v;
    }

    /**
     * Resolve localized labels for a canonical key.
     *
     * We prefer translation keys when available; fallback to DB label.
     *
     * @return array{en: string|null, mr: string|null}
     */
    private function resolveLocalizedLabels(string $fieldIdentifier, string $canonicalKey, string $dbLabel): array
    {
        // Only horoscope.* fields are localized today.
        $parts = explode('.', $fieldIdentifier, 2);
        $scope = $parts[0] ?? '';

        $default = $dbLabel !== '' ? $dbLabel : $canonicalKey;
        if ($scope !== 'horoscope') {
            return ['en' => $default, 'mr' => $default];
        }

        $field = $parts[1] ?? '';

        // Map to components.horoscope.options.{field}.{key}
        $optionField = null;
        if (in_array($field, ['nadi', 'gan', 'rashi', 'nakshatra', 'yoni', 'mangal_dosh_type'], true)) {
            $optionField = $field;
        }

        if ($optionField === null) {
            return ['en' => $default, 'mr' => $default];
        }

        $baseKey = 'components.horoscope.options.' . $optionField . '.' . $canonicalKey;

        // Force-look up both locales explicitly.
        $en = App::getLocale() === 'en'
            ? __($baseKey)
            : trans($baseKey, [], 'en');
        $mr = App::getLocale() === 'mr'
            ? __($baseKey)
            : trans($baseKey, [], 'mr');

        $en = ($en !== $baseKey) ? $en : $default;
        $mr = ($mr !== $baseKey) ? $mr : $default;

        return ['en' => $en, 'mr' => $mr];
    }
}

