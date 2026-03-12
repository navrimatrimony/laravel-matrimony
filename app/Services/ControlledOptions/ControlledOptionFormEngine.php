<?php

namespace App\Services\ControlledOptions;

use Illuminate\Support\Facades\App;

/**
 * Phase-5 Day-37: Centralized Controlled Option Form Engine.
 *
 * Universal Controlled Field Engine: builds UI-ready option metadata
 * for controlled-option fields using the registry + engine + label resolver.
 */
class ControlledOptionFormEngine
{
    public function __construct(
        private readonly ControlledOptionRegistry $registry,
        private readonly ControlledOptionLabelResolver $labels,
        private readonly ControlledOptionEngine $engine,
    ) {
    }

    /**
     * Get options ready for UI (active master rows, localized labels).
     *
     * @return array<int, array{id: int, key: string, label: string}>
     */
    public function getOptions(string $fieldKey, ?string $locale = null): array
    {
        $locale = $locale ?? App::getLocale() ?: 'en';
        $optionsById = $this->labels->optionsWithIds($fieldKey, $locale);
        $options = [];
        foreach ($optionsById as $id => $opt) {
            $options[] = [
                'id' => (int) $id,
                'key' => (string) $opt['key'],
                'label' => (string) $opt['label'],
            ];
        }

        return $options;
    }

    /**
     * Build UI-ready metadata for a controlled field (options + selected state).
     *
     * @param  int|string|null|array<int,int|string|null>  $selected
     * @return array{
     *   field_key: string,
     *   multiple: bool,
     *   options: array<int,array{id:int,key:string,label:string,selected:bool}>,
     *   normalized_selected: int[]
     * }
     */
    public function build(string $fieldKey, int|string|null|array $selected = null, ?string $locale = null): array
    {
        $config = $this->registry->get($fieldKey);
        $isMulti = (bool) ($config['multi_select'] ?? false);
        $locale = $locale ?? App::getLocale() ?: 'en';

        $normalizedSelected = $this->normalizeSelected($fieldKey, $selected);
        $selectedSet = array_flip($normalizedSelected);

        $options = $this->getOptions($fieldKey, $locale);
        foreach ($options as $i => $opt) {
            $options[$i]['selected'] = isset($selectedSet[$opt['id']]);
        }

        return [
            'field_key' => $fieldKey,
            'multiple' => $isMulti,
            'options' => $options,
            'normalized_selected' => $normalizedSelected,
        ];
    }

    /**
     * Normalize posted selected values to validated id array.
     *
     * @param  int|string|null|array<int,int|string|null>  $selected
     * @return int[]
     */
    public function normalizeSelected(string $fieldKey, int|string|null|array $selected): array
    {
        $config = $this->registry->get($fieldKey);
        $isMulti = (bool) ($config['multi_select'] ?? false);

        if ($isMulti) {
            $ids = is_array($selected) ? $selected : ($selected === null ? [] : [$selected]);

            return $this->engine->resolveArray($fieldKey, $ids);
        }

        // Single-select
        if (is_array($selected)) {
            $selected = $selected[0] ?? null;
        }
        $id = $this->engine->resolveId($fieldKey, $selected);

        return $id !== null ? [$id] : [];
    }
}

