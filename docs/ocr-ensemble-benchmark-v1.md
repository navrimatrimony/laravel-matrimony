# OCR Ensemble Benchmark v1

> **Status:** FROZEN — Phase 2 Workstream A complete (2026-07-13)  
> **Decision:** **NO-GO** for second OCR engine integration — **Tesseract remains winner**  
> **Stage A pilot:** Batch #43 (10 admin-verified `approval_snapshot_json`)  
> **Stage B validation:** **Not run** — no Stage A candidate met +5pp threshold  
> **Truth SSOT:** `approval_snapshot_json` only

---

## Phase 2 final report (frozen)

| Engine | Stage | Batch | Critical accuracy | Delta vs baseline | Status | Decision |
|--------|-------|-------|-------------------|-------------------|--------|----------|
| **phase1_tesseract** | A | 43 | **68.75%** | — | Complete | **Baseline winner** |
| **paddleocr_v1** | A | 43 | — | — | Benchmark incomplete | Excluded from comparison |
| **easyocr_v1** | A | 43 | **50.00%** | **−18.75pp** | Complete | **NO-GO** |

### Per-field snapshot (Stage A pilot — EasyOCR vs baseline)

| Field | EasyOCR (approx.) | Notes |
|-------|-------------------|-------|
| DOB | ~100% | Strong on date patterns |
| Name | ~10% | Extractor + layout mismatch on EasyOCR text |
| Religion | ~22% | |
| Gender | ~40% | |
| Mobile | (see JSON report) | |

Full per-field tables: `storage/app/private/ocr-ensemble-benchmark/stage_B_easyocr_v1_batch43_*.json` (and `.comparison.csv`).

### Candidate #1 — PaddleOCR

| Item | Value |
|------|-------|
| **Status** | Benchmark incomplete |
| **Reason** | Runtime framework issue (documented) |
| **Observed errors** | `ConvertPirAttribute2RuntimeAttribute` (PaddlePaddle 3.3.x + oneDNN/PIR); `ResourceExhaustedError` (~8.3 GB allocation) |
| **Decision** | **Excluded from comparison** — does not alter Phase 2 NO-GO outcome |
| **Future** | Sidecar remains in `tools/ocr-ensemble-paddle-sidecar/` for a **separate** re-evaluation if runtime is fixed; will not retroactively change this freeze |

### Candidate #2 — EasyOCR

| Item | Value |
|------|-------|
| **Status** | Benchmark complete |
| **Critical accuracy** | 50.00% on Batch #43 (10 images) |
| **GO threshold** | ≥ 73.75% (+5pp vs 68.75% baseline) |
| **Decision** | **NO-GO** — fails integration gate by 18.75pp |
| **OCR runtime** | CPU-only sidecar; ~43–82 s/image on VPS (acceptable for offline benchmark) |
| **Predictions artifact** | `storage/app/private/ocr-ensemble-benchmark/predictions/batch43_easyocr_v1_*.json` |

### Phase 2 integration gate

Per `docs/OCR-ENSEMBLE-BENCHMARK-SUCCESS-CRITERIA.md` §3.5:

```
NO-GO → Tesseract-only through Phase 5
```

- No second `ocr_attempt` row integration in Phase 2.
- `intake_ocr_ensemble_enabled` remains **OFF** for production.
- Phase 3 may proceed (field extract / vote / parse input) on **Tesseract-only** path.

### Product sign-off

| Role | Name | Date | Decision |
|------|------|------|----------|
| Product | _pending_ | | NO-GO — Tesseract winner |
| Dev | _pending_ | | Benchmark doc frozen |

---

## Methodology (frozen — do not change for engine comparison)

Truth: `approval_snapshot_json`  
Prediction: `raw_ocr_text` → **Benchmark Field Extractor** (frozen) → **Scorer**  
**Not** `parsed_json`.

All candidate engines scored through the **same** frozen extractor. Do not retune extractor per engine.

---

## How to reproduce (archival)

### Stage A — Phase 1 Tesseract baseline

```bash
php artisan ocr-ensemble:benchmark-score 43 --engine=phase1_tesseract --stage=A
```

**Baseline:** `critical_accuracy = 68.75%` on Batch #43.

### EasyOCR (completed run)

Sidecar: `tools/ocr-ensemble-easyocr-sidecar/` (CPU torch via `install.sh`).

```bash
php artisan ocr-ensemble:benchmark-run 43 --engine=easyocr_v1 --stage=B --baseline=68.75
```

Re-score existing predictions without re-OCR:

```bash
php artisan ocr-ensemble:benchmark-score 43 \
  --engine=easyocr_v1 \
  --stage=B \
  --predictions=storage/app/private/ocr-ensemble-benchmark/predictions/batch43_easyocr_v1_YYYYMMDD_HHMMSS.json
```

### PaddleOCR (not scored — runtime blocked)

Sidecar: `tools/ocr-ensemble-paddle-sidecar/` — documented for future separate evaluation only.

---

## Predictions file format

```json
{
  "items": [
    {
      "intake_id": 735,
      "raw_ocr_text": "...",
      "ocr_time_ms": 1200
    }
  ]
}
```

---

## Document history

| Date | Change |
|------|--------|
| 2026-07-13 | Stage A baseline 68.75% (Tesseract) |
| 2026-07-13 | EasyOCR pilot complete — 50%, NO-GO |
| 2026-07-13 | Paddle — benchmark incomplete, excluded from comparison |
| 2026-07-13 | **Phase 2 Workstream A frozen** — Tesseract winner, Phase 3 unblocked |

**Related:** `OCR-ENSEMBLE-BENCHMARK-SUCCESS-CRITERIA.md`, `OCR-ENSEMBLE-PHASE-2-IMPLEMENTATION-PLAN.md`
