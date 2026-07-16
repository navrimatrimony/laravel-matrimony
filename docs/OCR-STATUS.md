# OCR STATUS

> **2026-07-16 18:05 IST** · Product Goal **In Progress**

| Item | Value |
|------|------:|
| Artifact | `product_metrics_gt20_20260716_180251.json` |
| Critical | **89.5%** |
| Name | **80%** |
| Mobile | **100%** |
| Religion | **94.1%** |
| Gender | **80%** |
| DOB | 95% |

## Current Accepted Baseline

- Critical: **89.5%**
- Artifact: `product_metrics_gt20_20260716_180251.json`
- Any future loop must benchmark against this accepted baseline.
- No loop may become production unless it equals or exceeds this baseline.

## Loop 20

- **Accepted:** orphan-sticker penalty + trailing `(mobile)` preference  
- Crit **88.4% → 89.5%**; D8 mobile recovered; mobile field **100%** on GT-20

## NEXT Loop 21

Hard Mode A: `snehal`/`1.1` names, PDF name OCR, `D(8)` DOB/gender, `10-33-15`/`snehal` gender, PDF2 religion.
