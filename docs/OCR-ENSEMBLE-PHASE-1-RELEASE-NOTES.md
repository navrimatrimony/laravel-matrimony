# OCR Ensemble — Phase 1 Release Notes

> **Status:** FROZEN  
> **Date:** 2026-07-13  
> **Commit:** `4c9c4696` — Add OCR ensemble Phase 1 for bulk file intake path  
> **Parent:** Blueprint v1.0 + Phase Contracts v1.0 + Implementation Checklist v1.1

---

## Summary

Phase 1 delivers the **foundation** for the OCR Ensemble pipeline: feature flag, bulk-file worker path, OpenCV minimal preprocessing (`opencv_minimal_v1`), Tesseract multipass OCR, and persistent `ocr_attempt` rows. **No second OCR engine, no field voting, no Sarvam judge, no admin comparison UI.**

Production behavior is **unchanged** while `intake_ocr_ensemble_enabled` remains `false` (default).

---

## What shipped

| Area | Detail |
|------|--------|
| Feature flag | `intake_ocr_ensemble_enabled` — Admin → Intake Settings (default **off**) |
| Scope | **Bulk file upload only** (`ProcessBulkIntakeBatchItemJob` path) |
| Preprocessing | `opencv_minimal_v1` via existing `OcrService` preset pipeline (`photo_capture` default) |
| Primary OCR | Tesseract multipass (`laravel_native_ocr`) — enriched, not replaced |
| Persistence | `biodata_intake_ocr_attempts` per intake; `raw_ocr_text` at create time |
| Bulk item meta | `ocr_ensemble_processing` → `ocr_ready` when flag on |
| Pipeline version | `phase1_v1` |

### Key files

- `app/Services/Intake/IntakeOcrEnsembleGate.php`
- `app/Services/Intake/IntakeOcrEnsemblePhase1Service.php`
- `app/Services/Intake/IntakeCreationService.php` — `prepareForBulkFile()`
- `app/Services/Intake/BulkIntakeBatchService.php`
- `config/ocr.php` — `ensemble.phase1`
- Tests: `IntakeOcrEnsemblePhase1Test`, `IntakeOcrEnsembleGateTest`, unit service test

### Explicitly out of scope (Phase 1)

- Second OCR engine / Python sidecar
- Field extractor, voting, `field_resolution_json`
- Sarvam judge
- Admin OCR comparison table on `correct-candidate`
- Single-intake admin upload path
- Changes to `BiodataParserService` / `ParseIntakeJob`

---

## Staging validation (PASS)

**Batch #44** — 5 real biodata images, flag **ON**:

| Check | Result |
|-------|--------|
| Items parsed | 5/5 |
| `ocr_ensemble_status` | 5/5 `ocr_ready` |
| `preprocessing_version` | 5/5 `opencv_minimal_v1` |
| OCR attempt rows | 5/5 present |
| `raw_ocr_text` | 5/5 non-empty |
| Job failures | 0 |

**Ground truth reference (not re-scored in Phase 1):** Batch **#43** — 10 biodatas manually reviewed on Admin `correct-candidate`; `approval_snapshot_json` is the benchmark baseline for Phase 2+.

---

## Rollback

| Level | Action |
|-------|--------|
| **L1 Instant** | Set `intake_ocr_ensemble_enabled = false` — legacy `prepare()` path |
| **L2 Code revert** | `git revert` Phase 1 commit — no DB migration rollback required |

---

## Operations

```bash
# Enable for test batches only (admin UI or DB)
# Admin → Intake Settings → OCR Ensemble enabled

# Export OCR for review (existing tool)
php tools/export_bulk_batch_ocr.php <batch_id> --format=json
```

Queue: `bulk-intake` worker must be running for background OCR.

---

## Tests (automated)

All passing locally:

- `php artisan test --filter=IntakeOcrEnsemble`
- `php artisan test --filter=AdminBulkIntakeAsyncProcessingTest`

---

## Next phase

**Phase 2** — Benchmark-gated second OCR engine. **No Phase 2 code until benchmark go/no-go.** See `docs/OCR-ENSEMBLE-PHASE-2-IMPLEMENTATION-PLAN.md`.

---

## Document history

| Date | Change |
|------|--------|
| 2026-07-13 | Phase 1 frozen after Batch #44 staging PASS |
