# OCR Research Phase — Ledger (§20)

> **Approved Goal:** Continue Product OCR Vision — maximize **raw OCR text fidelity** (Marathi + Devanagari + English biodata).  
> Downstream stages preserve/utilize that fidelity — they do not replace poor OCR.  
> **Product Goal status:** **In Progress** (NOT complete)  
> **Loop 01 status:** **Complete**  
> **Research Phase status:** **Open** (plateau §17 / completion §18 not met)

**Authority:** Blueprint §20 + DOC (esp. §17 Plateau, §18 Research Completion).  
**Triage:** raw has info? → parser/normalizer. Else → OCR/preprocess. Never re-optimize solved layers; attack largest remaining information loss.  
**Do not stop after each loop** — measure → rank → fix → bench → keep/reject → commit → push → repeat.

---

## Technique register (accept / reject)

| Technique | Result | Evidence / reason |
|-----------|--------|-------------------|
| Fuzzy `जन्म तारीख` label + Marathi/English month forms | **Accepted** | Dates already in raw; DOB recovery on images |
| Glued month+year (`ऑगस्ट1998`) | **Accepted** | Production OCR noise; measurable recoveries |
| PDF Imagick raster → Tesseract when embed unusable | **Accepted** | Needs Ghostscript; recovers scanned PDFs (`27.pdf`) |
| Ghostscript user-local install (`%LOCALAPPDATA%`) | **Accepted** | Environment ownership; raster verified |
| Reject ITRANS / Latin garbage as usable PDF text | **Accepted** | `27.pdf` forced to raster; DOB OK |
| Bare `तारीख` / month-name line DOB pass | **Accepted** | testing PDF `December 10, 1995` |
| Narrow invalid month **14→11** | **Accepted** | Proven 4↔1; single map only |
| Wide / open month-digit invent (e.g. 19→10) | **Rejected** | False ISO on multipass garbles |
| Truncated-year invent (`जून199` → age≈28 digit) | **Rejected** | Invents last digit; not fidelity |
| Multipass score: boost valid slash dates / penalize garbled-only | **Accepted** | Prefer original when preprocess destroys DOB; WhatsApp + D(1) |
| Full-page preset / DPI sweep on `28.pdf` | **Rejected** | No calendar date signal in raw (`24 फिट 1991`) |
| Invent day 21 from `२४०३/१९९९` on `D (8)` | **Rejected** | Guesses wrong day; Mode A |
| EasyOCR / Paddle / DocTR as production replace | **Rejected** (Sprint 2) | NO-GO vs Tesseract GT-20 critical |
| Date-band crop (Loop 02) | **Pending** | Next approach for Mode A residuals |
| Horizontal date-band on `D (8)` | **Rejected (GT)** | Improves glued→`२४/०३/१९९९` but day stays **24≠21**; no GT match |
| Color/red-channel suppress on `D (8)` | **Rejected** | Still reads day 24 / wrong months; no uplift to truth |
| Opaque blue-fill watermark wipe (`D (8)`) | **Rejected** | No DOB recover |
| PDF DPI/crop/channel only (`28.pdf`) | **Rejected** | Marathi multipass still preferred garbage |
| English ordinal date parse (`24th March 1991`) | **Accepted** | Resume-style DOB in raw |
| Multipass: include `eng`; don’t penalize Latin resumes | **Accepted** | Stops Marathi hallucination winning over English resumes |
| PDF raster 300 DPI + multipass (not forced `off`) | **Accepted** | `28.pdf` → DOB **1991-03-24** |

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

---

## Loop 01 — Complete (DOB weakness)

**Closed:** 2026-07-15. Baseline GT-20 DOB **25%** → large recovery via parser + PDF raster + multipass date scoring.  
**Does not close Product Goal.**

Residual Mode A (ranked for Loop 02+):

1. **`D (8).jpeg`** — preprocess yields `२४०३/१९९९` (year OK-ish; day wrong); invent rejected.  
2. **`28.pdf`** — raster/presets/DPI: no parseable DOB in raw.

---

## Active improvement cycle (Loop 02+)

1. **Done (accept):** English resume path — `28.pdf` recovered (`24th March 1991`).  
2. **Reject so far on `D (8)`:** bands, color channel, de-blue — OCR still prefers day **24** vs GT **21** (watermark overlap).  
3. **Next ranked:** watermark/color-layer separation or date-band with stronger filter for overlay biodata; then remasure GT-20 DOB.  
4. Plateau only per DOC §17 after multiple approaches exhausted.

---

## Changelog

| Date | Note |
|------|------|
| 2026-07-15 | Fidelity objective; raw-vs-parser forensic; month/label fix |
| 2026-07-15 | Glued month-year; PDF raster-OCR fallback (Ghostscript) |
| 2026-07-15 | GS user-local; ITRANS reject; bare-तारीख; multipass date scoring |
| 2026-07-15 | Loop 01 Complete; Product Goal In Progress; technique register; Loop 02 date-band pending |
| 2026-07-15 | Loop 02: reject D8 overlays/bands; accept English resume scoring + ordinal DOB; **28.pdf recovered** |
