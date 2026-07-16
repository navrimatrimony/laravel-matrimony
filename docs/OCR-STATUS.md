# OCR STATUS

> **2026-07-16 17:20 IST** · Product Goal **In Progress**

| Item | Value |
|------|------:|
| Artifact | `product_metrics_gt20_20260716_172006.json` |
| Critical | **86.3%** |
| Name | **80%** |
| Mobile | 83.3% |
| Religion | **94.1%** |
| Gender | **80%** |
| DOB | 95% |

## Current Accepted Baseline

- Critical: **86.3%**
- Artifact: `product_metrics_gt20_20260716_172006.json`
- Any future loop must benchmark against this accepted baseline.
- No loop may become production unless it equals or exceeds this baseline.

## Loop 18

- **Accepted:** Hindu-from-caste + `शश्री` peel  
- Religion **82.4% → 94.1%**; crit **84.2% → 86.3%**; zero losses

## NEXT Loop 19

Mode A RAW: `snehal`, `1.1`, `D(8)` DOB/gender/mobile, PDF name/gender, `10-33-15` gender. No global multipass demotion.
