# OCR Product Metrics Dashboard

> **Audience:** Product Owner (one-glance progress toward Product Goal)  
> **Dataset:** GT-20 (`Batch-001` / `gt-20.csv`)  
> **Pipeline:** Production Laravel OCR (`OcrService` multipass + Phase 3 field extractor)  
> **Product Goal:** Highest practical **RAW OCR TEXT FIDELITY** — **In Progress**  
> **Last updated:** 2026-07-15 18:11 IST  
> **Artifact:** `product_metrics_gt20_20260715_181117.json`  
> **Authority:** DOC §19 — update this file after every research loop

---

## Scoreboard

| Metric | Baseline (Sprint 2) | Current | Delta | Trend |
|--------|--------------------:|--------:|------:|:-----:|
| **Critical field accuracy** (5 fields) | **42.1%** | **60.0%** | **+17.9 pp** | ⬆ |
| **DOB accuracy** | **25.0%** | **95.0%** | **+70.0 pp** | ⬆ |
| **Name (full_name)** | **30.0%** | **35.0%** | **+5.0 pp** | ⬆ |
| **Mobile (primary)** | **55.6%** | **55.6%** | **0 pp** | → |
| **Religion** | **47.1%** | **52.9%** | **+5.8 pp** | ⬆ |
| **Gender** | **55.0%** | **60.0%** | **+5.0 pp** | ⬆ |
| **PDF DOB** (GT-20 PDF rows) | **0/3** | **3/3 (100%)** | — | ⬆ |
| **Judge usage** | n/a | n/a | — | — |
| **Human corrections** | n/a | n/a | — | Needs production telemetry |

**Verdict:** Moving toward Product Goal. DOB largely solved on GT-20 (**1 miss**: `D (8).jpeg`). Largest remaining critical hole is **Name**.

---

## Product Impact ranking (next work)

Priority = residual miss rate × field criticality × production frequency (DOC §19).

| Rank | Weakness | Miss rate now | Production impact | Decision |
|-----:|----------|--------------:|-------------------|----------|
| 1 | **Name (`full_name`)** | **65% miss** (13/20) | **Highest** — every intake | **Next loop** |
| 2 | Mobile | 44.4% miss | High | After name |
| 3 | Religion / Gender | ~40–47% miss | Medium–high | After name/mobile |
| 4 | DOB residual `D (8)` watermark | 1/20 | Medium (overlay suchak forms) | Parallel only if generalizable; not GT-overfit |

**Impact gate:** A `D (8)`-only invent-day fix would be Rejected. Name extractor/OCR fidelity on biodata titles affects thousands of intakes → proceed.

---

## How to refresh

```bash
php tools/ocr-product-metrics-gt20.php
```

Then rewrite Current / Delta / Trend above from the new JSON artifact.

---

## History

| Date | Event |
|------|-------|
| 2026-07-15 | Dashboard created; full remasure: critical 60%, DOB 95%, PDF DOB 100%; name ranked #1 next |
