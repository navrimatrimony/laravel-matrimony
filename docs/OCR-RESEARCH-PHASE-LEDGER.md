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
6. **Reject / defer:** `28.pdf` raster still has no parseable date (`24 फिट 1991` only) — true raw OCR fidelity / preprocess, not parser.  
7. **Done (accept):** Invalid OCR month digit recovery (`१६/१४/१९९६` → `1996-11-16`) — production-general 1↔4 confusion when month out of range.  
8. **Reject:** Truncating-year invent (`जून199` → guess last digit by age≈28) — would bias wrong years; keep as raw OCR fidelity gap.  
9. **GT-20 DOB now:** **17/20 (85%)** post-PDF+month recovery (baseline was 25%). Remaining misses: `28.pdf`, `D (1).jpeg` (truncated year), `D (8).jpeg` (garbled `२४०३/९९९९`).  
10. **Next:** Preprocess / higher-fidelity OCR on the 3 remaining raw gaps (not more inventive date guessing).

**Production generality check:** month/label/glued-year, PDF usable-text filter, PDF raster, and invalid-month digit recovery apply to real intakes, not GT-20-only tricks.

---

## Changelog

| Date | Note |
|------|------|
| 2026-07-15 | Fidelity objective; raw-vs-parser forensic; month/label fix |
| 2026-07-15 | Glued month-year; PDF raster-OCR fallback (needs Ghostscript on Windows Imagick) |
| 2026-07-15 | GS user-local install; ITRANS embed reject; bare-तारीख/month line DOB; PDF bench 2/3 DOB OK |
| 2026-07-15 | Invalid-month OCR recovery; DOB 17/20 (85%); reject truncated-year invent |
