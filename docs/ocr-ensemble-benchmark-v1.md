# OCR Ensemble Benchmark v1

> **Stage A pilot:** Batch #43 (10 admin-verified `approval_snapshot_json`)  
> **Stage B validation:** 40 new held-out images (not Batch #43/#44)  
> **Truth SSOT:** `approval_snapshot_json` only

---

## How to run

### Stage A — Phase 1 Tesseract baseline

Truth: `approval_snapshot_json`  
Prediction: `raw_ocr_text` → **Benchmark Field Extractor** (`MarathiOcrFieldRescueService` + label hints)  
**Not** `parsed_json`.

```bash
php artisan ocr-ensemble:benchmark-score 43 --engine=phase1_tesseract --stage=A
```

Outputs:
- JSON report: `storage/app/private/ocr-ensemble-benchmark/stage_A_phase1_tesseract_batch43_*.json`
- Comparison table: same path with `.comparison.csv` suffix (`intake_id, field, truth, prediction, match, engine`)

### Stage A — Candidate engine (after offline OCR + field extract)

```bash
php artisan ocr-ensemble:benchmark-score 43 \
  --engine=paddleocr_v1 \
  --stage=A \
  --predictions=/path/to/paddle_predictions.json
```

Predictions file format:

```json
{
  "items": [
    {
      "intake_id": 735,
      "fields": {
        "full_name": "...",
        "date_of_birth": "1992-01-04",
        "primary_contact_number": "8149379216",
        "religion": "Hindu",
        "gender": "male"
      },
      "ocr_time_ms": 1200
    }
  ]
}
```

---

## Results log

| Run | Stage | Engine | Batch | Critical accuracy | Avg corrections | Report file |
|-----|-------|--------|-------|-------------------|-----------------|-------------|
| _pending_ | A | phase1_tesseract | 43 | | | |

---

## Go / no-go

See `docs/OCR-ENSEMBLE-BENCHMARK-SUCCESS-CRITERIA.md`. Decision scored on **Stage B** held-out set.
