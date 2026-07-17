# OCR GT Corrections — 2026-07-17 (Product Owner)

> These are **GT corrections only**, not OCR improvements.

## Changes

| File | Field | Before | After | Reason |
|------|-------|--------|-------|--------|
| `testing … (2).pdf` | religion | `Hindu` | *(removed / null)* | Biodata has no religion; prior GT was labeling mistake |
| `snehal.jpeg` | full_name | `स्नेहल शहाजी भोसले` | **confirmed** (not `शहानी`) | Correct spelling |
| `1.1.jpeg` | full_name | `अनिल जयवंत शिंदे` | **confirmed** (not `जयबंत`) | Correct spelling |

## Title normalization (matcher + name strip)

Accepted as titles (not name tokens): `Adv`, `Advocate`, `अॅड.`, `ॲड.` (plus OCR forms `अँड.` / `&`).

## SSOT file

`storage/app/private/ocr-ensemble-benchmark/sprint2_gt20_score_20260715_130342.json`

Tool: `tools/ocr-gt-corrections-20260717.php`
