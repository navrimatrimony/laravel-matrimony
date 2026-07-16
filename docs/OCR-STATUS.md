# OCR STATUS

> **2026-07-16 18:20 IST** · Product Goal **In Progress**

| Item | Value |
|------|------:|
| Artifact | `product_metrics_gt20_20260716_181938.json` |
| Critical | **90.5%** |
| Name | **80%** |
| Mobile | **100%** |
| Religion | **94.1%** |
| Gender | **85%** |
| DOB | 95% |

## Current Accepted Baseline

- Critical: **90.5%**
- Artifact: `product_metrics_gt20_20260716_181938.json`
- Any future loop must benchmark against this accepted baseline.
- No loop may become production unless it equals or exceeds this baseline.

## Loop 21

- **Accepted:** source-line `कु.` after name strip  
- Crit **89.5% → 90.5%**; gender **80% → 85%**; D8 gender recovered

## NEXT Loop 22

Hard Mode A residuals (9 fields): PDF name/gender/religion, `snehal`/`1.1` names, `D(8)` DOB, `10-33-15`/`snehal` gender.
