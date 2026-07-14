# OCR Ensemble Phase 4 — Validation, Acceptance & Rollout Plan

> **Step:** Phase 4 validation & freeze (docs + test run only — no application code changes)  
> **Repository:** `laravel-matrimony`  
> **Date:** 2026-07-14  
> **Prerequisite:** Phase 4a–4f implemented and reviewed  
> **Verdict:** **PASS for implementation freeze** — automated suite green; **CONDITIONAL** for staging/production Sarvam enable until operator drills and remaining contract gaps are closed.

---

## 1. Executive summary

Phase 4 implements the Sarvam judge path after Phase 3 field resolution:

**Trigger Evaluator → Request Builder → Sarvam Client → Merger → Assembler → Persist**

| Area | Status | Notes |
|------|--------|-------|
| Implementation (4a–4f) | **Complete** | Full orchestration wired into bulk processing |
| Automated tests | **113 passed** (486 assertions) | Phase 4 + Phase 3 regression suite |
| Feature gates | **Verified** | Ensemble AdminSetting + Phase 3 + Phase 4 config |
| Soft-fail | **Verified** | HTTP/config/exception → preserve Phase 3; never throw |
| SSOT safety | **Verified** | `raw_ocr_text` never mutated; parser/queue untouched |
| Benchmark isolation | **Verified** | No `OcrEnsembleBenchmark` imports in Phase 4 production files |
| Immutable DTOs | **Verified** | Request/response/merge/trigger reports are readonly |
| Contract gaps | **Open** | `ocr_attempt` save, trigger-rate telemetry, 50-image ≤20% drill |
| Production enable | **Blocked** | Keep flags off until staging acceptance |

**Production default remains:** `intake_ocr_ensemble_enabled = false`  
Phase 4 additionally requires `OCR_ENSEMBLE_PHASE4_ENABLED` (and Phase 3 enabled) when ensemble is on.

**Do not start Phase 5 until this freeze is explicitly accepted.**

---

## 2. Complete pipeline review

### 2.1 Production entry path (bulk file upload)

```
ProcessBulkIntakeBatchItemJob
  → BulkIntakeBatchService::processPendingItem()
      → Phase 1 OCR ensemble (if gated)
      → IntakeOcrEnsemblePhase3Service::runForBulkItemIfApplicable()
      → IntakeOcrEnsemblePhase4Service::runForBulkItemIfApplicable()   [Phase 4]
      → queueAutoFreeParseAfterUploadForItem() / ParseIntakeJob
          → prefers last_parse_input_text when present (Phase 3f; unchanged by Phase 4)
```

Phase 4 runs **inline after Phase 3**, **before** parse dispatch. Queue contract unchanged.

### 2.2 Phase 4 internal pipeline

| Step | Class | Responsibility |
|------|-------|----------------|
| Gate + eligibility | `IntakeOcrEnsemblePhase4Service` | Ensemble + Phase3 + Phase4 flags; file items only |
| Load envelope | same | Read `field_resolution_json` (Phase 3 output) |
| Trigger | `OcrEnsembleSarvamJudgeTriggerEvaluator` | Blueprint §5.1 fields only |
| Request | `OcrEnsembleSarvamJudgeRequestBuilder` | Deterministic `SarvamJudgeRequest` |
| HTTP | `OcrEnsembleSarvamJudgeClient` | Sole network entry; retries; immutable `SarvamJudgeResponse` |
| Merge | `OcrEnsembleSarvamJudgeMerger` | New envelope; confidence-gated; gender never touched |
| Assemble | `OcrEnsembleParseInputAssembler` | Rebuild parse input from merged envelope |
| Quality gate | `MIN_ASSEMBLED_TEXT_LENGTH` (20) | Skip persist if too short |
| Persist | `IntakeOcrEnsemblePhase4Service` | `field_resolution_json` + `last_parse_input_text` only |

### 2.3 Frozen trigger fields

Eligible: `full_name`, `date_of_birth`, `primary_contact_number`, `religion`

Never trigger / never merge: `gender` and all other structured fields.

### 2.4 Persistence contract

| Column | Written by Phase 4? | Notes |
|--------|---------------------|-------|
| `field_resolution_json` | Yes (only if merge changed + quality OK) | Merged envelope with optional per-field `merge` metadata |
| `last_parse_input_text` | Yes (same condition) | Rebuilt via existing assembler |
| `raw_ocr_text` | **Never** | Immutable SSOT; tested |
| `parsed_json` | No | Parser / `ParseIntakeJob` only |
| `biodata_intake_ocr_attempts` | **Not yet** | Contract gap — see §8 |
| `parse_status` / queue | No | Unchanged |

On skip / soft-fail / merge no-op / quality fail → **no DB write**.

### 2.5 Soft-fail and skip reasons

**Entry (`runForBulkItemIfApplicable`):**

| Reason | Condition |
|--------|-----------|
| `phase4_gate_disabled` | `!isPhase4Enabled()` |
| `bulk_item_ineligible` | Non-file item |
| `missing_biodata_intake` | No linked intake |
| `reused_transcript` | Ensemble skip meta |
| `missing_field_resolution_json` | Phase 3 envelope absent |

**Pipeline (`judge` / `runPipeline`):**

| Outcome | Typical reason |
|---------|----------------|
| skipped | `no_triggers`, `empty_judge_request` |
| soft_failed | `sarvam_timeout`, `sarvam_http_error`, `sarvam_config_error`, `phase4_judge_exception`, … |
| noop | `merge_noop`, `assembled_parse_input_too_short` |
| resolved | Merge improved + quality pass + persist |

Callers never receive thrown exceptions from Phase 4 orchestration.

### 2.6 Scope boundaries (verified)

| In scope (4a–4f) | Out of scope / deferred |
|------------------|-------------------------|
| Trigger, request, client, merger, orchestration | Admin Comparison UI (Phase 5) |
| Persist envelope + parse input | Persisting Sarvam as `ocr_attempt` |
| Soft-fail + gates | Changing `ParseIntakeJob` / parser |
| Mocked HTTP unit/integration tests | Live Sarvam staging drills |
| Logging (`phase4_*`) | Formal trigger-rate telemetry dashboard |

### 2.7 Immutable DTO usage (verified)

| DTO | Role |
|-----|------|
| `SarvamJudgeTriggerReport` | Trigger evaluation output |
| `SarvamJudgeRequest` / `SarvamJudgeRequestField` | Deterministic HTTP payload source |
| `SarvamJudgeResponse` / `SarvamJudgeResponseField` | Parsed HTTP outcome |
| `SarvamJudgeMergeResult` | Merge summary + new envelope |
| `FieldResolutionEnvelope` / field records | Phase 3 envelope; merge returns **new** instance |
| `Phase4JudgeResult` | Orchestrator outcome |

Merger does not mutate input envelope field objects for unchanged keys.

---

## 3. Verification matrix

| Check | Result | Evidence |
|-------|--------|----------|
| Feature gates | **PASS** | `IntakeOcrEnsembleGate::isPhase4Enabled()` = ensemble ∧ phase3 ∧ phase4 |
| Soft-fail | **PASS** | Timeout/500/config → soft_failed; Phase 3 data preserved |
| No `raw_ocr_text` mutation | **PASS** | Service never assigns it; model guard + tests |
| No parser changes | **PASS** | `BiodataParserService` / Phase 4 do not touch parser |
| No queue behavior changes | **PASS** | Still Phase3 → Phase4 → existing parse dispatch; `ParseIntakeJob` unmodified for Phase 4 |
| No benchmark deps | **PASS** | Grep clean under `OcrEnsemble/`; foundation guards |
| Immutable DTOs | **PASS** | Readonly constructors; merge returns new envelope |
| Gender never Sarvam | **PASS** | Trigger + merger tests |
| Deterministic request serialize | **PASS** | Client/request builder tests |

---

## 4. Implementation checklist (4a–4f)

| # | Item | Done |
|---|------|------|
| 4a | Foundation: gate, constants, skeleton DI, bulk hook | ✅ |
| 4b | Trigger evaluator (Blueprint §5.1) | ✅ |
| 4c | Request builder + DTOs | ✅ |
| 4d | HTTP client, parser, retry, response DTOs | ✅ |
| 4e | Merger + merge result + confidence rules | ✅ |
| 4f | Orchestration, quality gate, persist, integration tests | ✅ |

---

## 5. Regression checklist

| # | Item | Done |
|---|------|------|
| R01 | Phase 4 foundation + trigger + request + client + merger unit tests | ✅ |
| R02 | Phase 4 integration (skip / success / soft-fail / no-op / quality / raw immutability / bulk hook) | ✅ |
| R03 | Phase 3 foundation + resolve + assembler + field pipeline + bulk Phase 3 integration | ✅ |
| R04 | Flag-off path: Phase 4 skipped when gate disabled | ✅ |
| R05 | No Phase 5 / UI work started | ✅ |

---

## 6. Automated test run (freeze evidence)

**Command set:** all `OcrEnsemblePhase4*` tests + Phase 3 ensemble regression listed in §5.

| Metric | Value |
|--------|-------|
| **Tests** | **113 passed** |
| **Assertions** | **486** |
| **Failed** | **0** |
| **Date** | 2026-07-14 |

---

## 7. Acceptance criteria

### 7.1 Met (implementation freeze)

- [x] Sarvam **not called** when all four trigger fields resolved without conflict
- [x] Sarvam **called** (mocked) when DOB/mobile/religion/name triggers fire
- [x] Gender-only missing → **not** called
- [x] Religion resolved with passing validator → **not** triggered even if confidence low
- [x] HTTP failure → intake not failed; Phase 3 preserved
- [x] Merge + persist only when improvements + quality gate pass
- [x] `raw_ocr_text` immutable
- [x] Phase 3 regression green

### 7.2 Not yet met (operator / remaining product contract)

- [ ] Sarvam output saved as `ocr_attempt` (`sarvam_ai_vision`) when called
- [ ] Measurable production/staging trigger rate ≤ 20% on 50-image set (4.F01)
- [ ] Live Sarvam endpoint + key validated in staging
- [ ] Optional: explicit `sarvam_judge_triggered` metadata flag / cost telemetry

---

## 8. Remaining blockers

| ID | Blocker | Severity | Required for |
|----|---------|----------|--------------|
| B1 | No `ocr_attempt` persistence for Sarvam judge output | Medium (contract §Phase 4) | Full blueprint parity / admin comparison evidence |
| B2 | No formal trigger-rate metrics beyond logs | Medium | 4.F01 go/no-go |
| B3 | Staging live HTTP drill not run | High for prod enable | Production flag-on |
| B4 | Phase 3 ground-truth gate (3.F01) may still be open | High for ensemble prod | Ensemble production rollout |
| B5 | Client uses configurable chat/completions-style judge JSON (not document-digitization poll loop) | Low/Medium | Confirm product accepts this judge transport |

**None of B1–B5 block freezing 4a–4f application code as complete for orchestration.**  
They **do** block declaring Phase 4 “production-ready with Sarvam live.”

---

## 9. Rollout checklist

| # | Step | Owner | Done |
|---|------|-------|------|
| RO1 | Confirm 4a–4f committed/pushed as intended freeze set | Eng | ☐ |
| RO2 | Keep `intake_ocr_ensemble_enabled=false` in production | Ops | ☐ |
| RO3 | Staging: enable ensemble + Phase 3 + Phase 4 with test Sarvam key | Ops | ☐ |
| RO4 | Staging: 10–20 file bulk sample; verify skip/soft-fail/resolve logs | QA | ☐ |
| RO5 | Staging: confirm `raw_ocr_text` unchanged; parse still succeeds | QA | ☐ |
| RO6 | Record observed trigger rate | QA | ☐ |
| RO7 | Decide B1 (ocr_attempt) before Phase 5 comparison UI | Product/Eng | ☐ |
| RO8 | Production enable only after RO3–RO7 | Ops | ☐ |

---

## 10. Rollback plan

| Layer | Action |
|-------|--------|
| Instant | Set `intake_ocr_ensemble_enabled=false` **or** `OCR_ENSEMBLE_PHASE4_ENABLED=false` |
| Behavior | Phase 4 skips entirely; Phase 3 (if still on) or legacy path continues |
| Data | Soft-fail / skip wrote nothing; successful merges only touched `field_resolution_json` + `last_parse_input_text` — reversible by re-parse / re-run Phase 3 if needed |
| Code | No migration required for rollback; no `raw_ocr_text` repair needed |

---

## 11. Production readiness

| Question | Answer |
|----------|--------|
| Is Phase 4 **code-complete** for 4a–4f orchestration? | **Yes** |
| Can Phase 4 be **implementation-frozen**? | **Yes** |
| Is Phase 4 **production-ready with live Sarvam**? | **Not yet** — flags stay off; close B1–B4 / RO checklist |
| May Phase 5 start? | **Only after explicit freeze acceptance** — do not start in this step |

---

## 12. Freeze decision

| Decision | Status |
|----------|--------|
| **Freeze Phase 4a–4f implementation** | **APPROVED for freeze** pending user commit of any uncommitted 4e/4f files |
| **Pass → Phase 5** | **HOLD** until freeze accepted + product decision on B1/B5 |
| **Production Sarvam enable** | **HOLD** |

---

## 13. Recommended next steps (after freeze)

1. Commit/push any remaining uncommitted Phase 4e/4f + this validation doc if not already on `main`.
2. Close B1 if Phase 5 comparison UI needs Sarvam columns from `ocr_attempts`.
3. Staging live drill + 4.F01 trigger-rate measurement.
4. Explicit go/no-go for Phase 5 Admin Comparison UI.
