# OCR Research Loop 08 — Mobile residual

> **Status:** COMPLETE slice (residual remain, incl. 27.pdf flip)  
> **Artifact:** `product_metrics_gt20_20260716_094041.json`  
> **Forensic:** Mode A **0** / Mode B **7**

## Results

| Metric | Before | After |
|--------|-------:|------:|
| Mobile | 61.1% | **72.2%** |
| Critical | 74.7% | **76.8%** |

## Accepted

- Stop phone-regex whitespace merge (`9850… 8437…` → fake `9599…`)
- Labels: `संपर्क` / OCR `संपकण`
- Boost first phone immediately after contact label

## Recoveries

- testing PDF3 `8698501396`
- `photo_…10-33-07` `9881459325`
- `photo_…14-44-04` `9850959973`

## Residual / watch

- `27.pdf` flipped OK→wrong (`9604289289`) — investigate next loop
- Mode A digit OCR (`D8`, `1.2`, etc.) — no invent

## Next

Continuing automatically to **Loop 09** — mobile residual (`27.pdf`) then name/gender Mode A.
