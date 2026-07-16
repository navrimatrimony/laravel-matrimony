# OCR Research Loop 12 — Image-only clean_document multipass

> **Status:** REJECTED (null result)  
> **Artifact:** `product_metrics_gt20_20260716_112834.json`

## Change

Add `clean_document` as an extra multipass preset for image extensions only (no PDF default change).

## Result

| Metric | Before | After |
|--------|-------:|------:|
| Critical | 80% | **80%** |
| All critical fields | identical | **0 flips** |
| PDF DOB | 100% | 100% |

No uplift; extra preprocess cost. Reverted from `variantPresetNames()`.

## Knowledge

Mild additive presets that never win selection are wasted budget under `max_attempts` and do not move RAW fidelity.
