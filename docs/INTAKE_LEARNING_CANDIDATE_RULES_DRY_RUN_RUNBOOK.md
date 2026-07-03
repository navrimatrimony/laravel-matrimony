# Intake Learning Candidate Rules Dry-Run Runbook

## Purpose

`intake:learning-candidate-rules-audit` inspects reviewed biodata intake snapshots and reports whether any field-level learning candidate rules could be considered in the future.

This command is read-only and dry-run only.

It does not:

- create active learning rules
- promote any learning rule
- backfill review provenance
- apply manual CSV mappings
- mutate `biodata_intakes`
- mutate `approval_snapshot_json`, `parsed_json`, `raw_ocr_text`, routing JSON, quality JSON, field confidence JSON, profiles, or OCR attempts
- call OCR, Sarvam, or paid vision providers
- enable live smart routing

## Safety Principles

Sarvam is a teacher/evidence signal only. It is not final truth.

Final truth is an authorized human-reviewed or approved snapshot.

Authorized human actors:

- `admin`
- `profile_user`
- `suchak`

Learning promotion remains disabled after this audit.

## Current Expected Server Result

The current Phase 5A/5B server baseline has 11 reviewed snapshots with `legacy_unknown_provenance`.

Those rows are expected to be excluded as learning sources. The expected current result is:

- zero eligible learning source rows
- blocked/no candidate safety status
- legacy/unknown provenance blockers present

This is expected until the manual legacy provenance CSV process is completed and separately approved.

## What Counts As A Future Learning Source

A reviewed intake row can be counted only when all are true:

- `approval_snapshot_json` exists
- `review_actor_type` is `admin`, `profile_user`, or `suchak`
- `reviewed_by_user_id` is present
- `review_surface` is `admin_panel`, `mobile_app`, `website`, or `api`
- `reviewed_at` is present
- the approved snapshot has the audited field
- the field value is not blank or a placeholder
- provider candidate risk is false
- duplicate/manual conflict risk is false

Legacy unknown rows, system/unknown actors, missing reviewer ids, missing review surfaces, missing review timestamps, provider candidates, duplicate/manual conflict risks, and blank fields are excluded.

## Fields Audited

- `full_name`
- `date_of_birth`
- `height`
- `education`
- `occupation`
- `primary_contact_number`
- `address`
- `religion`
- `caste`

## Local Commands

```powershell
cd E:\LaravelProjects\laravel-matrimony
php artisan intake:learning-candidate-rules-audit --limit=500
php artisan intake:learning-candidate-rules-audit --limit=500 --json
php artisan intake:learning-candidate-rules-audit --field=full_name --limit=500
php artisan intake:learning-candidate-rules-audit --actor=admin --limit=500
php artisan intake:learning-candidate-rules-audit --min-samples=10 --limit=500
php artisan intake:learning-candidate-rules-audit --since=2026-07-03 --limit=500
```

## Server Commands

```bash
cd /home/navri/htdocs/navrimilenavryala.com
php artisan intake:learning-candidate-rules-audit --limit=500
php artisan intake:learning-candidate-rules-audit --limit=500 --json
php artisan intake:learning-candidate-rules-audit --field=full_name --limit=500
php artisan intake:learning-candidate-rules-audit --actor=admin --limit=500
php artisan intake:learning-candidate-rules-audit --min-samples=10 --limit=500
```

Related safety checks:

```bash
php artisan intake:review-provenance-capture-audit --limit=500
php artisan intake:learning-readiness-audit --limit=500
php artisan intake:routing-acceptance-report --limit=500 --fail-on-risk --max-paid-calls=12 --max-skip-calls=0 --max-reuse-previous=0 --max-unknown=20
```

## Interpreting Output

`eligible_learning_source_rows` counts reviewed rows with at least one eligible field sample.

`field_candidate_summary` shows per-field sample counts, actor mix, surface mix, low-confidence corrected count, conflict risk count, provider candidate count, min-sample status, candidate status, and recommendation.

Candidate statuses:

- `blocked_no_authorized_samples`
- `blocked_min_samples_not_met`
- `dry_run_candidate_only`

Recommendations:

- `collect_more_authorized_reviews`
- `keep_learning_disabled`
- `future_candidate_requires_admin_approval`

`dry_run_candidate_only` does not mean a rule is ready for production. It only means enough dry-run samples exist for a future proposal.

## Future Promotion Requirements

Any future learning promotion must be a separate approved phase.

Before promotion, require:

- separate human approval
- minimum sample thresholds
- low conflict rate
- sanity checks on field values
- actor trust policy
- admin approval if required
- regression analysis
- explicit production safety review

Do not create or enable rule promotion from this audit command.
