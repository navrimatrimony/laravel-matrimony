# Retention Tuning

Configure in `config/data_engine.php`:
- `retention.snapshot_keep_per_entity`
- `retention.snapshot_max_age_days`
- `retention.comparison_keep_files`
- `retention.comparison_max_age_days`
- `retention.report_max_age_days`
- `retention.log_max_age_days`

Dry-run:
- `php artisan data-audit:cleanup --dry-run`

Apply:
- `php artisan data-audit:cleanup --execute`
