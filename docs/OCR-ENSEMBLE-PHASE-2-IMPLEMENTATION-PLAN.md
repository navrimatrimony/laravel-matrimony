# OCR Ensemble — Phase 2 Implementation Plan

> **Status:** PLAN ONLY — **no code until benchmark go/no-go**  
> **Date:** 2026-07-13  
> **Prerequisite:** Phase 1 FROZEN (`docs/OCR-ENSEMBLE-PHASE-1-RELEASE-NOTES.md`)  
> **Parent:** Blueprint v1.0 + Phase Contracts §Phase 2 + Test Plan §6

---

## 1. Phase 2 goal (one sentence)

Decide whether a **second OCR engine** (technology-neutral, benchmark-selected) earns integration by demonstrating **≥5% uplift on critical fields** vs Phase 1 Tesseract+preprocess — and if yes, integrate it as a **non-fatal HTTP sidecar** that adds a second `ocr_attempt` row per intake.

---

## 2. What Phase 2 is NOT

| Not in Phase 2 | Belongs to |
|----------------|------------|
| Field voting / `field_resolution_json` | Phase 3 |
| Sarvam judge | Phase 4 |
| Admin comparison UI table | Phase 5 |
| Parser / `ParseIntakeJob` changes | Phase 3 |
| EasyOCR/Paddle as fixed choice before benchmark | Rejected by blueprint |
| New diagnostics / admin features | Out of scope |

---

## 3. Ground truth SSOT (frozen — product decision)

| Source | Role |
|--------|------|
| **Batch #43** `approval_snapshot_json` | **Primary ground truth** — 10 admin-verified biodatas |
| Batch #44 | Phase 1 **pipeline** validation only (not re-scored for quality) |
| #735 / #736 / #737 | Same one biodata, three OCR paths — **OCR lab reference**, not three people |

**Scoring rule:** compare engine output fields against `approval_snapshot_json` (admin corrected), not `parsed_json` alone.

**Critical fields (go/no-go):** `full_name`, `date_of_birth`, `primary_contact_number`, `religion`, `gender`

**Full benchmark (50-image stage):** all 16 structured fields (blueprint §3.1) scored separately in report.

---

## 4. Delivery gate (mandatory order)

```
Phase 2
    ↓
A. Benchmark only (offline / scripts — NO production integration)
    ↓
B. Go/no-go decision recorded in docs/ocr-ensemble-benchmark-v1.md
    ↓
C. IF go → sidecar integration PR (focused, flag-gated)
    ↓
D. Phase 2 tests + staging simulation
    ↓
E. Phase 2 freeze
    ↓
Phase 3 only
```

**Never:** benchmark and sidecar integration in the same PR.  
**Never:** start Phase 3 before Phase 2 freeze (or documented no-go).

---

## 5. Workstream A — Benchmark (before any integration code)

### 5.1 Stage 1: 10-image technology check (checklist 2.01)

| Step | Action |
|------|--------|
| 1 | Select **10 cases** from Batch #43 ground truth (layout mix: table + photo-right) |
| 2 | For each image: run **Phase 1 pipeline** (preprocess + Tesseract) — baseline scores |
| 3 | For each **candidate second engine** (offline): run on same preprocessed image |
| 4 | Score critical 5 fields per case vs `approval_snapshot_json` |
| 5 | Record in `docs/ocr-ensemble-benchmark-v1.md` § Tech check |
| 6 | Pick ≤1 candidate for 50-image stage OR reject all |

**Candidate engines (evaluate only — do not hardcode):**

- PaddleOCR (HTTP sidecar)
- EasyOCR (HTTP sidecar)
- Others only if benchmark setup is trivial

**Pass to Stage 2 if:** best candidate shows **≥5% absolute uplift** on critical-field accuracy vs Tesseract baseline on the 10-image set.

### 5.2 Stage 2: 50-image decision benchmark (checklist 2.02)

| Step | Action |
|------|--------|
| 1 | Expand ground truth to **50 verified rows** (`approval_snapshot_json` or curated private dataset per golden runbook) |
| 2 | Re-run baseline + winning candidate from Stage 1 |
| 3 | Score critical 5 + all 16 fields; record timing p50/p95 |
| 4 | Apply go/no-go rule (§6) |
| 5 | Product sign-off on benchmark doc (checklist 2.04) |

### 5.3 Benchmark artifacts (checklist 2.03)

| Artifact | Location |
|----------|----------|
| Main report | `docs/ocr-ensemble-benchmark-v1.md` |
| Raw score tables | `docs/benchmark-results/` (gitignored if PII) or private storage |
| Repro script | TBD at implement — must be rerunnable (test 2.T03) |

**Existing helpers (reuse, do not fork):**

- `php tools/export_bulk_batch_ocr.php <batch_id>` — OCR text export
- `php artisan bulk-intake:compare-candidates` — field display comparison (two batches)
- Batch #43 intakes — `approval_snapshot_json` via DB or admin export

---

## 6. Go / no-go rule (frozen)

```
IF second_engine_critical_field_accuracy >= tesseract_accuracy + 5%
   AND sidecar ops acceptable (uptime, deploy, RAM, timeout budget)
THEN integrate second engine in production pipeline
ELSE stay Tesseract-only through Phase 5; document no-go in benchmark doc
```

**No-go path is valid:** Phase 3 proceeds with **single-engine vote pass-through** (one `ocr_attempt` only).

---

## 7. Workstream B — Integration (only if go)

### 7.1 Scope (checklist 2.05–2.09)

| # | Item | Implementation notes |
|---|------|----------------------|
| 2.05 | Python OCR sidecar on staging | Separate process/container; not embedded in PHP |
| 2.06 | HTTP client + health check | Timeout ≤ blueprint budget; config URL in `.env` |
| 2.07 | Second `ocr_attempt` row | Same intake; `is_primary` stays Tesseract until Phase 3 voting |
| 2.08 | Sidecar down → Tesseract-only | **Job must succeed**; warning log |
| 2.09 | Engine constant + display name | Technology-neutral `engine` enum + config label |

### 7.2 Expected code touchpoints (plan only)

| Component | Change |
|-----------|--------|
| `IntakeOcrEnsemblePhase2Service` (new) | Orchestrate second engine after Phase 1 extract |
| `config/ocr.php` | `ensemble.phase2.sidecar_url`, timeouts, engine id |
| `IntakeOcrEnsemblePhase1Service` or batch worker | Call Phase 2 service when flag on + sidecar configured |
| `IntakeOcrAttemptRecorder` | Record second attempt with distinct `engine` |
| `.env.example` | Document sidecar vars |

**No changes:** `BiodataParserService`, `ParseIntakeJob`, correction save, bulk UI (except optional status badge if already in Phase 1).

### 7.3 Sidecar contract (draft)

```
POST /ocr
Body: { image_base64 | image_url, preprocessing_version, language_hint }
Response: { text, duration_ms, engine_meta }
Timeout: configurable (default align with tesseract_multipass max_runtime)
Health: GET /health → 200
```

Exact schema frozen at integration start — not before benchmark completes.

### 7.4 Failure behavior (non-negotiable)

| Failure | System behavior |
|---------|-----------------|
| Sidecar timeout | Log warning; 1 attempt only; job succeeds |
| Sidecar 5xx | Same |
| Invalid JSON | Same |
| Sidecar OOM | Queue retry per config; no corrupt intake row |

---

## 8. Tests (after integration only)

| ID | Case |
|----|------|
| 2.T01 | Mock sidecar success → two `ocr_attempt` rows |
| 2.T02 | Mock sidecar timeout → one attempt; warning logged |
| 2.T03 | Benchmark script reproducible on fixed 10-image set |

Plus regression: Phase 1 tests unchanged with sidecar URL empty.

---

## 9. Staging simulation (post-integration)

| Step | Action |
|------|--------|
| 1 | Deploy sidecar to staging server |
| 2 | Set sidecar URL in `.env`; flag ON |
| 3 | Upload 5-file batch (can reuse Batch #44 pattern) |
| 4 | Verify 2 attempts/intake when sidecar healthy |
| 5 | Stop sidecar → verify 1 attempt; jobs still complete |
| 6 | Toggle sidecar URL off → Tesseract-only (2.R01) |

**Timing target:** p95 worker < 60s with second engine (Test Plan §2.3).

---

## 10. Phase 2 freeze criteria

| # | Item |
|---|------|
| 2.F01 | `docs/ocr-ensemble-benchmark-v1.md` complete with signed go/no-go |
| 2.F02 | Integration checklist done **OR** no-go documented with Tesseract-only path confirmed |

---

## 11. Recommended timeline

| Week | Focus |
|------|-------|
| 1 | Build/run 10-image benchmark scripts; score vs Batch #43 truth |
| 2 | Candidate engine comparison; tech-check report |
| 3 | Expand to 50 images (if Stage 1 pass); decision meeting |
| 4+ | **Only if go:** sidecar POC + integration PR + staging |

---

## 12. Open decisions (resolve at Phase 2 kickoff)

| # | Question | Owner |
|---|----------|-------|
| D1 | Which candidate engine(s) enter 10-image tech check? | Product + Dev |
| D2 | Sidecar hosting: same server vs separate VM? | DevOps |
| D3 | Private benchmark data path (PII not in git)? | Ops |
| D4 | 50-image set: all from production batches or curated upload? | Product |

---

## 13. Kickoff command (when approved)

Product says: **"Phase 2 benchmark सुरू कर"** → start Workstream A only (§5).  
Product says: **"Phase 2 integrate"** → only after go decision in benchmark doc.

---

## Document history

| Date | Change |
|------|--------|
| 2026-07-13 | Initial plan — Phase 1 frozen; Batch #43 = ground truth SSOT |

**Related:**

- `docs/OCR-ENSEMBLE-PHASE-1-RELEASE-NOTES.md`
- `docs/OCR-ENSEMBLE-IMPLEMENTATION-CHECKLIST.md` § Phase 2
- `docs/OCR-ENSEMBLE-PHASE-CONTRACTS.md` § Phase 2
- `docs/OCR-ENSEMBLE-TEST-PLAN.md` §6
