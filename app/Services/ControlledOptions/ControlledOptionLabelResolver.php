<?php

namespace App\Services\ControlledOptions;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5 Day-36: Centralized label resolver for controlled options.
 *
 * Resolves EN/MR labels using translations first, then DB labels, then keys.
 */
class ControlledOptionLabelResolver
{
    public function __construct(
        private readonly ControlledOptionRegistry $registry,
    ) {
    }

    /**
     * Get option list (key => ['id' => int, 'label' => string]) for a field and locale.
     *
     * @return array<string, array{id:int,label:string}>
     */
    public function options(string $fieldKey, string $locale = null): array
    {
        $config = $this->registry->get($fieldKey);
        if (($config['source_type'] ?? null) !== 'master_table') {
            return [];
        }

        $table = $config['table'];
        $keyColumn = $config['key_column'] ?? 'key';
        $labelColumn = $config['label_column'] ?? 'label';
        $idColumn = $config['id_column'] ?? 'id';
        $activeColumn = $config['active_column'] ?? null;
        $translationNs = $config['translation_namespace'] ?? null;
        $strict = $config['strict_keys'] ?? null;

        $q = DB::table($table);
        if ($activeColumn && Schema::hasColumn($table, $activeColumn)) {
            $q->where($activeColumn, true);
        }
        $rows = $q->get([$idColumn, $keyColumn, $labelColumn]);

        $locale = $locale ?: (App::getLocale() ?: 'en');
        $out = [];

        foreach ($rows as $row) {
            $key = (string) ($row->$keyColumn ?? '');
            if ($key === '') {
                continue;
            }
            if (is_array($strict) && ! in_array($key, $strict, true)) {
                continue;
            }

            $dbLabel = (string) ($row->$labelColumn ?? '');
            $label = $dbLabel !== '' ? $dbLabel : $key;

            if ($translationNs) {
                $tKey = $translationNs . '.' . $key;
                $translated = trans($tKey, [], $locale);
                if ($translated !== $tKey) {
                    $label = $translated;
                }
            }

            $out[$key] = [
                'id' => (int) $row->$idColumn,
                'label' => $label,
            ];
        }

        return $out;
    }

    /**
     * Get human label for a single key.
     */
    public function label(string $fieldKey, string $key, string $locale = null): ?string
    {
        $opts = $this->options($fieldKey, $locale);

        return $opts[$key]['label'] ?? null;
    }

    /**
     * Convenience: return list of options keyed by id instead of key.
     *
     * @return array<int,array{key:string,label:string}>
     */
    public function optionsWithIds(string $fieldKey, string $locale = null): array
    {
        $byKey = $this->options($fieldKey, $locale);
        $out = [];
        foreach ($byKey as $key => $row) {
            $out[$row['id']] = [
                'key' => $key,
                'label' => $row['label'],
            ];
        }

        return $out;
    }
}

