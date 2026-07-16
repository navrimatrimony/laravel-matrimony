# OCR Research Loop 22 — `कन्या वर्ण` gender cue

> **Status:** ACCEPTED  
> **Artifact:** `product_metrics_gt20_20260716_191007.json`  
> **Workflow:** Tier A residual-pack PASS → Tier B full remasure

## Forensic

| File | Field | Mode | Root |
|------|-------|------|------|
| `snehal.jpeg` | gender | **B** | RAW has `कन्या वर्ण`; rescue fallback `male` won before descriptor cue |

## Change

Infer female from `कन्या वर्ण` / labeled `कन्या` **before** rescue gender fallback.

## Result

| Metric | Before | After |
|--------|-------:|------:|
| Critical | 90.5% | **91.6%** |
| Gender | 85% | **90%** |
| Losses | — | **0** |
| Gains | — | snehal gender |

## Workflow note

Tier A (miss+canary cache replay) validated gain before full GT-20 remasure.
