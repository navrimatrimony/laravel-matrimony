# OCR Research Loop 19 — Mobile: previous-line संपर्क + no digit-soup invent

> **Status:** ACCEPTED  
> **Artifact:** `product_metrics_gt20_20260716_174313.json`

## Forensic

| File | Field | Mode | Root |
|------|-------|------|------|
| `1.2.jpeg` | mobile | **B** | Real phones on line before `संपर्कः`; occupation OCR soup invented `7010071901` via whole-line `normalizePhone` |
| `28.pdf` | mobile | **B** | Same digit-soup invent path demoted true `9773394047` |

## Change

1. Associate phones on the **previous** line with a contact label (as well as next line).
2. Stop inventing phones from whole-line `normalizePhone` on mixed OCR text; only accept compact single-phone lines.

## Result

| Metric | Before | After |
|--------|-------:|------:|
| Critical | 86.3% | **88.4%** |
| Mobile | 83.3% | **94.4%** |
| Losses | — | **0** |
| Gains | — | `1.2` + `28.pdf` mobile |

## Knowledge

Occupation/salary OCR digit noise must never become a mobile candidate. Label↔phone adjacency is bidirectional on biodata pages.
