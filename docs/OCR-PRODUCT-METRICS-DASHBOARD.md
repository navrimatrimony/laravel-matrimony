# OCR Product Metrics Dashboard

> Compass only. Goal = RAW OCR fidelity.  
> **Updated:** 2026-07-16 19:12 IST  
> **Artifact:** `product_metrics_gt20_20260716_191007.json`

| Metric | Baseline | Current | Delta |
|--------|--------:|--------:|------:|
| Critical | 42.1% | **91.6%** | **+49.5** |
| DOB | 25% | **95%** | +70 |
| Name | 30% | **80%** | +50 |
| Mobile | 55.6% | **100%** | +44.4 |
| Religion | 47.1% | **94.1%** | +47.0 |
| Gender | 55.0% | **90%** | +35 |

## Research focus

Hard Mode A residuals (name OCR garble, D8 DOB, remaining gender/religion).  
Parser only for clear Mode B. Global multipass demotion rejected.

## Priority

1. Hard Mode A name/DOB residual against the **91.6%** accepted baseline  
2. `10-33-15` gender (no strong cue in raw)  
3. PDF religion Mode A (no religion tokens)  
