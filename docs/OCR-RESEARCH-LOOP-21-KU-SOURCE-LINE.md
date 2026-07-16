# OCR Research Loop 21 — `कु.` on source line after name strip

> **Status:** ACCEPTED  
> **Artifact:** `product_metrics_gt20_20260716_181938.json`

## Forensic

| File | Field | Mode | Root |
|------|-------|------|------|
| `D (8).jpeg` | gender | **B** | RAW has `कु. प्रतीक्षा`; name cleaner strips `कु.` so Loop 15 extracted-name cue missed |

## Change

If extracted name lacks `कु.` but a non-relation source line still has `कु.` immediately before the extracted given name, infer female.

## Result

| Metric | Before | After |
|--------|-------:|------:|
| Critical | 89.5% | **90.5%** |
| Gender | 80% | **85%** |
| Losses | — | **0** |
| Gains | — | D8 gender |

## Knowledge

Honorific signals must be read from the OCR source line when the cleaner strips them from the structured name value.
