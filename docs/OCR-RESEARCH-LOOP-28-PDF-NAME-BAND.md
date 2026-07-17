# OCR Research Loop 28 — PDF embedded name-band (शिवाजी)

> **Status:** ACCEPTED  
> **Artifact:** `product_metrics_gt20_20260717_101021.json`

## Hypothesis

PDF3 embedded text has `चि. चिवाजी` (wrong); page-0 top-band OCR reads `शिवाजी`. Embedded path skipped raster, so image-only name-band never ran.

## Change

When PDF embedded text is usable, still raster page-0 top band and prepend `मुलाचे`/`मुलीचे` label lines (additive).

## Evidence

- Tier A: GAIN PDF3 name; 0 losses; canary 23/23  
- Tier B: crit **97.9% → 98.9%**; name **95% → 100%**; 0 regressions

## Remaining

`D (8).jpeg` DOB — OCR day 24 ≠ GT 21 (invent forbidden; prior date-band approaches rejected).
