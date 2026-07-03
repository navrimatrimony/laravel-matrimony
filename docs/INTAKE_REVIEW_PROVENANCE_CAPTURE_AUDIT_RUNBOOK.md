# Intake Review Provenance Capture Audit Runbook

## Purpose

`intake:review-provenance-capture-audit` verifies whether reviewed biodata intake snapshots have learning-safe human review provenance.

This command is read-only. It does not backfill, apply, promote learning rules, refresh smart routing, call OCR, call Sarvam, or mutate intake/profile/OCR attempt data.

## Why This Exists

Phase 5A learning readiness requires trusted human-reviewed snapshots. Sarvam may be used as a teacher/evidence signal in other workflows, but final truth is the authorized human-reviewed or approved snapshot.

Authorized human actors are:

- `admin`
- `profile_user`
- `suchak`

Learning promotion remains disabled.

## What The Audit Checks

The command scans only `biodata_intakes` rows where `approval_snapshot_json` is present.

Provenance is complete only when all are true:

- `review_actor_type` is `admin`, `profile_user`, or `suchak`
- `reviewed_by_user_id` is present
- `review_surface` is `admin_panel`, `mobile_app`, `website`, or `api`
- `reviewed_at` is present

The report separates:

- `legacy_unknown_provenance`
- `complete_authorized_human_provenance`
- `incomplete_future_review_provenance`
- `system_or_unknown_actor`
- `missing_surface`
- `missing_reviewer_id`

System and unknown actors are not learning-ready.

## Local Commands

```powershell
cd E:\LaravelProjects\laravel-matrimony
php artisan intake:review-provenance-capture-audit --limit=500
php artisan intake:review-provenance-capture-audit --limit=500 --json
php artisan intake:review-provenance-capture-audit --limit=500 --actor=admin
php artisan intake:review-provenance-capture-audit --limit=500 --actor=profile_user
php artisan intake:review-provenance-capture-audit --limit=500 --actor=suchak
php artisan intake:review-provenance-capture-audit --limit=500 --since=2026-07-03
```

## Server Commands

```bash
cd /home/navri/htdocs/navrimilenavryala.com
php artisan intake:review-provenance-capture-audit --limit=500
php artisan intake:review-provenance-capture-audit --limit=500 --json
php artisan intake:review-provenance-capture-audit --limit=500 --since=2026-07-03
```

Related read-only checks:

```bash
php artisan intake:learning-readiness-audit --limit=500
php artisan intake:legacy-provenance-backfill-audit --limit=500
php artisan intake:legacy-provenance-mapping-template --limit=500 --csv
php artisan intake:legacy-provenance-mapping-validate --file=storage/app/intake-legacy-provenance-template.csv
php artisan intake:routing-acceptance-report --limit=500 --fail-on-risk --max-paid-calls=12 --max-skip-calls=0 --max-reuse-previous=0 --max-unknown=20
```

## Interpreting Results

`complete_authorized_human_provenance_count` should increase for new reviews after the provenance capture phase.

Existing legacy rows may remain `legacy_unknown_provenance`. The current known legacy baseline is 11 unknown rows. Those rows should stay untouched until the manual CSV process is completed and separately approved.

If `incomplete_future_review_provenance_count > 0`, fix the review capture path before any learning work. This means a future review was saved without a required actor id, actor type, surface, or timestamp.

If `missing_reviewer_id_count > 0`, `missing_surface_count > 0`, or `system_or_unknown_actor_count > 0`, inspect whether those are expected legacy rows or a broken future review path.

## Safety Status

`pass_when_future_reviews_complete` means the scanned rows do not contain missing provenance blockers.

`not_ready_legacy_or_incomplete_provenance_present` means legacy or incomplete provenance is present. This does not automatically mean data should be backfilled. Legacy rows need manual CSV mapping; incomplete future rows need code-path investigation.

## Recommendations

`legacy_rows_need_manual_mapping_csv; do_not_backfill_automatically` means old reviewed rows still lack reliable actor provenance. Use the manual mapping export/validation workflow; do not create or run an automatic backfill.

`future_review_capture_ok; learning_promotion_still_disabled` means the scanned rows have complete authorized human provenance. Learning promotion is still disabled.

`fix_review_surface_or_actor_capture_before_learning` means at least one future-looking reviewed snapshot is incomplete and the capture path must be fixed before learning.

## Safety Rules

- Do not backfill automatically.
- Do not apply manual mappings from this command.
- Do not mutate `biodata_intakes`.
- Do not mutate `approval_snapshot_json`.
- Do not mutate `parsed_json` or `raw_ocr_text`.
- Do not mutate profiles.
- Do not create or update OCR attempts.
- Do not call OCR or Sarvam.
- Do not enable learning promotion.
- Do not enable live smart routing.
