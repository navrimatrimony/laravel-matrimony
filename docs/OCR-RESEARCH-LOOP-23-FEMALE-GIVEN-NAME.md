# OCR Research Loop 23 — Strong female given-name gender

> **Status:** ACCEPTED  
> **Artifact:** `product_metrics_gt20_20260716_193354.json`  
> **Workflow:** Tier A residual-pack PASS → Tier B remasure

## Hypothesis (Mode B)

`photo_2026-06-05_10-33-15.jpg` already extracts candidate name `रेखा शिवदास पाटील` but gender stayed null — no honorific/section cue. A conservative allowlist of high-confidence female Marathi given names (exact first token) recovers gender without inventing missing OCR.

## Change

`OcrEnsembleGenderExtractor::fromStrongFemaleGivenName()` after source-line `कु.` recovery.

## Evidence

- Unit: strong female given name recovers `रेखा`; male given name stays null  
- Tier A: GAIN gender on `10-33-15`; 0 losses; canary 24/24  
- Tier B: crit **91.6% → 92.6%**; gender **90% → 95%**; 0 regressions vs `191007`

## Not claimed

Does not fix Mode A name/DOB/religion residuals (PDF1/2/3, snehal, 1.1, D8).
