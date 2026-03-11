<?php

namespace App\Services\ControlledOptions;

use Illuminate\Support\Facades\DB;
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
                'rakshasa' => ['rakshas', 'rakshasa', 'राक्षस', 'राक्षसगण'],
            ];
        }

        return null;
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
     */
    private function normalizeForTokenMatching(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return '';
        }

        $v = preg_replace('/\s+/u', ' ', $v);
        $v = trim($v, " \t\n\r\0\x0B,;:|");

        return $v;
    }
}

