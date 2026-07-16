# OCR Research Loop 13 — Multipass selection (name-label signal)

> **Status:** REJECTED (GT-20 remeasure regression)  
> **Policy tried:** `tesseract_multipass_v2_name_signal` (not shipped)  
> **Artifact:** `product_metrics_gt20_20260716_134838.json`

## Problem

Score components saturated at **115**, so ties used **max char_count**, preferring noisier `mar`-only OCR. On `snehal.jpeg` the winner was original/mar/psm6 with garbled `षामली ट` while other same-score attempts had better name-line text.

## Change

1. Boost score from Devanagari/Latin tokens after `नाव` / `नांव` / `नावरस नांव` / `Name` labels (breaks 115 ceiling).
2. Tie-break: name-label signal → name words → label hits → prefer `mar+eng` → Devanagari ratio → Devanagari chars (not raw length).
3. Reject Loop 12 `clean_document` additive (0 uplift).

## Probe evidence (local)

`tools/ocr-loop13-raw-image-probe.php` + `ocr-loop13-prod-winner.php`:

- Before: `has_snehal=N`, name=`षामली ट`, gender=male  
- After: `has_snehal=Y`, RAW contains `स्नेहल शहाजी भोसले`, gender=female  

## GT-20 remeasure (reject gate)

| Metric | Loop 11 baseline | Loop 13 candidate |
|--------|----------------:|------------------:|
| Critical | **80.0%** | **73.7%** |
| Name | **75%** | **55%** |
| Religion | **76.5%** | **64.7%** |
| Gender | 70% | 70% |
| DOB | 95% | 95% |
| Mobile | 83.3% | 83.3% |

**8 losses / 2 gains** vs `product_metrics_gt20_20260716_111153.json`. Notable losses: `27.pdf` name+mobile, `photo_2026-06-05_10-33-15` name, `1.3.jpeg` name. Gains: `snehal.jpeg` gender, `1.2.jpeg` mobile.

Production code **reverted**; forensic tools retained.

## Knowledge

Global name-label score boost + tie-break reorder helps some saturated ties (`snehal`) but demotes previously winning attempts on other biodata/PDF rows. Future RAW name work must be **cohort-gated** or use a softer boost that cannot beat label_hits / DOB signals on full biodata winners.

## Hard Mode A unchanged

`1.1` / `D(8)` / several religion rows still lack truth tokens in any preset — need future RAW approaches, not parser invent.
