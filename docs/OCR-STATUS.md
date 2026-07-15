# OCR STATUS — Safe Shutdown resume point

> **Recorded:** 2026-07-15 21:25 IST  
> **Authority:** DOC §22 Safe Shutdown / Resume  
> **Product Goal:** RAW OCR text fidelity — **In Progress** (NOT complete)

---

## Resume command (tomorrow)

```text
Continue the Approved Goal.
Resume from the last committed state.
```

---

## Last committed facts

| Item | Value |
|------|------:|
| Last artifact | `product_metrics_gt20_20260715_212444.json` |
| Critical (GT-20) | **73.7%** |
| DOB | 95% |
| Name | 65% |
| Mobile | 61.1% |
| Religion | 76.5% |
| Gender | **70%** |
| PDF DOB | 5/5 |

**Baseline (Sprint 2):** critical 42.1%

---

## Loops

| Loop | Field | Status |
|------|-------|--------|
| 01 | DOB | Complete |
| 02 | DOB residual / English resume | Complete |
| 03 | Name | Complete slice (residual remain) |
| 04 | Mobile | Complete slice (residual remain) |
| 05 | Religion | Complete slice (Mode A residual) |
| **06** | **Gender** | **Complete slice — resume here for next work** |

---

## Next loop (start tomorrow)

**Loop 07 — next highest loss after gender uplift**

Priority order (compass):

1. **Name residual** (65%, 7 misses) — Mode B / raw OCR fidelity  
2. **Mobile residual** (61.1%, ~7 misses) — digit shift / preference; multipass flakiness noted  
3. **Gender residual** (70%, 6 misses) — Mode A weak OCR; no short `कु.` invent  
4. **Religion Mode A** (76.5%) — English resumes / no token in raw  

Do **not** declare Vision Complete. Dashboard = compass only (DOC §19.1).  
Continue automatically per DOC §21 after each loop.

---

## Loop 06 accepted (today)

- `OcrEnsembleGenderExtractor`: Ms./Miss on Name line; `मुलीची माहिती` section; `कुमारी`; name labels  
- **Rejected:** short `कु.` as female (OCR often = `चि.` on male, e.g. `कु. अविनाश`)  
- **Rejected for now:** aggressive bare-`वर` fallback dropping (regressed true males)  
- Wired via `OcrEnsembleFieldExtractor`  
- Forensic: `tools/ocr-loop06-gender-forensic.php`

---

## Intentionally unclean / leave alone

- `database/seeders/location/LocationSeeder.php` — unrelated local dirty; **do not commit**  
- `tools/**/__pycache__/` — do not commit  

---

## Server pull

**Not required.** Local-first. Pull only for milestone / staging / Production Release Gate.
