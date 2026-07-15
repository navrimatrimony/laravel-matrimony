# OCR Product Metrics Dashboard

> **Role:** Product **Compass** — guides priority and shows progress. **Not** the Product Goal.  
> **Product Goal:** Highest practical **RAW OCR TEXT FIDELITY** (Marathi + Devanagari + English biodata).  
> **Status:** Product Goal **In Progress** (toward DOC §20 Production Release Gate — not yet)  
> **Authority:** DOC §19 / §19.1 / §20 / §21  
> **Last updated:** 2026-07-15 19:45 IST  
> **Artifact:** `product_metrics_gt20_20260715_194518.json`

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
| Structured (GT-20 critical 5) | **42.1%** | **68.4%** | ⬆ |
| Judge % | n/a | n/a | — |
| Human correction % | n/a | n/a | — |
| Avg OCR time | ~50 s | multipass | — |
| Cost / biodata | ≈ ₹0 | ≈ ₹0 | → |

---

## B. GT-20 scoreboard

| Metric | Baseline | Current | Delta | Trend |
|--------|--------:|--------:|------:|:-----:|
| Critical | 42.1% | **68.4%** | **+26.3 pp** | ⬆ |
| DOB | 25% | **95%** | +70 pp | ⬆ |
| Name | 30% | **65%** | **+35 pp** | ⬆ |
| Mobile | 55.6% | **66.7%** | **+11.1 pp** | ⬆ |
| Religion | 47.1% | 52.9% | +5.8 | ⬆ |
| Gender | 55.0% | 60.0% | +5.0 | ⬆ |
| PDF DOB | 0/3 | **5/5** | — | ⬆ |

---

## C. Production scoreboard (anti-overfit)

| Cohort | Critical | Status |
|--------|--------:|--------|
| GT-20 | 68.4% | Compass only |
| Batch-001 / Batch-002 / Last 100 / Last 500 | — | Fill only with real labels |

---

## D. Priority

| Rank | Loss | Note |
|-----:|------|------|
| 1 | Religion (52.9%, 8 miss) | Lowest remaining critical accuracy |
| 2 | Gender (60%, 8 miss) | After religion Mode B |
| 3 | Name residual (7/20) | Mode B / raw OCR |
| 4 | Mobile residual (6/18) | Digit glue / relative preference |
| — | Production Release Gate | DOC §20 when enabling prod |

---

## History

| Date | Event |
|------|-------|
| 2026-07-15 | Compass + stacks; Loop 03 name → **65%**; critical **66.3%** |
| 2026-07-15 | Loop 04 mobile: local snippet + left-biased context → mobile **66.7%**, critical **68.4%** |
