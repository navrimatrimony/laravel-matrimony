# OCR Research Loop 20 — Orphan sticker vs trailing father paren phone

> **Status:** ACCEPTED  
> **Artifact:** `product_metrics_gt20_20260716_180251.json`

## Forensic

| File | Field | Mode | Root |
|------|-------|------|------|
| `D (8).jpeg` | mobile | **B** | Orphan sticker `959…` beat father trailing `(९८२१२१२०७८)` |

## Change

1. Stronger penalty for short unlabeled low-letter phone fragments (stickers).
2. Soft relation when phone is in trailing parens; +35 for clean final `(mobile)`.

## Result

| Metric | Before | After |
|--------|-------:|------:|
| Critical | 88.4% | **89.5%** |
| Losses | — | **0** |
| Gains | — | D8 mobile |

## Knowledge

Overlay stickers and OCR digit soup in job titles must lose to a clean trailing parenthetical mobile on the father intro line.
