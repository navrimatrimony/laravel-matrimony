# OCR Product Metrics Dashboard

> **Role:** Product **Compass** — guides priority and shows progress. **Not** the Product Goal.  
> **Product Goal:** Highest practical **RAW OCR TEXT FIDELITY** (Marathi + Devanagari + English biodata).  
> **Status:** Product Goal **In Progress** (toward DOC §20 Production Release Gate — not yet)  
> **Authority:** DOC §19 / §19.1 / §20 / §21 / §22  
> **Last updated:** 2026-07-15 21:25 IST  
> **Artifact:** `product_metrics_gt20_20260715_212444.json`  
> **Resume:** [`docs/OCR-STATUS.md`](OCR-STATUS.md)

```text
Dashboard shall guide priority, not success.
GT-20 green ≠ Vision complete.
Final success = real production biodata fidelity.
```

---

## A. Fidelity stack

| Layer | Baseline | Current | Trend |
|-------|--------:|--------:|:-----:|
| RAW OCR Fidelity | weak | improving | ⬆ |
| Structured (GT-20 critical 5) | **42.1%** | **73.7%** | ⬆ |
| Judge % | n/a | n/a | — |
| Human correction % | n/a | n/a | — |
| Avg OCR time | ~50 s | multipass | — |
| Cost / biodata | ≈ ₹0 | ≈ ₹0 | → |

---

## B. GT-20 scoreboard

| Metric | Baseline | Current | Delta | Trend |
|--------|--------:|--------:|------:|:-----:|
| Critical | 42.1% | **73.7%** | **+31.6 pp** | ⬆ |
| DOB | 25% | **95%** | +70 pp | ⬆ |
| Name | 30% | **65%** | **+35 pp** | ⬆ |
| Mobile | 55.6% | **61.1%** | +5.5 | ⬆ |
| Religion | 47.1% | **76.5%** | **+29.4 pp** | ⬆ |
| Gender | 55.0% | **70%** | **+15 pp** | ⬆ |
| PDF DOB | 0/3 | **5/5** | — | ⬆ |

---

## C. Production scoreboard (anti-overfit)

| Cohort | Critical | Status |
|--------|--------:|--------|
| GT-20 | 73.7% | Compass only |
| Batch-001 / Batch-002 / Last 100 / Last 500 | — | Fill only with real labels |

---

## D. Priority (tomorrow)

| Rank | Loss | Note |
|-----:|------|------|
| 1 | Name residual (65%) | Mode B / raw OCR |
| 2 | Mobile residual (61.1%) | Preference / digit OCR |
| 3 | Gender residual (70%) | Mode A; no short `कु.` |
| — | Production Release Gate | DOC §20 when enabling prod |

---

## History

| Date | Event |
|------|-------|
| 2026-07-15 | Loop 03 name → **65%** |
| 2026-07-15 | Loop 04 mobile → **66.7%** peak; remasure later **61.1%** (multipass flip) |
| 2026-07-15 | Loop 05 religion → **76.5%**; critical **71.6%** |
| 2026-07-15 | Loop 06 gender → **70%**; critical **73.7%**; Safe Shutdown |
