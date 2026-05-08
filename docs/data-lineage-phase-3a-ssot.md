# Phase 3A SSOT - Snapshot Engine Foundation

## Scope
Phase 3A introduces a deterministic and read-only snapshot engine that captures runtime outputs from three layers:

1. Canonical DB values (`matrimony_profiles`)
2. API payload output (controller JSON response shape)
3. Rendered Blade output (Laravel view pipeline only)

Phase 2 lineage contracts remain frozen and unchanged.

## Snapshot Architecture
- Entry point: `php artisan data-audit:snapshot`
- Orchestration: `SnapshotGeneratorService`
- Render extraction: `RenderedFieldExtractor`
- Storage persistence: `SnapshotStorageService`
- Admin visibility: snapshot metadata only (count/latest/health hint), no comparison UI in Phase 3A.

## Snapshot JSON Contract (v1)
```json
{
  "snapshot_version": "1",
  "profile_id": 206,
  "captured_at": "2026-05-06T12:00:00+05:30",
  "sources": {
    "db": true,
    "api": true,
    "rendered": true
  },
  "db": {},
  "api": {},
  "rendered": {},
  "metrics": {
    "capture_duration_ms": 0,
    "memory_peak_kb": 0,
    "rendered_pages_count": 0
  }
}
```

## Storage Layout
- Base: `storage/app/data-audit/snapshots/`
- Per profile: `profile_<id>/`
- File pattern: `snapshot_YYYY_MM_DD_HHMMSS.json`

Example:
`storage/app/data-audit/snapshots/profile_206/snapshot_2026_05_06_120000.json`

## Capture Sources
- **DB source**: raw canonical fields from `matrimony_profiles` for high-priority lineage keys.
- **API source**: actual JSON payload from the profile API controller path (no external HTTP crawling).
- **Rendered source**: HTML content generated from Laravel controller/view pipeline for:
  - public profile page
  - wizard section (`basic-info`)

## Safety Guarantees
- No browser launch or browser automation.
- No JS execution.
- No Selenium/Playwright/Puppeteer.
- No AST parsing engine.
- No user-data mutation by snapshot services.
- Read-only snapshot generation and storage only.

## Known Limitations (Phase 3A)
- Rendered field extraction is intentionally lightweight and text-based, not a DOM/JS renderer.
- Captured rendered snippets are truncated excerpts plus content hash for storage efficiency.
- API snapshot shape follows the existing controller output contract.
- No cross-layer mismatch scoring in this phase.

## Phase 3B Extension Points
- DB/API/rendered side-by-side diff engine.
- Snapshot-to-snapshot drift tracking.
- Field-level mismatch severity scoring.
- Optional retention policy tooling (delete oldest snapshots per profile).
- Dedicated admin comparison UI and drill-down.
