# OCR Ensemble Pipeline — Implementation Checklist

> **Parent:** Blueprint v1.0 + Phase Contracts v1.0 + Production Readiness Review v1.0  
> **Rule:** **One phase at a time** — never start Phase N+1 until Phase N is implemented, tested, production-simulated, and frozen.

---

## Delivery gate (every phase)

```
One Phase
    ↓
Implementation (single PR or focused PR series)
    ↓
Unit tests
    ↓
Integration / feature tests
    ↓
Production simulation (staging, flag on, real images)
    ↓
Phase freeze (checklist 100% for that phase)
    ↓
Next Phase only
```

**Never:** two phases in one PR.  
**Never:** blueprint change during implementation without v1.1+ document.

---

## Pre-implementation (before Phase 1 code)

| # | Item | Owner | Done |
|---|------|-------|------|
| P0-01 | Blueprint v1.0 frozen acknowledged | Product | ☐ |
| P0-02 | Production Readiness Review accepted | Product | ☐ |
| P0-03 | v1.1 doc clarifications accepted (see Readiness Review §8) | Product | ☐ |
| P0-04 | Test Plan approved | Product | ☐ |
| P0-05 | Ground truth dataset: min **10** manually verified rows | Ops | ☐ |
| P0-06 | Seed rows include #735 (truth), #736, #737 | Ops | ☐ |
| P0-07 | Staging worker + queue verified (`bulk-intake`) | DevOps | ☐ |

---

## Phase 1 — Foundation: Flag, Queue Worker, OpenCV v1, Tesseract, Attempts

### Implementation

| # | Item | Done |
|---|------|------|
| 1.01 | Admin setting `intake_ocr_ensemble_enabled` (default `false`) | ☑ |
| 1.02 | Flag read in bulk processing path only (scope per v1.1) | ☑ |
| 1.03 | When flag `false`: byte-identical behavior to current production | ☑ |
| 1.04 | OpenCV minimal v1: EXIF rotate | ☑ |
| 1.05 | OpenCV minimal v1: grayscale + contrast | ☑ |
| 1.06 | OpenCV minimal v1: best-effort text-region crop | ☑ |
| 1.07 | OpenCV failure → degrade (log, use original image) | ☑ |
| 1.08 | PDF path: skip or rasterize page 1 (document behavior) | ☑ |
| 1.09 | Tesseract via existing multipass (enriched, not replaced) | ☑ |
| 1.10 | `preprocessing_version` string defined and stored | ☑ |
| 1.11 | `IntakeOcrAttemptRecorder` saves attempt per intake | ☑ |
| 1.12 | `raw_ocr_text` set once at intake insert (worker) | ☑ |
| 1.13 | Skip ensemble for bulk `input_type=text` | ☑ |
| 1.14 | Skip re-ensemble on duplicate file reuse (`REUSED_TRANSCRIPT`) | ☑ |
| 1.15 | Ensemble completes **before** `ParseIntakeJob` dispatch | ☑ |
| 1.16 | No changes to `BiodataParserService` / `ParseIntakeJob` logic | ☑ |

### Tests

| # | Item | Done |
|---|------|------|
| 1.T01 | Unit: flag off → legacy code path | ☑ |
| 1.T02 | Unit: OpenCV degrade when extension unavailable (mock) | ☑ |
| 1.T03 | Feature: bulk upload with flag on → `ocr_attempt` row exists | ☑ |
| 1.T04 | Feature: bulk upload with flag off → no regression | ☑ |
| 1.T05 | Feature: text-only bulk item skips ensemble | ☑ |
| 1.T06 | Feature: empty OCR still marks `empty_ocr_text` when text < 20 chars | ☑ |
| 1.T07 | Feature: duplicate file reuse skips re-ensemble OCR | ☑ |

### Rollback

| # | Item | Done |
|---|------|------|
| 1.R01 | Toggle flag `false` on staging → legacy behavior verified | ☑ |
| 1.R02 | Deploy revert removes flag code without DB migration rollback | ☑ |

### Production simulation

| # | Item | Done |
|---|------|------|
| 1.S01 | Upload 5 real biodata on staging (flag on) | ☑ |
| 1.S02 | Worker completes within 40s average (Tesseract-only) | ☑ |
| 1.S03 | `ocr_attempts` inspectable in DB | ☑ |

### Phase 1 freeze

| # | Item | Done |
|---|------|------|
| 1.F01 | All Phase 1 items checked | ☑ |
| 1.F02 | PR merged; tag/note in changelog | ☑ |

**Phase 1 status: FROZEN (2026-07-13)** — staging Batch #44 validated; ground truth Batch #43 (`approval_snapshot_json`). See `docs/OCR-ENSEMBLE-PHASE-1-RELEASE-NOTES.md`.

**PASS → Phase 2 only**

---

## Phase 2 — Benchmark + Second OCR (conditional)

### Benchmark (before integration code)

| # | Item | Done |
|---|------|------|
| 2.01 | 10-image technology check completed | ☑ |
| 2.02 | 50-image decision benchmark completed | N/A — no Stage A candidate qualified (+5pp); Stage B skipped per plan |
| 2.03 | Results in `docs/ocr-ensemble-benchmark-v1.md` | ☑ |
| 2.04 | Go/no-go decision recorded | ☑ NO-GO — Tesseract winner |

### Implementation (only if go)

| # | Item | Done |
|---|------|------|
| 2.05 | Python OCR sidecar deployed (staging) | N/A — NO-GO |
| 2.06 | HTTP client with timeout + health check | ☑ benchmark-only clients exist; production integration N/A |
| 2.07 | Second `ocr_attempt` row per intake | N/A — NO-GO |
| 2.08 | Sidecar down → Tesseract-only, job succeeds | N/A — NO-GO |
| 2.09 | Engine constant + display name configurable | N/A — NO-GO |

### Tests

| # | Item | Done |
|---|------|------|
| 2.T01 | Sidecar mock success → two attempts | ☐ |
| 2.T02 | Sidecar timeout → one attempt, warning log | ☐ |
| 2.T03 | Benchmark script reproducible | ☑ |

### Rollback

| # | Item | Done |
|---|------|------|
| 2.R01 | Disable sidecar URL → Tesseract-only | N/A — NO-GO |

### Phase 2 freeze

| # | Item | Done |
|---|------|------|
| 2.F01 | Benchmark doc complete | ☑ |
| 2.F02 | Integration items checked OR no-go documented | ☑ NO-GO documented |

**Phase 2 status: FROZEN (2026-07-13)** — Tesseract baseline winner; Paddle benchmark incomplete (excluded); EasyOCR NO-GO (−18.75pp). See `docs/ocr-ensemble-benchmark-v1.md`.

**PASS → Phase 3 only**

---

## Phase 3 — Field Extract, Validators, Vote, Parse Input

### Implementation

| # | Item | Done |
|---|------|------|
| 3.01 | `field_resolution_json` storage (per v1.1 decision) | ☐ |
| 3.02 | Field extractor: 16 fields (blueprint list) | ☐ |
| 3.03 | Shared regex/helpers from parser (no duplicate fork) | ☐ |
| 3.04 | Per-field normalize → vote → validator | ☐ |
| 3.05 | Single-engine mode works (pass-through) | ☐ |
| 3.06 | Assemble `last_parse_input_text` | ☐ |
| 3.07 | `ParseIntakeJob` uses assembled input | ☐ |
| 3.08 | Gender missing → empty, no error | ☐ |
| 3.09 | Income soft validator (no hard fail) | ☐ |
| 3.10 | Paragraph fields not voted | ☐ |

### Tests

| # | Item | Done |
|---|------|------|
| 3.T01 | Unit: each field validator (mobile, DOB, religion) | ☐ |
| 3.T02 | Unit: vote tie-break rules | ☐ |
| 3.T03 | Feature: #735 ground truth fields ≥ target accuracy on extract | ☐ |
| 3.T04 | Feature: `parsed_json` populated after parse | ☐ |
| 3.T05 | Regression: flag off unchanged | ☐ |

### Phase 3 freeze

| # | Item | Done |
|---|------|------|
| 3.F01 | Ground truth 10-image extract score recorded | ☐ |
| 3.F02 | All items checked | ☐ |

**PASS → Phase 4 only**

---

## Phase 4 — Sarvam Judge

### Implementation

| # | Item | Done |
|---|------|------|
| 4.01 | Trigger: name conflict | ✅ |
| 4.02 | Trigger: DOB missing | ✅ |
| 4.03 | Trigger: mobile missing | ✅ |
| 4.04 | Trigger: religion missing | ✅ |
| 4.05 | Gender missing does NOT trigger | ✅ |
| 4.06 | Sarvam attempt saved | ☐ (deferred — see Phase 4 validation B1) |
| 4.07 | Sarvam failure non-fatal (soft-fail) | ✅ |
| 4.08 | Re-merge affected fields into `field_resolution_json` | ✅ |
| 4.09 | Rebuild `last_parse_input_text` when merge changes (existing assembler; ParseIntakeJob unchanged) | ✅ |
| 4.10 | Trigger rate telemetry | ☐ (logs only; 4.F01 pending) |
| 4.11 | Request builder (deterministic) | ✅ |
| 4.12 | HTTP client + retry + response DTOs | ✅ |
| 4.13 | Orchestration + quality gate + persist (4f) | ✅ |

### Tests

| # | Item | Done |
|---|------|------|
| 4.T01 | Synthetic: DOB missing → Sarvam called (mock) | ✅ |
| 4.T02 | Synthetic: all fields OK → Sarvam not called | ✅ |
| 4.T03 | Synthetic: gender only missing → Sarvam not called | ✅ |
| 4.T04 | Synthetic: Sarvam API error → intake not failed | ✅ |
| 4.T05 | Merge no-op / quality gate / raw_ocr immutability / bulk hook | ✅ |

### Phase 4 freeze

| # | Item | Done |
|---|------|------|
| 4.F00 | Implementation freeze 4a–4f + validation doc | ✅ (2026-07-14) — see `OCR-ENSEMBLE-PHASE-4-VALIDATION-AND-ROLLOUT.md` |
| 4.F01 | Sarvam trigger rate on 50-image set ≤ 20% | ☐ |
| 4.F02 | Staging live Sarvam drill signed off | ☐ |
| 4.F03 | Decide/implement `ocr_attempt` save (B1) before Phase 5 UI | ☐ |

**Automated suite at freeze:** 113 passed / 486 assertions (Phase 4 + Phase 3 ensemble regression).

**PASS → Phase 5 only** after 4.F00 accepted **and** product clears B1/B5; keep production flags off until 4.F01–4.F02.

---

## Phase 5 — Admin Comparison UI

### Implementation

| # | Item | Done |
|---|------|------|
| 5.01 | Table on `correct-candidate` only | ☐ |
| 5.02 | Columns: Field, Final, Tesseract, Second OCR, Sarvam, Reason | ☐ |
| 5.03 | NOT on bulk list / intake list | ☐ |
| 5.04 | Bulk list: status badge only (`ocr_ensemble_processing` / `ocr_ready`) | ☐ |
| 5.05 | Legacy intake empty state | ☐ |
| 5.06 | No regression on correction save | ☐ |

### Tests

| # | Item | Done |
|---|------|------|
| 5.T01 | Feature test: table renders for ensemble intake | ☐ |
| 5.T02 | Feature test: table absent on bulk index | ☐ |
| 5.T03 | Manual: admin can read Reason column | ☐ |

### Phase 5 freeze

| # | Item | Done |
|---|------|------|
| 5.F01 | All items checked | ☐ |
| 5.F02 | **Program v1.0 complete** — production flag rollout plan approved | ☐ |

---

## Post-v1.0 (not in scope until Phase 5 frozen)

- Weight learning  
- Layout detection  
- Marathi label normalizer  
- LLM text cleanup  
- EasyOCR without new benchmark  

---

## Document history

| Version | Date | Change |
|---------|------|--------|
| 1.0 | 2026-07-12 | Initial checklist |
| 1.1 | 2026-07-13 | Phase 1 frozen — Batch #44 staging PASS |
