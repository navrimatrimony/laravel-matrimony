# OCR STATUS

> **Project state: RESEARCH HOLD** (strategic priority change — **not** product Complete)  
> **Suspended:** 2026-07-17  
> **Next org priority:** Flutter Matchmaker APK  
> **OCR remains resumable** via this file + Dashboard + Ledger + Blueprint + SSOT + DOC

---

## Product Owner Status (§24) — Research Hold

| # | Item | Value |
|---|------|-------|
| 1 | Overall Goal Completion | Critical **98.9%** (Tesseract SSOT) — **not** Goal Complete |
| 2 | Current Stage | **RESEARCH HOLD** |
| 3 | Current Activity | None — OCR suspended |
| 4 | Highest Priority Problem | `D (8).jpeg` DOB Mode A (Tesseract day **24** vs GT **21**) |
| 5 | Remaining Major Work | ≥500 biodata bench → then Sarvam need/placement/cost decision |
| 6 | Estimated Remaining Time | N/A while held |
| 7 | Current Blockers | Org priority = Flutter APK (not a technical blocker) |
| 8 | Next Automatic Step | **None** until OCR goal is re-approved |
| 9 | Last Stable Commit | `5fa43be1` (`main`, Research Hold tip) |
| 10 | Exact Resume Point | Section **Resume command** below |

---

## Accepted baseline (frozen)

| Item | Value |
|------|-------|
| Artifact | `storage/app/private/ocr-ensemble-benchmark/product_metrics_gt20_20260717_101021.json` |
| Critical | **98.9%** (93/94) |
| Name / Mobile / Religion / Gender | **100%** |
| DOB | **95%** (1 miss) |
| Engine SSOT | Tesseract multipass (production path) |
| Invent forbidden | Day 21 from OCR 24 — **rejected** |
| Sarvam DI | Research finding only (Loop 31) — **not** production-wired |
| Phase 5 / §20.6 Admin OCR Comparison | **Complete** (Correct Candidate PO Visibility) |

## Remaining unresolved problems

1. **Mode A:** `D (8).jpeg` DOB — Tesseract/local engines read day **24**; watermark wipe does not fix; Sarvam DI reads **21** in research only (paid; deferred until volume evidence).  
2. **Production ensemble flags** — staging/production enable still requires ops/product approval (P5-B4).  
3. **Large-dataset fidelity unknown** — GT-20 is compass only; need ≥**500** biodatas before paid-OCR architecture decisions.

## Recommended next research loop (when OCR resumes)

**Not Loop 32 Sarvam residual wiring.**  

**Recommended:** Large-dataset OCR benchmarking (≥500 biodatas) → measure Mode A frequency → then decide whether/where Sarvam (or other) is justified by accuracy vs cost.

## Production release status

| Item | Status |
|------|--------|
| Tesseract upload OCR | In use (unchanged this hold) |
| EasyOCR / Paddle / DocTR as production replace | **Rejected** (Sprint 2 NO-GO) |
| Sarvam DI / second-pass residual | **Not integrated** (PO decision) |
| Ensemble Phase 5 UI | Code complete; production flag enable = **approval required** |
| MutationService / PHASE-5 profile SSOT | Untouched by OCR research hold |

## Resume command (no chat required)

```text
Continue the Approved Goal from RESEARCH HOLD.

Read first (in order):
1. docs/OCR-STATUS.md
2. docs/OCR-PRODUCT-METRICS-DASHBOARD.md
3. docs/OCR-RESEARCH-PHASE-LEDGER.md
4. docs/OCR-ENSEMBLE-PIPELINE-BLUEPRINT.md (§20)
5. docs/DEVELOPER-OPERATING-CONTRACT.md
6. docs/OCR-ENSEMBLE-PHASE-5-PRODUCT-OWNER-VISIBILITY.md

Then:
git -C "E:\LaravelProjects\laravel-matrimony" status
git -C "E:\LaravelProjects\laravel-matrimony" log -5 --oneline

Resume point: RESEARCH HOLD → next approved OCR goal is large-dataset
benchmarking (≥500), unless Product Owner names a different Approved Goal.

Do NOT invent D8 DOB. Do NOT hardcode D (8). Do NOT wire Sarvam without
explicit new approval after volume evidence.
```

## Handover authority map

| Doc | Role |
|-----|------|
| `OCR-STATUS.md` | State + resume |
| `OCR-PRODUCT-METRICS-DASHBOARD.md` | Compass metrics |
| `OCR-RESEARCH-PHASE-LEDGER.md` | Loops / techniques |
| Blueprint §20 | Product OCR Vision |
| DOC | Agent execution |
| SSOT / PHASE-5 | Profile mutation rules |

**Do not run further OCR research loops while in RESEARCH HOLD.**
