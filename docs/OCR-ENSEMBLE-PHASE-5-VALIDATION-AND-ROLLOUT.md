# OCR Ensemble Phase 5 — Validation, Acceptance & Rollout Plan

> **Step:** Phase 5g + **v1.0 freeze review** (documentation only — no application code changes)  
> **Repository:** `laravel-matrimony`  
> **Date:** 2026-07-14  
> **Prerequisite:** Phase 5a–5f implemented; **P5-B1** and **P5-B2** closed  
> **Automated evidence:** `php artisan test --filter=OcrEnsemblePhase` → **196 passed / 1032 assertions** (2026-07-14)  
> **Final verdict:** **READY FOR STAGING** — **NOT READY FOR PRODUCTION**

---

## 1. Executive summary

Phase 5 delivers a **read-only** OCR comparison review path for administrators, embedded on **Correct Candidate**, plus compact **Bulk Intake list** status badges derived from existing metadata only.

```
IntakeOcrEnsemblePhase5Service
  → OcrEnsembleComparisonEvidenceLoader
  → OcrEnsembleComparisonTableBuilder
  → Phase5ComparisonResult
  → AdminBulkIntakeController::correctCandidateForm
  → correct-candidate Blade (+ ocr-comparison-review-panel)

OcrEnsembleBulkListBadgePresenter (read-only)
  → AdminBulkIntakeController::show
  → dense-item-row badges
```

| Area | Status | Notes |
|------|--------|-------|
| Phase 5a–5f implementation | **Complete** | Constants, DTOs, loader, builder, orchestrator, admin wiring, UI |
| P5-B1 Correct Candidate placement | **Closed** | Comparison panel on `correct-candidate`; standalone route redirects |
| P5-B2 Bulk list status badges | **Closed** | Presenter + dense list chips from existing meta only |
| Phase 1–4 + 4.5 pipeline | **Intact** | Ensemble OCR → Phase 3 resolve → Phase 4 judge → parse queue |
| Automated tests | **196 passed / 1032 assertions** | Full `OcrEnsemblePhase*` suite |
| Feature gates | **Verified** | Master AdminSetting + per-phase config |
| `raw_ocr_text` immutability | **Verified** | Phase 3/4/4.5 tests; Phase 5 is read-only |
| Comparison integrity | **Verified** | Missing engines → empty columns; FR = final/reason SSOT |
| Admin authorization | **Verified** | `auth` + `admin` + `admin.section`; non-admin 403 |
| Live Sarvam / trigger-rate | **Open (ops)** | Staging drills still pending (P5-B3 / 4.F01–4.F02) |
| Production enable | **Blocked (ops/product)** | Keep ensemble flags **off** until staging acceptance + sign-off (P5-B4) |

**Production default remains:** `intake_ocr_ensemble_enabled = false`

---

## 2. Full pipeline architectural audit

### 2.1 End-to-end production path (bulk file, flag on)

```
ProcessBulkIntakeBatchItemJob
  → BulkIntakeBatchService::processPendingItem()
      → Phase 1 OCR ensemble (gated) → ocr_attempts + item_meta ocr_ensemble_*
      → IntakeOcrEnsemblePhase3Service   → field_resolution_json + last_parse_input_text
      → IntakeOcrEnsemblePhase4Service   → optional Sarvam merge + (4.5) sarvam ocr_attempt
      → ParseIntakeJob                  → prefers last_parse_input_text when present

Admin (read-only):
  GET …/bulk-intakes/{batch}/items/{item}/correct-candidate
      → IntakeOcrEnsemblePhase5Service → comparison panel on Correct Candidate

  GET …/biodata-intakes/{intake}/ocr-comparison
      → redirects to Correct Candidate when a bulk item exists (else 404)

Bulk list (read-only badges):
  GET …/bulk-intakes/{batch}
      → OcrEnsembleBulkListBadgePresenter from item_meta + FR + ocr_attempts
```

### 2.2 Phase freeze status

| Phase | Deliverable | Freeze posture |
|-------|-------------|----------------|
| **1** | Flag, OpenCV minimal, Tesseract multipass, `ocr_attempts`, bulk meta | **FROZEN** |
| **2** | Second OCR benchmark | **NO-GO FROZEN** — Tesseract-only; Second OCR column often empty by design |
| **3** | Extract → normalize → vote → validate → assemble → persist envelope | **Implementation FROZEN**; ground-truth 3.F01 remains **ops** |
| **4** | Sarvam triggers → request → client → merge → quality gate → persist | **Implementation FROZEN**; 4.F01–4.F02 remain **ops** |
| **4.5** | B1 Sarvam `ocr_attempt` append; B2 retry resume when FR missing | **Hardened + tested** |
| **5** | Comparison on Correct Candidate + bulk list badges | **Implementation FROZEN** (P5-B1 / P5-B2 closed) |

### 2.3 Phase 5 internal architecture

| Step | Class | Writes DB? |
|------|-------|------------|
| Gate + eligibility | `IntakeOcrEnsemblePhase5Service` | No |
| Load evidence | `OcrEnsembleComparisonEvidenceLoader` | No |
| Build rows | `OcrEnsembleComparisonTableBuilder` | No |
| Outcome | `Phase5ComparisonResult` (`skipped` / `empty` / `resolved`) | No |
| Correct Candidate HTTP | `AdminBulkIntakeController::correctCandidateForm` | No (read path) |
| Standalone redirect | `AdminIntakeOcrComparisonController` | No |
| Bulk list badges | `OcrEnsembleBulkListBadgePresenter` | No |
| View | `correct-candidate` + review panel; dense-item-row badges | No |

### 2.4 Feature gates (verified)

| Gate | Condition |
|------|-----------|
| Master | `AdminSetting` `intake_ocr_ensemble_enabled` (default **false**) |
| Phase 3 | Master ∧ `ocr.ensemble.phase3.enabled` |
| Phase 4 | Phase 3 ∧ `ocr.ensemble.phase4.enabled` |
| Phase 5 | Master ∧ `ocr.ensemble.phase5.enabled` (**does not** require Phase 4) |

Phase 5 skip reason when disabled: `phase5_gate_disabled`.

### 2.5 Persistence / lifecycle matrix

| Artifact | Written by | Read by Phase 5 / badges | Mutated by Phase 5? |
|----------|------------|--------------------------|---------------------|
| `raw_ocr_text` | OCR / intake creation | Badge presenter (empty vs legacy) | **Never** |
| `biodata_intake_ocr_attempts` | Phase 1; Phase 4.5 Sarvam append | EvidenceLoader; badge Sarvam evidence | **Never** |
| `field_resolution_json` | Phase 3; Phase 4 merge | EvidenceLoader; badge Phase 3 / Comparison Ready / Sarvam | **Never** |
| `last_parse_input_text` | Phase 3; Phase 4 merge | Eager-loaded for list context; not required for table columns | **Never** |
| `item_meta_json.ocr_ensemble_status` | Phase 1 worker | Badge OCR Complete / Awaiting Review | **Never** (Phase 5) |

### 2.6 Retry safety (Phase 4.5 — verified)

| Case | Behavior |
|------|----------|
| Intake linked, FR missing | Resume Phase 3 then Phase 4 before parse |
| Sarvam HTTP soft-fail | No Sarvam attempt row; Phase 3 data preserved |

### 2.7 Scope boundaries (Phase 5)

| In | Out |
|----|-----|
| Read-only comparison on Correct Candidate | Edit/save from comparison table |
| Bulk list status badges only | Full comparison table on bulk dense list |
| Redirect from legacy standalone comparison URL | New APIs / persistence / Phase 6 |

---

## 3. Automated verification summary

| Gate | Result | Evidence |
|------|--------|----------|
| Feature gates | **Pass** | Gate unit tests Phase 3/4/5; admin skip outcome |
| `raw_ocr_text` immutability | **Pass** | Phase 3 resolve, Phase 4 judge, Phase 4.5 tests |
| `field_resolution_json` lifecycle | **Pass** | Phase 3 persist; Phase 4 merge; Phase 5 read-only |
| `last_parse_input_text` lifecycle | **Pass** | Phase 3/4 persist; Parse path prefers assembled text |
| `ocr_attempt` evidence | **Pass** | Phase 1 + Phase 4.5 Sarvam append-only |
| Correct Candidate placement | **Pass** | `OcrEnsemblePhase5CorrectCandidateComparisonTest` |
| Bulk list badges | **Pass** | `OcrEnsemblePhase5BulkListBadgesTest` |
| Rollback behavior | **Pass (design)** | Master flag / phase config off; Phase 5 writes nothing |

---

## 4. Implementation checklist (Phase 5)

| # | Item | Done |
|---|------|------|
| 5.A | Constants + DTOs + interfaces + DI (5a) | ✅ |
| 5.B | EvidenceLoader (5b) | ✅ |
| 5.C | TableBuilder (5c) | ✅ |
| 5.D | Phase5Service orchestration (5d) | ✅ |
| 5.E | Admin route + controller + auth (5e) | ✅ |
| 5.F | Read-only comparison UI (5f) | ✅ |
| 5.G | Validation + rollout documentation (5g) | ✅ |
| 5.01 | Table on **`correct-candidate` only** (blueprint §7.1) | ✅ **P5-B1 closed** |
| 5.02 | Columns: Field, Final, Tesseract, Second OCR, Sarvam, Reason | ✅ (+ Status/Source badge columns) |
| 5.03 | Not on bulk dense list / intake index | ✅ |
| 5.04 | Bulk list status badge (`ocr_ensemble_processing` / `ocr_ready` + related) | ✅ **P5-B2 closed** |
| 5.05 | Legacy / ensemble-not-run empty state | ✅ |
| 5.06 | No regression on correction save | ⚠️ **manual/staging** — form unchanged; smoke still ops |

### Automated tests (Phase 5)

| # | Item | Done |
|---|------|------|
| 5.T01 | Foundation / gate / DTO round-trips | ✅ |
| 5.T02 | EvidenceLoader engine slots | ✅ |
| 5.T03 | TableBuilder rows + order | ✅ |
| 5.T04 | Orchestration outcomes | ✅ |
| 5.T05 | Admin auth + skip/empty/resolved (+ redirect) | ✅ |
| 5.T06 | Comparison UI badges + determinism | ✅ |
| 5.T07 | Correct Candidate embedding (P5-B1) | ✅ |
| 5.T08 | Bulk list badge presenter + HTML (P5-B2) | ✅ |

---

## 5. Regression checklist

Run before staging flag enable:

| # | Check | Pass criteria |
|---|-------|---------------|
| R-01 | `php artisan test --filter=OcrEnsemblePhase` | All green (baseline: **196 / 1032**) |
| R-02 | Ensemble flag **off** bulk file upload | Legacy path; no new FR / Phase 4/5 behavior |
| R-03 | Ensemble flag **on**, Phase 3/4 config on | FR + parse input; soft-fail preserves Phase 3 |
| R-04 | `raw_ocr_text` spot-check on 3 intakes after judge | Byte-identical before/after |
| R-05 | Existing OCR attempts unchanged after Sarvam append | Only new row added |
| R-06 | Admin non-privileged user hits Correct Candidate / redirect | 403 |
| R-07 | Correction save on `correct-candidate` | Unchanged behavior (smoke) |
| R-08 | ParseIntakeJob still prefers `last_parse_input_text` when set | Parse success; no queue contract change |
| R-09 | Bulk list shows ensemble badges | OCR Complete / Phase 3 / Legacy / No OCR as applicable |

---

## 6. Production rollout checklist

| # | Step | Owner | Status |
|---|------|-------|--------|
| RO1 | Keep `intake_ocr_ensemble_enabled=false` in production until RO8 | Ops | ☐ |
| RO2 | Deploy code with Phase 5 UI + Phase 4.5 (flags still off) | DevOps | ☐ |
| RO3 | Staging: enable master + phase3 + phase4 + phase5 | Ops | ☐ |
| RO4 | Staging: 10–20 real biodata files through bulk | QA | ☐ |
| RO5 | Staging: open Correct Candidate; verify 16 rows, badges, empty Second OCR | QA | ☐ |
| RO6 | Staging: live Sarvam drill; record trigger rate (target ≤20%) | QA | ☐ **P5-B3** |
| RO7 | Staging: correction save smoke (5.06) | QA | ☐ |
| RO8 | Product sign-off: production enable window | Product | ☐ **P5-B4** |
| RO9 | Production: enable gates progressively (master → phase3 → phase4 → phase5) | Ops | ☐ |
| RO10 | Monitor `phase3_*` / `phase4_*` logs + soft-fail rates 48h | Ops | ☐ |

---

## 7. Rollback checklist

| Layer | Action |
|-------|--------|
| Instant (Phase 5 UI/read) | `OCR_ENSEMBLE_PHASE5_ENABLED=false` **or** master ensemble off |
| Instant (judge) | `OCR_ENSEMBLE_PHASE4_ENABLED=false` |
| Instant (full ensemble) | `intake_ocr_ensemble_enabled=false` |
| Behavior | Phase 5 skips; Phase 4 skips; Phase 3 optional; legacy OCR/parse continues |
| Data | Phase 5 wrote **nothing**; Phase 4 writes only FR + parse input (+ append-only Sarvam attempts) |
| Code / DB | No destructive migration required for rollback |
| UI | Comparison panel shows `skipped` when Phase 5 gated off; badges still reflect stored meta |

---

## 8. Acceptance checklist

| # | Acceptance item | Status |
|---|-----------------|--------|
| A1 | Phase 5a–5f complete behind gates | ✅ |
| A2 | Automated Phase suite green | ✅ **196 / 1032** |
| A3 | Read-only comparison; no edit/save | ✅ |
| A4 | Missing engines render empty | ✅ |
| A5 | Deterministic 16-field order | ✅ |
| A6 | Admin auth enforced | ✅ |
| A7 | SSOT: no `raw_ocr_text` mutation from Phase 5 | ✅ |
| A8 | Blueprint §7.1 `correct-candidate` placement | ✅ **P5-B1** |
| A9 | Bulk list status-only badges visible | ✅ **P5-B2** |
| A10 | Staging live Sarvam + trigger-rate signed | ❌ **ops** (P5-B3) |
| A11 | Production flag enable approved | ❌ **ops/product** (P5-B4) |

---

## 9. Remaining blockers

| ID | Severity | Status | Blocker | Needed for |
|----|----------|--------|---------|------------|
| **P5-B1** | — | **CLOSED** | Comparison UI on Correct Candidate | Blueprint §7.1 |
| **P5-B2** | — | **CLOSED** | Bulk list ensemble status badges | Checklist 5.04 |
| **P5-B3** | High (ops) | **Open** | Live Sarvam staging drill + trigger-rate ≤20% (4.F01/F02) | Production Phase 4 enable |
| **P5-B4** | Medium | **Open** | Formal product go/no-go for progressive production flags | Production enable |
| **P5-B5** | Informational | **Documented** | Second OCR still NO-GO — Second column often empty by design | Expectations / not a defect |

**Also documented (ops, not application gaps):**

| ID | Notes |
|----|-------|
| **3.F01** | Ground-truth 10-image extract score recording (Phase 3 validation) |
| **5.06** | Correction-save staging smoke |

**Closed since Phase 4 validation (for reference):**

| ID | Resolution |
|----|------------|
| Former B1 (`ocr_attempt` for Sarvam) | Closed in Phase 4.5 — append-only `sarvam_ai_vision` on successful judge |
| Former B2 (retry without FR) | Closed in Phase 4.5 — resume Phase 3/4 when envelope missing |

---

## 10. Final verdict (v1.0 freeze review)

# READY FOR STAGING

**Not** READY FOR PRODUCTION.  
**Not** NOT READY (for staging).

### Precise reasons

1. **Application code complete through Phase 1–5** for v1.0 scope: OCR → Phase 3 → Phase 4 → Phase 5 Correct Candidate comparison + bulk badges.
2. **Blueprint Phase 5 acceptance items for UI placement and bulk badges are satisfied** (P5-B1, P5-B2 closed).
3. **Automated suite green:** `OcrEnsemblePhase*` = **196 passed / 1032 assertions**.
4. **SSOT & safety gates verified:** feature flags, soft-fail, append-only OCR attempts, `raw_ocr_text` immutability, Phase 5 zero writes.
5. **Not READY FOR PRODUCTION** because remaining blockers are **operational / product**: live Sarvam + trigger-rate (P5-B3), production go/no-go (P5-B4), staging drills (RO3–RO7), ground-truth scoring (3.F01). Production flags must stay **off**.
6. **Phase 6 is out of scope** for this freeze.

### Staging posture

- Enable on **staging only**: master + phase3 + phase4 + phase5 as needed.
- Review comparison **on Correct Candidate**.
- Confirm bulk list badges for ensemble vs legacy rows.
- Treat empty Second OCR as expected under Phase 2 NO-GO.
- Measure Sarvam trigger rate before any production Phase 4 enable.

### Explicit non-goals of this freeze review

- No application code changes  
- No commit  
- No Phase 6  
- No production flag enable  

---

## 11. Document history

| Version | Date | Change |
|---------|------|--------|
| 1.0 | 2026-07-14 | Phase 5g validation & rollout — READY FOR STAGING |
| 1.1 | 2026-07-14 | v1.0 freeze review — P5-B1/B2 closed; suite 196/1032; still READY FOR STAGING / NOT production |
