# OCR Research Loop 01 — DOB weakness (problem-driven)

> **Status:** OPEN — forensic first, tools second  
> **Authority:** Blueprint §20 problem-driven research  
> **Baseline:** Tesseract GT-20 `date_of_birth` = **25%** (weakest critical field)

---

## 1. Problem statement

Production biodata OCR fails most often on **date of birth** among critical fields on the hard GT-20 set. Replacing Tesseract with another engine wholesale is **not** the next step until we know whether failure mode is:

| Code | Mode | Implication |
|------|------|-------------|
| A | Date digits not in OCR transcript | Preprocess / better OCR / line crop |
| B | Date in transcript; extract/normalize miss | Improve extractors (Sprint 1 path) |
| C | Glyph digit confusion (८/३, ०/०, …) | Digit lexicon / post-correct |
| D | Label only (जन्म वेळ) without calendar date | Product/data — not invent DOB |

---

## 2. Method (no engine shopping)

1. For each GT-20 row with truth DOB and Tesseract miss: inspect Batch-001 OCR / parse-input text.  
2. Classify A/B/C/D.  
3. Rank modes by share.  
4. Implement **only** the top mode’s cheapest high-leverage fix.  
5. Re-score GT-20 DOB + critical %.  
6. Keep if uplift; else discard.

Candidate tools (only after classification):

- Preprocess sidecar (deskew/contrast) if A  
- Extractor label/fuzzy expand if B  
- Digit maps if C  
- Offline second OCR on date-band crop if A still high after preprocess

---

## 3. SSOT / DOC

- No production second OCR without new GO report  
- No silent profile writes  
- Local GT only; PII not committed  

---

## 4. Exit

Loop 01 closes when: DOB mode mix documented + one measured intervention accepted or rejected with evidence.

---

## 5. Preliminary forensic (2026-07-15)

From Sprint 2 GT-20 Tesseract score (`sprint2_gt20_score_20260715_130342.json`):

| Bucket | Count | Note |
|--------|------:|------|
| DOB misses (match=false) | 15 | |
| Wrong prediction (non-null) | **0** | Not primarily wrong-date confusions |
| Empty OCR (`raw_ocr_len=0`) | 1 | PDF/path edge |
| Null prediction with OCR text | **14** | Dominant — extract miss **or** no calendar date in transcript |

Folder artifact prefix scan (`sprint2_batch001_tesseract_folder_*`, `raw_ocr_text_prefix` only):

| Signal | Count |
|--------|------:|
| Prefix shows `DD/MM/YYYY`-like pattern | **2** |
| Prefix shows no date pattern | **13** |

**Working conclusion:** Prefer **Mode A/D** (date not reliably in OCR text / only birth-time labels) over pure extract bugs. Next interventions: date-line crop + preprocess, then OCR — **not** engine shopping. Extract improve is secondary for the 2 HAS_DATE cases.

**Next concrete step:** Prototype date-band preprocess + re-OCR on the 13 NO_DATE_PATTERN images; measure DOB Δ on GT-20.
