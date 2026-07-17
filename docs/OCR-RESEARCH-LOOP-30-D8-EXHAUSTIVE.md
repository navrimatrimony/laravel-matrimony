# OCR Research Loop 30 — Exhaust RAW approaches on D(8) DOB

> **Status:** COMPLETE — Proven Mode A OCR limitation (invent forbidden)  
> **Baseline held:** `product_metrics_gt20_20260717_101021.json` @ **98.9%**  
> **Decision:** No production change. No reproducible RAW method extracts day **21**.

## Product Owner Status (§24) — post Loop 30

| # | Item | Value |
|---|------|-------|
| 1 | Overall Goal Completion | Critical **98.9%** (93/94); 1 Mode A residual |
| 2 | Current Stage | Research closed on D8 day-glyph under current engines |
| 3 | Current Activity | Loop 30 A–G evidence pack complete |
| 4 | Highest Priority Problem | D8 DOB day reads **24/28/20** — never clean **21** |
| 5 | Remaining Major Work | PO GT revisit **or** new engine/SR class not yet in stack |
| 6 | Estimated Remaining Time | Hold baseline unless PO escalates |
| 7 | Current Blockers | None technical — fidelity wall on this glyph |
| 8 | Next Automatic Step | Hold; no invent; no D8 hardcode |
| 9 | Last Stable Commit | (docs/tools for Loop 30 pending commit if requested) |
| 10 | Exact Resume Point | After Loop 30 limitation classification |

---

## Source (ORIGINAL)

| Property | Value |
|----------|-------|
| Path | `storage/app/ocr-dev-batches/Batch-001/D (8).jpeg` |
| Size | 720×1016 |
| Bytes | 264706 |
| JPEG quality | 100 |
| SHA-256 | (see `loop30_evidence_ab_cde.json` → `meta.sha256`) |

Tight DOB crop: `storage/app/private/ocr-temp/d8-loop30/crops/dob_line_tight.png`

---

## A. Multi-engine verification (tight DOB crop + original)

| Engine | Target | Raw (snip) | Conf | Time |
|--------|--------|------------|------|------|
| **Tesseract** mar+eng PSM6 (prod) | tight | `ज्म :२४०३/१९९९ …` | 64.54 | ~276ms |
| Tesseract mar+eng PSM11 | tight | `२४/०३/९९९९ …` | 75.31 | ~259ms |
| Tesseract mar+eng PSM4/3 | tight | garbled / no clean 21 | ~58–61 | ~240–320ms |
| Multipass full original | full page | DOBish day **२४** | (score in debug) | multipass |
| **EasyOCR** hi | tight | `20/07/7090` | 0.3185 | ~10s |
| EasyOCR | original | `20/07/7090` near जन्म | 0.3336 | ~98s |
| **PaddleOCR** hi | tight | `२८/०३/१९९९` | n/a | ~5.7s |
| PaddleOCR | original | `२८/०३/१९९९` | n/a | ~46s |
| **DocTR** | tight | `4/93/3885` | n/a | ~4.6s |
| DocTR | original | `24/03/8888` | n/a | ~9s |

Production Tesseract modes (`config/ocr.php` `psm_modes` **[6,4,11]** + langs mar+eng) all favor day **२४** when a date is present. No engine emitted clean day **२१** on the original or tight crop.

Evidence: `tools/ocr-loop30-d8-exhaustive.php`, `tools/ocr-loop30-d8-engines.py`  
JSON: `storage/app/private/ocr-temp/d8-loop30/loop30_evidence_*.json`

---

## B. Glyph-level investigation

**Hypothesis verdict:** primarily **१/ → ४** (slash merging with Marathi **१**), not a clean **२१→२४** substitution of two intact digits.

Evidence:

- Enlargements **2× / 4× / 8×** Lanczos: `glyph/dob_line_x{2,4,8}.png`
- Day-focus NN crops: `glyph/date_focus_x4_nn.png`, `glyph/day_only_x8_nn.png`
- Tesseract word boxes: `boxes/dob_line_x{2,4,8}_boxes.png`
- Visual: second day glyph forms a connected component with `/`, topology resembling Devanagari **४**
- Day-only Tesseract PSM7/10: `र१/०३` (has **१** + slash but **not** clean `२१`; no GT recovery)

---

## C. Segmentation experiments

| Segment | Typical Tesseract raw | Day signal |
|---------|----------------------|------------|
| Full page | biodata soup; DOB region **२४** | 24 |
| DOB line | `२४०३/१९९९` | 24 |
| Date token | `/०३/१९९९` (day often lost) | truncated |
| Day digits | `/०३/…` or weak | day lost / merge |
| Month | weak / noise | — |
| Year | label fragments | — |

Paddle on date/day segments: `/०३/१९९९` — day glyph disappears at crop boundary (supports merge/segmentation).

---

## D. Preprocessing matrix

### Imagick (tight crop)

Ops: grayscale, adaptive threshold, sharpen, denoise, CLAHE, NN/Lanczos upscale, DPI-like 300/400/600, red-channel negate.

- **Best structured date:** `upscale_nn_2` PSM6 → `जन्म : २४/०३/१९९९` (conf **82.87**) — still day **24**
- Grayscale/CLAHE/denoise often → **२७** (27), not 21
- Imagick Otsu constants unavailable on this build → covered via OpenCV

### OpenCV (`tools/ocr-loop30-d8-cv-matrix.py`)

Otsu, adaptive, CLAHE, morphology open/erode, NN/Lanczos/Cubic ×3–4, **horizontal bridge-open** and erode to split `१`/`/`.

- **`clean_21_without_24_count` = 0**
- Bridge-split still reads **२४** or **२६** — does not recover **२१**
- Super-resolution: `cv2.dnn_superres` API present; **no model weights** bundled → not runnable

---

## E. OCR pipeline audit

**User/API upload path**

```110:123:app/Services/Intake/IntakeCreationService.php
        $path = $file->store('intakes');
        // ...
            $extractedText = $this->ocrService->extractTextFromPath($path, $originalName);
```

**OCR on stored original**

```49:69:app/Services/OcrService.php
        $fullPath = storage_path('app/private/'.$storagePath);
        // ...
            $result = $this->tesseractMultiPassOcr->extractFromImage(
                $fullPath,
                $storagePath,
                $originalFilename,
                $presetOverride
            );
```

**Bulk ensemble** (when flag on): still `$file->store('intakes')` then `extractTextFromPath` with a **preset** (derived variants for scoring only). Stored `file_path` remains the original upload.

**Not the path:** Original → resize/compress → OCR as SSOT.  
Probe used Batch-001 original bytes (q=100). Multipass may OCR a chosen variant; debug records `original_absolute_path` vs `final_ocr_input_path`.

---

## F. Ensemble (disagreement recorded; no invent vote)

| Engine | Day-like raw | Vote? |
|--------|--------------|-------|
| Tesseract | **24** (`२४`) | — |
| EasyOCR | **20** (`20/07/7090`) | — |
| Paddle | **28** (`२८`) | — |
| DocTR | **24** / garbage | — |

Engines **disagree**, but **none** produce day **21**. Confidence does not justify voting to 21 (invent forbidden).

---

## G. Final decision

Every reasonable RAW path tested (production Tesseract modes, EasyOCR, PaddleOCR, DocTR, segmentation, Imagick + OpenCV preprocess, slash-bridge morphology) **fails to extract day 21** from the ORIGINAL image without guessing.

**Classification:** `D (8).jpeg` DOB day is a **proven OCR limitation** under the current architecture/engine budget.

**Not done:** invent `21` from `24`; hardcode filename `D (8)`; promote EasyOCR/Paddle/DocTR (still wrong day).

**Production:** no code change. Critical remains **98.9%**.

---

## Tools / artifacts

| Artifact | Role |
|----------|------|
| `tools/ocr-loop30-d8-exhaustive.php` | A/B/C/D/E Tesseract + Imagick |
| `tools/ocr-loop30-d8-engines.py` | A/F EasyOCR/Paddle/DocTR |
| `tools/ocr-loop30-d8-cv-matrix.py` | D OpenCV + bridge-split |
| `storage/app/private/ocr-temp/d8-loop30/` | Crops, boxes, JSON evidence |

## Next

Tesseract Mode A residual held. Sarvam DI later recovered day 21 in Loop 31 research only — **not** production-integrated. Next product plan: large-dataset benchmarking (≥500), not D8 invent.
