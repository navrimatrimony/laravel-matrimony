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

1. **Done:** Recover dates already in raw (label + months). Image remasure earlier: 7/12 OK.  
2. **Done:** Glued month+year (`ऑगस्ट1998`) — production-general OCR noise, not GT-only.  
3. **Done:** Ghostscript installed user-local (`%LOCALAPPDATA%\Ghostscript\extracted`); Imagick PDF raster works.  
4. **Done (accept):** Reject ITRANS/Latin encoding-garbage as “usable” PDF text → raster OCR (`27.pdf` DOB recovered).  
5. **Done (accept):** Bare `तारीख` / English–Marathi month-name line pass in DOB normalizer (`testing …pdf` → `1995-12-10`).  
6. **Reject / defer:** `28.pdf` raster still has no parseable date (`24 फिट 1991` only) — true raw OCR fidelity / preprocess, not parser. Higher DPI + presets: **no** calendar date signal.  
7. **Done (accept):** Invalid OCR month **14→11** only (narrow map). Broad month invent rejected (19→10 false ISO).  
8. **Reject:** Truncating-year invent (`जून199` → guess last digit by age≈28).  
9. **Done (accept):** Multipass score prefers **valid slash dates**; penalizes garbled-only dates — recovers WhatsApp + keeps `D (1)` with production presets (`photo_capture` yields `30 जून1991`).  
10. **Reject / defer:** `D (8).jpeg` still `२४०३/१९९९` (day wrong) after preprocess — needs crop/OCR, not inventing 21 from 24.  
11. **GT-20 DOB (production multipass):** after score fix, hard spot-check 3/4 OK; remaining hard gaps: `28.pdf`, `D (8).jpeg`.  
12. **Next:** Date-band crop / stronger raster for `28.pdf` + `D (8)` only if measurable; else document plateau on these two.

**Production generality check:** month/label/glued-year, PDF usable-text filter, PDF raster, narrow 14→11, and multipass calendar-date scoring apply to real intakes.

---

## Changelog

| Date | Note |
|------|------|
| 2026-07-15 | Fidelity objective; raw-vs-parser forensic; month/label fix |
| 2026-07-15 | Glued month-year; PDF raster-OCR fallback (needs Ghostscript on Windows Imagick) |
| 2026-07-15 | GS user-local install; ITRANS embed reject; bare-तारीख/month line DOB; PDF bench 2/3 DOB OK |
| 2026-07-15 | Narrow 14→11 month map; multipass prefers valid slash dates; D(1) via preprocess; 28+D(8) still Mode A |
