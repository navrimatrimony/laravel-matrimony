# OCR Research Loop 07 — Name residual

> **Status:** COMPLETE slice (Mode A / garbled residual remain)  
> **Artifact:** `product_metrics_gt20_20260716_091807.json`  
> **Forensic:** Mode A **1** / Mode B **6**

## Results

| Metric | Before | After |
|--------|-------:|------:|
| Name | 65% | **70%** |
| Critical | 73.7% | **74.7%** |

## Accepted

- Do not truncate names like `चिवाजी` by stripping bare `चि`/`कु`
- Glued megapage `नावनवनाथ…`
- OCR label `नाब` ≈ `नाव`
- Biodata-title name path scoring; reject tiny fragments (`न्स`, `डे कू`)
- Keep glued `श्री` honorific strip (`श्रीनाथ` → `नाथ`)

## Rejected

- Invent `शि` from OCR `चिवाजी` / invent missing surname `डाकवे`

## Next

Continuing automatically to **Loop 08 — Mobile residual** (§21).
