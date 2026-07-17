# OCR Research Loop 31 — Watermark separation + Sarvam DI (D8 extension)

> **Status:** COMPLETE — PO accepted (2026-07-17)  
> **Baseline held (Tesseract SSOT metrics):** `product_metrics_gt20_20260717_101021.json` @ **98.9%**  
> **Production:** unchanged — Sarvam DI is a **research finding only** (no integration, no second-pass).

## Product Owner Status (§24) — post Loop 31 (accepted)

| # | Item | Value |
|---|------|-------|
| 1 | Overall Goal Completion | Critical **98.9%** accepted Tesseract baseline |
| 2 | Current Stage | Research closed for D8 exhaust; paid OCR deferred |
| 3 | Current Activity | Commit Loop 30/31; hold production |
| 4 | Highest Priority | Large-dataset benchmark (≥500 biodatas) |
| 5 | Remaining Major Work | Volume evidence before any Sarvam architecture decision |
| 6 | Estimated Remaining Time | Dataset collection / bench planning |
| 7 | Current Blockers | None for hold-baseline decision |
| 8 | Next Automatic Step | Next approved product goal / ≥500 biodata bench (not Loop 32) |
| 9 | Last Stable Commit | (Loop 30/31 research commit) |
| 10 | Exact Resume Point | After Loop 31 accept — no Sarvam prod wire |

---

## 1. Watermark / color separation (ORIGINAL)

**Source:** `storage/app/ocr-dev-batches/Batch-001/D (8).jpeg` (720×1016, q=100)

### Methods

| Method | Variants |
|--------|----------|
| HSV blue-mask | inpaint, whitefill, left-strip only |
| RGB blue-dominant | inpaint |
| LAB blue (`b*`) | inpaint; HSV+LAB combo |
| Black-text extract | suppress blue hue while keeping dark ink |
| Red-channel | blue suppress (pass 1) |

Correct DOB band: **y ≈ 18–30%** (first pass accidentally cropped header — corrected in `ocr-loop31-d8-watermark-dob.py`).

### RAW Tesseract results (no normalize)

| Variant (DOB band/tight) | Typical day | Clean `21/03/1999`? |
|--------------------------|-------------|---------------------|
| original | `२४/०३` / `२४०३` | **No** |
| hsv / lab / rgb inpaint | still `२४/०३` | **No** |
| hsv left wipe | still `२४/०३` | **No** |
| whitefill | still `२४०३/१९९९` | **No** |
| black_text_gray | date often destroyed | **No** |

**Counts (`loop31_watermark_dob_corrected.json`):**  
`repro_21_03_1999_count = 0` · `still_24_with_1999_count = 54`

**Verdict:** Color separation does **not** recover day 21 for Tesseract. Do **not** ship blue-mask as production preprocess for this failure class.

---

## 2. Sarvam Document Intelligence

| Item | Value |
|------|-------|
| Available | **Yes** (`services.sarvam.subscription_key` set) |
| API | Existing `AiVisionExtractionService::extractViaSarvamDocumentIntelligence` |
| Confidence | API meta has no numeric conf; job `Completed` |

| Target | Duration | RAW DOB | `21/03/1999` |
|--------|----------|---------|--------------|
| original_full | ~11.6s | `जन्म तारीख : २१/०३/१९९९.` | **Yes** |
| dob_tight | ~6.9s | `जन्म तारीख : २१/०३/१९९९.` | **Yes** |
| hsv_left_inpaint dob_tight | ~6.8s | `जन्म तारीख : २१/०३/१९९९` | **Yes** |
| dob_band | ~7.0s | `जन्म तारीख : २१/०३/१९९९.` | **Yes** |

**4/4 reproducible** extractions of `२१/०३/१९९९` — no digit invent map; independent OCR engine output.

Note: Sarvam request path may **enhance/upscale** (`ai_request_payload_enhanced=true`, e.g. 720→1451×2048). That is engine pipeline, not a D8 hardcode.

Evidence: `storage/app/private/ocr-temp/d8-loop31/loop31_sarvam_evidence.json` + `sarvam_*_raw.txt`

---

## 3. Glyph comparison

| Crop | Observation |
|------|-------------|
| `crops2/original_dob_tight_x4.png` | Blue vertical watermark overlaps left labels and sits against day digits |
| `crops2/hsv_left_inpaint_dob_tight_x4.png` | Blue largely removed; day glyphs remain soft; Tesseract still → **२४** |
| `boxes2/*_tight_x4_boxes.png` | Word box around date token; does not split `१` from `/` |

**Conclusion:** Watermark **does interfere** near the DOB, but after blue removal the black ink still presents a **१/** topology that Tesseract reads as **४**. Watermark is a contributing factor, not a complete explanation. Sarvam DI reads **२१** on both original and watermark-removed crops.

---

## 4. Acceptance decision

| Path | Reproducible `21/03/1999`? | Action |
|------|---------------------------|--------|
| Watermark / color preprocess + Tesseract | **No** | **Reject** for production preprocess |
| Sarvam Document Intelligence | **Yes (4/4)** | **Accept as research finding only** — **do not** production-wire yet |

**Do not:** invent `21` from Tesseract `24`; hardcode `D (8)`; integrate Sarvam DI / second-pass / residual routing (PO: paid service; single residual insufficient).

**Limitation (production):**

- Under **Tesseract multipass SSOT** (accepted product metrics): D8 DOB remains Mode A residual (**98.9%** held).  
- Sarvam DI can recover this case in research; revisit only after **≥500 biodata** benchmarking for need / placement / cost.

**Deferred:** Loop 32 Sarvam residual wiring — explicitly **not started**.

---

## Tools / artifacts

| Path | Role |
|------|------|
| `tools/ocr-loop31-d8-watermark.py` | Pass-1 color matrix (header crop bug) |
| `tools/ocr-loop31-d8-watermark-dob.py` | Corrected DOB-band color matrix |
| `tools/ocr-loop31-d8-sarvam.php` | Sarvam DI on original + crops |
| `tools/ocr-loop31-sarvam-env-check.php` | Key presence check |
| `storage/app/private/ocr-temp/d8-loop31/` | Variants, crops, boxes, JSON, raw texts |

## Next (deferred)

**Not Loop 32.** Next product plan: large-dataset OCR benchmarking (target ≥ **500** biodatas), then decide whether/where Sarvam is justified.
