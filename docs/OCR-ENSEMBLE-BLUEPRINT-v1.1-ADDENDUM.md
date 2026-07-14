# OCR Ensemble — Blueprint v1.1 Addendum (Clarifications Only)

> **Parent:** `OCR-ENSEMBLE-PIPELINE-BLUEPRINT.md` **v1.0 (DESIGN FROZEN)**  
> **Type:** Clarifications from Production Readiness Review — **not** scope change  
> **Date:** 2026-07-12  
> **Effect:** v1.0 remains frozen; implement per v1.0 + this addendum

---

## A1. `raw_ocr_text` timing

- Ensemble runs inside the **existing queue worker** (`ProcessBulkIntakeBatchItemJob` path for bulk).
- `raw_ocr_text` is written **once at intake insert** from primary Tesseract output (after OpenCV preprocess).
- Ensemble **never mutates** `raw_ocr_text` after insert.
- Phase 3+ improvements go to `last_parse_input_text` and `field_resolution_json`.

## A2. Job ordering

- Ensemble step completes **before** `ParseIntakeJob` is dispatched.
- No parallel ensemble + parse on the same intake.

## A3. Input type skip

- Bulk `input_type=text` → **skip ensemble**; use existing text path.

## A4. Duplicate file reuse

- Duplicate file hash → existing `REUSED_TRANSCRIPT` behavior.
- **Do not** re-run ensemble or second engine on reused uploads in v1.0.

## A5. OpenCV / PDF degrade

- OpenCV unavailable or fails → log warning; run Tesseract on **original** image; job continues.
- PDF: skip OpenCV crop or rasterize first page only; do not fail job.

## A6. Sarvam failure (Phase 4)

- Sarvam API error/timeout → **non-fatal**; log; leave fields missing; route to admin `needs_review`.
- Intake job must not fail solely due to Sarvam.

## A7. `field_resolution_json` storage

- **Decision before Phase 3 code:** store on `biodata_intakes` as nullable JSON column **or** approved telemetry JSON key.
- Single source only — no duplicate in cache + column.

## A8. One-phase-at-a-time delivery

```
Phase → Implement → Test → Staging simulation → Freeze → Next phase
```

Never two phases in one PR.

## A9. Phase 1 path scope

- **Required:** admin **bulk file upload** path only (product decision 2026-07-12).
- Admin single-intake web upload → **deferred** post Phase 1.

## A11. Product decisions (2026-07-12)

See `OCR-ENSEMBLE-PRODUCT-DECISIONS.md`:

- Ground truth: **admin verified > Sarvam draft**
- Golden set: **10 images now** (not partial 3+7)
- Correction UI: **left zoomable image, right form** — high priority
- Testing on production server (no customers yet) — OK with feature flag

## A12. Post-v1.0 locked roadmap (2026-07-14)

See parent blueprint **§19**:

1. Phase 4 transport / Judge path → **CLOSED**
2. Sprint 1 → Phase 3 DOB/candidate forensics  
3. Sprint 2 → OCR engine **evaluation** (benchmark only)  
4. Sprint 3 → multi-OCR production only after GO  
5. Sprint 4 → knowledge/learning (SSOT-governed)

Do not reopen Phase 4 HTTP debugging for empty DOB / `merge_noop` class issues.

---

## Document history

| Version | Date | Change |
|---------|------|--------|
| 1.1 | 2026-07-12 | Clarifications from readiness review |
| 1.1a | 2026-07-14 | A12 — pointer to blueprint §19 locked post-v1.0 roadmap |
