# OCR Ensemble Pipeline — Production Readiness Review

> **Review type:** Independent architecture validation (no code, no new features)  
> **Documents reviewed:** `OCR-ENSEMBLE-PIPELINE-BLUEPRINT.md` v1.0, `OCR-ENSEMBLE-PHASE-CONTRACTS.md` v1.0  
> **Reviewer:** Implementation readiness gate (pre-coding)  
> **Date:** 2026-07-12  
> **Verdict:** **CONDITIONAL PASS** — safe to begin **Phase 1** after v1.1 clarifications below are accepted (documentation only).

---

## 1. Executive summary

| Area | Rating | Notes |
|------|--------|-------|
| SSOT compliance | **Amber** | Mostly aligned; timing of `raw_ocr_text` vs async ensemble needs v1.1 clarification |
| Data flow | **Green** | OCR → extract → vote → parse → approval chain is sound |
| Performance | **Amber** | Worker CPU/RAM; job ordering; bulk batch concurrency |
| Rollback | **Green** | Feature flag sufficient for v1 |
| Failure handling | **Amber** | Several edge paths underspecified |
| Testability | **Green** | Golden dataset + benchmark rules addressable |
| Scope discipline | **Green** | Frozen scope is tight |

**No blocking architectural flaws.** Proceed to Phase 1 implementation **after** v1.1 doc patches (§8) — no code required for patches.

---

## 2. SSOT compliance

### 2.1 Compliant (no change needed)

| Rule | Blueprint alignment | Existing code |
|------|---------------------|---------------|
| `raw_ocr_text` not mutated by `ParseIntakeJob` | ✅ | `ParseIntakeJob` header comment enforces |
| `parsed_json` not manually edited | ✅ | Bulk correction uses `approval_snapshot_json` |
| Approval → `IntakeApprovalService` → `MutationService` | ✅ | Ensemble does not touch this |
| `biodata_intake_ocr_attempts` as evidence | ✅ | Table + `IntakeOcrAttemptRecorder` exist |
| `last_parse_input_text` for canonical parse input | ✅ | Parse job prefers over `raw_ocr_text` in AI modes |
| Bulk `item_status` technical only | ✅ | Unchanged by design |

### 2.2 Amber — needs v1.1 clarification (not scope expansion)

#### A) When is `raw_ocr_text` written?

**Today:** `IntakeCreationService::prepare()` runs **inside** `ProcessBulkIntakeBatchItemJob` (worker), calls `OcrService` synchronously, then `persistPrepared()` sets immutable `raw_ocr_text`.

**Blueprint says:** HTTP immediate + queue ensemble.

**Reality:** Bulk HTTP is already async via queue. Phase 1 likely **extends the existing worker path**, not a second HTTP-blocking path.

**v1.1 recommendation:** State explicitly:

> Phase 1 “queue” = existing `bulk-intake` worker (and equivalent single-intake job if added). `raw_ocr_text` is set **once at intake insert** inside the worker from primary Tesseract output after OpenCV preprocess. No post-insert mutation of `raw_ocr_text`.

#### B) `field_resolution_json` storage location

Blueprint: “column TBD.” Implementation without decision risks duplicate storage in `routing_telemetry_json` + new column.

**v1.1 recommendation:** Pick one before Phase 3 coding:

- Preferred: nullable JSON column on `biodata_intakes` **or** dedicated `intake_field_resolution_json` keyed by `intake_id` in existing telemetry pattern — document choice in v1.1.

#### C) Ensemble parse input vs `raw_ocr_text` (Phase 3+)

`ParseIntakeJob` uses `last_parse_input_text` when set. Ensemble-assembled text must write **`last_parse_input_text` before parse dispatch**, not overwrite `raw_ocr_text`.

**v1.1 recommendation:** Add one line to blueprint §8.3: “Ensemble never updates `raw_ocr_text` after insert.”

#### D) Duplicate file reuse

`IntakeCreationService` reuses peer `raw_ocr_text` on duplicate file hash.

**Edge case:** Ensemble enabled + duplicate upload → should **skip re-OCR** and reuse attempts or force fresh?

**v1.1 recommendation:** Document: duplicate reuse policy unchanged in v1.0; ensemble runs only on **new file hash** intakes. Reused transcript = `ENGINE_REUSED_TRANSCRIPT` attempt; no second engine, no Sarvam.

---

## 3. Data flow validation

### 3.1 Happy path (bulk file, flag on, Phase 5 complete)

```
POST bulk store → batch + pending items → HTTP redirect
        ↓
ProcessBulkIntakeBatchItemJob
        ↓
[IntakeCreationService or ensemble orchestrator]
  OpenCV → Tesseract (+ optional 2nd engine)
  → ocr_attempts
  → field extract → vote → [Sarvam if triggered]
  → field_resolution_json + last_parse_input_text
  → persist intake (raw_ocr_text at insert)
        ↓
ParseIntakeJob (parse_input_only for bulk free parse)
        ↓
parsed_json
        ↓
Admin correct-candidate (comparison table read-only)
```

**Validated:** Logical and compatible with existing bulk free-parse path.

### 3.2 Job ordering risk (Phase 3+)

**Risk:** `queueFreeParseAfterUpload` dispatches `ParseIntakeJob` at end of `processPendingItem`. If ensemble is split into a **separate** job in Phase 1, parse may run **before** ensemble completes.

**Mitigation (v1.1):** Phase 1 keeps ensemble **inside** `ProcessBulkIntakeBatchItemJob` (or chains `OcrEnsembleJob` → `ParseIntakeJob` with explicit dispatch-after-success). Never parallel parse + ensemble on same intake.

### 3.3 Text-only bulk items

`input_type=text` bypasses file OCR today.

**Validated:** Ensemble must **skip** for text items (use `source_text` as raw). Document in Phase 1 contract out-of-scope — already implied; add explicit skip rule in v1.1.

### 3.4 PDF uploads

Tesseract multipass supports PDF; OpenCV preprocess may be image-only.

**Edge case:** PDF through OpenCV v1 may fail or no-op.

**v1.1 recommendation:** Phase 1: PDF → skip OpenCV crop or use first-page rasterize; log `preprocessing_skipped_pdf`. Do not fail job.

### 3.5 Admin re-parse / manual transcript

Existing paths: admin re-parse, `BulkIntakeManualTranscriptService`, manual crop OCR.

**Edge case:** Ensemble intakes re-parsed — overwrite `field_resolution_json`?

**v1.1 recommendation:** Re-parse uses existing canonical transcript rules; ensemble metadata preserved in `ocr_attempts`; `field_resolution_json` updated only when ensemble job re-run explicitly (future admin action). Phase 1–5: no auto re-ensemble on re-parse.

---

## 4. Performance risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| OpenCV + Tesseract + (later) sidecar sequential in one worker | Medium | Accept 20–40s; do not run on HTTP thread |
| Bulk batch 50 files × 30s = 25 min | Medium | Existing queue; `WithoutOverlapping` per item — OK |
| VPS RAM (OpenCV + Tesseract) | Medium | Limit worker concurrency; monitor `srv1207365` |
| Python sidecar cold start (Phase 2) | Medium | Health check + timeout fallback |
| Sarvam poll loop (Phase 4) | High cost/time | Already in `AiVisionExtractionService`; judge-only reduces volume |
| DB growth `ocr_attempts` | Low | 2–4 rows/intake acceptable |

**40 sec average target** (user benchmark) is achievable for Tesseract-only; **with sidecar + Sarvam** some intakes may exceed — benchmark must track p95, not just mean.

---

## 5. Rollback strategy

| Level | Action | Effect |
|-------|--------|--------|
| **L1 Instant** | `intake_ocr_ensemble_enabled = false` | Legacy `prepare()` + OCR path |
| **L2 Deploy** | Revert Phase 1 PR | Flag + new job code removed |
| **L3 Data** | No migration required Phase 1 | Extra `ocr_attempts` rows harmless |
| **L4 Sidecar** (Phase 2) | Disable sidecar URL in config | Tesseract-only fallback |

**Validated:** Rollback is production-safe if flag defaults `false` and Phase 1 does not alter behavior when off.

**Gap:** Document rollback drill in test plan (toggle flag on staging, upload 3 files, toggle off, verify identical behavior).

---

## 6. Failure scenarios

| Scenario | Expected behavior | Blueprint coverage |
|----------|-------------------|-------------------|
| OpenCV extension missing | Log warning; run Tesseract on original image | **Gap** — v1.1: mandatory degrade path |
| Tesseract empty text | `empty_ocr_text` bulk item failure (existing) | ✅ `USABLE_OCR_TEXT_MIN_LENGTH = 20` |
| Sidecar timeout (Phase 2) | Continue Tesseract-only | ✅ |
| Sidecar wrong JSON | Log; Tesseract-only | **Gap** — v1.1: specify |
| All engines disagree on name | Sarvam judge (Phase 4) | ✅ |
| Sarvam API down | Log; leave fields missing; admin review | **Gap** — v1.1: intake must not fail; `needs_review` |
| Sarvam timeout | Same as API down | **Gap** |
| Validator rejects all DOB candidates | DOB missing → Sarvam trigger | ✅ |
| Gender missing | Empty field; no Sarvam | ✅ v1.0 |
| Queue worker dead | Items stay pending (existing) | ✅ |
| Flag on mid-batch | Only new items use ensemble | **Gap** — v1.1: per-intake at process time |
| Partial Phase deploy (Phase 3 without 2) | Single-engine vote | ✅ contract |

---

## 7. Missing edge cases (summary)

| # | Edge case | Suggested v1.1 action |
|---|-----------|----------------------|
| 1 | `raw_ocr_text` write timing vs worker | Clarify §2.2A |
| 2 | Job ordering ensemble vs parse | Explicit chain §3.2 |
| 3 | Text-only bulk skip | Document skip |
| 4 | PDF + OpenCV | Degrade path |
| 5 | Duplicate file reuse | Skip ensemble re-run |
| 6 | OpenCV unavailable | Degrade to raw image |
| 7 | Sarvam failure | Non-fatal; admin review |
| 8 | `field_resolution_json` column | Decide before Phase 3 |
| 9 | Single-intake admin web upload (non-bulk) | Phase 1 scope: bulk only or both? |
| 10 | Income soft validator false positive | Admin verify; no auto-reject intake |

**Item 9** is the only scope question: Phase 1 contract says bulk + single when flag on — confirm admin single intake uses same worker pattern or defer single to Phase 1.1.

---

## 8. Recommended v1.1 document changes (no code)

| ID | Document | Change |
|----|----------|--------|
| V1.1-01 | Blueprint §8 | `field_resolution_json` storage decision placeholder → chosen pattern before Phase 3 |
| V1.1-02 | Blueprint §8.3 | Explicit: ensemble never mutates `raw_ocr_text` after insert |
| V1.1-03 | Phase 1 contract | Ensemble runs inside existing bulk worker; parse dispatch after ensemble step |
| V1.1-04 | Phase 1 contract | Skip ensemble for `input_type=text` |
| V1.1-05 | Phase 1 contract | OpenCV failure → degrade, job continues |
| V1.1-06 | Phase 4 contract | Sarvam failure → non-fatal, log, `needs_review` |
| V1.1-07 | Blueprint §1 | Add **one-phase-at-a-time** delivery rule (see Implementation Checklist) |
| V1.1-08 | Phase 1 scope | Clarify: bulk path required; admin single-intake optional same phase or follow-up |

---

## 9. Performance vs accuracy tradeoff

Blueprint correctly prioritizes accuracy. Review confirms:

- 20–30s admin wait: **acceptable** for worker path
- 40s average benchmark target: **achievable** Tesseract-only; document p95 ≤ 60s for ensemble+Sarvam edge cases

---

## 10. Test & benchmark readiness

| Artifact | Status |
|----------|--------|
| Blueprint success criteria | ✅ |
| Phase contracts | ✅ |
| Ground truth seed (#735–737) | ✅ exists in production |
| Formal test plan document | ⏳ created: `OCR-ENSEMBLE-TEST-PLAN.md` |
| Implementation checklist | ⏳ created: `OCR-ENSEMBLE-IMPLEMENTATION-CHECKLIST.md` |
| 50-image curated dataset | ⏳ operator task before Phase 2 decision |

---

## 11. Final verdict

| Question | Answer |
|----------|--------|
| Architecture sound? | **Yes** |
| SSOT safe? | **Yes**, with v1.1 clarifications |
| Ready for Phase 1 code? | **Yes**, after v1.1 doc patches accepted (1–2 hours doc work) |
| Ready for Phase 2–5 code? | **No** — complete prior phase + gate |
| New features needed? | **None** |

**Sign-off gate for coding:**

1. Accept this review + v1.1 patches  
2. Approve `OCR-ENSEMBLE-IMPLEMENTATION-CHECKLIST.md`  
3. Approve `OCR-ENSEMBLE-TEST-PLAN.md`  
4. Begin seeding ground truth dataset (min 10 verified rows before Phase 1 merge to production flag on)

---

## 12. Document history

| Version | Date | Change |
|---------|------|--------|
| 1.0 | 2026-07-12 | Initial production readiness review |
