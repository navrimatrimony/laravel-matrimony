# OCR Research Loop 16 — OCR `मिस.` gender + English Cast religion

> **Status:** ACCEPTED  
> **Artifact:** `product_metrics_gt20_20260716_155920.json`

## Forensic

| File | Field | Mode | Root |
|------|-------|------|------|
| `photo_2026-06-05_10-33-22.jpg` | `gender` | **B** | OCR `मिस.` on noisy header; rescue fallback `male` beat female cues |
| `28.pdf` | `religion` | **B** | English resume `Cast: - Ezhava` present in raw |

## Change

1. Treat Devanagari OCR `मिस.` as female honorific before rescue fallback.
2. Infer religion from English resume `Cast:` line for known Hindu caste tokens (e.g. Ezhava).

## Result

| Metric | Before | After |
|--------|-------:|------:|
| Critical | 82.1% | **83.2%** |
| Gender | 75% | **80%** |
| Losses | — | **0** |
| Gains | — | `10-33-22` gender |

## Knowledge

`मिस.` is safer than global short `कु.` because it is a distinct OCR corruption of Ms. on female biodata headers. English Cast inference is resume-specific, not GT-only.
