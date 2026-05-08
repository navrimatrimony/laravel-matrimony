# Scheduler Setup

- Ensure a single scheduler authority in production.
- Cron should run Laravel scheduler every minute.
- Governance schedules are lock-protected and use `onOneServer`.
- Validate with `php artisan schedule:list` and confirm no duplicate entries.
