# OCR Product Metrics Dashboard

> Compass only. Goal = RAW OCR fidelity.  
> **Updated:** 2026-07-16 18:05 IST  
> **Artifact:** `product_metrics_gt20_20260716_180251.json`

| Metric | Baseline | Current | Delta |
|--------|--------:|--------:|------:|
| Critical | 42.1% | **89.5%** | **+47.4** |
| DOB | 25% | **95%** | +70 |
| Name | 30% | **80%** | +50 |
| Mobile | 55.6% | **100%** | +44.4 |
| Religion | 47.1% | **94.1%** | +47.0 |
| Gender | 55.0% | **80%** | +25 |

## Research focus

**RAW OCR** (religion/gender Mode A; OCR-garbled names).  
Parser only for clear Mode B. Global multipass widening **rejected** (crit 68.4%). Loop 13 name-label tie-break **rejected** (crit 73.7%).

## Priority

1. Name / gender / DOB Mode A residual against the **89.5%** accepted baseline  
2. Religion Mode A  
3. Gender Mode A (`snehal`, `10-33-15`, `10-33-22`) without global multipass demotion  
