# OCR Ensemble Pipeline — Test Plan

> **Parent:** Blueprint v1.0, Phase Contracts v1.0, Production Readiness Review v1.0  
> **Version:** 1.0  
> **Date:** 2026-07-12  
> **Type:** Test strategy — **not** automated test code.

Private biodata images and PII stay in `storage/app/intake-golden-datasets/` per existing runbook — **never commit to git**.

---

## 1. Test objectives

| Objective | Measure |
|-----------|---------|
| Accuracy | Field-level match vs ground truth |
| Cost | Sarvam call rate |
| Reliability | Job success rate; fallback behavior |
| Performance | Worker duration p50 / p95 |
| SSOT | No `raw_ocr_text` mutation; no parser bypass |
| Rollback | Flag off = legacy behavior |

---

## 2. Benchmark rules (frozen before coding)

### 2.1 Critical fields (decision metrics)

These five fields drive **go/no-go** and **Sarvam trigger** evaluation:

| Field | Key |
|-------|-----|
| Name | `full_name` |
| DOB | `date_of_birth` |
| Religion | `religion` |
| Mobile | `primary_contact_number` |
| Village | `village` |

### 2.2 Full ensemble fields (16)

All blueprint §3.1 fields scored separately in full benchmark reports.

### 2.3 Success thresholds (program level)

| Metric | Target | When measured |
|--------|--------|---------------|
| Overall field accuracy (16 fields) | **> 95%** | 50-image set, post Phase 3 |
| Critical field accuracy (5 fields) | **> 98%** | 50-image set |
| Sarvam usage rate | **< 20%** | 50-image set, post Phase 4 |
| Average worker time | **< 40 sec** | Staging, Phase 1+ (Tesseract-only baseline) |
| p95 worker time | **< 60 sec** | Staging, with second engine + occasional Sarvam |
| Job failure rate | **< 1%** | Excluding known bad scans |

### 2.4 Second engine go/no-go (Phase 2)

```
IF critical_field_accuracy(second_engine) >= critical_field_accuracy(tesseract) + 5%
   AND sidecar ops acceptable (uptime, deploy, RAM)
THEN integrate second engine
ELSE Tesseract-only through Phase 5
```

### 2.5 Normalization rules for scoring

| Field type | Match rule |
|------------|------------|
| DOB | Same calendar date after normalize to `YYYY-MM-DD` |
| Mobile | Last 10 digits exact |
| Name | Normalized Devanagari strip prefixes; fuzzy ≥ 0.92 OR exact |
| Religion / caste | Master table ID or canonical label match |
| Village | Master ID or normalized string match |
| Height | Same cm within ±2 cm |
| Income | Same integer value (ignore formatting) |

---

## 3. Ground truth dataset

### 3.1 Location

```text
storage/app/intake-golden-datasets/ocr-ensemble/
  images/           # private image files (gitignored)
  ground-truth.csv  # operator-curated (gitignored)
  ground-truth.jsonl
  benchmark-results/
```

Use existing artisan helpers where applicable (`intake:golden-dataset-csv-template`).

### 3.2 CSV schema (per image)

| Column | Required | Example |
|--------|----------|---------|
| `case_id` | Yes | `GT-001` |
| `intake_id` | Optional | `735` |
| `layout_type` | Yes | `table` / `photo_right` / `mixed` |
| `image_path` | Yes | `images/gt-001.jpg` |
| `full_name` | Yes | `चि अविनाश अर्जुन खोडवे` |
| `date_of_birth` | Yes | `1992-01-04` |
| `gender` | If visible | `male` |
| `primary_contact_number` | If visible | `8149379216` |
| `religion` | Yes | `Hindu` |
| `caste` | If visible | `Maratha` |
| `sub_caste` | If visible | `96 Kuli` |
| `village` | If visible | `Solapur` |
| `district` | If visible | `Solapur` |
| `taluka` | If visible | `Akkalkot` |
| `state` | If visible | `Maharashtra` |
| `height` | If visible | `5 ft 6 in` |
| `education` | If visible | `M.Com, GDC&A` |
| `occupation` | If visible | `Senior executive...` |
| `income` | If visible | `853387` |
| `marital_status` | If visible | `never_married` |
| `verified_by` | Yes | operator name |
| `verified_at` | Yes | ISO date |
| `notes` | Optional | layout quirks |

**Rule:** Every value must be **human-verified** from image or trusted Sarvam output (#735). Never copy parser output as truth without verification.

### 3.3 Seed cases (start today)

| case_id | intake_id | Role | layout_type |
|---------|-----------|------|-------------|
| GT-735 | 735 | Sarvam ground truth | table |
| GT-736 | 736 | Tesseract bulk baseline | table |
| GT-737 | 737 | ML Kit reference (not voter) | table |
| GT-004–010 | — | Add 7 varied biodata | mix |

**Minimum before Phase 1 production flag on:** 10 rows.  
**Minimum before Phase 2 decision:** 50 rows.

---

## 4. Test cases by phase

### Phase 1

| ID | Case | Steps | Expected |
|----|------|-------|----------|
| P1-01 | Flag off regression | Bulk upload 3 files, flag false | Identical to current prod behavior |
| P1-02 | Flag on attempt | Bulk upload 1 file, flag true | ≥1 `ocr_attempt`; `raw_ocr_text` set |
| P1-03 | OpenCV degrade | Simulate missing OpenCV | Tesseract on original; job succeeds |
| P1-04 | Text bulk skip | Upload text-only item | No ensemble; existing text path |
| P1-05 | Empty OCR | Bad scan image | `empty_ocr_text` failure |
| P1-06 | Timing | 5 images staging | Avg < 40s Tesseract-only |
| P1-07 | Rollback | Flag off after on | Legacy path restored |

### Phase 2

| ID | Case | Steps | Expected |
|----|------|-------|----------|
| P2-01 | 10-image bench | Run benchmark script | Report file generated |
| P2-02 | 50-image bench | Full dataset | Go/no-go recorded |
| P2-03 | Sidecar up | Flag on, sidecar healthy | 2 attempts/intake |
| P2-04 | Sidecar down | Stop sidecar | 1 attempt; job succeeds |
| P2-05 | No-go path | Document rejection | Phase 3 without second engine |

### Phase 3

| ID | Case | Steps | Expected |
|----|------|-------|----------|
| P3-01 | GT-735 extract | Run ensemble on 735 image | Critical fields ≥ 98% |
| P3-02 | GT-736 extract | Compare to 735 | Measurable uplift vs baseline |
| P3-03 | Single engine | No-go Phase 2 | Vote pass-through works |
| P3-04 | Gender missing | Image without gender | Empty; no Sarvam |
| P3-05 | Parse chain | Full job | `parsed_json` populated |
| P3-06 | 50-image accuracy | Full bench | Overall > 95% |

### Phase 4

| ID | Case | Steps | Expected |
|----|------|-------|----------|
| P4-01 | No trigger | GT-735 clean path | Sarvam not called |
| P4-02 | DOB missing | Synthetic corrupt DOB | Sarvam called |
| P4-03 | Name conflict | Mock divergent engines | Sarvam called |
| P4-04 | Gender only missing | — | Sarvam NOT called |
| P4-05 | Sarvam down | Mock 500 | Intake needs_review; not failed |
| P4-06 | Trigger rate | 50-image set | < 20% |

### Phase 5

| ID | Case | Steps | Expected |
|----|------|-------|----------|
| P5-01 | Table visible | Open correct-candidate | 16 rows + reasons |
| P5-02 | List clean | Bulk index | No OCR columns |
| P5-03 | Legacy intake | Pre-ensemble intake | Empty state message |
| P5-04 | Save regression | Correct + save | Unchanged behavior |

---

## 5. Failure cases (must not crash system)

| ID | Failure | Expected system behavior |
|----|---------|-------------------------|
| F-01 | OpenCV crash | Log; fallback image; continue |
| F-02 | Tesseract empty | `empty_ocr_text` item status |
| F-03 | Sidecar timeout | Tesseract-only; warning |
| F-04 | Sidecar invalid JSON | Tesseract-only; warning |
| F-05 | All validators fail mobile | Field missing; Sarvam trigger (Phase 4) |
| F-06 | Sarvam timeout | Log; fields stay missing; admin review |
| F-07 | Sarvam 402/429 | Log; no retry storm; admin review |
| F-08 | Queue worker OOM | Item retry per queue config; no data corruption |
| F-09 | Duplicate file upload | Reuse transcript; skip re-ensemble |
| F-10 | PDF corrupt | Item failed with readable error |

---

## 6. Benchmark process

### 6.1 10-image technology check

```
1. Select 10 cases (include GT-735, GT-736, GT-737 + 7 layout mix)
2. Run: Tesseract + preprocess only
3. Run: each candidate second engine (offline, not production)
4. Score critical 5 fields per case
5. Record in benchmark-results/tech-check-v1.md
6. Decision: proceed to sidecar integration POC or reject engine
```

### 6.2 50-image decision benchmark

```
1. Complete 50 verified ground-truth rows
2. Run production pipeline on staging (per phase completed)
3. Score all 16 fields + critical 5
4. Record: accuracy, Sarvam %, timing p50/p95
5. Apply go/no-go rules §2.4
6. Sign benchmark doc
```

### 6.3 Ongoing regression (post v1.0)

```powershell
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/ocr-ensemble/ground-truth.jsonl
```

Extend existing OCR regression command when Phase 3 complete — **separate implementation task**.

---

## 7. Production simulation checklist

| Step | Action |
|------|--------|
| 1 | Deploy to staging with flag `false` — smoke test bulk |
| 2 | Enable flag on staging |
| 3 | Upload batch of 10 real biodata |
| 4 | Verify worker logs, timing, attempts |
| 5 | Admin correct-candidate review (Phase 5+) |
| 6 | Toggle flag `false` — verify rollback |
| 7 | Production: flag `false` deploy first; enable for pilot batch only |

---

## 8. Test responsibilities

| Role | Responsibility |
|------|----------------|
| Operator | Curate ground truth CSV; verify PII not in git |
| Developer | Automated tests per Implementation Checklist |
| Product | Accept benchmark thresholds; sign phase freeze |

---

## 9. Document history

| Version | Date | Change |
|---------|------|--------|
| 1.0 | 2026-07-12 | Initial test plan |

**Related documents:**

- `OCR-ENSEMBLE-PIPELINE-BLUEPRINT.md` v1.0  
- `OCR-ENSEMBLE-PHASE-CONTRACTS.md` v1.0  
- `OCR-ENSEMBLE-PRODUCTION-READINESS-REVIEW.md` v1.0  
- `OCR-ENSEMBLE-IMPLEMENTATION-CHECKLIST.md` v1.0  
- `INTAKE_GOLDEN_DATASET_PRIVATE_CURATION_RUNBOOK.md`
