# Gender SSOT Audit

Date: 2026-06-20

## Runtime matrimony rule

- Matrimony profile gender source is `matrimony_profiles.gender_id`.
- Runtime matrimony display, comparison, matching, visibility, mobile profile payloads, and profile photo placeholders must not use `users.gender` as a fallback.
- `users.gender` has been removed from application runtime reads/writes and is dropped by `2026_06_20_120000_drop_gender_from_users_table.php`.

## Removed `users.gender` writes

- `app/Http/Controllers/Api/AuthController.php`: mobile registration no longer validates, writes, or returns account gender.
- `app/Http/Controllers/Auth/RegisteredUserController.php`: web registration no longer writes blank legacy gender.
- `app/Http/Controllers/Admin/AdminIntakeController.php`: admin candidate gender is retained as input only for profile `gender_id` mapping where a profile is created.
- `app/Http/Controllers/Suchak/ManualProfileController.php`: Suchak candidate gender is retained as input only for profile `gender_id` mapping.
- `app/Services/MutationService.php`: intake-created auth shells no longer write gender to `users`.

## Removed legacy reads

- `app/Http/Controllers/OnboardingController.php` and `app/Http/Controllers/ProfileWizardController.php` no longer read `users.gender` to bootstrap profile gender.

## Data repair note

Precondition for dropping `users.gender`: `matrimony_profiles.gender_id IS NULL` count is `0` in local and live databases. No migration in this pass infers profile gender from `users.gender`.
