# OCR Ensemble Benchmark v2 — Sprint 2 (Batch-001)

> **Status:** CLOSED for EasyOCR decision — **NO-GO** (GT-20 critical accuracy)  
> **Date:** 2026-07-15  
> **Dataset:** `storage/app/ocr-dev-batches/Batch-001` (51 files) + **GT-20** human labels  
> **Authority:** Blueprint §19.3 Sprint 2 + Success Criteria §3 + DOC  
> **Supersedes** interim proxy-only section of the same-day draft for EasyOCR decision

---

## 1. Final decision (GT-20)

| Engine | Critical accuracy (5 fields) | Delta vs Tesseract | Decision |
|--------|-----------------------------:|-------------------:|----------|
| **phase1_tesseract** | **42.11%** (40/95 cells) | — | **Baseline — keep as primary** |
| **easyocr_v1** | **27.37%** (26/95 cells) | **−14.74 pp** | **NO-GO** — do not integrate |
| **paddleocr_v1** | — | — | **Incomplete** (not run this sprint) |
| **DocTR** | — | — | **N/A** (no sidecar) |

**Gate rule:** GO requires ≥ **+5 pp** critical uplift vs Tesseract on labeled set.  
EasyOCR fails by a wide margin → **Tesseract-only** continues (same conclusion as Phase 2 freeze v1).

### Per-field accuracy (GT-20)

| Field | Tesseract | EasyOCR |
|-------|----------:|--------:|
| full_name | 30.0% | 10.0% |
| date_of_birth | 25.0% | 25.0% |
| primary_contact_number | 55.6% | 55.6% |
| religion | 47.1% | 23.5% |
| gender | 55.0% | 25.0% |

Notes:

- GT-20 was intentionally **hard-heavy** (many OCR-fail DOB cases) so absolute % is lower than Stage A proxy on easy scans. Relative ranking still clear.  
- EasyOCR PDFs skipped (image CLI only) → scored as miss when truth present (fair vs “skip and exclude”).  
- **Primary mobile only** used in GT (product: alternate mobiles for consent fallback = separate track; not required for OCR engine GO/NO-GO).

Artifact: `storage/app/private/ocr-ensemble-benchmark/sprint2_gt20_score_20260715_130342.json`

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

## 4. Ops notes (EasyOCR local)

- Venv short path: `C:\eov` (avoids WinError 206 under repo)  
- `OCR_ENSEMBLE_EASYOCR_PYTHON=C:\eov\Scripts\python.exe`  
- `run_ocr.py` UTF-8 stdout for Windows  
- Folder ingest: `php artisan ocr-ensemble:benchmark-ingest-folder`

---

## 5. Product implications

1. **No Sprint 3 multi-OCR vote** for EasyOCR (no GO).  
2. Production path remains **Tesseract-only** under ensemble flag.  
3. Optional later: PaddleOCR v5 / DocTR benchmark **only** if/when local sidecar works — new report required.  
4. Consent **secondary mobile** remains a product workflow (not an OCR GT requirement). Track separately from Sprint 2 scoring.  
5. Next locked sprint without second-engine GO: **Sprint 4 — Knowledge / Learning design** (Blueprint §19.3).

---

## 6. Sign-off

| Role | Decision | Date |
|------|----------|------|
| Agent (DOC) | EasyOCR **NO-GO**; Tesseract baseline | 2026-07-15 |
| Product | (production flag still off until separate release approval) | — |
