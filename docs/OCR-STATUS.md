# OCR STATUS

> **2026-07-16 14:22 IST** · Product Goal **In Progress**

| Item | Value |
|------|------:|
| Artifact | `product_metrics_gt20_20260716_142130.json` |
| Critical | **81.1%** |
| Name | **80%** |
| Mobile | 83.3% |
| Religion | 76.5% |
| Gender | 70% |
| DOB | 95% |

## Loop 12–13 (recovery)

- Loop 12 `clean_document` — **rejected** (0 uplift)  
- Loop 13 multipass `v2_name_signal` — **rejected** (73.7% remeasure); code reverted

## Loop 14

- **Accepted:** father-line surname append for 2-token candidate names  
- Name **75% → 80%**; crit **80% → 81.1%**; WhatsApp `full_name` recovered

## NEXT Loop 15

Mode A RAW: `snehal` name/gender, religion nulls, `1.1` garble. No global multipass demotion.
