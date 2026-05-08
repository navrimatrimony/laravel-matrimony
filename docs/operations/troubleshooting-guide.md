# Troubleshooting Guide

- Check `/admin/data-engine` for heartbeat, failure streak, and last success timestamps.
- Inspect `storage/logs/data-engine.log` for command-level errors.
- Inspect `python-data-engine/output/recovery-audit/recovery.log` for quarantined artifacts.
- For repeated notify events, verify cooldown and suppression env settings.
