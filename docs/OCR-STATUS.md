# OCR STATUS

> **2026-07-16 21:10 IST** · Product Goal **In Progress**

| Item | Value |
|------|------:|
| Artifact | `product_metrics_gt20_20260716_210840.json` |
| Critical | **94.7%** |
| Name | **85%** |
| Mobile | **100%** |
| Religion | **94.1%** |
| Gender | **100%** |
| DOB | 95% |

## Current Accepted Baseline

- Critical: **94.7%**
- Artifact: `product_metrics_gt20_20260716_210840.json`
- Any future loop must benchmark against this accepted baseline.
- No loop may become production unless it equals or exceeds this baseline.

## Loop 25

- **Accepted:** megapage PDF → raster + PDF multipass `off` + keep `(कदम)` alias  
- Crit **92.6% → 94.7%**; name **85%**; gender **100%**; PDF1 recovered  

## NEXT Loop 26

Hard Mode A: `snehal`/`1.1` names, PDF3 `चि`≠`शि`, PDF2 religion, D8 DOB.
