<?php

declare(strict_types=1);

namespace App\Services\Intake;

/**
 * SSOT: intake biodata location suggestions bind to every hierarchy location field on preview/full_form.
 *
 * @see docs/INTAKE-LOCATION-SUGGESTIONS-SSOT.md
 */
final class IntakeLocationFieldRegistry
{
    public const DOC_PATH = 'docs/INTAKE-LOCATION-SUGGESTIONS-SSOT.md';

    /** @var list<string> */
    public const LOCKED_INVARIANTS = [
        'Biodata/parser text never overwrites the visible typeahead value on page load.',
        'Profile-saved location ids/text stay in the form until the member clicks Apply on a suggestion.',
        'Apply PATCH writes approval_snapshot_json and updates the visible typeahead on explicit Apply; final approve merges applied suggestions.',
        'Suggestions use indigo From biodata + Apply (same as other parse suggestions).',
    ];

    /**
     * DOM anchor for inline suggestion UI ({@see resources/views/intake/preview.blade.php}).
     *
     * @return array{type: string, value?: string|int, prefix?: string, index?: int, container?: string}
     */
    public static function domAnchor(string $fieldKey): array
    {
        if ($fieldKey === 'birth_place') {
            return ['type' => 'location_context', 'value' => 'birth'];
        }
        if ($fieldKey === 'native_place') {
            return ['type' => 'location_context', 'value' => 'native'];
        }
        if ($fieldKey === 'work_location') {
            return ['type' => 'location_context', 'value' => 'work'];
        }
        if (preg_match('/^addresses\.(\d+)$/', $fieldKey, $m) === 1) {
            return ['type' => 'parents_address_row', 'index' => (int) $m[1]];
        }
        if (preg_match('/^parents_addresses\.(\d+)$/', $fieldKey, $m) === 1) {
            return ['type' => 'parents_address_row', 'index' => (int) $m[1]];
        }
        if (preg_match('/^self_addresses\.(\d+)$/', $fieldKey, $m) === 1) {
            return ['type' => 'self_address_row', 'index' => (int) $m[1]];
        }
        if (preg_match('/^relatives_parents_family\.(\d+)$/', $fieldKey, $m) === 1) {
            return ['type' => 'relatives_row', 'container' => 'relatives_parents_family', 'index' => (int) $m[1]];
        }
        if (preg_match('/^relatives_maternal_family\.(\d+)$/', $fieldKey, $m) === 1) {
            return ['type' => 'relatives_row', 'container' => 'relatives_maternal_family', 'index' => (int) $m[1]];
        }

        return ['type' => 'unknown', 'value' => $fieldKey];
    }

    /**
     * @return list<string>
     */
    public static function supportedFieldKeyPatterns(): array
    {
        return [
            'birth_place',
            'native_place',
            'work_location',
            'addresses.{n}',
            'parents_addresses.{n}',
            'self_addresses.{n}',
            'relatives_parents_family.{n}',
            'relatives_maternal_family.{n}',
        ];
    }
}
