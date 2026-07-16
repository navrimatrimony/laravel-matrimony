# OCR Research Loop 17 — English Cast next-line religion

> **Status:** ACCEPTED  
> **Artifact:** `product_metrics_gt20_20260716_162754.json`

## Forensic

| File | Field | Mode | Root |
|------|-------|------|------|
| `28.pdf` | `religion` | **B** | `Cast: -` and `Ezhava` on separate OCR lines |

## Change

When `Cast:` has no same-line value, read the next line as caste token and map known Hindu castes (incl. Ezhava) to religion.

## Result

| Metric | Before | After |
|--------|-------:|------:|
| Critical | 83.2% | **84.2%** |
| Religion | 76.5% | **82.4%** |
| Losses | — | **0** |
| Gains | — | `28.pdf` religion |

## Knowledge

English resume OCR frequently splits label and value across lines; same-line-only Cast parsing under-recovers.
