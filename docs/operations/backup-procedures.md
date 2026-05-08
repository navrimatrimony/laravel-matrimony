# Backup Procedures

Include these artifacts in filesystem backup jobs:
- `storage/app/data-audit/snapshots`
- `python-data-engine/output/comparisons`
- `python-data-engine/config/comparison_suppressions.yml`
- `python-data-engine/config/data_lineage.yml`
- DB table `data_audit_operation_events`

Restore order:
1. Restore DB.
2. Restore snapshot/comparison artifacts.
3. Restore config files.
4. Run `php artisan data-audit:cleanup --dry-run` for integrity check.
