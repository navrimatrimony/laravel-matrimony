# Phase 3C SSOT - Governed Operations Layer

## Scope
Phase 3C operationalizes snapshot comparison for sustained production use with deterministic governance controls.
Locked contracts remain unchanged:
- Phase 2 lineage report schema
- Phase 3A snapshot schema
- Phase 3B comparison core contract

## Suppression Architecture
- Source: `python-data-engine/config/comparison_suppressions.yml`
- Rule dimensions:
  - `field`
  - `comparison_type`
  - `route_view`
  - `suppress` (default true)
  - `reason`
  - `severity_override`
- Suppressed rows remain in report for traceability but are excluded from governed health scoring.

## Retention Policy
- Snapshots retained by profile (`ENGINE_SNAPSHOT_RETENTION_PER_PROFILE`, default 30)
- Comparison reports retained globally (`ENGINE_COMPARISON_RETENTION_FILES`, default 300)
- Cleanup command supports dry-run and execution modes.

## Comparison Lifecycle
1. Load latest or profile-targeted snapshot
2. Compute deterministic comparisons
3. Apply suppression and severity overrides
4. Compute governed score
5. Write comparison report
6. Update trend/history sections in-report

## Trend Model
- Field-level failure counters from recent comparison history
- Captures:
  - `failure_count`
  - `first_seen`
  - `last_seen`
  - `trend`: `persistent` (>=3 failures) or `intermittent`

## Operational Guarantees
- Read-only snapshot/comparison analysis
- No browser automation, JS execution, AI inference, or AST engines
- No mutation of profile/user domain data
- Explainable rule outcomes for each row (`suppressed`, `suppression_reason`, `effective_severity`)

## Future AI Extension Points
- AI anomaly scoring can layer over governed outputs later.
- AI phase must consume (not replace) deterministic baseline.
- Suppression/override controls remain authoritative governance boundary.

