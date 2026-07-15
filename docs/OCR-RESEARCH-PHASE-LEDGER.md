# OCR Research Phase — Ledger (§20)

> **Approved Goal:** Maximize **raw OCR text fidelity** (Marathi + Devanagari + English biodata).  
> Downstream stages preserve/utilize that fidelity — they do not replace poor OCR.  
> **Status:** **In Progress**

**Triage each loop:** raw has info? → parser/normalizer. Else → OCR/preprocess. Never re-optimize solved layers; attack largest remaining information loss.

---

## Forensic answer (required gate)

**Q:** Of GT-20’s 15 DOB misses, how many lack a date in Raw OCR vs date present but parser miss?

**A (full-page Tesseract re-OCR + expanded date signals; artifact `sprint2_gt20_dob_raw_vs_parser_forensic_20260715_152255.json`):**

| Bucket | Count | Meaning |
|--------|------:|---------|
| PDF not classified via image CLI | **3** | Need PDF→image path (raw pipeline gap) |
| Date signal in raw; extract failed (before fix) | **11** | Mostly Marathi/English month lines; label regex bug `तारीख` |
| Extracted correctly on fresh OCR | **1** | Already recoverable |
| No date signal in raw (images) | **0** | Earlier prefix-only “no date” was incomplete |

**Implication:** Do not spend the next cycle only on slash-DD/MM parsers. Primary image gap was **recognizing calendar dates that are already in raw** (month-name DOB + broken `जन्म तारीख` label match). Remaining hard cases are **garbled digit dates** and **PDF raster OCR** → true Raw OCR / preprocess work.

---

## Active improvement cycle

1. **Done:** Recover dates already in raw (label + Marathi/English months). Re-measure former DOB misses: **7/12 images NOW_OK**, **5 STILL_MISS** (raw/garbled), **3 PDF** pending.  
2. **Next (largest remaining loss):** Raw OCR fidelity on the 5 STILL_MISS images + PDF→image raster for 3 PDFs — not more parser tweaks for already-solved month forms.

---

## Baseline (Sprint 2 GT-20)

Tesseract critical **42.11%**; DOB field historically **25%**. Re-score after each kept change.

---

## Changelog

| Date | Note |
|------|------|
| 2026-07-15 | Problem-driven ledger; Loop 01 DOB |
| 2026-07-15 | Forensic raw-vs-parser; month/label recognition fix |
| 2026-07-15 | Primary objective named **raw OCR text fidelity**; 7/12 DOB image misses recovered on re-OCR+extract |
