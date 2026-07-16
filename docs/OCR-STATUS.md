# OCR STATUS

> **2026-07-16 13:50 IST** · Product Goal **In Progress**

| Item | Value |
|------|------:|
| Artifact | `product_metrics_gt20_20260716_111153.json` |
| Critical | **80.0%** |
| Name | **75%** |
| Mobile | 83.3% |
| Religion | 76.5% |
| Gender | 70% |
| DOB | 95% |

## Loop 12

- **Rejected:** image-only `clean_document` multipass — **0 uplift**, crit **80%** (`112834`)

## Loop 13

- **Rejected:** multipass `v2_name_signal` — crit **80% → 73.7%** on remeasure (`134838`); code reverted  
- **Kept:** probe tools + knowledge (snehal gender gain; global demotion on `27.pdf`, `10-33-15`, etc.)

## NEXT Loop 14

Name / religion Mode A residual on GT-20 @ 80% baseline. Avoid global multipass tie-break; prefer cohort-safe or extractor paths where RAW already contains truth.
