# Phase C.2: Manual Candidate Screening Decision — Implementation Summary

## Goal

Allow admin to manually set/clear a business screening decision for a bulk intake item, without changing `item_status` and without migrations.

## Storage

- No migrations
- Data stored in `bulk_intake_batch_items.item_meta_json.screening_review` only
- No candidate profile fields copied into `item_meta_json`

### screening_review structure

```
status: eligible_for_consent | needs_review | stopped | cleared
reason_key: nullable string
note: nullable string
reviewed_by_user_id
reviewed_at
cleared_by_user_id
cleared_at
```

### Allowed reason_key values

**eligible_for_consent:** corrected_basic_fields, valid_mobile_ready, admin_verified

**needs_review:** missing_mobile, invalid_mobile, dob_unclear, age_issue, gender_unclear, possible_duplicate, unclear_biodata, admin_followup_needed

**stopped:** manual_duplicate, duplicate_existing_profile, already_married, not_interested, wrong_number, blocked_or_complaint, invalid_candidate

## Files changed

1. `app/Services/Intake/BulkIntakeCandidateScreeningReviewService.php` (NEW)
  - validate, save, clear, read `screening_review` metadata
2. `app/Http/Controllers/Admin/AdminBulkIntakeController.php`
  - `saveItemScreeningReview()` and `clearItemScreeningReview()`
  - passes screening review data to bulk list and correction views
3. `routes/web/admin.php`
  - POST `items.save-screening-review`
  - POST `items.clear-screening-review`
4. `resources/views/admin/bulk-intakes/show.blade.php`
  - Manual screening badge overrides advisor badge
  - Advisor reasons still shown as smaller hints
  - Set screening / Clear screening actions
5. `resources/views/admin/bulk-intakes/correct-candidate.blade.php`
  - Manual screening decision card below Screening advisor
  - Form: status, reason_key, note, Save / Clear
6. Tests updated:
  - `tests/Feature/Intake/AdminBulkIntakeCandidateCorrectionTest.php`
  - `tests/Feature/Intake/AdminBulkIntakeCandidateDisplayTest.php`
  - `tests/Feature/Intake/AdminBulkIntakeRoutesTest.php`

## Rules enforced

- Admin only (non-admin blocked)
- Does NOT change `bulk_intake_batch_items.item_status`
- Does NOT create users or profiles
- Does NOT call MutationService or IntakeApprovalService
- Does NOT queue WhatsApp, OCR, or provider calls
- Does NOT mutate `raw_ocr_text`, `parsed_json`, or `approval_snapshot_json`
- No `candidate_screening_status` column
- No migrations

## Validation

- status required: eligible_for_consent, needs_review, stopped
- reason_key nullable, max 100
- note nullable, max 1000
- reason_key required when status = needs_review or stopped
- reason_key optional when status = eligible_for_consent

## UI behavior

### Bulk list

- Shows manual badge: Eligible for consent / Needs review / Stopped
- Manual badge overrides read-only advisor badge
- Advisor reasons still visible as smaller hints
- Set screening (link to correction page) / Clear screening (POST)

### Correction page

- Manual screening decision card below Screening advisor
- Save screening decision / Clear screening buttons

## Tests (all passing)

- admin can set eligible_for_consent, needs_review, stopped screening
- admin can clear screening
- bulk list shows manual screening badge
- manual screening does not change item_status
- manual screening does not mutate raw_ocr_text, parsed_json, approval_snapshot_json
- does not copy candidate fields into item_meta_json
- non-admin cannot set/clear
- read-only advisor still works when no manual screening
- existing manual duplicate tests stay green

## Commands run

```
php artisan optimize:clear
php artisan test --filter=AdminBulkIntakeCandidateCorrectionTest
php artisan test --filter=AdminBulkIntakeCandidateDisplayTest
php artisan test --filter=AdminBulkIntakeRoutesTest
git diff --check
```

All tests passed.