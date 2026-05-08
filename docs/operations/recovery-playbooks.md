# Recovery Playbooks

- `compare` failure: rerun `php artisan data-audit:compare --latest`; verify latest comparison JSON is valid; check quarantine folder.
- `snapshot` failure: rerun `php artisan data-audit:snapshot --entity=matrimony_profile --limit=10`; verify snapshot path permissions.
- Stale engine: inspect `/admin/data-engine` heartbeat; run `php artisan data-audit:analyze` manually.
- Lock contention: if operation shows `skipped_locked`, investigate scheduler duplication before manual retry.
