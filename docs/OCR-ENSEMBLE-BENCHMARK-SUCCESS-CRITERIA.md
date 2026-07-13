# OCR Ensemble — Benchmark Success Criteria

> **Status:** FROZEN (Phase 2 gate reference)  
> **Date:** 2026-07-13  
> **Parent:** `OCR-ENSEMBLE-TEST-PLAN.md` §2.3–2.5 + `OCR-ENSEMBLE-PHASE-CONTRACTS.md` §Phase 2  
> **Ground truth SSOT:** Batch #43 `approval_snapshot_json` (10 admin-verified biodatas)

---

## 1. Purpose

Objective go/no-go rules for **Phase 2 second OCR engine** integration.  
Scores are measured against **admin verified truth** (`approval_snapshot_json`), not machine `parsed_json` alone.

**No production integration code** until this document’s Phase 2 gate (§3) is evaluated and signed in `docs/ocr-ensemble-benchmark-v1.md`.

---

## 2. Scoring fields

### 2.1 Critical five (Phase 2 go/no-go driver)

| Field key | Label |
|-----------|-------|
| `full_name` | Name |
| `date_of_birth` | DOB |
| `primary_contact_number` | Mobile |
| `religion` | Religion |
| `gender` | Gender |

**Critical-field accuracy (one number):** % of (image × critical field) cells that match ground truth after normalization (Test Plan §2.5).

### 2.2 Full sixteen (reporting + Phase 3 program target)

Blueprint §3.1 — all scored separately in 50-image reports; **not** the Phase 2 integration gate unless noted.

`full_name`, `date_of_birth`, `gender`, `primary_contact_number`, `height`, `education`, `occupation`, `income`, `religion`, `caste`, `sub_caste`, `state`, `district`, `taluka`, `village`, `marital_status`

### 2.3 Normalization (frozen)

Per `OCR-ENSEMBLE-TEST-PLAN.md` §2.5 — DOB `YYYY-MM-DD`, mobile last 10 digits, name fuzzy ≥0.92 or exact, religion/caste master match, etc.

---

## 3. Phase 2 integration gate (GO / NO-GO)

### 3.1 Primary rule (frozen — blueprint)

```
GO  IF  critical_accuracy(second_engine)
         >= critical_accuracy(phase1_tesseract_baseline) + 5 percentage points
     AND  no unacceptable regression on any critical field (§3.3)
     AND  sidecar ops acceptable (§3.4)

NO-GO  OTHERWISE  →  Tesseract-only through Phase 5; document in benchmark report
```

This is a **relative uplift** rule, not “second engine must hit 98% in isolation.”

### 3.2 Benchmark stages

| Stage | Dataset | Purpose | Advance if |
|-------|---------|---------|------------|
| **Tech check** | 10 images (from Batch #43 truth set + layout mix) | Compare candidate engines | Best candidate meets §3.1 on 10-image set |
| **Decision** | 50 verified images | Confirm uplift + ops | §3.1 confirmed; product sign-off |

### 3.3 Unacceptable regression (NO-GO triggers)

Even if average critical accuracy improves ≥5%, **NO-GO** if any of:

| Rule | Threshold |
|------|-----------|
| Critical-field accuracy **drops** vs Phase 1 baseline on same image set | > **2 pp** on aggregate critical accuracy |
| Mobile field accuracy (second engine) | **Below Phase 1 baseline** on 50-image set |
| Job failure rate (benchmark runs) | **≥ 1%** excluding known bad scans |
| Any single critical field | Second engine **worse than baseline** on **> 20%** of cases in 50-image set |

### 3.4 Sidecar ops (GO requires all)

| Check | Requirement |
|-------|-------------|
| Deploy | Reproducible on staging (not laptop-only) |
| Health | `GET /health` stable |
| RAM | Fits server budget (document actual MB) |
| Timeout | Within configured budget; failed calls **do not** fail intake job (Phase 2 contract) |

### 3.5 Timing (benchmark + staging)

| Metric | Threshold | Baseline |
|--------|-----------|----------|
| Phase 1 avg worker (Tesseract-only) | **< 40 s** | Already validated Batch #44 |
| With second engine — avg | **≤ 2× Phase 1** measured on same 5-image staging batch |
| With second engine — p95 | **< 60 s** | Test Plan §2.3 |

---

## 4. Program-level targets (NOT Phase 2 integration gate)

These measure **end-state pipeline** (after Phase 3–4), on **50-image** ground truth:

| Metric | Target | When |
|--------|--------|------|
| Overall field accuracy (16 fields) | **> 95%** | Post Phase 3 |
| Critical field accuracy (5 fields) | **> 98%** | 50-image set (program) |
| Sarvam trigger rate | **< 20%** | Post Phase 4 |
| Job failure rate | **< 1%** | Production / staging |

**Do not block Phase 2 integration** on 98% absolute critical accuracy — Phase 2 only asks whether a **second engine beats Phase 1 Tesseract by +5%** with acceptable ops/regression.

Per-field aspirational reporting (e.g. mobile exact-match rate) belongs in `ocr-ensemble-benchmark-v1.md` tables, not as extra frozen gates unless product amends this doc.

---

## 5. Ground truth workflow (frozen)

```
Batch #43  →  approval_snapshot_json  →  benchmark scoring SSOT
Batch #44  →  Phase 1 pipeline validation only (not re-scored for Phase 2 gate)
```

Expand 50-image set: additional batches with admin-verified snapshots per golden dataset runbook.

---

## 6. Artifacts required before integration

| Artifact | Owner |
|----------|-------|
| `docs/ocr-ensemble-benchmark-v1.md` | Dev + Product |
| 10-image score tables | Dev |
| 50-image score tables (if Stage 1 pass) | Dev |
| Signed go/no-go line | Product |

---

## 7. Document history

| Date | Change |
|------|--------|
| 2026-07-13 | Initial frozen criteria — aligned to Test Plan §2.3–2.4 |

**Related:** `OCR-ENSEMBLE-PHASE-2-IMPLEMENTATION-PLAN.md`, `OCR-ENSEMBLE-IMPLEMENTATION-CHECKLIST.md` §2.01–2.04
