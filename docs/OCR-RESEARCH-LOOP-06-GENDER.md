# OCR Research Loop 06 — Gender

> **Status:** COMPLETE slice (residual Mode A remain)  
> **Authority:** Blueprint §20 + DOC §19 + §21 Continue + §22 Shutdown  
> **Artifact:** `product_metrics_gt20_20260715_212444.json`

## Results

| Metric | Before (Loop 05) | After |
|--------|-----------------:|------:|
| Gender | 60% | **70%** |
| Critical | 71.6% | **73.7%** |

## Accepted

- `OcrEnsembleGenderExtractor` + wire in `OcrEnsembleFieldExtractor`
- English `Name:` + `Ms.`/`Miss`/`Mrs.` (ignore Father `Mr.`)
- Section `मुलीची माहिती` / `मुलाची माहिती`
- Full `कुमारी` honorific
- Candidate name labels `मुलीचे` / `मुलाचे` / वधू / वर labels

## Rejected

- Short `कु.` as female — OCR often misreads `चि.` on male (`कु. अविनाश`)
- Dropping male fallback when `नावरस` present — regressed true males on remasure

## Residual

Mode A weak OCR / wrong secondary cues on some female pages; invent rejected.

## Next

See [`OCR-STATUS.md`](OCR-STATUS.md) — Loop 07 priority: Name residual, then Mobile.
