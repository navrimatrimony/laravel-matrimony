# OCR Ensemble Benchmark v2 — Sprint 2 (Batch-001)

> **Status:** **CLOSED** — all §19.3 candidate engines have written GO/NO-GO on GT-20  
> **Date:** 2026-07-15  
> **Dataset:** `storage/app/ocr-dev-batches/Batch-001` (51 files) + **GT-20** human labels  
> **Authority:** Blueprint §19.3 Sprint 2 + §19.4 + Success Criteria §3 + DOC  
> **Transition:** No engine met +5 pp uplift → **Sprint 3 SKIPPED** → **Sprint 4 next**

---

## 0. Sprint 2 checklist (Blueprint §19)

| Candidate | Benchmark | GO/NO-GO written |
|-----------|-----------|------------------|
| Tesseract (baseline) | ✓ GT-20 | Baseline — keep primary |
| EasyOCR | ✓ GT-20 | **NO-GO** |
| PaddleOCR v5 (`paddleocr` 3.7 / `paddlepaddle` 3.2.2) | ✓ GT-20 | **NO-GO** |
| DocTR (`python-doctr` 0.12) | ✓ GT-20 | **NO-GO** |

**Can Sprint 2 be closed?** **Yes** — §19.4 “New benchmark doc + GO/NO-GO per engine” satisfied.

---

## 1. Final decision (GT-20)

| Engine | Critical accuracy (5 fields) | Delta vs Tesseract | Decision |
|--------|-----------------------------:|-------------------:|----------|
| **phase1_tesseract** | **42.11%** (40/95 cells) | — | **Baseline — keep as primary** |
| **easyocr_v1** | **27.37%** (26/95 cells) | **−14.74 pp** | **NO-GO** — do not integrate |
| **paddleocr_v1** | **14.74%** (14/95 cells) | **−27.37 pp** | **NO-GO** — do not integrate |
| **doctr_v1** | **4.21%** (4/95 cells) | **−37.90 pp** | **NO-GO** — do not integrate |

**Gate rule:** GO requires ≥ **+5 pp** critical uplift vs Tesseract on the labeled set.  
**No candidate GO** → production remains **Tesseract-only**; **Sprint 3 multi-OCR vote is not opened**.

### Per-field accuracy (GT-20)

| Field | Tesseract | EasyOCR | PaddleOCR | DocTR |
|-------|----------:|--------:|----------:|------:|
| full_name | 30.0% | 10.0% | 15.0% | 0.0% |
| date_of_birth | 25.0% | 25.0% | 5.0% | 0.0% |
| primary_contact_number | 55.6% | 55.6% | 27.8% | 22.2% |
| religion | 47.1% | 23.5% | 5.9% | 0.0% |
| gender | 55.0% | 25.0% | 20.0% | 0.0% |

Notes:

- GT-20 was intentionally **hard-heavy** (many OCR-fail DOB cases) so absolute % is lower than Stage A proxy on easy scans. Relative ranking still clear.  
- Image CLI engines: PDFs skipped → scored as miss when truth present (same rule for EasyOCR / Paddle / DocTR).  
- **Primary mobile only** used in GT (product: alternate mobiles for consent fallback = separate track; not required for OCR engine GO/NO-GO).  
- Scorer / extract path shared: same Phase 3 field extractor + GT matcher as Tesseract/EasyOCR comparison.

### Artifacts

| Engine | Path |
|--------|------|
| Tesseract + EasyOCR | `storage/app/private/ocr-ensemble-benchmark/sprint2_gt20_score_20260715_130342.json` |
| PaddleOCR | `storage/app/private/ocr-ensemble-benchmark/sprint2_gt20_paddleocr_v1_20260715_140422.json` |
| DocTR | `storage/app/private/ocr-ensemble-benchmark/sprint2_gt20_doctr_v1_20260715_140757.json` |

---

## 2. Dataset

| Field | Value |
|-------|-------|
| Inbox | `storage/app/ocr-dev-batches/Batch-001` |
| Count | 51 media files |
| GT | `ground-truth/gt-20.csv` (20 human-verified rows) |
| Bulk ingest | local batch id `3` |

---

## 3. Pre-GT probe (kept for history)

| Engine | Run | Proxy DOB | Avg time | Empty |
|--------|-----|----------:|---------:|------:|
| Tesseract | Full 51 | 70.59% | 50.0 s | 1.96% |
| Tesseract | Stage A 10 | 100% | ~54 s | 0% |
| EasyOCR | Stage A 10 | 60% | 81.1 s | 0% |

Proxy ≠ truth. Formal decision is §1 only.

---

## 4. Ops notes (local sidecars)

| Engine | Venv | Notes |
|--------|------|-------|
| EasyOCR | `C:\eov` | Short path (Win path-length); UTF-8 stdout |
| PaddleOCR | `C:\pov` | Python **3.12** (`py -3.12`); `paddlepaddle==3.2.2` + `paddleocr==3.7.0`; `lang=hi`; mkldnn off; first run downloads PaddleX models |
| DocTR | `C:\dov` | `python-doctr==0.12.0`; first run downloads weights; CLI redirects download chatter off stdout |

- Folder ingest: `php artisan ocr-ensemble:benchmark-ingest-folder`  
- Agent scorer: `php storage/app/_agent_sprint2_engine_gt20.php <engine> <python.exe> <runner.py>`

---

## 5. Product implications

1. **Sprint 3 skipped** — no second-engine GO → no multi-OCR production vote this program cycle.  
2. Production path remains **Tesseract-only** under the ensemble flag.  
3. Future engine generations require a **new** GT benchmark + written GO (Phase 2 / Sprint 2 NO-GO bind their vintage, not forever).  
4. Consent **secondary mobile** remains a product workflow (not an OCR GT requirement).  
5. **Next locked sprint:** Sprint 4 — Knowledge / Learning **design** (Blueprint §19.3 / §19.4).

---

## 6. Sign-off

| Role | Decision | Date |
|------|----------|------|
| Agent (DOC) | Sprint 2 **CLOSED**; EasyOCR / PaddleOCR / DocTR all **NO-GO**; Tesseract baseline; Sprint 3 **SKIPPED** | 2026-07-15 |
| Product | (production flag still off until separate release approval) | — |
