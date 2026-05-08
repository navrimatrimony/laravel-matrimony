# Quarantine Handling

- Quarantine path: `python-data-engine/output/quarantine`.
- Recovery audit log: `python-data-engine/output/recovery-audit/recovery.log`.
- Quarantine is used for malformed comparison artifacts (invalid JSON).
- Review artifact, fix source cause, then rerun operation; do not reintroduce quarantined files directly.
