# OCR Product Metrics Dashboard

> Compass only. Goal = RAW OCR fidelity.  
> **Project state: RESEARCH HOLD** (2026-07-17) — **not** Complete  
> **Updated:** 2026-07-17  
> **Artifact:** `storage/app/private/ocr-ensemble-benchmark/product_metrics_gt20_20260717_101021.json` (Tesseract SSOT · **accepted**)

| Metric | Baseline | Current | Delta |
|--------|--------:|--------:|------:|
| Critical | 42.1% | **98.9%** | **+56.8** |
| DOB | 25% | **95%** | +70 |
| Name | 30% | **100%** | +70 |
| Mobile | 55.6% | **100%** | +44.4 |
| Religion | 47.1% | **100%** | +52.9 |
| Gender | 55.0% | **100%** | +45 |

## Research Hold

| Item | Value |
|------|-------|
| State | **RESEARCH HOLD** |
| Why | Strategic priority → Flutter Matchmaker APK |
| Product Complete? | **No** |
| Last OCR research loop | Loop 31 (watermark + Sarvam DI research-only) |
| Admin PO Visibility (§20.6) | Complete on Correct Candidate |

## Unresolved (compass)

- 1 Mode A residual: `D (8).jpeg` DOB (Tesseract **24** ≠ GT **21**)  
- Sarvam DI can recover in research; **not** production-integrated  
- Volume fidelity (≥500 biodatas) not yet measured  

## Priority when OCR resumes

1. Re-read `docs/OCR-STATUS.md` resume command  
2. Large-dataset benchmarking (target ≥ **500**)  
3. Revisit Sarvam only after volume evidence (need / placement / cost)  

**No OCR research loops while RESEARCH HOLD is active.**
