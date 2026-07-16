# OCR STATUS

> **2026-07-16 16:28 IST** · Product Goal **In Progress**

| Item | Value |
|------|------:|
| Artifact | `product_metrics_gt20_20260716_162754.json` |
| Critical | **84.2%** |
| Name | **80%** |
| Mobile | 83.3% |
| Religion | **82.4%** |
| Gender | **80%** |
| DOB | 95% |

## Current Accepted Baseline

- Critical: **84.2%**
- Artifact: `product_metrics_gt20_20260716_162754.json`
- Any future loop must benchmark against this accepted baseline.
- No loop may become production unless it equals or exceeds this baseline.

## Loop 16–17

- Loop 16: OCR `मिस.` + Cast same-line → crit **83.2%**, gender **80%**
- Loop 17: Cast next-line → crit **84.2%**, religion **82.4%**; `28.pdf` religion recovered

## NEXT Loop 18

Mode A RAW: `snehal`, `1.1`, `D(8)`, remaining religion/gender/name. No global multipass demotion.
