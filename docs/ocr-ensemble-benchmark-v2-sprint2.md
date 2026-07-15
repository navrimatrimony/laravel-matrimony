# OCR Ensemble Benchmark v2 — Sprint 2 (Batch-001)

> **Status:** PARTIAL — Stage A engine probe complete; **critical GO/NO-GO blocked on Ground Truth**  
> **Date:** 2026-07-15  
> **Dataset:** `storage/app/ocr-dev-batches/Batch-001` (51 real files)  
> **Bulk ingest:** local `bulk_intake_batches.id = 3` (queue processing continuing)  
> **Authority:** Blueprint §19.3 Sprint 2 + DOC §13.6–13.7  
> **Does not supersede** Phase 2 freeze in `ocr-ensemble-benchmark-v1.md` until GT-scored Stage B is signed

---

## 1. Decision snapshot (interim)

| Engine | Run | Proxy DOB extract | Avg OCR time | Empty OCR | Integration |
|--------|-----|-------------------|--------------|-----------|-------------|
| **phase1_tesseract** | Full Batch-001 (51) | **70.59%** | **50.0 s** | **1.96%** | **Baseline — keep** |
| **phase1_tesseract** | Stage A same 10 imgs | **100%** (10/10) | ~54 s | 0% | Baseline |
| **easyocr_v1** | Stage A 10 imgs | **60%** (6/10) | **81.1 s** | 0% | **Provisional NO-GO** (DOB worse + slower) |
| **paddleocr_v1** | Not run | — | — | — | **Incomplete** (sidecar not installed this session) |
| **DocTR** | Not run | — | — | — | **N/A** (no sidecar in repo) |

**Proxy metric** = Phase 3 field extractor found a value (not verified against admin truth).  
**Formal +5pp critical accuracy GO/NO-GO requires Ground Truth** (Success Criteria §3).

### Provisional product call (pre-GT)

- Do **not** integrate EasyOCR into production ensemble on this evidence.  
- Tesseract remains primary.  
- Re-evaluate EasyOCR only after GT scoring on ≥20 labeled images shows ≥ +5pp critical uplift on held-out set.  
- PaddleOCR / DocTR remain **benchmark incomplete** until runnable local/sidecar ops exist.

---

## 2. Dataset

| Field | Value |
|-------|-------|
| Inbox | `storage/app/ocr-dev-batches/Batch-001` |
| Count | 51 (29 jpeg + 17 jpg + 5 pdf) |
| Mix | Admin scans, WhatsApp photos, PDFs, mixed lighting |
| Ground truth at drop | None (per DOC) |

Artifacts:

- Tesseract full: `storage/app/private/ocr-ensemble-benchmark/sprint2_batch001_tesseract_folder_20260715_100050.json`
- EasyOCR Stage A: `storage/app/private/ocr-ensemble-benchmark/sprint2_batch001_easyocr_stageA_20260715_101543.json`

---

## 3. Tesseract baseline (full 51)

| Metric | Value |
|--------|------:|
| Image count | 51 |
| Empty OCR rate (`raw_len` < 20) | 1.96% |
| Avg OCR time | 49984 ms |
| Proxy DOB extract | 70.59% |
| Proxy mobile extract | 84.31% |
| Proxy name extract | 96.08% |

Notes:

- PDF `28.pdf` empty text extract (known PDF path limitation).  
- Multiple photo cases still null DOB → remaining OCR/layout problem for Sprint 2 engines / Sprint 1 gaps.

---

## 4. EasyOCR Stage A (same first 10 JPEGs)

| Metric | Tesseract (subset) | EasyOCR |
|--------|-------------------:|--------:|
| DOB proxy extract | 10/10 | 6/10 |
| Avg time | ~54 s | 81 s |
| Failures | 0 | 0 |
| Mobile disagreements vs Tesseract | — | several (needs GT) |

When both produced DOB, values matched on 6/6 overlapping cases. EasyOCR simply **missed** 4 DOBs that Tesseract extracted.

Ops fixes applied for this run:

- EasyOCR venv at short path `C:\eov` (Windows path-length limit under repo `.venv`)  
- `run_ocr.py` UTF-8 stdout reconfigure for Windows cp1252  
- `.env`: `OCR_ENSEMBLE_EASYOCR_PYTHON=C:\eov\Scripts\python.exe`

---

## 5. PaddleOCR / DocTR

| Engine | Status | Reason |
|--------|--------|--------|
| PaddleOCR v5 | Incomplete | Not installed in this sprint window; prior v1 freeze documented runtime issues |
| DocTR | Incomplete | No sidecar in `tools/` |

---

## 6. Ground Truth request (agent-owned)

To close formal GO/NO-GO, agent requests **20** labels (Batch-001 subset favoring hard cases + 3 clean controls).

Files (exact names in Batch-001):

1. `28.pdf`  
2. `27.pdf`  
3. `testing 16 to 20 pdf and with photo (1).pdf`  
4. `testing 16 to 20 pdf and with photo (2).pdf`  
5. `testing 16 to 20 pdf and with photo (3).pdf`  
6. `1.jpeg`  
7. `D (1).jpeg`  
8. `D (8).jpeg`  
9. `photo_2026-02-12_21-53-42.jpg`  
10. `photo_2026-06-05_10-32-45.jpg`  
11. `photo_2026-06-05_10-33-07.jpg`  
12. `photo_2026-06-05_10-33-15.jpg`  
13. `photo_2026-06-05_10-33-22.jpg`  
14. `photo_2026-06-06_14-44-04.jpg`  
15. `snehal.jpeg`  
16. `WhatsApp Image 2025-12-03 at 11.40.19 AM.jpeg`  
17. `WhatsApp Image 2026-06-17 at 7.28.14 PM.jpeg`  
18. `1.1.jpeg`  
19. `1.2.jpeg`  
20. `1.3.jpeg`  

Template CSV (local):  
`storage/app/ocr-dev-batches/Batch-001/ground-truth/gt-20-template.csv`

Columns: `filename,full_name,date_of_birth,primary_contact_number,gender,religion,notes`  
DOB format: `YYYY-MM-DD` or `DD/MM/YYYY`.

---

## 7. Tooling added this sprint

| Item | Purpose |
|------|---------|
| `php artisan ocr-ensemble:benchmark-ingest-folder` | Folder → bulk intake queue |
| EasyOCR UTF-8 CLI fix | Windows Marathi JSON |
| Short-path venv `C:\eov` | Avoid WinError 206 |

No production second-engine vote path added (Blueprint rule).

---

## 8. Next

1. User fills GT-20 sheet.  
2. Agent scores Tesseract + EasyOCR against GT (critical five).  
3. Written GO/NO-GO update to this doc (or v2.1).  
4. Only then Sprint 3 production multi-OCR if GO.
