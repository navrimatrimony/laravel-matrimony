# Intake Legacy Provenance Manual CSV Runbook

## Purpose

This runbook covers the manual CSV process for legacy reviewed biodata intake snapshots that are missing review actor provenance.

The process is read-only until a separately approved future apply step exists. The current validator does not update the database, does not backfill provenance, and does not enable learning promotion.

## Current Legacy State

The known Phase 5A.2 server baseline has 11 reviewed snapshots reported as `legacy_unknown_provenance`.

That is expected because these rows were reviewed before future review flows captured:

- `reviewed_by_user_id`
- `review_actor_type`
- `review_surface`
- `reviewed_at`

These legacy rows must remain untouched until an authorized manual mapping process is completed and separately approved.

## Why Automatic Backfill Is Not Allowed

Automatic backfill is not allowed because the legacy audit did not find high-confidence safe provenance evidence for these rows.

Do not guess the actor. Do not infer final truth from weak timestamp, uploader, parser, OCR, or Sarvam evidence. Sarvam is only a teacher/evidence signal; final truth is the authorized human-reviewed or approved snapshot.

Authorized human actor types:

- `admin`
- `profile_user`
- `suchak`

## Generate The CSV Template

Local:

```powershell
cd E:\LaravelProjects\laravel-matrimony
php artisan intake:legacy-provenance-mapping-template --limit=500 --csv --output=storage/app/intake-legacy-provenance-template.csv
```

Server:

```bash
cd /home/navri/htdocs/navrimilenavryala.com
php artisan intake:legacy-provenance-mapping-template --limit=500 --csv --output=storage/app/intake-legacy-provenance-template.csv
```

The template contains safe IDs and evidence labels only. It must not include raw OCR text, full phone numbers, candidate names, full addresses, provider payloads, secrets, or hashes.

## Manual Fields To Fill

Only fill manual fields after checking authorized review records outside this command.

Allowed `reviewer_decision` values:

- `approve`
- `skip`
- `needs_more_evidence`

The validator also accepts the older `approve_manual_mapping` value for compatibility with existing CSVs.

Required fields when `reviewer_decision=approve`:

- `manual_actor_id`
- `manual_actor_type`
- `manual_surface`
- `reviewer_decision`
- `manual_notes`

Allowed `manual_actor_type` values:

- `admin`
- `profile_user`
- `suchak`

Allowed `manual_surface` values:

- `admin_panel`
- `mobile_app`
- `website`
- `api`

Use `manual_notes` to record the safe reason for the manual decision. Do not include personal data, phone numbers, candidate names, raw OCR text, addresses, provider payloads, secrets, or hashes.

## Risk Rules

If evidence is weak, use `needs_more_evidence`.

If the actor cannot be confidently identified, use `needs_more_evidence` or `skip`.

Do not approve blank rows.

Do not approve uncertain rows.

Do not guess actor type, actor id, or review surface.

Do not approve based only on uploader, `updated_at`, OCR attempts, parser output, Sarvam output, or routing recommendations.

## Validate The CSV

The validator is read-only. It checks the CSV against current database state and reports safe row-level risks.

Default local validation:

```powershell
cd E:\LaravelProjects\laravel-matrimony
php artisan intake:legacy-provenance-mapping-validate
```

When `--file` is omitted, the validator tries:

```text
storage/app/intake-legacy-provenance-template.csv
```

Explicit local validation:

```powershell
php artisan intake:legacy-provenance-mapping-validate --file=storage/app/intake-legacy-provenance-template.csv
php artisan intake:legacy-provenance-mapping-validate --file=storage/app/intake-legacy-provenance-template.csv --json
php artisan intake:legacy-provenance-mapping-validate --file=storage/app/intake-legacy-provenance-template.csv --fail-on-risk
```

Server validation:

```bash
cd /home/navri/htdocs/navrimilenavryala.com
php artisan intake:legacy-provenance-mapping-validate
php artisan intake:legacy-provenance-mapping-validate --file=storage/app/intake-legacy-provenance-template.csv
php artisan intake:legacy-provenance-mapping-validate --file=storage/app/intake-legacy-provenance-template.csv --fail-on-risk
```

If the default file is missing, generate the template first:

```bash
php artisan intake:legacy-provenance-mapping-template
```

Then validate explicitly:

```bash
php artisan intake:legacy-provenance-mapping-validate --file=storage/app/intake-legacy-provenance-template.csv
```

## Interpreting Validation

`validation_status=pass` means the CSV is internally valid and matches the current reviewed snapshot state.

`validation_status=fail` means the CSV must be fixed before any future apply proposal is considered.

`future_apply_candidate_count` is only a count of rows that could be reviewed for a future apply process. It does not apply anything.

Validation success does not update `biodata_intakes`, `approval_snapshot_json`, `parsed_json`, `raw_ocr_text`, profiles, OCR attempts, quality fields, routing fields, or review snapshots.

## Learning Safety

Learning promotion remains disabled after validation.

Run the capture audit after validation to confirm the current database state is unchanged:

```bash
php artisan intake:review-provenance-capture-audit --limit=500
php artisan intake:learning-readiness-audit --limit=500
```

Legacy unknown rows may remain unknown until a separately approved manual apply/backfill mechanism exists. Do not create or run an apply command as part of this process.
