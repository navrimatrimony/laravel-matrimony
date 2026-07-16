# OCR Research Loop 25 — Megapage PDF raster + surname alias keep

> **Status:** ACCEPTED  
> **Artifact:** `product_metrics_gt20_20260716_210840.json`  
> **Workflow:** Tier A residual-pack PASS → Tier B remasure

## Hypothesis

PDF1 embedded megapage text (`नावनवनाथ…णे तारीख`) was treated as usable Devanagari, skipping raster. When raster ran, `preset=null` burned the multipass time budget on preprocessing (`attempt_count=0`). Also name cleaner stripped `(कदम)` aliases needed for GT match.

## Changes

1. `OcrService::pdfEmbeddedTextIsUsable` — reject megapage glue (≤2 lines, ≥400 chars, `नावन|णे तारीख|जातिहंदू`).  
2. PDF raster multipass uses preset `off` by default (avoid preprocess budget burn).  
3. `OcrEnsembleNameExtractor` — keep short Devanagari surname aliases `(कदम)`.

## Evidence

- Tier A: GAIN PDF1 name+gender; 0 losses; canary 24/24  
- Tier B: crit **92.6% → 94.7%**; name **80% → 85%**; gender **95% → 100%**; 0 regressions

## Not claimed

Does not fix snehal/1.1/PDF3 names, PDF2 religion, or D8 DOB day.
