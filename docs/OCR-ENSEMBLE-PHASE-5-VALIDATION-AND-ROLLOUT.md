# OCR Ensemble Phase 5 — Validation, Acceptance & Rollout Plan

> **Step:** Phase 5g — validation & documentation only (no application code changes)  
> **Repository:** `laravel-matrimony`  
> **Date:** 2026-07-14  
> **Prerequisite:** Phase 5a–5f implemented (foundation → evidence → table → orchestration → admin route → review UI)  
> **Automated evidence:** `php artisan test --filter=OcrEnsemblePhase` → **180 passed / 980 assertions** (2026-07-14)  
> **Final verdict:** **READY FOR STAGING** (not production)

---

## 1. Executive summary

Phase 5 delivers a **read-only** OCR comparison review path for administrators:

```
IntakeOcrEnsemblePhase5Service
  → OcrEnsembleComparisonEvidenceLoader
  → OcrEnsembleComparisonTableBuilder
  → Phase5ComparisonResult
  → AdminIntakeOcrComparisonController
  → Blade comparison table (16 canonical fields)
```

| Area | Status | Notes |
|------|--------|-------|
| Phase 5a–5f implementation | **Complete** | Constants, DTOs, loader, builder, orchestrator, admin route, UI |
| Phase 1–4 + 4.5 pipeline | **Intact** | Ensemble OCR → Phase 3 resolve → Phase 4 judge → parse queue |
| Automated tests | **180 passed / 980 assertions** | Full `OcrEnsemblePhase*` suite |
| Feature gates | **Verified** | Master AdminSetting + per-phase config |
| `raw_ocr_text` immutability | **Verified** | Phase 3/4/4.5 tests; Phase 5 is read-only |
| Comparison integrity | **Verified** | Missing engines → empty columns; FR = final/reason SSOT |
| Admin authorization | **Verified** | `auth` + `admin` + `admin.section`; non-admin 403 |
| Blueprint UI placement | **Drift** | Table lives on `biodata-intakes/{id}/ocr-comparison`, **not** yet on `correct-candidate` |
| Bulk list status badges | **Incomplete** | Meta written (`ocr_ensemble_status`); bulk Blade does not surface Phase 5 badges |
| Live Sarvam / trigger-rate | **Open** | Staging ops drills still pending (carried from Phase 4) |
| Production enable | **Blocked** | Keep ensemble flags **off** until staging acceptance |

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

Admin (read-only, separate request):
  GET /admin/biodata-intakes/{intake}/ocr-comparison
      → IntakeOcrEnsemblePhase5Service → comparison table UI
```

### 2.2 Phase freeze status

| Phase | Deliverable | Freeze posture |
|-------|-------------|----------------|
| **1** | Flag, OpenCV minimal, Tesseract multipass, `ocr_attempts`, bulk meta | Frozen (staging history) |
| **2** | Second OCR benchmark | **NO-GO freeze** — Tesseract-only; second column stays empty in v1 |
| **3** | Extract → normalize → vote → validate → assemble → persist envelope | Frozen |
| **4** | Sarvam triggers → request → client → merge → quality gate → persist | Frozen (4a–4f) |
| **4.5** | B1 Sarvam `ocr_attempt` append; B2 retry resume when FR missing | Hardened + tested |
| **5** | Comparison service + admin review UI | Implementation complete; validation = this doc |

### 2.3 Phase 5 internal architecture (unchanged by 5g)

| Step | Class | Writes DB? |
|------|-------|------------|
| Gate + eligibility | `IntakeOcrEnsemblePhase5Service` | No |
| Load evidence | `OcrEnsembleComparisonEvidenceLoader` | No |
| Build rows | `OcrEnsembleComparisonTableBuilder` | No |
| Outcome | `Phase5ComparisonResult` (`skipped` / `empty` / `resolved`) | No |
| HTTP | `AdminIntakeOcrComparisonController` | No |
| View | `admin.intake.ocr-comparison` (+ table partial) | No |

### 2.4 Feature gates (verified)

| Gate | Condition |
|------|-----------|
| Master | `AdminSetting` `intake_ocr_ensemble_enabled` (default **false**) |
| Phase 3 | Master ∧ `ocr.ensemble.phase3.enabled` |
| Phase 4 | Phase 3 ∧ `ocr.ensemble.phase4.enabled` |
| Phase 5 | Master ∧ `ocr.ensemble.phase5.enabled` (**does not** require Phase 4) |

Phase 5 skip reason when disabled: `phase5_gate_disabled`.

### 2.5 Persistence / lifecycle matrix

| Artifact | Written by | Read by Phase 5 | Mutated by Phase 5? |
|----------|------------|-----------------|---------------------|
| `raw_ocr_text` | OCR / intake creation | Indirect (attempts only) | **Never** |
| `biodata_intake_ocr_attempts` | Phase 1; Phase 4.5 Sarvam append | EvidenceLoader | **Never** (append-only elsewhere) |
| `field_resolution_json` | Phase 3; Phase 4 merge | EvidenceLoader → finals/reasons/candidates | **Never** |
| `last_parse_input_text` | Phase 3; Phase 4 merge | Not required for table columns | **Never** |
| `parsed_json` | Parser / `ParseIntakeJob` | No | **Never** |

### 2.6 Retry safety (Phase 4.5 — verified)

| Scenario | Behavior |
|----------|----------|
| Intake linked, FR missing | Resume Phase 3 then Phase 4 before parse |
| FR already present | Skip re-resolve / re-judge on retry |
| Sarvam HTTP soft-fail | No Sarvam attempt row; Phase 3 data preserved |
| Sarvam success | Append-only `sarvam_ai_vision` attempt (`is_primary=false`) |

### 2.7 Comparison evidence integrity (verified)

| Rule | Implementation |
|------|----------------|
| Missing engine | Explicit empty engine slot; column shows `—` |
| Final / reason | Authoritative from `field_resolution_json` |
| Engine columns gated | Present attempt required before candidate display |
| Row order | `OcrEnsemblePhase3Constants::STRUCTURED_FIELDS` (16) |
| No vote/OCR/Sarvam in Phase 5 | Loader + builder + UI are read-only |

### 2.8 Admin authorization (verified)

| Case | Result |
|------|--------|
| Super/admin under `auth`+`admin`+`admin.section` | 200 |
| Non-admin member | 403 |
| Missing intake | 404 |
| Gate disabled | 200 + outcome `skipped` (page explains unavailability) |

Route section maps under Intake & OCR via `admin.biodata-intakes.*`.

### 2.9 Scope boundaries (Phase 5)

| In scope (5a–5f) | Out of scope / remaining |
|------------------|--------------------------|
| Comparison DTOs + loader + builder + orchestrator | Embedding table on `correct-candidate` |
| Admin GET route + read-only Blade table | Bulk list `ocr_ready` badges (Blade display) |
| Status/source badges; empty/legacy states | Edit / save / AJAX / Livewire |
| Unit + feature tests | Live Sarvam staging trigger-rate ≤20% |
| | Second OCR production engine |

---

## 3. Validation matrix (5g audit)

| Concern | Result | Evidence |
|---------|--------|----------|
| Feature gates | **Pass** | Gate unit tests Phase 3/4/5; admin skip outcome |
| Retry safety | **Pass** | `OcrEnsemblePhase45HardeningTest` |
| `raw_ocr_text` immutability | **Pass** | Phase 3 resolve, Phase 4 judge, Phase 4.5 tests |
| `field_resolution_json` lifecycle | **Pass** | Phase 3 persist; Phase 4 merge; Phase 5 read-only |
| `last_parse_input_text` lifecycle | **Pass** | Phase 3/4 persist; Parse path prefers assembled text |
| `ocr_attempt` evidence | **Pass** | Phase 1 + Phase 4.5 Sarvam append-only |
| Comparison evidence integrity | **Pass** | EvidenceLoader + TableBuilder + UI tests |
| Deterministic row order | **Pass** | TableBuilder + ComparisonUiTest |
| Admin authorization | **Pass** | `OcrEnsemblePhase5AdminIntegrationTest` |
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
| 5.01 | Table on **`correct-candidate` only** (blueprint §7.1) | ❌ **open** — currently `biodata-intakes.ocr-comparison` |
| 5.02 | Columns: Field, Final, Tesseract, Second OCR, Sarvam, Reason | ✅ (+ Status/Source badge columns for operator clarity) |
| 5.03 | Not on bulk dense list / intake index | ✅ |
| 5.04 | Bulk list status badge (`ocr_ensemble_processing` / `ocr_ready`) | ❌ **open** — meta exists; Blade display not confirmed |
| 5.05 | Legacy / ensemble-not-run empty state | ✅ |
| 5.06 | No regression on correction save | ⚠️ **manual/staging** — Phase 5 route is separate; correction form untouched by code |

### Automated tests (Phase 5)

| # | Item | Done |
|---|------|------|
| 5.T01 | Foundation / gate / DTO round-trips | ✅ |
| 5.T02 | EvidenceLoader engine slots | ✅ |
| 5.T03 | TableBuilder rows + order | ✅ |
| 5.T04 | Orchestration outcomes | ✅ |
| 5.T05 | Admin auth + skip/empty/resolved | ✅ |
| 5.T06 | Comparison UI badges + determinism | ✅ |

---

## 5. Regression checklist

Run before staging flag enable:

| # | Check | Pass criteria |
|---|-------|---------------|
| R-01 | `php artisan test --filter=OcrEnsemblePhase` | All green (baseline: 180 / 980) |
| R-02 | Ensemble flag **off** bulk file upload | Legacy path; no new FR / Phase 4/5 behavior |
| R-03 | Ensemble flag **on**, Phase 3/4 config on | FR + parse input; soft-fail preserves Phase 3 |
| R-04 | `raw_ocr_text` spot-check on 3 intakes after judge | Byte-identical before/after |
| R-05 | Existing OCR attempts unchanged after Sarvam append | Only new row added |
| R-06 | Admin non-privileged user hits comparison URL | 403 |
| R-07 | Correction save on `correct-candidate` | Unchanged behavior (smoke) |
| R-08 | ParseIntakeJob still prefers `last_parse_input_text` when set | Parse success; no queue contract change |

---

## 6. Production rollout checklist

| # | Step | Owner | Status |
|---|------|-------|--------|
| RO1 | Keep `intake_ocr_ensemble_enabled=false` in production until RO7 | Ops | ☐ |
| RO2 | Deploy code with Phase 5 UI + Phase 4.5 (flags still off) | DevOps | ☐ |
| RO3 | Staging: enable master + phase3 + phase4 + phase5 | Ops | ☐ |
| RO4 | Staging: 10–20 real biodata files through bulk | QA | ☐ |
| RO5 | Staging: open comparison URLs; verify 16 rows, badges, empty engines | QA | ☐ |
| RO6 | Staging: live Sarvam drill; record trigger rate (target ≤20%) | QA | ☐ |
| RO7 | Product sign-off: surface placement (`correct-candidate` vs intake route) | Product | ☐ |
| RO8 | Product sign-off: production enable window | Product | ☐ |
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
| UI | Comparison page shows `skipped` when Phase 5 gated off |

---

## 8. Acceptance checklist

| # | Acceptance item | Status |
|---|-----------------|--------|
| A1 | Phase 5a–5f complete behind gates | ✅ |
| A2 | Automated Phase suite green | ✅ 180 / 980 |
| A3 | Read-only comparison; no edit/save | ✅ |
| A4 | Missing engines render empty | ✅ |
| A5 | Deterministic 16-field order | ✅ |
| A6 | Admin auth enforced | ✅ |
| A7 | SSOT: no `raw_ocr_text` mutation from Phase 5 | ✅ |
| A8 | Blueprint §7.1 `correct-candidate`-only placement | ❌ open |
| A9 | Bulk list status-only badges visible | ❌ open |
| A10 | Staging live Sarvam + trigger-rate signed | ❌ open |
| A11 | Production flag enable approved | ❌ open |

---

## 9. Remaining blockers

| ID | Severity | Blocker | Needed for |
|----|----------|---------|------------|
| **P5-B1** | Medium (blueprint) | Comparison UI not on `correct-candidate`; currently intake route | Blueprint §7.1 completion / product exception |
| **P5-B2** | Low–Medium | Bulk list does not display `ocr_ensemble_processing` / `ocr_ready` badges | Checklist 5.04 / operator UX |
| **P5-B3** | High (ops) | Live Sarvam staging drill + trigger-rate ≤20% (Phase 4.F01/F02) | Production Phase 4 enable |
| **P5-B4** | Medium | Formal product go/no-go for progressive production flags | Production enable |
| **P5-B5** | Informational | Second OCR still NO-GO — Sarvam/Second columns often empty by design | Expectations / not a defect |

**Closed since Phase 4 validation (for reference):**

| ID | Resolution |
|----|------------|
| Former B1 (`ocr_attempt` for Sarvam) | Closed in Phase 4.5 — append-only `sarvam_ai_vision` on successful judge |
| Former B2 (retry without FR) | Closed in Phase 4.5 — resume Phase 3/4 when envelope missing |

---

## 10. Final verdict

# READY FOR STAGING

### Precise reasons

1. **Code-complete through Phase 5 UI** with read-only architecture matching Phase 5 contracts (service → evidence → table → result → Blade).
2. **Automated suite green:** `OcrEnsemblePhase*` = **180 passed / 980 assertions**, including Phase 4.5 hardening and Phase 5 admin/UI coverage.
3. **SSOT & safety gates verified:** feature flags, soft-fail, append-only OCR attempts, `raw_ocr_text` immutability, Phase 5 zero writes.
4. **Not READY FOR PRODUCTION** because: production flags must remain off; live Sarvam/trigger-rate drills unfinished; blueprint placement (`correct-candidate`) and bulk status badge display remain open; product rollout approval pending.
5. **Not NOT READY** because: no architectural blockers prevent staging enable of master+phase flags for controlled validation; known gaps are product/placement/ops, not broken core pipeline.

### Staging posture

- Enable on **staging only**: master + phase3 + phase4 + phase5 as needed.
- Use intake comparison URL for operator review until P5-B1 resolved.
- Treat empty Second OCR as expected under Phase 2 NO-GO.
- Measure Sarvam trigger rate before any production Phase 4 enable.

### Explicit non-goals of this step

- No application code changes  
- No commit  
- No Phase 6  

---

## 11. Document history

| Version | Date | Change |
|---------|------|--------|
| 1.0 | 2026-07-14 | Phase 5g validation & rollout — READY FOR STAGING |
