# OCR Research Loop 24 — Name-band crop OCR (Mode A)

> **Status:** REJECTED (production wiring)  
> **Baseline held:** `product_metrics_gt20_20260716_193354.json` @ **92.6%**  
> **Workflow:** Tier A residual-pack with `--refresh-cache`

## Hypothesis

Full-page multipass garbles candidate names that a **top ~25% image band** can read (`स्नेहल`, `अनिल`, `प्रकाश` on PDF1 raster).

## Offline probe (accepted as evidence)

`tools/ocr-loop24-name-band-probe.php` — band OCR recovers truth needles absent from production full-page text on `snehal.jpeg`, `1.1.jpeg`, and PDF1 raster.

## Production attempts (rejected)

1. **Always prepend full band text** — Tier A: D8 name/gender loss; D1 canary name loss.  
2. **Gated + label-only band lines** — 0 miss gains; later refresh run collapsed PDF canaries (28/27 null fields) under extra OCR cost/noise.  

Code reverted to `tesseract_multipass_v1` (no name-band merge).

## Remaining residuals

All hard Mode A / matcher-threshold gaps: PDF1 name+gender, PDF2 religion, PDF3 `चि`≠`शि`, D8 DOB day 24≠21, snehal/1.1 name OCR.

## Next

Do not ship name-band merge until an additive variant can prove **gains > 0 and losses = 0** on residual-pack canaries (including PDFs) without emptying multipass winners.
