# Phase 4 SSOT - Platformization and Multi-Project Support

## Platform Architecture
- Core governance engines remain deterministic and contract-safe (Phase 2–3 locked).
- New platform layer introduces entity-driven adapters, config hierarchy, scheduling, and hooks.
- Runtime split:
  - Laravel: snapshot orchestration + admin surfaces + scheduling + alerts
  - Python: snapshot comparison + suppression + trend/history + cleanup

## Entity Abstraction Model
- Entity key drives behavior (`matrimony_profile`, `customer`, `employee`, `vendor`, etc.).
- Entity config defines:
  - canonical table
  - canonical fields
  - route hints
- Adapters resolve:
  - target records
  - db/api/rendered capture strategy

## Adapter System
- `EntityAdapter` contract in Laravel
- `MatrimonyProfileEntityAdapter` for full DB/API/rendered capture
- `GenericTableEntityAdapter` for reusable DB-first capture (project-agnostic fallback)
- `EntityAdapterRegistry` resolves adapter by entity key from config

## Configuration Hierarchy
1. Environment vars (`DATA_AUDIT_*`, `ENGINE_*`)
2. Laravel platform config (`config/data_audit_platform.php`)
3. Python entity YAML (`python-data-engine/config/entities/*.yml`)
4. Suppression/retention config (`comparison_suppressions.yml`, retention envs)

## Deployment Model
- Local/staging/production profile via `DATA_AUDIT_ENV_PROFILE`
- Scheduled jobs:
  - daily snapshot
  - daily compare
  - retention cleanup (dry-run/execute policy)
  - threshold notifications

## Package-Ready Boundaries (future)
- Laravel package candidates:
  - entity adapter contracts/registry
  - snapshot orchestrator commands
  - admin comparison drill-down pages
- Python package candidates:
  - comparison module + retention + trend/index builder
  - suppression engine
  - config loaders

## Non-Goals
- No AI inference
- No browser automation
- No AST/template execution
- No auto-fix mutation in platform layer

