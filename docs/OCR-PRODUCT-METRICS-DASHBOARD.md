# OCR Product Metrics Dashboard

> Compass only. Goal = RAW OCR fidelity.  
> **Updated:** 2026-07-16 16:28 IST  
> **Artifact:** `product_metrics_gt20_20260716_162754.json`

| Metric | Baseline | Current | Delta |
|--------|--------:|--------:|------:|
| Critical | 42.1% | **84.2%** | **+42.1** |
| DOB | 25% | **95%** | +70 |
| Name | 30% | **80%** | +50 |
| Mobile | 55.6% | **83.3%** | +27.7 |
| Religion | 47.1% | **82.4%** | +35.3 |
| Gender | 55.0% | **80%** | +25 |

## Research focus

**RAW OCR** (religion/gender Mode A; OCR-garbled names).  
Parser only for clear Mode B. Global multipass widening **rejected** (crit 68.4%). Loop 13 name-label tie-break **rejected** (crit 73.7%).

## Priority

1. Name / gender / religion Mode A residual against the **84.2%** accepted baseline  
2. Religion Mode A  
3. Gender Mode A (`snehal`, `10-33-15`, `10-33-22`) without global multipass demotion  
