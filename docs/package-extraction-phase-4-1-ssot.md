# Phase 4.1 SSOT - Package Extraction Preparation

## Package Boundary Architecture
- Keep runtime behavior in application namespace (`App\...`) unchanged.
- Add extraction-ready mirror namespace (`NMN\DataGovernance\...`) for stable external package targets.
- Preserve existing contracts for snapshots, comparisons, suppressions, scoring, and reporting.

## Service Provider Plan
- Introduce `App\Providers\DataGovernanceServiceProvider` as package-style boundary.
- Responsibilities:
  - config merge for data-governance config tree
  - command registration for governance CLI operations
  - view namespace registration (`data-governance::`)
  - service/container bindings for adapters/storage
- Add mirror provider namespace for future package handoff:
  - `NMN\DataGovernance\Providers\DataGovernanceServiceProvider`

## Config Publish Strategy
- Isolate publish-ready configs under:
  - `config/data-governance/platform.php`
  - `config/data-governance/suppressions.php`
  - `config/data-governance/entities.php`
- Keep `config/data_audit_platform.php` as backward-compat shim.
- Future package publish tag: `data-governance-config`.

## Migration Strategy
- No schema changes in Phase 4.1.
- Migration extraction deferred to package publication phase.
- Existing DB contracts remain authoritative.

## Asset / View Publish Strategy
- Register namespaced views with `data-governance::`.
- Keep existing route behavior and admin pages unchanged.
- Future extraction can move views to package resources and publish override hooks.

## Compatibility Guarantees
- Deterministic behavior unchanged.
- Snapshot/comparison/report contracts unchanged.
- Existing commands/routes remain compatible.
- Namespace mirrors are additive; legacy class paths continue to work.
- No AI, browser automation, AST execution, or auto-fix behavior introduced.

