# Phase C.3: Screening Queue Filters — Implementation Summary

## Goal

Bulk Intake Batch page वर screening queue views — admin screening decision नुसार efficiently review करू शकतो.

## Screening source priority

1. Manual screening (C.2) — active असेल तर final
2. Advisor (C.1) — manual नसेल तर

## Filters (`?screening=`)

| Param | Meaning |
|-------|---------|
| `all` (default) | सर्व items |
| `eligible` | Effective status = eligible |
| `needs_review` | Effective status = needs review |
| `stopped` | Effective status = stopped |
| `advisor` | No active manual screening |
| `manual` | Active manual screening |

Combines with existing `?status=` filter.

## Counts

- Status filter लागू असेल → counts त्याच dataset वर
- Status filter नसेल → संपूर्ण batch

## Files changed

1. `app/Services/Intake/BulkIntakeCandidateScreeningQueueService.php` (NEW)
2. `app/Http/Controllers/Admin/AdminBulkIntakeController.php`
3. `resources/views/admin/bulk-intakes/show.blade.php`
4. `tests/Feature/Intake/AdminBulkIntakeCandidateDisplayTest.php`

## No changes

- No migrations, routes, JSON structure
- C.2 manual screening fully preserved
- item_status, parsed_json, approval_snapshot_json, raw_ocr_text untouched

## Verification

```
php artisan optimize:clear
php artisan test --filter=AdminBulkIntakeCandidateCorrectionTest
php artisan test --filter=AdminBulkIntakeCandidateDisplayTest
php artisan test --filter=AdminBulkIntakeRoutesTest
git diff --check
```
