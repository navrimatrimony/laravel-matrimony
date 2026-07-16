# OCR Product Metrics Dashboard

> **Role:** Product **Compass** — not success.  
> **Product Goal:** RAW OCR text fidelity — **In Progress**  
> **Last updated:** 2026-07-16 09:40 IST  
> **Artifact:** `product_metrics_gt20_20260716_094041.json`  
> **Resume:** [`docs/OCR-STATUS.md`](OCR-STATUS.md)

---

## A. Fidelity stack

| Layer | Baseline | Current | Trend |
|-------|--------:|--------:|:-----:|
| Structured (GT-20 critical 5) | **42.1%** | **76.8%** | ⬆ |

---

## B. GT-20 scoreboard

| Metric | Baseline | Current | Delta | Trend |
|--------|--------:|--------:|------:|:-----:|
| Critical | 42.1% | **76.8%** | **+34.7 pp** | ⬆ |
| DOB | 25% | **95%** | +70 | ⬆ |
| Name | 30% | **70%** | +40 | ⬆ |
| Mobile | 55.6% | **72.2%** | **+16.6** | ⬆ |
| Religion | 47.1% | **76.5%** | +29.4 | ⬆ |
| Gender | 55.0% | **70%** | +15 | ⬆ |
| PDF DOB | 0/3 | **5/5** | — | ⬆ |

---

## D. Priority

| Rank | Loss | Note |
|-----:|------|------|
| 1 | Mobile residual (27.pdf flip + Mode A digits) | Loop 09 |
| 2 | Name residual (70%) | OCR garble |
| 3 | Gender residual (70%) | Mode A |

---

## History

| Date | Event |
|------|-------|
| 2026-07-15 | Loops 01–06; critical **73.7%** |
| 2026-07-16 | Loop 07 name → **70%**; critical **74.7%** |
| 2026-07-16 | Loop 08 mobile → **72.2%**; critical **76.8%** |
