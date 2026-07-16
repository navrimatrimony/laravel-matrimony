# OCR Research Loop 18 — Hindu caste → religion + शश्री name flake

> **Status:** ACCEPTED  
> **Artifact:** `product_metrics_gt20_20260716_172006.json`

## Forensic

| File | Field | Mode | Root |
|------|-------|------|------|
| `D (8).jpeg` | religion | **B** | `जात ल मराठा` → caste Maratha, religion null |
| `photo_2026-06-05_10-33-07.jpg` | religion | **B** | जात / मराठा present without हिंदू token |
| `27.pdf` | full_name | OCR flake | multipass sometimes yields `शश्रीनाथ` |

## Change

1. When religion null and caste is a known Hindu caste (Maratha, Kunbi, Ezhava, …), infer Hindu.
2. Peel OCR-doubled `शश्री…` before existing `श्री` honorific strip.

Rejected mid-loop: glued-नाव parentheses widening (caused collateral name demotion).

## Result

| Metric | Before | After |
|--------|-------:|------:|
| Critical | 84.2% | **86.3%** |
| Religion | 82.4% | **94.1%** |
| Name | 80% | **80%** |
| Losses | — | **0** |
| Gains | — | D8 + 10-33-07 religion |

## Knowledge

Marathi biodata often omits explicit धर्म when जात is Maratha/Kunbi. OCR multipass can double श्री; peel is safer than global multipass retune.
