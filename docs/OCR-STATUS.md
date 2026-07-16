# OCR STATUS

> **2026-07-16 15:20 IST** · Product Goal **In Progress**

| Item | Value |
|------|------:|
| Artifact | `product_metrics_gt20_20260716_151836.json` |
| Critical | **82.1%** |
| Name | **80%** |
| Mobile | 83.3% |
| Religion | 76.5% |
| Gender | **75%** |
| DOB | 95% |

## Current Accepted Baseline

- Critical: **82.1%**
- Artifact: `product_metrics_gt20_20260716_151836.json`
- Any future loop must benchmark against this accepted baseline.
- No loop may become production unless it equals or exceeds this baseline.

## Loop 12–13 (recovery)

- Loop 12 `clean_document` — **rejected** (0 uplift)  
- Loop 13 multipass `v2_name_signal` — **rejected** (73.7% remeasure); code reverted

## Loop 14

- **Accepted:** father-line surname append for 2-token candidate names  
- Name **75% → 80%**; crit **80% → 81.1%**; WhatsApp `full_name` recovered

## Loop 15

- **Accepted:** extracted-name `कु.` fallback when direct gender cues and fallback are absent  
- Gender **70% → 75%**; crit **81.1% → 82.1%**; `1.jpeg` gender recovered

## NEXT Loop 16

Mode A RAW: `snehal` name/gender, religion nulls, `1.1` garble. No global multipass demotion.
