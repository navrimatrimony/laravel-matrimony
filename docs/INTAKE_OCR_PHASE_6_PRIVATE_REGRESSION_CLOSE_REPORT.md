# Intake OCR Phase 6 Private Regression Close Report

## Phase scope completed

Phase 6 closed the current private OCR regression hardening pass for deterministic parser safety and regression protection.

Completed scope:

- Deterministic parser hardening was applied only where a safe parser rule was justified.
- The occupation improvement from Phase 6H was locked by a stricter threshold guard in Phase 6I.
- The private golden dataset remained local-only and was not committed.
- Learning stayed disabled.
- No Sarvam, OCR provider, paid vision, database mutation, profile apply, backfill, or live routing behavior was enabled by this close report.

## Safety rules preserved

This report is safe for GitHub. It intentionally excludes:

- Raw OCR text
- Names
- Phone numbers
- Full addresses
- Real private case IDs
- Raw expected values
- Raw actual parsed values
- Private golden dataset contents

Only aggregate counts, percentages, command references, and phase-level recommendations are included.

## Completed commits summary

| Commit | Summary | Safety note |
| --- | --- | --- |
| `fe370ff4` | Improved deterministic occupation parsing. | Parser-only hardening, no learning or provider calls. |
| `dd2d1d74` | Updated OCR regression thresholds after occupation hardening. | Runbook threshold guard update only. |

## Accuracy improvements summary

| Field | Baseline | Current locked state | Result |
| --- | ---: | ---: | --- |
| height | 0% | 94.74% | Improved |
| document_contact_number | 44.44% | 100% | Improved |
| occupation | 0% | 66.67% | Improved |
| religion | 61.11% | 83.33% | Improved |
| sub_caste | 56.25% | 100% | Improved |
| caste | 100% | 100% | Maintained |
| education | 65% | 80% | Improved |
| address | 72.22% | 77.78% | Improved |
| overall | Not locked here | 88.46% | Current locked state |

## Current locked threshold guard command

```powershell
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl --fail-under=89 --fail-under-field=full_name:85 --fail-under-field=date_of_birth:95 --fail-under-field=height:94 --fail-under-field=education:80 --fail-under-field=occupation:66 --fail-under-field=document_contact_number:100 --fail-under-field=address:88 --fail-under-field=religion:83 --fail-under-field=caste:100 --fail-under-field=sub_caste:100
```

## Current field accuracy table

| Field | Expected | Exact | Mismatches | Missing actual | Accuracy |
| --- | ---: | ---: | ---: | ---: | ---: |
| full_name | 20 | 17 | 3 | 0 | 85% |
| date_of_birth | 20 | 19 | 0 | 1 | 95% |
| height | 19 | 18 | 0 | 1 | 94.74% |
| education | 20 | 16 | 0 | 4 | 80% |
| occupation | 15 | 10 | 4 | 1 | 66.67% |
| primary_contact_number | 0 | 0 | 0 | 0 | Not scored |
| document_contact_number | 18 | 18 | 0 | 0 | 100% |
| address | 18 | 14 | 4 | 0 | 77.78% |
| religion | 18 | 15 | 0 | 3 | 83.33% |
| caste | 18 | 18 | 0 | 0 | 100% |
| sub_caste | 16 | 16 | 0 | 0 | 100% |

Overall current locked accuracy: 88.46%.

## Remaining weak fields

| Field | Accuracy | Mismatches | Missing actual | Notes |
| --- | ---: | ---: | ---: | --- |
| address | 77.78% | 4 | 0 | Best next target because remaining failures are mismatches and address quality affects profile quality. |
| education | 80% | 0 | 4 | Mostly missing-actual issues; likely needs extraction-source audit later. |
| full_name | 85% | 3 | 0 | Remaining failures are mismatches; not the recommended immediate next target. |
| religion | 83.33% | 0 | 3 | Mostly missing-actual issues; likely needs extraction-source audit later. |
| occupation | 66.67% | 4 | 1 | Improved in Phase 6H but still has 5 aggregate failures. |

## Learning status

Learning remains disabled.

Latest audit summary:

- Recommendation: `keep_learning_disabled`
- Safety status: `blocked_no_learning_candidates`
- Eligible learning source rows: 0
- Blocked rows: 10

No learning promotion was enabled and no learning rules were created.

## Private dataset safety confirmation

The private golden dataset remains local-only.

Safety confirmations:

- The report does not copy dataset rows or private field values.
- The report uses only aggregate counts and percentages.
- `storage/app/intake-golden-datasets` is not tracked by Git.
- The locked regression command is read-only and does not mutate intakes, profiles, parser input text, learning state, or routing behavior.

## Next recommended phase

Recommended next phase:

**Phase 6K - Address failure micro-audit, read-only.**

Reason:

Address is the best immediate target because it has 4 mismatches, 0 missing actual, and affects full profile quality. Education and religion are mostly missing-actual issues and may need extraction-source audit later. Occupation improved in Phase 6H but still has 5 aggregate failures; the immediate next safe target should be address because it has 4 mismatches and the current locked threshold is only 77.

Phase 6K should be audit-only at the start. It should not implement parser changes until the failure categories are understood without printing raw OCR text, names, phones, full addresses, real private case IDs, raw expected values, or raw actual parsed values.
