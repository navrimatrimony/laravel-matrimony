# OCR STATUS

> **2026-07-16 16:00 IST** · Product Goal **In Progress**

| Item | Value |
|------|------:|
| Artifact | `product_metrics_gt20_20260716_155920.json` |
| Critical | **83.2%** |
| Name | **80%** |
| Mobile | 83.3% |
| Religion | 76.5% |
| Gender | **80%** |
| DOB | 95% |

## Current Accepted Baseline

- Critical: **83.2%**
- Artifact: `product_metrics_gt20_20260716_155920.json`
- Any future loop must benchmark against this accepted baseline.
- No loop may become production unless it equals or exceeds this baseline.

## Loop 15

- **Accepted:** extracted-name `कु.` fallback → gender **75%**, crit **82.1%**

## Loop 16

- **Accepted:** OCR `मिस.` female + English `Cast:` Hindu inference  
- Gender **75% → 80%**; crit **82.1% → 83.2%**; `10-33-22` gender recovered

## NEXT Loop 17

Mode A RAW: `snehal`, `1.1`, `D(8)`, religion nulls, PDF name/gender residuals. No global multipass demotion.
