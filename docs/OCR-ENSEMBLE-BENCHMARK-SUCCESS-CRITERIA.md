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

### 3.2 Benchmark dataset (frozen — anti-overfitting)

| Stage | Images | Source | Role |
|-------|--------|--------|------|
| **Stage A — Pilot** | **10** | Batch #43 only (`approval_snapshot_json`) | Engine comparison; pick candidate for Stage B |
| **Stage B — Validation** | **40** | **New images** — never used in Stage A or prior benchmarks | Confirm uplift generalizes |
| **Total decision set** | **50** | 10 + 40 | GO/NO-GO scored primarily on **Stage B**; Stage A is pilot only |

**Rules:**

- Stage B images must be **admin-verified** (`approval_snapshot_json`) before scoring.
- Stage B must **not** include Batch #43 or Batch #44 items.
- GO/NO-GO (§3.1) requires **+5% uplift on Stage B critical accuracy** vs Phase 1 baseline on the **same 40 images**.
- Stage A pass alone is **not** sufficient for integration.

### 3.3 Benchmark stages (workflow)

| Stage | Dataset | Purpose | Advance if |
|-------|---------|---------|------------|
| **Stage A (pilot)** | 10 from Batch #43 | Compare candidate engines offline | Best candidate qualifies for Stage B trial |
| **Stage B (validation)** | 40 new verified images | Unbiased decision | §3.1 met on Stage B + §3.4–3.6 |
| **Report** | 50 total | Signed benchmark doc | Product sign-off |

### 3.4 Required metrics (every benchmark run)

Capture **per engine** (Phase 1 baseline + each candidate) on each stage:

| Metric | Required | Notes |
|--------|----------|-------|
| Field accuracy | ✅ | Critical 5 + full 16 in report tables |
| OCR time | ✅ | p50 / p95 / max per image (ms) |
| Failure rate | ✅ | % jobs failed (exclude known bad scans) |
| Empty OCR rate | ✅ | % images with `raw_ocr_text` < 20 chars |
| Manual correction count | ✅ | Avg fields wrong vs `approval_snapshot_json` per image (proxy for admin burden) |

Record in `docs/ocr-ensemble-benchmark-v1.md` so accuracy vs performance trade-offs are visible.

### 3.5 Primary rule (frozen — blueprint)

```
GO  IF  critical_accuracy(second_engine, Stage B)
         >= critical_accuracy(phase1_baseline, Stage B) + 5 percentage points
     AND  no unacceptable regression (§3.6)
     AND  sidecar ops acceptable (§3.7)
     AND  metrics in §3.4 within thresholds

NO-GO  OTHERWISE  →  Tesseract-only through Phase 5
```

This is a **relative uplift** rule on **held-out validation images**, not “second engine must hit 98% in isolation.”

### 3.6 Unacceptable regression (NO-GO triggers)

Even if average critical accuracy improves ≥5%, **NO-GO** if any of:

| Rule | Threshold |
|------|-----------|
| Critical-field accuracy **drops** vs Phase 1 baseline on same image set | > **2 pp** on aggregate critical accuracy |
| Mobile field accuracy (second engine) | **Below Phase 1 baseline** on 50-image set |
| Job failure rate (benchmark runs) | **≥ 1%** excluding known bad scans |
| Empty OCR rate (Stage B) | **Higher than Phase 1 baseline** on same 40 images |
| Manual correction count (Stage B avg) | **Higher than Phase 1 baseline** on same 40 images |
| Any single critical field | Second engine **worse than baseline** on **> 20%** of cases in Stage B |

### 3.7 Sidecar ops (GO requires all)

| Check | Requirement |
|-------|-------------|
| Deploy | Reproducible on staging (not laptop-only) |
| Health | `GET /health` stable |
| RAM | Fits server budget (document actual MB) |
| Timeout | Within configured budget; failed calls **do not** fail intake job (Phase 2 contract) |

### 3.8 Timing (benchmark + staging)

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
Batch #43  →  approval_snapshot_json  →  Stage A pilot (10 images)
Batch #44  →  Phase 1 pipeline validation only (not in benchmark scoring)
New batch  →  40 admin-verified images  →  Stage B validation (held-out)
```

Stage B images: upload → admin correct-candidate → save → then score. No reuse of Batch #43/#44 files.

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
| 2026-07-13 | Stage A/B split (10 pilot + 40 held-out); required metrics §3.4 |

**Related:** `OCR-ENSEMBLE-PHASE-2-IMPLEMENTATION-PLAN.md`, `OCR-ENSEMBLE-IMPLEMENTATION-CHECKLIST.md` §2.01–2.04
