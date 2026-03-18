<?php

namespace App\Services\ControlledOptions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5 Day-36: Controlled Option Engine.
 *
 * Centralizes normalization + active-only master resolution for controlled-option fields.
 */
class ControlledOptionEngine
{
    public function __construct(
        private readonly ControlledOptionRegistry $registry,
    ) {
    }

    /**
     * Resolve raw text to canonical key + active master id for a logical field.
     *
     * @return array{matched: bool, canonical_key: string|null, id: int|null}
     */
    public function resolveKey(string $fieldKey, ?string $rawValue): array
    {
        $result = [
            'matched' => false,
            'canonical_key' => null,
            'id' => null,
        ];

        if ($rawValue === null) {
            return $result;
        }

        $rawValue = trim((string) $rawValue);
        if ($rawValue === '') {
            return $result;
        }

        $config = $this->registry->get($fieldKey);
        if (($config['source_type'] ?? null) !== 'master_table') {
            return $result;
        }

        $table = $config['table'];
        $keyColumn = $config['key_column'] ?? 'key';
        $labelColumn = $config['label_column'] ?? 'label';
        $activeColumn = $config['active_column'] ?? null;

        $masters = DB::table($table);
        if ($activeColumn && Schema::hasColumn($table, $activeColumn)) {
            $masters->where($activeColumn, true);
        }
        $masters = $masters->get([$config['id_column'] ?? 'id', $keyColumn, $labelColumn]);

        if ($masters->isEmpty()) {
            return $result;
        }

        $canonicalKey = null;

        $synonymConfig = $this->getSynonymConfig($fieldKey);
        if ($synonymConfig !== null) {
            $canonicalKey = $this->matchUsingSynonyms($rawValue, $synonymConfig);
        }

        if ($canonicalKey === null) {
            $canonicalKey = $this->fallbackNormalizeToKey($rawValue, $masters);
        }

        $strict = $this->getStrictAllowedKeys($fieldKey);
        if ($strict !== null && $canonicalKey !== null && ! in_array($canonicalKey, $strict, true)) {
            $canonicalKey = null;
        }

        if ($canonicalKey === null) {
            return $result;
        }

        $row = $masters->first(fn ($r) => (string) ($r->$keyColumn ?? '') === (string) $canonicalKey);
        if (! $row) {
            return $result;
        }

        $idColumn = $config['id_column'] ?? 'id';
        $id = $row->$idColumn ?? null;
        if ($id === null) {
            return $result;
        }

        $result['matched'] = true;
        $result['canonical_key'] = (string) $canonicalKey;
        $result['id'] = (int) $id;

        return $result;
    }

    /**
     * Revalidate numeric id for a logical field against active master rows.
     */
    public function resolveId(string $fieldKey, int|string|null $id): ?int
    {
        if ($id === null || $id === '' || ! is_numeric($id)) {
            return null;
        }
        $id = (int) $id;

        $config = $this->registry->get($fieldKey);
        if (($config['source_type'] ?? null) !== 'master_table') {
            return $id;
        }

        $table = $config['table'];
        $idColumn = $config['id_column'] ?? 'id';
        $keyColumn = $config['key_column'] ?? 'key';
        $activeColumn = $config['active_column'] ?? null;

        $q = DB::table($table)->where($idColumn, $id);
        if ($activeColumn && Schema::hasColumn($table, $activeColumn)) {
            $q->where($activeColumn, true);
        }
        $row = $q->first([$idColumn, $keyColumn]);
        if (! $row) {
            return null;
        }

        $strict = $this->getStrictAllowedKeys($fieldKey);
        if ($strict !== null) {
            $key = (string) ($row->$keyColumn ?? '');
            if (! in_array($key, $strict, true)) {
                return null;
            }
        }

        return (int) $row->$idColumn;
    }

    /**
     * Revalidate an array of numeric ids for a logical field.
     *
     * @param  array<int,int|string|null>  $ids
     * @return int[]
     */
    public function resolveArray(string $fieldKey, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $resolved = $this->resolveId($fieldKey, $id);
            if ($resolved !== null) {
                $out[] = $resolved;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Generic helper: active-only master id resolution by table name.
     * Kept for backwards-compatibility with existing MutationService helpers.
     */
    public function resolveActiveMasterId(string $table, int|string|null $id): ?int
    {
        if ($id === null || $id === '' || ! is_numeric($id)) {
            return null;
        }
        $id = (int) $id;

        $q = DB::table($table)->where('id', $id);
        if (Schema::hasTable($table) && Schema::hasColumn($table, 'is_active')) {
            $q->where('is_active', true);
        }

        $found = $q->value('id');

        return $found !== null ? (int) $found : null;
    }

    /**
     * Return canonical key for raw text, or null when unmatched.
     */
    public function normalizeText(string $fieldKey, ?string $rawValue): ?string
    {
        $res = $this->resolveKey($fieldKey, $rawValue);

        return $res['matched'] ? $res['canonical_key'] : null;
    }

    /**
     * Field-specific synonym configuration for horoscope and other fields.
     *
     * @return array<string, string[]>|null
     */
    private function getSynonymConfig(string $fieldKey): ?array
    {
        if ($fieldKey === 'horoscope.nadi') {
            return [
                'adi' => ['adi', 'aadi', 'adya', 'adhya', 'आदि', 'आद्य', 'आध्य'],
                'madhya' => ['madhya', 'madhy', 'मध्य', 'मध्यम'],
                'antya' => ['antya', 'anteya', 'अंत्य', 'अंत्या'],
            ];
        }

        if ($fieldKey === 'horoscope.gan') {
            return [
                'deva' => ['deva', 'dev', 'देव', 'देवगण'],
                'manav' => ['manav', 'manushya', 'human', 'मनुष्य', 'मानव', 'मनव'],
                'rakshasa' => ['rakshas', 'rakshasa', 'राक्षस', 'राक्षस गण', 'गण राक्षस', 'राक्षसगण'],
            ];
        }

        // Rashi: Marathi/English labels from lang so AI-parsed "वृश्चिक" etc. resolve to key.
        if ($fieldKey === 'horoscope.rashi') {
            return $this->buildHoroscopeRashiSynonymConfig();
        }

        // Nakshatra: Marathi/English labels + common short forms (e.g. "मूग" → mrigashira).
        if ($fieldKey === 'horoscope.nakshatra') {
            return $this->buildHoroscopeNakshatraSynonymConfig();
        }

        // core.religion / core.caste / core.sub_caste: Marathi/Devanagari so intake text matches master key.
        if ($fieldKey === 'core.religion') {
            return [
                'hindu' => ['हिंदु', 'Hindu', 'hindu'],
                'muslim' => ['मुस्लिम', 'Muslim', 'muslim'],
                'buddhist' => ['बौद्ध', 'Buddhist', 'buddhist'],
                'jain' => ['जैन', 'Jain', 'jain'],
                'sikh' => ['शीख', 'Sikh', 'sikh'],
            ];
        }
        if ($fieldKey === 'core.caste') {
            return [
                'maratha' => ['मराठा', 'Maratha', 'maratha'],
                'brahmin' => ['ब्राह्मण', 'Brahmin', 'brahmin'],
                'mali' => ['माली', 'Mali', 'mali'],
                'dhangar' => ['धंगर', 'Dhangar', 'dhangar'],
                'chambhar' => ['चांभार', 'Chambhar', 'chambhar'],
                'teli' => ['तेली', 'Teli', 'teli'],
                'lohar' => ['लोहार', 'Lohar', 'lohar'],
                'kshatriya' => ['क्षत्रिय', 'Kshatriya', 'kshatriya'],
                'ckp_chandraseniya_kayastha_prabhu_' => ['CKP', 'CKP (Chandraseniya Kayastha Prabhu)', 'ckp'],
                'bhandari' => ['भांडारी', 'Bhandari', 'bhandari'],
                'sonar' => ['सोनार', 'Sonar', 'sonar'],
                'sutar' => ['सुतार', 'Sutar', 'sutar'],
                'koli' => ['कोली', 'Koli', 'koli'],
                'lingayat' => ['लिंगायत', 'Lingayat', 'lingayat'],
                'rajput' => ['राजपूत', 'Rajput', 'rajput'],
                'agri' => ['आगरी', 'Agri', 'agri'],
                'gavli' => ['गवळी', 'Gavli', 'gavli'],
                'gura' => ['गुरा', 'Gura', 'gura'],
                'sunni' => ['सुन्नी', 'Sunni', 'sunni'],
                'shia' => ['शिया', 'Shia', 'shia'],
                'mahar' => ['महार', 'Mahar', 'mahar'],
                'digambar' => ['दिगंबर', 'Digambar', 'digambar'],
                'shwetambar' => ['श्वेतांबर', 'Shwetambar', 'shwetambar'],
                'jat' => ['जाट', 'Jat', 'jat'],
            ];
        }
        if ($fieldKey === 'core.sub_caste') {
            return [
                '96_kuli' => ['96 कुळी', '96 कुळी मराठा', '96 Kuli', '96 kuli', '96_kuli'],
                'kunbi_maratha' => ['कुनबी मराठा', 'Kunbi Maratha', 'kunbi maratha'],
                'deshmukh' => ['देशमुख', 'Deshmukh', 'deshmukh'],
            ];
        }
        if ($fieldKey === 'physical.complexion') {
            return [
                'fair' => ['गोरा', 'Fair', 'fair'],
                'very_fair' => ['खूप गोरा', 'Very Fair', 'very_fair'],
                'fair_wheatish' => ['गव्हाळ', 'Fair Wheatish', 'fair_wheatish'],
                'wheatish' => ['गव्हाळी', 'Wheatish', 'wheatish'],
                'dark' => ['गडद', 'Dark', 'dark'],
            ];
        }

        return null;
    }

    /**
     * Build synonym config for horoscope.rashi from lang (en + mr) so parsed Marathi text matches.
     *
     * @return array<string, string[]>
     */
    private function buildHoroscopeRashiSynonymConfig(): array
    {
        $keys = ['mesha', 'vrishabha', 'mithuna', 'karka', 'simha', 'kanya', 'tula', 'vrishchika', 'dhanu', 'makara', 'kumbha', 'meena'];
        $config = [];
        $base = 'components.horoscope.options.rashi';
        foreach ($keys as $key) {
            $synonyms = array_filter([
                $key,
                Lang::get("{$base}.{$key}", [], 'en'),
                Lang::get("{$base}.{$key}", [], 'mr'),
            ], fn ($s) => $s !== '' && $s !== "{$base}.{$key}");
            if ($synonyms !== []) {
                $config[$key] = array_values(array_unique($synonyms));
            }
        }

        return $config;
    }

    /**
     * Build synonym config for horoscope.nakshatra from lang (en + mr) + common short forms.
     *
     * @return array<string, string[]>
     */
    private function buildHoroscopeNakshatraSynonymConfig(): array
    {
        $keys = [
            'ashwini', 'bharani', 'krittika', 'rohini', 'mrigashira', 'ardra', 'punarvasu', 'pushya', 'ashlesha',
            'magha', 'purva_phalguni', 'uttara_phalguni', 'hasta', 'chitra', 'swati', 'vishakha', 'anuradha',
            'jyeshtha', 'mula', 'purva_ashadha', 'uttara_ashadha', 'shravana', 'dhanishta', 'shatabhisha',
            'purva_bhadrapada', 'uttara_bhadrapada', 'revati',
        ];
        $config = [];
        $base = 'components.horoscope.options.nakshatra';
        foreach ($keys as $key) {
            $synonyms = array_filter([
                $key,
                Lang::get("{$base}.{$key}", [], 'en'),
                Lang::get("{$base}.{$key}", [], 'mr'),
            ], fn ($s) => $s !== '' && $s !== "{$base}.{$key}");
            if ($key === 'mrigashira') {
                $synonyms[] = 'मूग'; // common short form for मृगशीर्ष in biodata
            }
            if ($synonyms !== []) {
                $config[$key] = array_values(array_unique($synonyms));
            }
        }

        return $config;
    }

    /**
     * Strict key allowlists for specific fields (e.g. horoscope nadi/gan).
     *
     * @return string[]|null
     */
    private function getStrictAllowedKeys(string $fieldKey): ?array
    {
        $config = $this->registry->get($fieldKey);

        return $config['strict_keys'] ?? null;
    }

    /**
     * Match raw value against synonym config; return canonical key or null.
     *
     * @param  array<string, string[]>  $synonymConfig
     */
    private function matchUsingSynonyms(string $rawValue, array $synonymConfig): ?string
    {
        $normalized = $this->normalizeForTokenMatching($rawValue);
        if ($normalized === '') {
            return null;
        }

        $normalizedLower = mb_strtolower($normalized, 'UTF-8');
        foreach ($synonymConfig as $canonical => $synonyms) {
            foreach ($synonyms as $syn) {
                $synNorm = $this->normalizeForTokenMatching($syn);
                if ($synNorm !== '' && $normalizedLower === mb_strtolower($synNorm, 'UTF-8')) {
                    return $canonical;
                }
            }
        }

        $tokens = preg_split('/[\s,;:|]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
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
                $tokenNorm = $this->normalizeForTokenMatching($token);
                $tokenLower = mb_strtolower($tokenNorm, 'UTF-8');

                foreach ($synTokens as $syn) {
                    if ($syn === '') {
                        continue;
                    }
                    $synLower = mb_strtolower($syn, 'UTF-8');
                    if ($tokenLower === $synLower) {
                        $matches[] = $canonical;
                        break 2;
                    }
                }
            }

            if (in_array($canonical, $matches, true)) {
                continue;
            }
            foreach ($synTokens as $syn) {
                if ($syn !== '' && mb_strpos($normalizedLower, mb_strtolower($syn, 'UTF-8')) !== false) {
                    $matches[] = $canonical;
                    break;
                }
            }
        }

        $unique = array_values(array_unique($matches));
        if (count($unique) === 1) {
            return $unique[0];
        }

        return null;
    }

    /**
     * Fallback: normalize to key-style string and try to match master `key` or label.
     */
    private function fallbackNormalizeToKey(string $rawValue, $masters): ?string
    {
        $trimmed = trim($rawValue);
        if ($trimmed === '') {
            return null;
        }

        $candidateKey = str_replace(' ', '_', mb_strtolower($trimmed, 'UTF-8'));
        $direct = $masters->first(fn ($row) => (string) ($row->key ?? '') === $candidateKey);
        if ($direct) {
            return (string) $direct->key;
        }

        $candidateLabel = mb_strtolower($trimmed, 'UTF-8');
        $byLabel = $masters->first(function ($row) use ($candidateLabel) {
            $label = mb_strtolower(trim((string) ($row->label ?? '')), 'UTF-8');

            return $label !== '' && $label === $candidateLabel;
        });

        if ($byLabel) {
            return (string) $byLabel->key;
        }

        return null;
    }

    /**
     * Normalize for token comparison (safe for Marathi + English).
     * Uses mb_* and optional Unicode NFC so composed/decomposed forms match.
     */
    private function normalizeForTokenMatching(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return '';
        }

        // Remove zero-width chars so "वृश्‍चिक" (with ZWJ) matches "वृश्चिक"
        $v = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $v);
        $v = preg_replace('/\s+/u', ' ', $v);
        $v = trim($v, " \t\n\r\0\x0B,;:|");
        if ($v === '') {
            return '';
        }

        if (class_exists('Normalizer')) {
            if (\Normalizer::isNormalized($v, \Normalizer::FORM_C) === false) {
                $n = \Normalizer::normalize($v, \Normalizer::FORM_C);
                if ($n !== false) {
                    $v = $n;
                }
            }
        }

        return $v;
    }
}

