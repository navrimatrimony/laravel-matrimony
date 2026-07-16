# OCR STATUS

> **2026-07-16 20:05 IST** · Product Goal **In Progress**

| Item | Value |
|------|------:|
| Artifact | `product_metrics_gt20_20260716_193354.json` |
| Critical | **92.6%** |
| Name | **80%** |
| Mobile | **100%** |
| Religion | **94.1%** |
| Gender | **95%** |
| DOB | 95% |

## Current Accepted Baseline

- Critical: **92.6%**
- Artifact: `product_metrics_gt20_20260716_193354.json`
- Any future loop must benchmark against this accepted baseline.
- No loop may become production unless it equals or exceeds this baseline.

## Loop 24

- **Rejected:** production name-band merge (Tier A regressions)  
- Offline probe kept as research evidence (`tools/ocr-loop24-name-band-probe.php`)  
- Baseline unchanged at **92.6%**

## NEXT Loop 25

Hard Mode A residuals only; safer additive OCR variants must pass Tier A canaries (incl. PDFs) before Tier B.
