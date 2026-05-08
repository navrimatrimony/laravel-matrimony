# Data Lineage Phase 2 SSOT (Frozen)

This document freezes the Phase 2 manifest-driven lineage system.

## 1) Final Architecture

- Source of truth: `python-data-engine/config/data_lineage.yml`
- Engine: `python-data-engine/scripts/modules/data_lineage_engine.py`
- Integration: `python-data-engine/scripts/runner.py` includes `data_lineage` in analyze report
- Admin:
  - Route: `admin.data-engine.data-lineage`
  - Controller: `App\Http\Controllers\Admin\DataEngineController::dataLineage()`
  - View: `resources/views/admin/data-engine/data-lineage.blade.php`
  - Index metrics: `resources/views/admin/data-engine/index.blade.php`

Design constraints:
- Deterministic regex scanning only
- No Blade execution
- No PHP execution from Python module
- No browser/runtime instrumentation

## 2) Detection Types (Frozen Taxonomy)

1. `wrong_sources`
2. `multi_source_conflicts`
3. `wizard_public_mismatches`
4. `missing_render_risks`
5. `manifest_errors` (validation failures)

Severity values:
- `high`, `medium`, `low`

## 3) Report JSON Contract (Frozen)

Required path:

```json
{
  "data_lineage": {
    "summary": {},
    "manifest_errors": [],
    "wrong_sources": [],
    "multi_source_conflicts": [],
    "wizard_public_mismatches": [],
    "missing_render_risks": [],
    "metrics": {}
  }
}
```

Required summary keys:
- `health_score`
- `manifest_errors`
- `wrong_sources`
- `multi_source_conflicts`
- `wizard_public_mismatches`
- `missing_render_risks`
- `fields_audited`

Compatibility rules:
- New keys MAY be added (additive only)
- Existing keys MUST NOT be silently renamed or removed
- Existing key semantics MUST remain backward-compatible unless migration notes are published

## 4) Manifest Schema (Phase 2)

Top-level:
- `version`
- `fields` (mapping)

Per field:
- `canonical_source.table`
- `canonical_source.column`
- `wizard.blades` (array)
- `wizard.bindings` (array)
- `public_profile.blades` (array)
- `public_profile.bindings` (array)

Optional metadata:
- `wizard.route`, `wizard.route_note`, `public_profile.route`

## 5) Regex Detection Rules (Phase 2)

Detected patterns:
- `$profile->field`
- `$user->field`
- `$profile['field']`
- `$user['field']`
- `optional($profile)->field`
- `data_get($profile, 'field')`
- Null-coalesce:
  - `$profile->x ?? $user->y`
  - `$user->x ?? $profile->y`

Normalization format:
- `profile.<field>`
- `user.<field>`

## 6) Performance Metrics Contract

Required metrics keys at `data_lineage.metrics`:
- `scan_duration_ms`
- `memory_peak_kb`
- `blade_count_scanned`
- `manifest_field_count`

Metrics are runtime observability; they are not part of detection taxonomy.

## 7) Safety Guarantees

Phase 2 guarantees:
- No Blade execution/compilation
- No Laravel kernel boot from lineage module
- No browser automation
- No runtime instrumentation hooks
- File read + regex parsing only

## 8) Admin UI Contract

Data Lineage admin page must show:
1. Overall health score
2. Manifest validation errors
3. Wrong source warnings
4. Multi-source conflicts
5. Wizard/public mismatches
6. Missing render risks

Severity colors:
- High: red
- Medium: yellow/amber
- Low: safe non-error accent (blue/neutral)

## 9) Known Limitations (Phase 2)

- Regex scanning may produce false positives/negatives for highly dynamic templates
- No AST-level semantic understanding
- No rendered HTML validation
- No runtime request/controller execution tracing

## 10) Phase 3 Extension Points (Allowed)

Phase 3 MAY add:
- Blade AST parsing
- Smarter lineage inference
- Confidence scoring
- Auto-fix recommendations
- Lineage graph generation

Phase 3 MUST NOT violate safety baseline:
- Do not execute Blade
- Do not execute PHP from lineage engine
- Do not compile templates
- Do not introduce browser automation in lineage verification path

