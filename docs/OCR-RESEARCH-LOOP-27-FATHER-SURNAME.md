# OCR Research Loop 27 — Father label `वडीलांचे` surname

> **Status:** ACCEPTED  
> **Artifact:** `product_metrics_gt20_20260717_092259.json`

## Hypothesis

`1.1.jpeg` had `अनिल जयबंत` (2 tokens) but father line uses OCR `वडीलांचे` (not `वडिलांचे`). Full `cleanCandidateName` on the father value truncated before `शिंदे`.

## Change

Recognize `वडीलांचे`; take last Devanagari token without person-name trim.

## Evidence

- Tier A: GAIN 1.1 name; 0 losses; canary 24/24  
- Tier B: crit **95.8% → 96.8%**; name **90% → 95%**; 0 regressions
