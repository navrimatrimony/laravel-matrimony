# OCR Research Phase — Ledger (§20)

> **Approved Goal:** Continue Product OCR Vision — maximize **raw OCR text fidelity** (Marathi + Devanagari + English biodata).  
> Downstream stages preserve/utilize that fidelity — they do not replace poor OCR.  
> **Product Goal status:** **In Progress** (NOT complete)  
> **Loop 01 status:** **Complete**  
> **Research Phase status:** **Open** (plateau §17 / completion §18 not met)

**Authority:** Blueprint §20 + DOC (§17–19).  
**Product dashboard:** [`docs/OCR-PRODUCT-METRICS-DASHBOARD.md`](OCR-PRODUCT-METRICS-DASHBOARD.md)  
**Triage:** raw has info? → parser/normalizer. Else → OCR/preprocess. **Product Impact First** (loss × frequency).  
**Do not stop after each loop** — measure → rank → fix → bench → dashboard + ledger → commit → push → repeat.

---

## Knowledge findings (durable)

| Finding | Useful? | Reason |
|---------|---------|--------|
| Dates often already in raw OCR; Label/month bugs hide them | Yes | Prefer parser recovery before new engines |
| ITRANS / wrong PDF text layer looks “long” but is unusable | Yes | Force raster when no Devanagari/English biodata keywords |
| English resumes OCR’d as Marathi produce Devanagari garbage that scores high | Yes | Include `eng`; don’t apply latin_garbage when English biodata keywords present |
| Most GT-20 **name** misses are Mode B (tokens in raw) | Yes | Extractor gaps (English Name, biodata-title names, OCR honorific noise) over new OCR engine |
| Megapage OCR discards phones when whole-line score includes birth+father | Yes | Score local left-biased snippet around each phone |
| Dashboard is a **compass**, not success | Yes | DOC §19.1 — Goal = RAW OCR fidelity on real biodata; GT-20 ≠ plateau |
| Progress report ≠ approval request | Yes | DOC §21 — CONTINUE by default after each loop |
| Ordinal English DOB (`24th March 1991`) | Yes | Common resume form; must parse |
| Horizontal date-band crop | Partial | Fixes glued slash form; does not fix wrong day under overlay |
| Blue watermark opaque wipe / red-channel | No (so far) | Overlay destroys or confuses day digits (`D (8)` still 24≠21) |
| Wide month-digit invent / truncated-year invent | No | False ISOs / age-bias guessing |
| Replace Tesseract with EasyOCR/Paddle/DocTR | No | Sprint 2 NO-GO on GT-20 critical |

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
| Date-band crop (Loop 02) | **Rejected for GT match on D8** | Partial structure help only; see Knowledge |
| Horizontal date-band on `D (8)` | **Rejected (GT)** | Improves glued→`२४/०३/१९९९` but day stays **24≠21**; no GT match |
| Color/red-channel suppress on `D (8)` | **Rejected** | Still reads day 24 / wrong months; no uplift to truth |
| Opaque blue-fill watermark wipe (`D (8)`) | **Rejected** | No DOB recover |
| PDF DPI/crop/channel only (`28.pdf`) | **Rejected** | Marathi multipass still preferred garbage |
| English ordinal date parse (`24th March 1991`) | **Accepted** | Resume-style DOB in raw |
| Multipass: include `eng`; don’t penalize Latin resumes | **Accepted** | Stops Marathi hallucination winning over English resumes |
| Trailing OCR junk after 3-token Marathi names; `मुलीचे बां` OCR for नाव | **Accepted** | Loop 03 residual; production-general |
| Mobile: local snippet + left-biased megapage context | **Accepted** | Loop 04; Mode B megapage no longer discards `मो.नं.` |
| Invent mobile digits / steal relative number as candidate | **Rejected** | Not fidelity |
| Religion: glued जातिहंदू / हहंद / कुळ / धर्म-जात+Maratha | **Accepted** | Loop 05 Mode B |
| Keep OCR garbage string as religion | **Rejected** | normalizeReligion → null |
| Gender: Ms. / मुलीची माहिती / कुमारी extractor | **Accepted** | Loop 06; critical **73.7%** |
| Short `कु.` as female | **Rejected** | Misreads `चि.` on male names |
| Drop male fallback on नावरस | **Rejected** | Regressed true male gender |
| Name: no bare चि/कु truncate; glued नाव/नाब; reject tiny fragments | **Accepted** | Loop 07; name **70%** |
| Invent शि from चिवाजी / invent missing surname | **Rejected** | Not fidelity |
| Mobile: no whitespace-merge phones; संपर्क/संपकण; first-after-label | **Accepted** | Loop 08; mobile **72.2%** |
| Invent missing/shifted mobile digits | **Rejected** | Not fidelity |
| Mobile: address-line संपर्क penalty; संपर्क नंबर boost | **Accepted** | Loop 09; `27.pdf` restored; mobile **77.8%** |

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

1. **`D (8).jpeg`** — watermark/overlay; OCR day 24 vs GT 21; invent rejected.  
2. ~~`28.pdf`~~ — **recovered** (English resume multipass).

---

## Loop 03 — Name (complete slice)

1. **Forensic:** Name Mode B **12** / Mode A **1**.  
2. **Accepted:** English `Name:`, biodata-title names, honorific/prefix cleanup.  
3. Name **30% → 65%** (residual Mode A/B remain).

## Loop 04 — Mobile (complete slice)

1. **Forensic:** Mode A **0** / Mode B **8**.  
2. **Accepted:** local snippet scoring + left-biased window on megapage OCR.  
3. Mobile **55.6% → 66.7%**; Critical **66.3% → 68.4%**.  
4. Residual digit-shift / wrong secondary preference deferred (no invent).

## Loop 05 — Religion (complete slice)

1. **Forensic:** Mode A **5** / Mode B **3**.  
2. **Accepted:** glued जातिहंदू; हहंद corrupt; कुळ label; धर्म-जात+Maratha; reject garbage religion.  
3. Religion **52.9% → 76.5%**; Critical **68.4% → 71.6%**.

## Loop 06 — Gender (complete slice)

1. **Forensic:** Mode A **4** / Mode B **4** (pre-fix sample).  
2. **Accepted:** Ms. on Name; `मुलीची माहिती`; `कुमारी`; name labels via `OcrEnsembleGenderExtractor`.  
3. **Rejected:** short `कु.`; aggressive नावरस fallback drop.  
4. Gender **60% → 70%**; Critical **71.6% → 73.7%**.

## Loop 07 — Name residual (complete slice)

1. **Forensic:** Mode A **1** / Mode B **6**.  
2. **Accepted:** no bare चि/कु truncate; glued नाव/नाब; biodata-title score; reject tiny fragments; keep श्री glue strip.  
3. Name **65% → 70%**; Critical **73.7% → 74.7%**.

## Loop 08 — Mobile residual (complete slice)

1. **Forensic:** Mode A **0** / Mode B **7**.  
2. **Accepted:** no whitespace phone-merge; `संपर्क`/`संपकण`; first phone after label.  
3. Mobile **61.1% → 72.2%**; Critical **74.7% → 76.8%**.  
4. Residual: `27.pdf` OK→wrong flip; Mode A digit OCR.

## Loop 09 — Address संपर्क vs संपर्क नंबर (complete)

1. **Root:** `27.pdf` address `संपर्क : 960…` beat `संपर्क नंबर :-- 994…`.  
2. **Accepted:** address-line penalty; `संपर्क नंबर` boost.  
3. Mobile **72.2% → 77.8%**; Critical **76.8% → 77.9%**.

## Active improvement cycle (Loop 10)

1. **Why:** Name **70%** and Gender **70%** — next shared floor.  
2. **Artifact:** `product_metrics_gt20_20260716_095820.json`.

---

## Changelog

| Date | Note |
|------|------|
| 2026-07-15 | Fidelity objective; raw-vs-parser forensic; month/label fix |
| 2026-07-15 | Glued month-year; PDF raster-OCR fallback (Ghostscript) |
| 2026-07-15 | GS user-local; ITRANS reject; bare-तारीख; multipass date scoring |
| 2026-07-15 | Loop 01 Complete; Product Goal In Progress; technique register; Loop 02 date-band pending |
| 2026-07-15 | Loop 02: reject D8 overlays/bands; accept English resume scoring + ordinal DOB; **28.pdf recovered** |
| 2026-07-15 | DOC §19 Product Impact First; Product Metrics Dashboard; remasure critical **60%**, DOB **95%**; **Name** ranked next |
| 2026-07-15 | DOC §19.1 Dashboard = compass not success; Production scoreboard scaffold (anti GT-overfit) |
| 2026-07-15 | DOC §21 Continue / §22 Safe Shutdown; Loop 04 mobile → **66.7%**; critical **68.4%**; Loop 05 Religion next |
| 2026-07-15 | Loop 05 religion → **76.5%**; critical **71.6%**; Loop 06 Gender next |
| 2026-07-15 | Loop 06 gender → **70%**; critical **73.7%**; Safe Shutdown STATUS |
| 2026-07-16 | Loop 07 name residual → **70%**; critical **74.7%**; Loop 08 Mobile next |
| 2026-07-16 | Loop 08 mobile → **72.2%**; critical **76.8%**; Loop 09 next |
| 2026-07-16 | Loop 09 address-संपर्क → mobile **77.8%**; critical **77.9%**; Loop 10 next |
