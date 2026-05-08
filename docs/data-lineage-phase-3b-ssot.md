# Phase 3B SSOT - Snapshot Comparison Engine

## Scope
Phase 3B adds deterministic comparison auditing on top of Phase 3A snapshots.
It does not modify snapshot schema, lineage report contracts, or introduce AI scoring.

## Comparison Architecture
- Input: latest (or profile-targeted) snapshot JSON under `storage/app/data-audit/snapshots/`
- Engine: `python-data-engine/scripts/modules/snapshot_comparison_engine.py`
- Entry: `python-data-engine/scripts/runner.py compare --latest|--profile=<id>`
- Output: JSON comparison report in `python-data-engine/output/comparisons/`
- Admin: lightweight summary only on `/admin/data-engine`

## Severity Model
- `high`: missing render for populated DB value; null propagation from populated DB
- `medium`: API drift; cross-layer inconsistency
- `low`: informational pass rows (exact/normalized matches)

Health score starts at 100 and subtracts:
- high: 20
- medium: 10
- low: 5

Score floor is `0`.

## Normalization Rules
Deterministic normalization only:
- whitespace normalization (`trim`, collapse spaces, lowercase)
- numeric-string normalization (`"180"` equals `180`)
- height normalization:
  - `"180 cm"` -> `180`
  - `"5 ft 11 in"` -> computed centimeters (rounded)

No fuzzy AI logic.

## Mismatch Taxonomy
- `exact_match` (pass)
- `normalized_match` (pass)
- `missing_render` (fail)
- `null_propagation` (fail)
- `api_drift` (fail)
- `cross_layer_inconsistency` (fail)

## Comparison JSON Contract
```json
{
  "snapshot_id": "snapshot_2026_05_06_125532",
  "snapshot_path": "...",
  "health_score": 91,
  "summary": {
    "compared_fields": 13,
    "mismatch_count": 2,
    "high_severity_count": 1,
    "medium_severity_count": 1,
    "pass_count": 11
  },
  "comparisons": [
    {
      "field": "height_cm",
      "db": "180",
      "api": "180",
      "rendered": "5 ft 11 in",
      "comparison_type": "normalized_match",
      "severity": "low",
      "status": "pass"
    }
  ],
  "metrics": {
    "compare_duration_ms": 0,
    "snapshot_load_ms": 0
  }
}
```

## Safety Constraints
- Read-only snapshot loading
- No browser automation, JS execution, AST engines, or AI scoring
- No mutation of snapshots or production tables

## Future Extension Points (Phase 3C+)
- Multi-snapshot trend baselines
- deterministic rule bundles per field family
- optional per-field suppression allowlist
- dedicated comparison drill-down UI
