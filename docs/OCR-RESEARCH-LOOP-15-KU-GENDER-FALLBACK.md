# OCR Research Loop 15 — Extracted-name `कु.` gender fallback

> **Status:** ACCEPTED  
> **Artifact:** `product_metrics_gt20_20260716_151836.json`

## Forensic

| File | Field | Mode | Root |
|------|-------|------|------|
| `1.jpeg` | `gender` | **B-like parser gap** | RAW/extracted candidate name was `कु.प्रतिक्षा नितिन मगर`, but no direct section/header gender cue fired |

## Change

If direct gender cues fail and fallback gender is absent, infer `female` from the **extracted candidate name** when it begins with `कु.`.

Guard:

- do **not** override explicit/fallback `male`
- do **not** use raw-line short `कु.` globally
- only use the already extracted candidate name

## Result

| Metric | Before | After |
|--------|-------:|------:|
| Critical | 81.1% | **82.1%** |
| Gender | 70% | **75%** |
| Losses | — | **0** |
| Gains | — | `1.jpeg` gender |

## Knowledge

Short `कु.` remains unsafe as a raw OCR cue, but it is materially safer on the already extracted candidate full name after explicit and fallback paths fail.
