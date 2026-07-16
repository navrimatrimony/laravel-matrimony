# OCR Product Metrics Dashboard

> Compass only. Goal = RAW OCR fidelity.  
> **Updated:** 2026-07-16 19:35 IST  
> **Artifact:** `product_metrics_gt20_20260716_193354.json`

| Metric | Baseline | Current | Delta |
|--------|--------:|--------:|------:|
| Critical | 42.1% | **92.6%** | **+50.5** |
| DOB | 25% | **95%** | +70 |
| Name | 30% | **80%** | +50 |
| Mobile | 55.6% | **100%** | +44.4 |
| Religion | 47.1% | **94.1%** | +47.0 |
| Gender | 55.0% | **95%** | +40 |

## Research focus

Hard Mode A residuals (name OCR garble, D8 DOB day, PDF religion with no tokens).  
Parser only for clear Mode B. Global multipass demotion rejected.

## Priority

1. Hard Mode A name residual (`snehal`, `1.1`, PDF1/PDF3) against **92.6%** baseline  
2. PDF2 religion Mode A (no religion/caste tokens in raw)  
3. `D(8)` DOB Mode A (OCR day 24 ≠ GT 21)  
