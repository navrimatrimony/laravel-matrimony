# OCR Research Loop 29 — D(8) DOB original-file + region preprocess

> **Status:** COMPLETE — Mode A limitation confirmed (invent forbidden)  
> **Baseline held:** `product_metrics_gt20_20260717_101021.json` @ **98.9%**  
> **Probe:** `tools/ocr-loop29-d8-dob-probe.php`

## Assumptions verified

### 1. Original file (not preview)

| Property | Value |
|----------|-------|
| Path | `storage/app/ocr-dev-batches/Batch-001/D (8).jpeg` |
| Size | **720×1016** |
| Bytes | 264706 |
| Format | JPEG |
| Compression quality | **100** |

Full-page OCR on this exact file: `जन्म :२४०३/…` (day **२४**).

### 2. Marathi numerals + DOB-region preprocess

Marathi digits are read correctly as Devanagari (`२४`, `०३`, `१९९९`).  
The failure is the **day glyph**, not Arabic↔Marathi conversion.

Bands (`12–28%`, `15–32%`, `18–35%`) × ops (`raw`, `gray`, `contrast`, `sharpen`, `threshold`, `zoom2`, `zoom3`, `red_channel`) × PSM `{6,7,8,4}`:

- **Every** DOB-positive snip shows day **२४** / `24`
- **No** clean day-**२१** signal on the birth line
- Full-page `२१` hits are phone digits (`९८२१२१…`), not DOB

### 3. Intake pipeline audit (OCR input)

| Step | Behavior |
|------|----------|
| Upload | `$file->store('intakes')` — original bytes |
| OCR | `OcrService::extractTextFromPath($path, $originalName)` on **stored original** |
| Multipass | May create **derived variants** for scoring; stored `file_path` is never replaced |
| Preview/thumbnail | Separate surfaces; **not** OCR input |

**Verdict:** No product bug of resize/compress-before-OCR. Preview may differ; OCR uses the original upload.

### 4. Invent-forbidden

RAW OCR consistently reads day **24** from the original. Changing to GT day **21** would invent. **Rejected.**

## Outcome

- No production code change  
- Critical remains **98.9%** (1 Mode A DOB residual)  
- D8 recorded as unavoidable RAW OCR limitation under current engine/preprocess budget  

## Next

Product Goal remains **In Progress** until D8 is recovered by higher-fidelity RAW (new evidence) or Product Owner revisits GT. No silent invent.
