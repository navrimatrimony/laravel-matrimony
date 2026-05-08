# Productionization & Ops Hardening SSOT

## 1) Production architecture
- Laravel schedules deterministic governance jobs (`analyze`, `snapshot`, `compare`, `cleanup`, `notify`).
- Each operation now uses lock-protected execution and records runtime events in `data_audit_operation_events`.
- Existing comparison logic and snapshot contract remain unchanged.

## 2) Cron/scheduler lifecycle
- Scheduler entries are named and hardened with `withoutOverlapping()` and `onOneServer()`.
- Command-level lock (`data-audit:lock:{operation}`) prevents duplicate manual/cron overlap.
- Exit state is deterministic: `success`, `failed`, or `skipped_locked`.

## 3) Retention governance
- Retention policy in `config/data_engine.php` (`retention` section).
- Snapshot pruning: per-entity cap + age-based pruning.
- Comparison/report/log pruning: rolling retention + age windows.
- Cleanup supports dry-run and execute mode.

## 4) Monitoring strategy
- Operation heartbeats persist in `data_audit_operation_events`.
- `/admin/data-engine` shows last success/failure per operation, failure streak, and average duration trend.
- Storage usage metrics are computed deterministically from snapshot/comparison/report directories.

## 5) Alert governance
- `data-audit:notify` evaluates:
  - health score threshold breach
  - high severity threshold breach
  - mismatch spike
  - storage exhaustion risk
  - snapshot failure streak
- Suppression via `DATA_AUDIT_ALERT_SUPPRESS`.
- Cooldown via `DATA_AUDIT_ALERT_COOLDOWN_MINUTES`.

## 6) Failure recovery strategy
- Failed runs do not block future runs; stale running rows are already released in engine service.
- Invalid comparison JSON artifacts are quarantined.
- Recovery events are appended to `python-data-engine/output/recovery-audit/recovery.log`.

## 7) Backup strategy
- Filesystem-first export readiness:
  - snapshots
  - comparisons
  - suppression/lineage configs
  - operation heartbeat history
- No cloud integration introduced in this phase.

## 8) Scaling assumptions
- Single scheduler authority (`onOneServer`) in production.
- Moderate file growth handled with rolling retention and age limits.
- Metrics remain lightweight and DB/file based (no external TSDB).

## 9) Operational safety guarantees
- Overlap prevention at scheduler and command lock level.
- Deterministic operation event recording for every run path.
- Quarantine instead of destructive delete for malformed runtime artifacts.

## 10) Future AI boundaries
- No AI anomaly detection, no autofix intelligence, no browser automation.
- Future AI phase must consume existing deterministic metrics and heartbeat events as read-only signals.
