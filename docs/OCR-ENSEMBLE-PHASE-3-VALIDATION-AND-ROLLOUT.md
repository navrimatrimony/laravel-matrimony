# OCR Ensemble Phase 3 — Validation, Acceptance & Rollout Plan

> **Step:** Phase 3g (validation only — no code changes)  
> **Repository:** `laravel-matrimony`  
> **Date:** 2026-07-13  
> **Prerequisite:** Phase 3a–3f approved and implemented locally  
> **Verdict:** **CONDITIONAL PASS for staging enable** — automated suite green; ground-truth accuracy gate and operator sign-off still required before production flag-on.

---

## 1. Executive summary

Phase 3 implements a frozen pipeline:

**Extractor → Normalizer → Voter → Validator → Assembler → Persist → ParseIntakeJob**

| Area | Status | Notes |
|------|--------|-------|
| Implementation (3a–3f) | **Complete** | Orchestrator, 16-field pipeline, persistence, parse-job preference |
| Automated tests | **54 passed** (225 assertions) | Unit + integration + ParseIntakeJob preference |
| Feature gates | **Verified** | Dual gate: AdminSetting + config |
| SSOT safety | **Verified** | `raw_ocr_text` immutable; soft-fail on errors |
| Ground-truth accuracy | **Not run** | P3-01 / P3-02 / 3.F01 still operator tasks |
| Production enable | **Blocked** | Until staging drill + accuracy sign-off |

**Production default remains:** `intake_ocr_ensemble_enabled = false`

---

## 2. Complete pipeline review

### 2.1 Production entry path (bulk file upload only)

```
Admin bulk upload (HTTP)
  → BulkIntakeBatch + pending BulkIntakeBatchItem rows
  → ProcessBulkIntakeBatchItemJob (queue: bulk-intake)
      → BulkIntakeBatchService::processPendingItem()
          → IntakeCreationService::prepareForBulkFile()   [Phase 1 if ensemble on]
          → persist intake + primary biodata_intake_ocr_attempts row
          → IntakeOcrEnsemblePhase3Service::runForBulkItemIfApplicable()   [Phase 3]
          → queueAutoFreeParseAfterUploadForItem()
              → ParseIntakeJob
                  → resolveNativeOcrParseInput()   [Phase 3f]
                  → BiodataParserService → parsed_json
```

**Job ordering:** Phase 3 runs **inline** in the same worker, **before** `ParseIntakeJob` dispatch. No parallel ensemble + parse race.

### 2.2 Phase 3 internal pipeline

| Step | Class | Method | Output |
|------|-------|--------|--------|
| Gate + eligibility | `IntakeOcrEnsemblePhase3Service` | `runForBulkItemIfApplicable()` | Skip or continue |
| Load attempts | same | `loadUsableAttempts()` | Success `ocr_attempts` with non-empty `raw_text` |
| Extract | `OcrEnsembleFieldExtractor` | `extractCandidates()` | `OcrEnsembleExtractionResultDto` (16 fields × engines) |
| Normalize | `OcrEnsembleFieldNormalizer` | `normalizeField()` | Canonical per-engine values |
| Vote | `OcrEnsembleFieldVoter` | `voteField()` | Winner + reason (single-engine pass-through when one engine) |
| Validate | `OcrEnsembleFieldValidator` | `validateField()` | Final value or `missing` |
| Envelope | `IntakeOcrEnsemblePhase3Service` | `buildEnvelope()` | `FieldResolutionEnvelope` |
| Assemble | `OcrEnsembleParseInputAssembler` | `assemble()` | `last_parse_input_text` candidate |
| Quality gate | `OcrEnsembleParseInputAssemblySupport` | `MIN_ASSEMBLED_TEXT_LENGTH` (20) | Skip if too short |
| Persist | `IntakeOcrEnsemblePhase3Service` | `resolve()` | `field_resolution_json` + `last_parse_input_text` |

### 2.3 Parse job preference (Step 3f)

`ParseIntakeJob::resolveNativeOcrParseInput()` (native / non–AI-vision path):

1. Non-empty `last_parse_input_text` → use assembled text (`parse_input_source: ensemble_assembled_phase3`)
2. Else if manual crop exists **or** `raw_ocr_text` blank → `OcrService::resolveParseInputText()` (legacy)
3. Else → `OcrService::buildParseInputFromDbRawOcr()`

AI-vision and reparse early-resolve paths are **unchanged**.

### 2.4 Persistence contract

| Column | Written by Phase 3? | Notes |
|--------|---------------------|-------|
| `field_resolution_json` | Yes (on resolve) | `_meta` + 16 `fields` with status, candidates, validator |
| `last_parse_input_text` | Yes (on resolve) | Assembled header + deduplicated OCR body |
| `raw_ocr_text` | **Never** | Immutable SSOT; tested |
| `parsed_json` | No | Written by `ParseIntakeJob` only |
| `biodata_intake_ocr_attempts` | Read-only | Phase 3 loads; does not mutate |
| `parse_status` | No | Unchanged by Phase 3 |

On **any skip/failure**, Phase 3 writes **nothing** (columns stay null or previous value).

### 2.5 Sixteen structured fields (frozen)

`full_name`, `date_of_birth`, `gender`, `primary_contact_number`, `height`, `education`, `occupation`, `income`, `religion`, `caste`, `sub_caste`, `state`, `district`, `taluka`, `village`, `marital_status`

Paragraph / narrative fields are **not voted**; they pass through in the assembled OCR body.

### 2.6 Soft-fail and skip reasons

**Bulk entry (`runForBulkItemIfApplicable`):**

| Reason | Condition |
|--------|-----------|
| `phase3_gate_disabled` | `!IntakeOcrEnsembleGate::isPhase3Enabled()` |
| `bulk_item_ineligible` | `input_type !== INPUT_FILE` |
| `missing_biodata_intake` | No linked intake |
| `reused_transcript` | `item_meta_json.ocr_ensemble_skip_reason === reused_transcript` |

**Resolve (`resolve`):**

| Reason | Condition |
|--------|-----------|
| `intake_not_persisted` | Intake not saved |
| `no_usable_ocr_attempts` | No success attempts with text |
| `no_field_candidates` | Extractor returned empty |
| `assembled_parse_input_too_short` | Assembled text &lt; 20 chars |
| `phase3_resolution_failed` | Uncaught exception (logged, no persist) |

Field-level `missing` does **not** abort resolve. Gender/income soft-fail does **not** block pipeline.

### 2.7 Scope boundaries (verified)

| In scope | Out of scope (Phase 3) |
|----------|------------------------|
| Bulk file upload via `ProcessBulkIntakeBatchItemJob` | Sarvam judge (Phase 4) |
| Single-engine pass-through (Phase 2 no-go) | Admin comparison UI (Phase 5) |
| `field_resolution_json` on `biodata_intakes` | New OCR engines |
| Parse input assembly + parse-job consumption | Parser logic changes |
| | Mobile / single-intake admin upload paths |
| | Re-ensemble on admin re-parse |

### 2.8 Known path gaps (document only — no fix in 3g)

| Gap | Risk | Mitigation at rollout |
|-----|------|----------------------|
| `processUnclaimedBulkBatch()` does not call Phase 3 | Low — method appears unused in codebase | Confirm no production caller before enable |
| Phase 3 not hooked to non-bulk intake creation | Expected | Document: Phase 3 applies to **admin bulk file** path only |
| Re-parse does not re-run Phase 3 | Expected | `field_resolution_json` frozen until explicit re-ensemble (future) |
| Successful parse overwrites `last_parse_input_text` with parser input | Low | Post-parse value should match assembled input when parse succeeds |

---

## 3. Feature-gate verification

### 3.1 Gate matrix

| Gate | Location | Default | Effect when OFF |
|------|----------|---------|-----------------|
| `intake_ocr_ensemble_enabled` | `AdminSetting` | `false` | Phase 1 + Phase 3 skipped; legacy `prepare()` |
| `ocr.ensemble.phase3.enabled` | `config/ocr.php` | `true` (env: `OCR_ENSEMBLE_PHASE3_ENABLED`) | Phase 3 skipped even if ensemble on |

**Effective Phase 3 enable:** `IntakeOcrEnsembleGate::isPhase3Enabled()` = `isEnabled() AND config('ocr.ensemble.phase3.enabled')`

### 3.2 Where gates are checked

| Component | Gate used | Behavior |
|-----------|-----------|----------|
| `IntakeOcrEnsemblePhase3Service::runForBulkItemIfApplicable()` | `isPhase3Enabled()` | Only Phase 3 entry point |
| `IntakeCreationService::prepareForBulkFile()` | `isEnabled()` | Phase 1 vs legacy (not Phase 3 directly) |
| `ParseIntakeJob` | **No gate** | Consumes `last_parse_input_text` if present regardless of flag |

**Important:** Parse job preference is **data-driven** (column populated or not), not gate-driven. Flag-off → Phase 3 does not populate columns → parse falls back to legacy path. Verified in `ParseIntakeJobPhase3ParseInputTest`.

### 3.3 Flag-off backward compatibility (verified)

| Scenario | Phase 3 | Parse input source |
|----------|---------|-------------------|
| Ensemble OFF | Skipped (`phase3_gate_disabled`) | `buildParseInputFromDbRawOcr` or `resolveParseInputText` |
| Ensemble ON, Phase 3 config OFF | Skipped (`phase3_gate_disabled`) | Same as flag-off |
| Ensemble ON, resolve skipped (soft-fail) | No persist | Fallback to raw OCR path |
| Ensemble ON, resolve success | Persist both columns | `ensemble_assembled_phase3` |

### 3.4 Admin UI

- Toggle: Admin → Intake Settings → `intake_ocr_ensemble_enabled`
- No separate Phase 3 admin toggle (config/env only)

---

## 4. Implementation checklist

Status as of Phase 3g validation (code review + test run).

### 4.1 Implementation items

| # | Item | Status | Evidence |
|---|------|--------|----------|
| 3.01 | `field_resolution_json` column on `biodata_intakes` | ☑ | Migration `2026_07_13_180000_add_field_resolution_json_to_biodata_intakes.php` |
| 3.02 | Field extractor: 16 structured fields | ☑ | `OcrEnsembleFieldExtractor`, `OcrEnsemblePhase3Constants` |
| 3.03 | Shared regex/helpers (no benchmark fork) | ☑ | Uses `MarathiOcrFieldRescueService`, `OcrNormalize`, production Support classes |
| 3.04 | Per-field normalize → vote → validator | ☑ | Frozen pipeline in `IntakeOcrEnsemblePhase3Service::resolveFieldRecords()` |
| 3.05 | Single-engine pass-through mode | ☑ | `voteModeForExtraction()` → `single_engine_pass_through` |
| 3.06 | Assemble `last_parse_input_text` | ☑ | `OcrEnsembleParseInputAssembler::assemble()` |
| 3.07 | `ParseIntakeJob` uses assembled input | ☑ | `resolveNativeOcrParseInput()` (Step 3f) |
| 3.08 | Gender missing → empty, no error | ☑ | Validator + pipeline tests |
| 3.09 | Income soft validator | ☑ | `OcrEnsembleFieldValidator` income path |
| 3.10 | Paragraph fields not voted | ☑ | Only `STRUCTURED_FIELDS` in extractor/envelope |
| 3.11 | Phase 3 hook before parse dispatch | ☑ | `BulkIntakeBatchService::processPendingItem()` line 185 |
| 3.12 | `raw_ocr_text` never mutated | ☑ | `OcrEnsemblePhase3ResolveTest` |
| 3.13 | No benchmark imports in production | ☑ | Foundation + pipeline + assembler tests |

### 4.2 Automated test items

| # | Item | Status | Evidence |
|---|------|--------|----------|
| 3.T01 | Unit: field validators (mobile, DOB, gender, income, etc.) | ☑ | `OcrEnsemblePhase3FieldPipelineTest` (15 tests) |
| 3.T02 | Unit: vote rules (single + multi-engine) | ☑ | Same file |
| 3.T03 | Feature: GT-735 ground-truth extract accuracy | ☐ | **Operator task** — see §6 |
| 3.T04 | Feature: `parsed_json` populated after full chain | ☑ | `ParseIntakeJobPhase3ParseInputTest` (partial); full bulk E2E optional |
| 3.T05 | Regression: flag off unchanged | ☑ | `ParseIntakeJobPhase3ParseInputTest`, `OcrEnsemblePhase3ResolveIntegrationTest` |
| 3.T06 | Unit: field extractor (16 fields) | ☑ | `OcrEnsemblePhase3FieldExtractorTest` (8 tests) |
| 3.T07 | Unit: parse input assembler | ☑ | `OcrEnsemblePhase3ParseInputAssemblerTest` (8 tests) |
| 3.T08 | Unit + integration: orchestrator resolve | ☑ | `OcrEnsemblePhase3ResolveTest` + integration (8 tests) |
| 3.T09 | Unit: foundation + gate | ☑ | `OcrEnsemblePhase3FoundationTest` (10 tests) |
| 3.T10 | Feature: ParseIntakeJob preference chain | ☑ | `ParseIntakeJobPhase3ParseInputTest` (5 tests) |

**Automated suite command:**

```powershell
php artisan test `
  tests/Unit/Intake/OcrEnsemblePhase3FoundationTest.php `
  tests/Unit/Intake/OcrEnsemblePhase3ResolveTest.php `
  tests/Unit/Intake/OcrEnsemblePhase3FieldPipelineTest.php `
  tests/Unit/Intake/OcrEnsemblePhase3FieldExtractorTest.php `
  tests/Unit/Intake/OcrEnsemblePhase3ParseInputAssemblerTest.php `
  tests/Feature/Intake/OcrEnsemblePhase3ResolveIntegrationTest.php `
  tests/Feature/Intake/ParseIntakeJobPhase3ParseInputTest.php
```

**Result (2026-07-13):** 54 passed, 225 assertions.

### 4.3 Phase 3 freeze gate

| # | Item | Status |
|---|------|--------|
| 3.F01 | Ground-truth 10-image extract score recorded | ☐ Operator / benchmark task |
| 3.F02 | All implementation + automated test items checked | ☐ Blocked on 3.F01 + staging sign-off |

**PASS → Phase 4 only** (after 3.F01 + production rollout checklist complete).

---

## 5. Production rollout checklist

### 5.1 Pre-deploy (code on staging)

| # | Action | Owner | Done |
|---|--------|-------|------|
| R-01 | Merge Phase 3a–3f to staging branch | Dev | ☐ |
| R-02 | Run migration: `field_resolution_json` column | DevOps | ☐ |
| R-03 | Confirm `intake_ocr_ensemble_enabled = false` in production DB | Ops | ☐ |
| R-04 | Confirm `OCR_ENSEMBLE_PHASE3_ENABLED` not set to `false` on staging (unless testing disable path) | Ops | ☐ |
| R-05 | Run full Phase 3 test suite (§4.2 command) — all green | Dev | ☐ |
| R-06 | Run existing bulk + parse regression suite | Dev | ☐ |
| R-07 | Verify `bulk-intake` queue worker running | Ops | ☐ |

### 5.2 Staging validation (flag off → on)

| # | Action | Expected | Done |
|---|--------|----------|------|
| R-08 | Upload 3 bulk files with flag **OFF** | Identical to pre-Phase-3 behavior; no `field_resolution_json` | ☐ |
| R-09 | Enable `intake_ocr_ensemble_enabled` on staging | Phase 1 + Phase 3 run on new uploads | ☐ |
| R-10 | Upload 10 real biodata PDFs/images | Items complete; `field_resolution_json` populated on resolve | ☐ |
| R-11 | Spot-check 5 intakes: `last_parse_input_text` ≠ raw OCR where fields resolved | Assembled header present | ☐ |
| R-12 | Spot-check 5 intakes: `parsed_json` populated; `parse_status = parsed` | Parser consumed assembled input | ☐ |
| R-13 | Upload duplicate file (reused transcript) | Phase 3 skipped (`reused_transcript`); parse still succeeds | ☐ |
| R-14 | Upload text-only bulk item | Phase 3 skipped (`bulk_item_ineligible`) | ☐ |
| R-15 | Monitor logs for `phase3_field_resolution_failed` | Zero or explained | ☐ |
| R-16 | Measure p50/p95 item processing time | Within acceptable bulk SLA | ☐ |

### 5.3 Ground-truth accuracy gate (required before production)

| # | Action | Target | Done |
|---|--------|--------|------|
| R-17 | Run P3-01: GT-735 extract | Critical 5 fields ≥ 98% | ☐ |
| R-18 | Run P3-02: GT-736 compare | Measurable uplift vs baseline | ☐ |
| R-19 | Run P3-06: 50-image set (when available) | Overall 16-field > 95% | ☐ |
| R-20 | Record scores in `docs/ocr-ensemble-benchmark-v1.md` or successor | Signed | ☐ |

### 5.4 Production enable (gradual)

| # | Action | Done |
|---|--------|------|
| R-21 | Deploy release to production (flag remains **OFF**) | ☐ |
| R-22 | Smoke test: 1 bulk upload flag off | ☐ |
| R-23 | Enable flag during low-traffic window | ☐ |
| R-24 | Upload 5 production biodata; admin review quality | ☐ |
| R-25 | Monitor 24h: error rate, queue depth, `phase3_*` skip reasons | ☐ |
| R-26 | If stable → announce internal enable; if not → rollback §7 | ☐ |

### 5.5 Post-enable monitoring (first 7 days)

| Metric | Alert threshold |
|--------|-----------------|
| `assembled_parse_input_too_short` skip rate | > 10% of bulk file items |
| `phase3_resolution_failed` | Any sustained occurrence |
| Bulk item `empty_ocr_text` rate | Increase vs 7-day baseline |
| Parse `error` rate | Increase vs baseline |
| p95 `ProcessBulkIntakeBatchItemJob` duration | > 60s sustained |

---

## 6. Regression test checklist

### 6.1 Automated (CI / pre-merge)

| ID | Suite | Command / file | Pass required |
|----|-------|----------------|---------------|
| REG-01 | Phase 3 unit + feature | §4.2 command | Yes |
| REG-02 | Phase 1 bulk ensemble | `tests/Feature/Intake/IntakeOcrEnsemblePhase1Test.php` | Yes |
| REG-03 | ParseIntakeJob canonical / reparse | `ParseIntakeJobCanonicalTranscriptTest.php` | Yes |
| REG-04 | ParseIntakeJob failure states | `ParseIntakeJobFailureStateTest.php` | Yes |
| REG-05 | Bulk intake upload async | `AdminBulkIntakeAsyncProcessingTest.php` | Yes |
| REG-06 | Gate unit | `tests/Unit/Intake/IntakeOcrEnsembleGateTest.php` | Yes |

### 6.2 Manual / staging regression

| ID | Case | Steps | Expected |
|----|------|-------|----------|
| REG-M01 | Flag off bulk upload | 3 files, ensemble OFF | No `field_resolution_json`; legacy parse |
| REG-M02 | Flag on happy path | 3 files, ensemble ON | Both columns set; `parsed_json` OK |
| REG-M03 | Phase 3 soft-fail | Corrupt/empty OCR intake | Skip reason logged; parse via raw OCR fallback |
| REG-M04 | Reused transcript | Duplicate file hash | `reused_transcript` skip; no duplicate Phase 3 |
| REG-M05 | Admin re-parse | Re-parse parsed intake | Uses canonical / raw rules; Phase 3 not re-run |
| REG-M06 | Manual crop intake | Intake with manual prepared PNG | `resolveParseInputText` path (not assembled) |
| REG-M07 | AI vision mode intakes | Paid / Sarvam path intakes | Unchanged; not native Phase 3 path |
| REG-M08 | Rollback drill | Enable → 3 uploads → disable → 3 uploads | Post-rollback identical to REG-M01 |

### 6.3 Ground-truth benchmark regression (from test plan)

| ID | Case | Target |
|----|------|--------|
| P3-01 | GT-735 extract | Critical fields ≥ 98% |
| P3-02 | GT-736 extract | Uplift vs baseline |
| P3-03 | Single engine | Vote pass-through |
| P3-04 | Gender missing | Empty; no Sarvam |
| P3-05 | Parse chain | `parsed_json` populated |
| P3-06 | 50-image accuracy | Overall > 95% |

---

## 7. Rollback plan

### 7.1 Instant rollback (L1 — no deploy)

| Step | Action | Effect | Data impact |
|------|--------|--------|-------------|
| 1 | Set `intake_ocr_ensemble_enabled = false` in Admin → Intake Settings | Phase 1 + Phase 3 stop on new items | None |
| 2 | Optionally set `OCR_ENSEMBLE_PHASE3_ENABLED=false` in `.env` | Phase 3 off even if ensemble re-enabled | None |
| 3 | Verify with 1 bulk upload | Legacy path; no new `field_resolution_json` | None |

**Time to effect:** Immediate for new uploads. In-flight queue jobs may complete with flag state at process time.

### 7.2 Deploy rollback (L2)

| Step | Action |
|------|--------|
| 1 | Revert Phase 3 merge commit / deploy previous release |
| 2 | Keep migration (nullable column harmless) **or** leave column unused |
| 3 | Confirm flag OFF |
| 4 | Run REG-M01 + REG-M08 |

### 7.3 Data rollback (L3)

| Data | Rollback needed? | Notes |
|------|------------------|-------|
| `field_resolution_json` | No | Nullable; ignored when Phase 3 off |
| `last_parse_input_text` from Phase 3 | No | Parse job falls back to raw OCR when not preferred |
| `raw_ocr_text` | No | Never mutated by Phase 3 |
| `biodata_intake_ocr_attempts` | No | Extra rows harmless |
| `parsed_json` | No | Re-parse available if needed |

**No destructive migration rollback required.**

### 7.4 Rollback verification checklist

| # | Check | Pass |
|---|-------|------|
| RB-01 | Flag OFF → bulk upload completes | ☐ |
| RB-02 | No new `field_resolution_json` written | ☐ |
| RB-03 | Parse status `parsed` for good OCR samples | ☐ |
| RB-04 | Queue depth returns to normal | ☐ |
| RB-05 | No elevated `phase3_field_resolution_failed` after disable | ☐ |

### 7.5 Rollback triggers (suggested)

- Parse error rate ↑ > 2× baseline for 1 hour after enable
- Bulk item failure rate ↑ > 2× baseline
- Sustained `phase3_resolution_failed` exceptions
- Ground-truth accuracy below contract minimum on staging sign-off
- Admin reports systematic field regression on critical fields (name, DOB, mobile)

---

## 8. Phase 3 acceptance criteria

### 8.1 Code acceptance (development gate) — **MET**

- [x] 16 structured fields extracted without full parser per engine
- [x] Frozen pipeline: Extractor → Normalizer → Voter → Validator → Assembler
- [x] `field_resolution_json` persisted with per-field status, candidates, validator metadata
- [x] `last_parse_input_text` assembled and consumed by `ParseIntakeJob`
- [x] `raw_ocr_text` never modified
- [x] Single-engine mode works (pass-through vote)
- [x] Gender missing does not block pipeline or trigger Sarvam
- [x] Income uses soft validator
- [x] Soft-fail: exceptions and skip reasons do not fail bulk item or intake
- [x] Feature gate defaults OFF; flag-off path backward compatible
- [x] No `OcrEnsembleBenchmark*` imports in production Phase 3 code
- [x] Automated test suite green (54 tests)

### 8.2 Staging acceptance (operator gate) — **PENDING**

- [ ] Staging rollout checklist §5.2 complete (R-08 through R-16)
- [ ] Rollback drill §7.4 complete (RB-01 through RB-05)
- [ ] No unexplained `phase3_resolution_failed` in staging logs
- [ ] Admin review of 10 staging intakes: assembled parse input quality acceptable

### 8.3 Accuracy acceptance (benchmark gate) — **PENDING**

- [ ] P3-01: GT-735 critical fields ≥ 98%
- [ ] P3-02: GT-736 uplift documented
- [ ] P3-06: 50-image overall > 95% (when dataset ready)
- [ ] 3.F01: 10-image extract score recorded and signed

### 8.4 Production acceptance (go-live gate) — **PENDING**

- [ ] All staging + accuracy gates met
- [ ] Production deploy with flag OFF verified (R-21, R-22)
- [ ] Gradual enable complete (R-23–R-26)
- [ ] 24h monitoring clean
- [ ] Sign-off: Dev + Ops + product owner

### 8.5 Explicit non-acceptance (out of Phase 3 scope)

The following are **not** required to accept Phase 3 code — they belong to later phases:

- Sarvam judge triggers (Phase 4)
- Admin field comparison UI (Phase 5)
- `field_resolution_json` display in bulk list
- Re-ensemble on admin re-parse
- Single-intake / mobile upload Phase 3 hook
- Ongoing `intake:ocr-regression` artisan command (separate task per test plan)

---

## 9. Validation verdict

| Gate | Result |
|------|--------|
| **Development (3a–3f)** | **PASS** — implementation complete; 54 automated tests green |
| **Staging enable** | **CONDITIONAL PASS** — proceed with §5.2 after deploy to staging |
| **Production enable** | **HOLD** — requires §5.3 accuracy sign-off + §5.4 gradual rollout |
| **Phase 4 start** | **HOLD** — complete 3.F01 + staging acceptance first |

---

## 10. Related documents

| Document | Purpose |
|----------|---------|
| `OCR-ENSEMBLE-PIPELINE-BLUEPRINT.md` | Architecture §13.3 success criteria |
| `OCR-ENSEMBLE-PHASE-CONTRACTS.md` | Phase 3 scope contract |
| `OCR-ENSEMBLE-IMPLEMENTATION-CHECKLIST.md` | Master phase checklist |
| `OCR-ENSEMBLE-TEST-PLAN.md` | P3-01–P3-06 benchmark cases |
| `OCR-ENSEMBLE-PRODUCTION-READINESS-REVIEW.md` | Rollback + SSOT review |
| `ocr-ensemble-benchmark-v1.md` | Phase 2 freeze; accuracy baseline |

---

## 11. Document history

| Version | Date | Change |
|---------|------|--------|
| 1.0 | 2026-07-13 | Phase 3g validation, rollout, regression, rollback, acceptance criteria |
