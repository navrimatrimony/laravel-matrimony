# Intake OCR Phase 6 Private Regression Close Report

## Final phase status

Phase 6 deterministic OCR parser hardening cycle is closed.

Final stop point:

- Final overall regression accuracy: 91.21%.
- Current locked overall guard: 91%.
- Current locked education guard: 95%.
- Current locked address guard: 88%.
- The remaining failures are explicitly deferred, not ignored.
- Future accuracy work, if required, should start as **Phase 7A - Occupation/religion strategy audit, read-only**.

This close report documents the final post-Phase 6R/6S state. It does not enable parser behavior changes, learning, live routing, provider calls, profile apply, backfill, or database mutation.

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

Only aggregate counts, percentages, command references, field names, commit references, safety status, and phase-level recommendations are included.

## Completed commits summary

| Commit | Summary | Safety note |
| --- | --- | --- |
| `fe370ff4` | Improve deterministic occupation parsing | Parser-only hardening, no learning or provider calls. |
| `dd2d1d74` | Update OCR regression thresholds after occupation hardening | Threshold guard update only. |
| `06384fa6` | Improve deterministic address normalization | Parser-only hardening, no provider calls or profile mutation. |
| `a393744c` | Update OCR regression thresholds after address hardening | Threshold guard update only. |
| `40d9b537` | Improve deterministic education validation | Parser-only hardening, no learning or provider calls. |
| `c3137d86` | Update OCR regression thresholds after education hardening | Threshold guard update only. |

## Accuracy improvements summary

| Field | Baseline | Final state | Result |
| --- | ---: | ---: | --- |
| height | 0% | 94.74% | Improved |
| document_contact_number | 44.44% | 100% | Improved |
| occupation | 0% | 66.67% | Improved |
| religion | 61.11% | 83.33% | Improved |
| sub_caste | 56.25% | 100% | Improved |
| caste | 100% | 100% | Maintained |
| education | 65% | 95% | Improved |
| address | 72.22% | 88.89% | Improved |
| overall | Not locked here | 91.21% | Final locked state |

## Current locked threshold guard command

```powershell
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl --fail-under=91 --fail-under-field=full_name:85 --fail-under-field=date_of_birth:95 --fail-under-field=height:94 --fail-under-field=education:95 --fail-under-field=occupation:66 --fail-under-field=document_contact_number:100 --fail-under-field=address:88 --fail-under-field=religion:83 --fail-under-field=caste:100 --fail-under-field=sub_caste:100
```

## Final accuracy table

| Field | Accuracy | Final threshold | Status |
| --- | ---: | ---: | --- |
| overall | 91.21% | 91 | pass |
| full_name | 85% | 85 | pass |
| date_of_birth | 95% | 95 | pass |
| height | 94.74% | 94 | pass |
| education | 95% | 95 | pass |
| occupation | 66.67% | 66 | pass |
| document_contact_number | 100% | 100 | pass |
| address | 88.89% | 88 | pass |
| religion | 83.33% | 83 | pass |
| caste | 100% | 100 | pass |
| sub_caste | 100% | 100 | pass |

## Final field detail

| Field | Expected | Exact | Mismatches | Missing actual | Accuracy |
| --- | ---: | ---: | ---: | ---: | ---: |
| full_name | 20 | 17 | 3 | 0 | 85% |
| date_of_birth | 20 | 19 | 0 | 1 | 95% |
| height | 19 | 18 | 0 | 1 | 94.74% |
| education | 20 | 19 | 0 | 1 | 95% |
| occupation | 15 | 10 | 4 | 1 | 66.67% |
| primary_contact_number | 0 | 0 | 0 | 0 | Not scored |
| document_contact_number | 18 | 18 | 0 | 0 | 100% |
| address | 18 | 16 | 2 | 0 | 88.89% |
| religion | 18 | 15 | 0 | 3 | 83.33% |
| caste | 18 | 18 | 0 | 0 | 100% |
| sub_caste | 16 | 16 | 0 | 0 | 100% |

Overall final locked accuracy: 91.21%.

## Deferred work

The remaining failures are deferred because the current cycle has reached a strong locked checkpoint and no additional shared low-risk hardening pattern was proven safely.

| Deferred area | Reason |
| --- | --- |
| Occupation strategy audit | Deferred to future Phase 7A, read-only first. It has the largest remaining failure count but the highest parser/source ambiguity risk. |
| Religion source-label/community mapping audit | Deferred. Remaining failures are missing-actual issues and may involve mapping or source-label ambiguity. |
| Full name sensitive mismatch audit | Deferred. This is a sensitive field and should not be hardened without a separate micro-audit. |
| Address source-label ambiguity review | Deferred. Remaining failures look like source selection or label ambiguity rather than a clearly shared normalization rule. |
| Single residuals for date_of_birth, height, and education | Deferred because yield is low and no shared safe parser pattern is established. |

## Learning status

Learning remains disabled.

Latest audit summary:

- Recommendation: `keep_learning_disabled`
- Safety status: `blocked_no_learning_candidates`
- Eligible learning source rows: 0
- Blocked rows: 10

No learning promotion was enabled and no learning rules were created.

## Private dataset safety confirmation

The private golden dataset remains local-only and untracked.

Safety confirmations:

- The report does not copy dataset rows or private field values.
- The report uses only aggregate counts and percentages.
- `storage/app/intake-golden-datasets` is not tracked by Git.
- The locked regression command is read-only and does not mutate intakes, profiles, parser input text, learning state, or routing behavior.

## Provider and mutation safety confirmation

No provider calls, Sarvam calls, database mutation, profile apply, profile backfill, live routing change, learning enablement, or learning promotion were enabled in this close state.

## Stop-point decision

Decision: close the current Phase 6 deterministic OCR parser hardening cycle at 91.21%.

Rationale:

- The current locked threshold guard passes at 91.21% against the overall threshold of 91%.
- Education is now protected at 95%.
- Address is now protected at 88%.
- Document contact, caste, and sub-caste are fully protected at 100%.
- Remaining failures are concentrated in fields that need read-only strategy audit before any further parser changes.

If additional accuracy is required later, start a new cycle as **Phase 7A - Occupation/religion strategy audit, read-only**.
