# OCR Product Metrics Dashboard

> **Role:** Product **Compass** — guides priority and shows progress. **Not** the Product Goal.  
> **Product Goal (unchanged):** Highest practical **RAW OCR TEXT FIDELITY** (Marathi + Devanagari + English biodata) → then structure / Judge / humans use that text.  
> **Status:** Product Goal **In Progress**  
> **Authority:** DOC §19 / §19.1 — Goal always overrides any green metric  
> **Last updated:** 2026-07-15 18:48 IST (post Loop 03 name extractor remasure)  
> **Artifact:** `product_metrics_gt20_20260715_184808.json`

```text
Dashboard shall guide priority, not success.
GT-20 green ≠ Vision complete.
```

---

## A. Fidelity stack (product)

| Layer | Baseline | Current | Trend | Notes |
|-------|--------:|--------:|:-----:|-------|
| **RAW OCR Fidelity** | Sprint-2 weak | improving | ⬆ | Primary Vision — structured % is downstream only |
| **Structured accuracy** (GT-20 critical 5) | **42.1%** | **63.2%** | ⬆ | +21.1 pp vs Sprint 2 |
| **Judge %** | n/a | n/a | — | Telemetry TBD |
| **Human correction %** | n/a | n/a | — | Admin ops TBD |
| **Avg OCR time / biodata** | ~50 s | multipass varies | — | Local Tesseract |
| **Cost per biodata** | ≈ ₹0 | ≈ ₹0 | → | No paid second engine in prod |

---

## B. GT-20 scoreboard (development hard set)

| Metric | Baseline (Sprint 2) | Current | Delta | Trend |
|--------|--------------------:|--------:|------:|:-----:|
| Critical (5 fields) | 42.1% | **63.2%** | **+21.1 pp** | ⬆ |
| DOB | 25.0% | **95.0%** | +70.0 pp | ⬆ |
| Name | 30.0% | **50.0%** | **+20.0 pp** | ⬆ |
| Mobile | 55.6% | 55.6% | 0 | → |
| Religion | 47.1% | 52.9% | +5.8 pp | ⬆ |
| Gender | 55.0% | 60.0% | +5.0 pp | ⬆ |
| PDF DOB | 0/3 | **3/3** | — | ⬆ |

Refresh: `php tools/ocr-product-metrics-gt20.php`

**Compass:** Name still lowest critical field (50%) despite Loop 03 uplift. DOB 95% ≠ Goal done. Education/occupation/address/caste/village/height not in this proxy.

---

## C. Production scoreboard (anti-overfit)

| Cohort | Critical | DOB | Name | Status |
|--------|--------:|----:|-----:|--------|
| **GT-20** | **63.2%** | 95% | 50% | Active compass |
| **Batch-001** unlabeled full | TBD | TBD | TBD | No invented labels |
| **Batch-002** | — | — | — | Needs data |
| **Last 100 / 500 intakes** | — | — | — | Needs review telemetry |

---

## D. Priority ranking

| Rank | Weakness | Decision |
|-----:|----------|----------|
| 1 | Name residual (10/20 miss; mostly Mode B / OCR noise) | Continue Loop 03 / next name pass |
| 2 | Mobile | After name plateau |
| 3 | Religion / Gender | After |
| 4 | Non-critical structured fields | After critical |
| 5 | `D (8)` DOB watermark | General overlay pattern only |

---

## History

| Date | Event |
|------|-------|
| 2026-07-15 | Dashboard created; critical 60% / DOB 95% |
| 2026-07-15 | §19.1 compass ≠ success; Production scaffold |
| 2026-07-15 | Loop 03 name remasure: critical **63.2%**, name **50%** (+20 pp vs Sprint 2) |
