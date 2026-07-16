# OCR Research Loop 14 — Father-line surname append (Mode B name)

> **Status:** ACCEPTED  
> **Artifact:** `product_metrics_gt20_20260716_142130.json`

## Forensic

| File | Field | Mode | Root |
|------|-------|------|------|
| WhatsApp Image 2025-12-03… | full_name | **B** | RAW has `विशाल पांडुरंग`; surname `डाकवे` on `चंडिलांचे नाव` line |

## Change

When labeled candidate name has **exactly 2** Devanagari tokens (score ≥ 90), append last Devanagari token from `चंडिलांचे/वडिलांचे/पित्याचे नाव` or `Father's Name` when not already present.

## Result

| Metric | Before | After |
|--------|-------:|------:|
| Critical | 80.0% | **81.1%** |
| Name | 75% | **80%** |
| Losses | — | **0** |
| Gains | — | WhatsApp `full_name` |

## Knowledge

Mode B surname recovery from labeled father line is safe when candidate block is only first+middle. Does not fix Mode A RAW garble (`snehal`, `1.1`).
