<?php

namespace App\Services;

use App\Models\FieldRegistry;

/**
 * Phase-5 Point 5: Canonical field catalog — single source for section order,
 * section labels, and minimal vs full wizard scope.
 * Same catalog used by: post-registration wizard (minimal), full edit, intake preview.
 */
class FieldCatalogService
{
    protected static ?array $sectionOrder = null;

    protected static ?array $minimalSections = null;

    protected static ?array $sectionLabels = null;

    protected static ?array $fieldToSection = null;

    protected static function load(): void
    {
        if (self::$sectionOrder !== null) {
            return;
        }
        self::$sectionOrder = config('field_catalog.section_order', []);
        self::$minimalSections = config('field_catalog.minimal_wizard_sections', ['basic-info', 'contacts']);
        self::$sectionLabels = config('field_catalog.section_labels', []);
        self::$fieldToSection = config('field_catalog.field_to_section', []);
    }

    /**
     * Ordered section keys for the given mode.
     *
     * @param  bool  $minimal  true = post-registration wizard (fewer sections)
     */
    public static function getSectionKeys(bool $minimal = false): array
    {
        self::load();
        if ($minimal) {
            return array_values(array_intersect(self::$sectionOrder, self::$minimalSections));
        }

        return self::$sectionOrder;
    }

    /**
     * First section key for the given mode.
     */
    public static function getFirstSection(bool $minimal = false): string
    {
        $keys = self::getSectionKeys($minimal);

        return $keys[0] ?? 'basic-info';
    }

    /**
     * Next section key after $current, or null if last.
     *
     * @param  bool  $minimal  true = use minimal wizard section list
     */
    public static function getNextSection(string $current, bool $minimal = false): ?string
    {
        $keys = self::getSectionKeys($minimal);
        $idx = array_search($current, $keys, true);
        if ($idx === false || $idx === count($keys) - 1) {
            return null;
        }

        return $keys[$idx + 1];
    }

    /**
     * Section key before $current, or null if first.
     */
    public static function getPreviousSection(string $current, bool $minimal = false): ?string
    {
        $keys = self::getSectionKeys($minimal);
        $idx = array_search($current, $keys, true);
        if ($idx === false || $idx <= 0) {
            return null;
        }

        return $keys[$idx - 1];
    }

    /**
     * Human-readable label for a section key.
     */
    public static function getSectionLabel(string $sectionKey): string
    {
        self::load();

        return self::$sectionLabels[$sectionKey] ?? $sectionKey;
    }

    /**
     * Sections for progress/nav: [ section_key => label ] in order.
     *
     * @param  bool  $minimal  true = only minimal wizard sections
     */
    public static function getSectionsForDisplay(bool $minimal = false): array
    {
        $keys = self::getSectionKeys($minimal);
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = self::getSectionLabel($key);
        }

        return $out;
    }

    /**
     * Whether the given section is in the minimal wizard set.
     */
    public static function isInMinimalWizard(string $sectionKey): bool
    {
        self::load();

        return in_array($sectionKey, self::$minimalSections, true);
    }

    /**
     * CORE field keys that belong to this section (from catalog mapping).
     * Optionally enriched with display_order from FieldRegistry.
     */
    public static function getFieldKeysForSection(string $sectionKey): array
    {
        self::load();
        $fieldKeys = array_keys(array_filter(self::$fieldToSection, fn ($s) => $s === $sectionKey));
        if (empty($fieldKeys)) {
            return [];
        }
        $registry = FieldRegistry::whereIn('field_key', $fieldKeys)
            ->where('is_enabled', true)
            ->orderBy('display_order')
            ->pluck('display_order', 'field_key')
            ->toArray();
        usort($fieldKeys, function ($a, $b) use ($registry) {
            $oa = $registry[$a] ?? 999;
            $ob = $registry[$b] ?? 999;

            return $oa <=> $ob;
        });

        return $fieldKeys;
    }

    /**
     * Catalog entry for a CORE field (label, input_type, options_source, etc.) from FieldRegistry.
     * Returns null if not found.
     */
    public static function getFieldCatalogEntry(string $fieldKey): ?array
    {
        $field = FieldRegistry::where('field_key', $fieldKey)->where('is_enabled', true)->first();
        if (! $field) {
            return null;
        }

        return [
            'field_key' => $field->field_key,
            'label' => $field->display_label,
            'data_type' => $field->data_type,
            'field_type' => $field->field_type,
            'is_mandatory' => $field->is_mandatory,
            'display_order' => $field->display_order,
            'category' => $field->category,
            'dependency_condition' => $field->dependency_condition,
        ];
    }
}
