# OCR Product Metrics Dashboard

> **Role:** Product **Compass** — guides priority and shows progress. **Not** the Product Goal.  
> **Product Goal:** Highest practical **RAW OCR TEXT FIDELITY** (Marathi + Devanagari + English biodata).  
> **Status:** Product Goal **In Progress** (toward DOC §20 Production Release Gate — not yet)  
> **Authority:** DOC §19 / §19.1 / §20  
> **Last updated:** 2026-07-15 19:12 IST  
> **Artifact:** `product_metrics_gt20_20260715_191214.json`

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
| Structured (GT-20 critical 5) | **42.1%** | **66.3%** | ⬆ |
| Judge % | n/a | n/a | — |
| Human correction % | n/a | n/a | — |
| Avg OCR time | ~50 s | multipass | — |
| Cost / biodata | ≈ ₹0 | ≈ ₹0 | → |

---

## B. GT-20 scoreboard

| Metric | Baseline | Current | Delta | Trend |
|--------|--------:|--------:|------:|:-----:|
| Critical | 42.1% | **66.3%** | **+24.2 pp** | ⬆ |
| DOB | 25% | **95%** | +70 pp | ⬆ |
| Name | 30% | **65%** | **+35 pp** | ⬆ |
| Mobile | 55.6% | 55.6% | 0 | → |
| Religion | 47.1% | 52.9% | +5.8 | ⬆ |
| Gender | 55.0% | 60.0% | +5.0 | ⬆ |
| PDF DOB | 0/3 | **3/3** | — | ⬆ |

---

## C. Production scoreboard (anti-overfit)

| Cohort | Critical | Status |
|--------|--------:|--------|
| GT-20 | 66.3% | Compass only |
| Batch-001 / Batch-002 / Last 100 / Last 500 | — | Fill only with real labels |

---

## D. Priority

| Rank | Loss | Note |
|-----:|------|------|
| 1 | Name residual (7/20) | Continue Mode B / raw OCR |
| 2 | Mobile | Next critical |
| 3 | Religion / Gender | After |
| — | Production Release Gate | DOC §20 when enabling prod |

---

## History

| Date | Event |
|------|-------|
| 2026-07-15 | Compass + stacks; Loop 03 name → 50% then **65%**; critical **66.3%** |
