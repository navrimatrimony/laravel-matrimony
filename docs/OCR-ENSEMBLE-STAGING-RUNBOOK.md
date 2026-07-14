# OCR Ensemble v1.0 — Staging Validation Runbook

> **Repository:** `laravel-matrimony`  
> **Status:** OCR Ensemble v1.0 **CODE COMPLETE** — operational validation only  
> **Date:** 2026-07-14  
> **Authority:** `OCR-ENSEMBLE-PIPELINE-BLUEPRINT.md`, `OCR-ENSEMBLE-PHASE-CONTRACTS.md`, `OCR-ENSEMBLE-PHASE-5-VALIDATION-AND-ROLLOUT.md`, `OCR-ENSEMBLE-IMPLEMENTATION-CHECKLIST.md`  
> **Scope:** Staging drills only. **No application code changes.** **No Phase 6.**

---

## Purpose

This runbook is the operator checklist for validating OCR Ensemble **Phase 1–5** on **staging** before any production flag enable. It covers bulk upload through parse, admin review (Correct Candidate + comparison panel), and bulk list badges.

**Production default remains:** `intake_ocr_ensemble_enabled = false`

**Staging verdict target:** confirm **READY FOR STAGING** evidence; production enable requires separate **P5-B4** product sign-off after this runbook completes.

---

## Pipeline overview (reference)

```text
Admin bulk upload (HTTP, immediate return)
  → ProcessBulkIntakeBatchItemJob  [queue: bulk-intake]
      → Phase 1: preprocess + Tesseract + ocr_attempt + item_meta ocr_ensemble_*
      → Phase 3: field_resolution_json + last_parse_input_text
      → Phase 4: optional Sarvam judge merge (+ append-only sarvam ocr_attempt)
      → ParseIntakeJob  [queue: bulk-intake]
          → prefers last_parse_input_text when present
          → BiodataParserService → parsed_json

Admin read-only (separate HTTP):
  → Bulk list badges (OcrEnsembleBulkListBadgePresenter)
  → Correct Candidate → OCR comparison review panel (Phase 5)
```

**Phase 2 NO-GO (frozen):** Tesseract-only. **Second OCR column is often empty by design** — not a staging failure.

---

## 1. Pre-staging checklist

Complete **before** enabling ensemble flags on staging.

| # | Item | Owner | Done |
|---|------|-------|------|
| PS-01 | Staging deploy includes OCR Ensemble v1.0 code (Phase 1–5 + 4.5) | DevOps | ☐ |
| PS-02 | Staging DB has additive columns only (`field_resolution_json`, `biodata_intake_ocr_attempts`, etc.) — **no** `migrate:fresh` / wipe | DevOps | ☐ |
| PS-03 | **Production** `intake_ocr_ensemble_enabled` confirmed **false** (do not enable prod during staging drill) | Ops | ☐ |
| PS-04 | Staging `.env` reviewed (§2) — Sarvam key present if Phase 4 live drill planned | Ops | ☐ |
| PS-05 | `php artisan config:clear` (or deploy hook) run after `.env` changes | DevOps | ☐ |
| PS-06 | Queue worker running on **`bulk-intake`** (handles both item processing and parse dispatch) | DevOps | ☐ |
| PS-07 | OpenCV / Tesseract available on staging worker host | DevOps | ☐ |
| PS-08 | Admin test account with `auth` + `admin` + `admin.section` access | QA | ☐ |
| PS-09 | Non-admin test account for 403 checks | QA | ☐ |
| PS-10 | Ground-truth dataset available (§5) — min 10 verified rows recommended | QA/Ops | ☐ |
| PS-11 | Automated regression baseline recorded: `php artisan test --filter=OcrEnsemblePhase` → **196 passed / 1032 assertions** | Dev | ☐ |
| PS-12 | Staging log access (app log + queue worker stdout) | Ops | ☐ |
| PS-13 | Read-only DB access for spot-check queries (§6) | Ops | ☐ |
| PS-14 | Rollback owner identified (who can toggle flags + restart workers) | Ops | ☐ |
| PS-15 | Evidence folder created for sign-off pack (§11) | QA | ☐ |

---

## 2. Environment variables to verify

Verify on **staging** `.env`. Defaults shown are from `config/ocr.php` when unset.

### 2.1 Master phase gates (required)

| Variable | Staging value | Purpose |
|----------|---------------|---------|
| `OCR_ENSEMBLE_PHASE3_ENABLED` | `true` (default) | Phase 3 field resolution |
| `OCR_ENSEMBLE_PHASE4_ENABLED` | `true` (default) | Sarvam judge |
| `OCR_ENSEMBLE_PHASE5_ENABLED` | `true` (default) | Correct Candidate comparison panel |

Set any of these to `false` **only** when deliberately testing disable/rollback paths.

### 2.2 Phase 1 (optional tuning)

| Variable | Default | Notes |
|----------|---------|-------|
| `OCR_ENSEMBLE_PHASE1_PRESET` | `photo_capture` | OpenCV/Tesseract preset |

### 2.3 Phase 4 — Sarvam judge (required for live Phase 4 drill)

| Variable | Default / fallback | Notes |
|----------|-------------------|-------|
| `OCR_ENSEMBLE_PHASE4_SARVAM_ENDPOINT` | `SARVAM_CHAT_COMPLETIONS_URL` or `https://api.sarvam.ai/v1/chat/completions` | Live HTTP endpoint |
| `OCR_ENSEMBLE_PHASE4_SARVAM_API_KEY` | `SARVAM_API_SUBSCRIPTION_KEY` | **Must be valid on staging** for live drill |
| `OCR_ENSEMBLE_PHASE4_SARVAM_MODEL` | `sarvam-m` | Judge model |
| `OCR_ENSEMBLE_PHASE4_TIMEOUT_SECONDS` | `30` | Per request |
| `OCR_ENSEMBLE_PHASE4_CONNECT_TIMEOUT_SECONDS` | `10` | Connect timeout |
| `OCR_ENSEMBLE_PHASE4_MAX_ATTEMPTS` | `3` | Retries |
| `OCR_ENSEMBLE_PHASE4_RETRY_BASE_MS` | `200` | Backoff |
| `OCR_ENSEMBLE_PHASE4_RETRY_MAX_MS` | `2000` | Backoff cap |

Optional (only if product enables confidence floor): `ocr.ensemble.phase4.min_confidence` — **not** set in default config; absence means validators-only triggers.

### 2.4 Phase 2 benchmark sidecars (NOT required for v1.0 staging)

These are **benchmark-only** after Phase 2 NO-GO. Leave unset unless re-running benchmark tooling:

- `OCR_ENSEMBLE_PADDLE_SIDECAR_URL`
- `OCR_ENSEMBLE_EASYOCR_SIDECAR_URL`
- `OCR_ENSEMBLE_BENCHMARK_TIMEOUT_SECONDS`

### 2.5 Verification commands (ops)

```bash
# After .env edit
php artisan config:clear

# Confirm resolved config (staging shell — read-only inspection)
php artisan tinker --execute="dump(config('ocr.ensemble.phase3.enabled'), config('ocr.ensemble.phase4.enabled'), config('ocr.ensemble.phase5.enabled'));"

# Confirm queue worker listens to bulk-intake
# (exact supervisor/systemd command depends on host — worker MUST include bulk-intake)
```

---

## 3. Admin settings to enable

| Setting | Location | Staging drill | Production |
|---------|----------|---------------|------------|
| **Enable OCR ensemble pipeline (Phase 1+)** | Admin → **Intake Settings** (`/admin/intake-settings`) — checkbox `intake_ocr_ensemble_enabled` | **Enable** for validation batches | **Keep OFF** until P5-B4 sign-off |

**Effective gates:**

| Phase | Requires |
|-------|----------|
| Phase 1 | Master admin setting ON |
| Phase 3 | Master ON ∧ `OCR_ENSEMBLE_PHASE3_ENABLED=true` |
| Phase 4 | Phase 3 gate ∧ `OCR_ENSEMBLE_PHASE4_ENABLED=true` |
| Phase 5 UI | Master ON ∧ `OCR_ENSEMBLE_PHASE5_ENABLED=true` (does **not** require Phase 4) |

**Save path:** POST `/admin/intake-settings` after checking the box.

---

## 4. Recommended staging rollout sequence

Execute in order. Do **not** skip baseline (flag-off) before flag-on.

| Step | Action | Goal |
|------|--------|------|
| **S0** | Complete §1 pre-staging checklist | Safe starting point |
| **S1** | Confirm master ensemble flag **OFF** | Baseline legacy behavior |
| **S2** | Upload **3 file** bulk batch (flag OFF) | Regression — no ensemble artifacts |
| **S3** | Enable master ensemble flag ON (keep phase env vars true) | Activate pipeline |
| **S4** | Upload **10–20 real biodata** files (mixed PDF/JPG, varied quality) | End-to-end ensemble + parse |
| **S5** | DB + log spot-checks (§6.2–6.5) | Phase 1–4 persistence |
| **S6** | Admin UI: bulk list badges (§6.8) | P5-B2 |
| **S7** | Admin UI: Correct Candidate + comparison (§6.6–6.7) | P5-B1 |
| **S8** | Correction save smoke on 2 items (§6.6) | Checklist 5.06 |
| **S9** | Sarvam trigger-rate sample (§6.4) — count triggers vs total | P5-B3 / 4.F01 |
| **S10** | Optional: ground-truth scoring GT-735 / 10-image set (§5) | 3.F01 |
| **S11** | Rollback drill (§9) — toggle flag OFF, upload 1 file | Prove instant legacy restore |
| **S12** | Compile evidence pack (§11) | Product sign-off input |

---

## 5. Test dataset requirements

### 5.1 Minimum staging batch (functional)

| Category | Count | Purpose |
|----------|-------|---------|
| Clear table-layout biodata (JPG/PNG) | 5 | Happy path OCR + Phase 3 |
| PDF (single-page biodata) | 3 | PDF raster path |
| Low-quality / skewed scan | 2 | OpenCV + empty OCR handling |
| Duplicate file (same bytes as prior upload) | 1 | `reused_transcript` skip |
| Text-only bulk row (no image) | 1 | Ensemble skip |
| **Total functional** | **12** | Minimum recommended |

### 5.2 Sarvam trigger coverage (Phase 4)

Include at least one image **likely** to produce each trigger where possible (operator-curated):

| Trigger | Field | Staging intent |
|---------|-------|----------------|
| Name conflict | `full_name` | Sarvam invoked |
| DOB missing | `date_of_birth` | Sarvam invoked |
| Mobile missing | `primary_contact_number` | Sarvam invoked |
| Religion missing | `religion` | Sarvam invoked |
| Gender only missing | `gender` | Sarvam **must NOT** invoke |
| All critical fields clean | — | Sarvam skip (`no_triggers`) |

### 5.3 Ground-truth dataset (accuracy gate — optional but recommended)

Location (private, gitignored):

```text
storage/app/intake-golden-datasets/ocr-ensemble/
  images/
  ground-truth.csv
  benchmark-results/
```

Seed cases (from Test Plan):

| case_id | intake_id | Role |
|---------|-----------|------|
| GT-735 | 735 | Sarvam ground truth reference |
| GT-736 | 736 | Tesseract baseline |
| GT-737 | 737 | ML Kit reference (not ensemble voter) |
| GT-004–010 | — | 7+ varied layouts |

**Targets (program level, post-staging analysis):**

| Metric | Target |
|--------|--------|
| Critical 5-field accuracy | > 98% |
| Overall 16-field accuracy | > 95% |
| Sarvam trigger rate | ≤ 20% of ensemble file items |
| Avg worker time (Tesseract-only path) | < 40 s |
| p95 worker time | < 60 s |
| Job failure rate (excl. known bad scans) | < 1% |

---

## 6. Step-by-step validation procedure

Record **batch ID**, **item IDs**, and **intake IDs** for every step.

### 6.1 Bulk upload (baseline — flag OFF)

**Steps**

1. Admin → **Bulk Intakes** → create new batch.
2. Upload 3 biodata files.
3. Wait for queue to drain (`bulk-intake` worker idle).
4. Open batch show page: `/admin/bulk-intakes/{batch}`.

**Expected results**

| Check | Expected |
|-------|----------|
| Items reach terminal status (parsed / needs review — per existing bulk rules) | Yes |
| `item_meta_json.ocr_ensemble_status` | **Absent or null** |
| `biodata_intakes.field_resolution_json` | **Null** for new intakes |
| Legacy OCR path | Same as pre-ensemble staging behavior |
| Bulk list OCR ensemble badges | **Legacy Path** or **No OCR** (not OCR Complete) |

---

### 6.2 Bulk upload + OCR (Phase 1 — flag ON)

**Steps**

1. Enable **Intake Settings → OCR ensemble** (§3).
2. Create new batch; upload 5 clear biodata images.
3. Monitor worker until all items complete.

**Expected results**

| Check | Expected |
|-------|----------|
| HTTP returns immediately | Yes (async queue) |
| `item_meta_json.ocr_ensemble_status` | `ocr_ready` (may briefly show processing during worker run) |
| `item_meta_json.ocr_ensemble_pipeline` | `phase1_v1` |
| `biodata_intake_ocr_attempts` | ≥ 1 row per intake, `engine = laravel_native_ocr`, `status = success` (unless empty OCR) |
| `preprocessing_version` on attempt | `opencv_minimal_v1` |
| `biodata_intakes.raw_ocr_text` | Non-empty for good scans; set at create — **note value for immutability check later** |
| Bad scan (< 20 chars OCR) | Item may show `empty_ocr_text` failure — expected, not ensemble crash |
| Duplicate file reuse | `ocr_ensemble_skip_reason = reused_transcript`; Phase 3/4 skipped |
| Text-only item | No ensemble meta; Phase 3 skip `bulk_item_ineligible` |
| Bulk badge | **OCR Complete** when `ocr_ensemble_status = ocr_ready` |

**DB spot-check (read-only)**

```sql
-- Replace :intake_id
SELECT id, LEFT(raw_ocr_text, 80) AS raw_prefix, field_resolution_json IS NOT NULL AS has_fr
FROM biodata_intakes WHERE id = :intake_id;

SELECT engine, status, preprocessing_version, LEFT(raw_text, 40) AS attempt_prefix
FROM biodata_intake_ocr_attempts WHERE intake_id = :intake_id ORDER BY id;
```

---

### 6.3 Phase 3 — field resolution

**Steps**

1. Using same flag-ON batch from §6.2 (or new upload).
2. Pick 5 completed file items with successful OCR.
3. Inspect DB + logs.

**Expected results**

| Check | Expected |
|-------|----------|
| `field_resolution_json` | Non-null JSON with `_meta` + 16 `fields` keys |
| `last_parse_input_text` | Non-null; includes assembled header + body |
| `_meta.pipeline_version` | `phase3_v1` |
| Gender missing on image | Field present with missing/empty — **does not** fail job |
| Single-engine vote | Works (Phase 2 NO-GO — one Tesseract candidate) |
| Skip: `assembled_parse_input_too_short` | Only when assembled text < 20 chars — item may lack FR |
| Log warnings | No unexplained `phase3_field_resolution_failed` bursts |
| Bulk badges | **Phase 3 Complete** + **Comparison Ready** when FR present |
| `raw_ocr_text` | **Byte-identical** to value captured right after Phase 1 |

**Phase 3 skip reasons (acceptable when intentional)**

| Reason | Meaning |
|--------|---------|
| `phase3_gate_disabled` | Master or phase3 env off |
| `bulk_item_ineligible` | Text-only item |
| `reused_transcript` | Duplicate file |
| `no_usable_ocr_attempts` | Empty/failed OCR |
| `assembled_parse_input_too_short` | Quality gate |

---

### 6.4 Phase 4 — Sarvam judge

**Steps**

1. Confirm Sarvam env vars (§2.3) on staging.
2. Use batch with mix of clean and problematic biodata (§5.2).
3. After processing, for each intake record: trigger outcome + attempts.

**Expected results**

| Check | Expected |
|-------|----------|
| Triggers evaluated | Only `full_name`, `date_of_birth`, `primary_contact_number`, `religion` |
| Gender-only missing | Log `phase4_sarvam_judge_skipped` / skip reason `no_triggers` — **no HTTP** |
| All triggers clean | Skip `no_triggers` — **no HTTP** |
| Trigger fired + Sarvam success | Log `phase4_sarvam_judge_resolved`; FR updated; `last_parse_input_text` rebuilt if merge changed |
| Sarvam HTTP timeout/500 | Log `phase4_sarvam_http_soft_failed`; Phase 3 data **preserved**; intake **not** failed |
| Sarvam success | New append-only `ocr_attempt` row: `engine = sarvam_ai_vision`, `status = success` |
| Sarvam soft-fail | **No** new Sarvam attempt row |
| Merge no improvement | Log `phase4_sarvam_merge_noop`; FR unchanged |
| `raw_ocr_text` | **Unchanged** after judge |
| Bulk badge | **Sarvam Reviewed** when Sarvam attempt or FR `sarvam_judge` source present |

**Trigger-rate calculation (P5-B3)**

```text
trigger_rate = (items where Sarvam HTTP was attempted) / (ensemble file items in batch)
Target: ≤ 20%
```

Count from logs (`phase4_sarvam_judge_resolved`, `phase4_sarvam_http_soft_failed`) or DB (presence of `sarvam_ai_vision` attempt after Phase 3).

---

### 6.5 Parse (ParseIntakeJob)

**Steps**

1. After §6.2–6.4 items complete, verify parse status in admin or DB.
2. Pick 3 intakes with `last_parse_input_text` set.

**Expected results**

| Check | Expected |
|-------|----------|
| `parse_status` | `parsed` (or existing equivalent success state) |
| `parsed_json` | Populated |
| Parse input source | Prefers assembled text when `last_parse_input_text` non-empty |
| Flag-off regression | Upload with ensemble OFF still parses via legacy path |
| Queue | `ParseIntakeJob` on **`bulk-intake`** queue |

---

### 6.6 Correct Candidate (correction flow)

**Steps**

1. Open `/admin/bulk-intakes/{batch}/items/{item}/correct-candidate` for a parsed ensemble item.
2. Verify correction form loads (existing fields, image preview, save button if eligible).
3. Save a **minor non-destructive** correction on 1 item (staging test data only).
4. Repeat for 1 legacy (flag-off) item.

**Expected results**

| Check | Expected |
|-------|----------|
| Page loads | 200 for authorized admin |
| Correction form | Unchanged behavior; save succeeds |
| Non-admin | 403 |
| Comparison panel | Visible below/alongside form (§6.7) |
| After save | Redirect/flash success; no comparison write side-effects |

---

### 6.7 OCR Comparison (Phase 5 — Correct Candidate)

**Steps**

1. On Correct Candidate page for ensemble item **with** `field_resolution_json`:
   - Confirm panel `data-testid="ocr-comparison-review"`.
   - Confirm outcome `resolved`, table with 16 canonical rows.
2. Open item **without** FR (legacy or skipped Phase 3):
   - Outcome `empty` or `skipped` with notice.
3. Temporarily set `OCR_ENSEMBLE_PHASE5_ENABLED=false`, reload Correct Candidate:
   - Outcome `skipped`, reason `phase5_gate_disabled`.
4. Legacy URL: `/admin/biodata-intakes/{intake}/ocr-comparison`:
   - Redirects to Correct Candidate when bulk item exists.
5. Non-admin → 403.

**Expected results**

| Check | Expected |
|-------|----------|
| Surface | Panel on **Correct Candidate only** (not full table on bulk dense list) |
| Columns | Field, Final, Tesseract, Second OCR, Sarvam, Reason (+ Status/Source badges) |
| Second OCR column | Often **empty** (Phase 2 NO-GO) — expected |
| Missing engine | Empty cell / dash — not fabricated |
| Final column | Matches `field_resolution_json` SSOT |
| Reason column | Vote / validator / sarvam_judge text present where applicable |
| Resolved finals | Highlighted (`ocr-comparison-final-highlight`) |
| Phase 5 | **Zero DB writes** from viewing comparison |

---

### 6.8 Bulk list badges (Phase 5 — status only)

**Steps**

1. Open `/admin/bulk-intakes/{batch}` (dense list).
2. Identify rows from: flag-off legacy, flag-on ensemble, empty OCR, Sarvam-merged.

**Expected results**

| Badge | When visible |
|-------|--------------|
| **OCR Complete** | `item_meta.ocr_ensemble_status = ocr_ready` |
| **Phase 3 Complete** | Non-empty `field_resolution_json` |
| **Comparison Ready** | Same as Phase 3 Complete |
| **Sarvam Reviewed** | Sarvam success attempt or FR sarvam_judge source |
| **Awaiting Review** | `ocr_ensemble_processing` OR (`ocr_ready` without FR) |
| **Legacy Path** | No ensemble path; has OCR transcript/attempts |
| **No OCR** | No ensemble path; empty transcript; no attempts |

| Check | Expected |
|-------|----------|
| Container | `data-testid="bulk-ocr-ensemble-badges"` per row |
| Order | Deterministic (OCR Complete → Phase 3 → Sarvam → Comparison Ready → Awaiting Review → Legacy/No OCR) |
| No full comparison table | On bulk list |

---

## 7. Expected results summary (quick reference)

| Stage | Pass criteria |
|-------|---------------|
| Flag OFF upload | No FR; no ensemble meta; legacy behavior |
| Phase 1 | `ocr_ready`, Tesseract attempt, immutable `raw_ocr_text` |
| Phase 3 | FR + assembled parse input; gender missing OK |
| Phase 4 | Triggers only on 4 fields; soft-fail safe; optional Sarvam attempt |
| Parse | `parsed_json` populated; prefers assembled input |
| Correct Candidate | Form works; comparison panel embedded |
| Phase 5 UI | Read-only; 16 rows; empty Second OCR OK |
| Bulk badges | Correct chips per metadata |
| Rollback | Flag OFF restores legacy on **new** uploads |

---

## 8. Failure diagnosis matrix

| Symptom | Likely cause | Check | Action |
|---------|--------------|-------|--------|
| Items stuck pending | Worker not running / wrong queue | `bulk-intake` worker status, failed jobs table | Start worker; retry failed jobs |
| No `ocr_ensemble_status` with flag ON | Admin setting not saved / wrong environment | Intake Settings checkbox; `admin_settings` row | Re-save setting; clear config cache |
| No `field_resolution_json` | Phase 3 gate off / empty OCR / assembled text too short | Env vars; OCR attempts; skip reason in logs | Fix OCR quality; check `OCR_ENSEMBLE_PHASE3_ENABLED` |
| Phase 3 never runs | Master flag off; text item; reused transcript | `item_meta`, item input type | Expected skip — document |
| Sarvam never called | No triggers; Phase 4 off; missing API key | Logs for `no_triggers`; env key | Expected if fields clean; else fix config |
| Sarvam called every item | Bad batch / all critical fields missing | FR contents per intake | Review images; check trigger evaluator inputs |
| Sarvam errors but intake failed | **Should not happen** (soft-fail) | Stack trace | **Escalate** — contract violation |
| `raw_ocr_text` changed after pipeline | **Should not happen** | Compare before/after snapshots | **Stop rollout** — SSOT violation |
| Parse fails after Phase 3 | Assembled text malformed / parser error | `last_parse_input_text` sample; parse logs | Test legacy parse; file issue |
| Comparison `skipped` | Phase 5 env false or master off | `OCR_ENSEMBLE_PHASE5_ENABLED` | Enable for UI drill |
| Comparison `empty` | No FR on intake | DB `field_resolution_json` | Run Phase 3 path first |
| Second OCR column empty | Phase 2 NO-GO | Benchmark doc | **Expected** — not a defect |
| Badges missing | Batch show not loading intake relations | Hard refresh; different item types | Verify ensemble vs legacy rows |
| 403 on Correct Candidate | Missing admin section | User roles | Use authorized admin account |
| Slow items (> 60s p95) | Large PDFs / CPU saturation | Worker CPU; item timing in logs | Reduce concurrency; staging host sizing |

### Log patterns to search

| Pattern | Meaning |
|---------|---------|
| `phase3_field_resolution_failed` | Phase 3 exception — investigate stack |
| `phase4_sarvam_judge_skipped` | No Sarvam call (often `no_triggers`) |
| `phase4_sarvam_judge_resolved` | Successful merge |
| `phase4_sarvam_http_soft_failed` | Sarvam HTTP fail — intake continues |
| `phase4_sarvam_merge_noop` | Sarvam returned no improvements |
| `phase4_assembled_parse_input_too_short` | Post-merge quality gate block |

---

## 9. Rollback procedure

Rollback is **flag-first** — no destructive DB operations required.

### 9.1 Instant rollback (preferred)

| Level | Action | Effect |
|-------|--------|--------|
| **R1** | Admin → Intake Settings → **uncheck** OCR ensemble | New uploads use legacy path immediately |
| **R2** | Set `OCR_ENSEMBLE_PHASE4_ENABLED=false` | Disables Sarvam judge only |
| **R3** | Set `OCR_ENSEMBLE_PHASE3_ENABLED=false` | Disables Phase 3 only |
| **R4** | Set `OCR_ENSEMBLE_PHASE5_ENABLED=false` | Hides comparison panel (skipped state) |

After `.env` changes: `php artisan config:clear` + reload PHP workers.

### 9.2 Rollback validation (required once per staging cycle)

1. Toggle master ensemble **OFF**.
2. Upload 1 new biodata file.
3. Confirm: no new FR; legacy behavior; badges show Legacy Path / No OCR.

### 9.3 Data notes

| Artifact | Rollback impact |
|----------|-----------------|
| Existing `field_resolution_json` | Remains in DB — inert when flag off |
| Existing `ocr_attempts` | Remain — append-only history |
| `raw_ocr_text` | Never modified by rollback |
| Phase 5 UI | Read-only — wrote nothing |

### 9.4 Code rollback (last resort)

Deploy previous release tag. **No** migration rollback required for ensemble additive schema.

---

## 10. Production go/no-go checklist

**Default decision: NO-GO** until all blocking rows checked.

### 10.1 Blocking (must pass)

| # | Criterion | Evidence | Pass |
|---|-----------|----------|------|
| G-01 | Staging S1–S8 complete without SSOT violations | §11 pack | ☐ |
| G-02 | `raw_ocr_text` immutability spot-check (≥ 3 intakes) | Before/after SQL or export | ☐ |
| G-03 | Flag-off regression confirmed on staging | S1 + rollback drill | ☐ |
| G-04 | Correction save smoke passed (2 items) | Screenshot / notes | ☐ |
| G-05 | Sarvam live drill complete | Staging logs | ☐ |
| G-06 | Sarvam trigger rate ≤ 20% on staging sample | §6.4 calculation | ☐ |
| G-07 | Automated suite baseline recorded | 196 / 1032 | ☐ |
| G-08 | Product owner sign-off (**P5-B4**) | Signed checklist | ☐ |
| G-09 | Production enable window + rollback owner assigned | Ops ticket | ☐ |
| G-10 | Production `intake_ocr_ensemble_enabled` still **false** until G-08 | DB check | ☐ |

### 10.2 Recommended (non-blocking but track)

| # | Criterion | Pass |
|---|-----------|------|
| G-11 | Ground-truth 10-image extract score recorded (**3.F01**) | ☐ |
| G-12 | p95 worker time < 60s on staging sample | ☐ |
| G-13 | 48h monitoring plan documented for post-enable | ☐ |

### 10.3 Verdict matrix

| Condition | Verdict |
|-----------|---------|
| G-01–G-04 pass; G-05–G-06 open | **READY FOR STAGING** (continue ops drills) |
| G-01–G-10 pass | **READY FOR PRODUCTION ENABLE** (progressive flag rollout) |
| SSOT violation or flag-off regression fails | **NOT READY** — stop |

---

## 11. Evidence pack for product sign-off

Create folder: `docs/evidence/ocr-ensemble-staging-YYYY-MM-DD/` (or secure ops drive — **no PII in git**).

### 11.1 Required artifacts

| # | Artifact | Description |
|---|----------|-------------|
| E-01 | **Runbook checklist** | This document with §1, §4, §10 checkboxes filled |
| E-02 | **Batch summary table** | Batch ID, item count, flag on/off, pass/fail |
| E-03 | **Screenshot: Intake Settings** | Ensemble checkbox state (staging) |
| E-04 | **Screenshot: Bulk list badges** | At least 3 rows: legacy, Phase 3 complete, Sarvam reviewed |
| E-05 | **Screenshot: Correct Candidate comparison** | Resolved outcome — full table visible |
| E-06 | **Screenshot: Comparison empty/skipped** | Legacy or gate-disabled state |
| E-07 | **Screenshot: Correction save success** | Flash message after save |
| E-08 | **Screenshot: Second OCR empty column** | Documents Phase 2 NO-GO expectation |
| E-09 | **Log excerpt** | One `phase4_sarvam_judge_resolved` (if triggered) |
| E-10 | **Log excerpt** | One `phase4_sarvam_judge_skipped` with `no_triggers` |
| E-11 | **Log excerpt** | One soft-fail example OR note "none observed" |
| E-12 | **DB snapshot (redacted)** | Query outputs for 2 intakes: attempts, FR presence, raw_ocr unchanged |
| E-13 | **Trigger-rate worksheet** | Numerator/denominator + percentage |
| E-14 | **Timing notes** | Avg/p95 item duration for 5+ items |
| E-15 | **Rollback proof** | Screenshot/log after flag-off upload |
| E-16 | **Test command output** | `php artisan test --filter=OcrEnsemblePhase` summary line |
| E-17 | **Production flag confirmation** | Screenshot/ query showing prod master flag **false** |

### 11.2 Redaction rules

- Redact mobile numbers, names, addresses in screenshots and SQL exports.
- Store raw biodata images only in private ops storage — **never commit**.

### 11.3 Sign-off block (copy into evidence folder)

```text
OCR Ensemble v1.0 — Staging Validation Sign-off

Staging environment: _______________________
Run date: _______________________
Batch IDs tested: _______________________

QA lead: _______________  Date: ______  Pass / Fail
Ops lead: _______________  Date: ______  Pass / Fail
Product owner: __________  Date: ______  Approve prod enable Y / N

Sarvam trigger rate: _______ % (target ≤ 20%)
raw_ocr_text immutability: Pass / Fail
Rollback drill: Pass / Fail

Notes:
```

---

## 12. Related documents

| Document | Use |
|----------|-----|
| `OCR-ENSEMBLE-PIPELINE-BLUEPRINT.md` | Design authority §13 acceptance |
| `OCR-ENSEMBLE-PHASE-CONTRACTS.md` | Per-phase contracts |
| `OCR-ENSEMBLE-PHASE-5-VALIDATION-AND-ROLLOUT.md` | Architecture audit + blocker status |
| `OCR-ENSEMBLE-IMPLEMENTATION-CHECKLIST.md` | Phase freeze checklist |
| `OCR-ENSEMBLE-TEST-PLAN.md` | Ground truth + metric thresholds |
| `OCR-ENSEMBLE-PHASE-1-RELEASE-NOTES.md` | Phase 1 staging Batch #44 reference |
| `docs/ocr-ensemble-benchmark-v1.md` | Phase 2 NO-GO record |

---

## Document history

| Version | Date | Change |
|---------|------|--------|
| 1.0 | 2026-07-14 | Initial staging validation runbook — OCR Ensemble v1.0 code complete |
