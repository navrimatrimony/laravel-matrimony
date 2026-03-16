<?php

/**
 * Ensures translation keys for Extended Family / Other Relatives UI exist.
 * Prevents accidental removal of notes_placeholder_extended_family and hint strings.
 */
test('relation extended family translation keys exist in en components', function () {
    $path = __DIR__ . '/../../lang/en/components.php';
    expect(file_exists($path))->toBeTrue();
    $en = require $path;
    $relation = $en['relation'] ?? [];
    expect($relation['notes_placeholder_extended_family'] ?? '')->toBe('Notes about this person only');
    expect($relation['notes_hint_other_relatives_below'] ?? '')->toContain('Other Relatives');
    expect($relation['notes_hint_other_relatives_other_tab'] ?? '')->toContain('Other Relatives');
});

test('relation extended family translation keys exist in mr components', function () {
    $path = __DIR__ . '/../../lang/mr/components.php';
    expect(file_exists($path))->toBeTrue();
    $mr = require $path;
    $relation = $mr['relation'] ?? [];
    expect($relation['notes_placeholder_extended_family'] ?? '')->toBe('फक्त या व्यक्तीविषयी नोंद');
    expect($relation['notes_hint_other_relatives_below'] ?? '')->toContain('इतर नातेवाईक');
    expect($relation['notes_hint_other_relatives_other_tab'] ?? '')->toContain('इतर नातेवाईक');
});
