# OCR Research Loop 26 — Image-only gated name-band

> **Status:** ACCEPTED  
> **Artifact:** `product_metrics_gt20_20260717_090918.json`  
> **Workflow:** Tier A residual-pack PASS → Tier B remasure

## Hypothesis

Full-page multipass garbles `स्नेहल` on snehal; top-band OCR recovers `मुलीचे नांव … स्नेहल`. Prior Loop 24 merges demoted canaries; safer variant is **image-only**, **label-lines only**, **family-gated**, never on PDF page rasters. Also strip OCR `&`/`अँड.` name noise.

## Evidence

- Tier A: GAIN snehal name; 0 losses; canary 24/24  
- Tier B: crit **94.7% → 95.8%**; name **85% → 90%**; 0 regressions

## Residual note

`1.1.jpeg` extracts `अनिल जयबंत` (needs father surname `शिंदे`); OCR father label is `वडीलांचे` (not `वडिलांचे`).
