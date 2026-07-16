# OCR Product Metrics Dashboard

> Compass only. Goal = RAW OCR fidelity.  
> **Updated:** 2026-07-16 11:12 IST  
> **Artifact:** `product_metrics_gt20_20260716_111153.json`

| Metric | Baseline | Current | Delta |
|--------|--------:|--------:|------:|
| Critical | 42.1% | **80.0%** | **+37.9** |
| DOB | 25% | **95%** | +70 |
| Name | 30% | **75%** | +45 |
| Mobile | 55.6% | **83.3%** | +27.7 |
| Religion | 47.1% | **76.5%** | +29.4 |
| Gender | 55.0% | **70%** | +15 |

## Research focus

**RAW OCR** (religion/gender Mode A; OCR-garbled names).  
Parser only for clear Mode B. Global multipass widening **rejected** (crit 68.4% regression).

## Priority

1. Image-gated RAW OCR (safe multipass / preprocess)  
2. Name Mode A OCR garble  
3. Gender / religion Mode A  
