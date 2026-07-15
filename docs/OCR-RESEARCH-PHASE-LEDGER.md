# OCR Research Phase — Problem-driven Ledger (§20)

> **Approved Goal:** Maximize Marathi + Devanagari + English OCR quality for production biodata.  
> **Method:** **Problem-driven** research — not an engine shopping queue.  
> **Status:** **In Progress** (2026-07-15)

---

## 0. Authority

```text
The objective is NOT to benchmark OCR engines.

The objective IS to maximize
Marathi + Devanagari + English
OCR accuracy for production biodata.
```

Loop (mandatory):

```text
Current accuracy → Biggest weakness → Candidate solutions for THAT weakness
  → Benchmark → Keep only measurable gains → Repeat → 90%+ → Stop
```

Engines (Surya, Paddle, …) may appear **only** as candidates for a named weakness — never as the roadmap itself.

---

## 1. Current accuracy (baseline)

| Metric | Value | Source |
|--------|------:|--------|
| Tesseract critical (5 fields) | **42.11%** | Sprint 2 GT-20 |
| EasyOCR / Paddle / DocTR | below baseline | Sprint 2 NO-GO vintages |

### Per-field (Tesseract GT-20) — weakness rank

| Field | Accuracy | Rank |
|-------|---------:|------|
| date_of_birth | **25.0%** | **#1 weakest critical** |
| full_name | 30.0% | #2 |
| religion | 47.1% | |
| gender | 55.0% | |
| primary_contact_number | 55.6% | strongest critical |

---

## 2. Active loop

### Loop 01 — DOB critical accuracy (OPEN)

| Step | Content |
|------|---------|
| Weakness | DOB extract/OCR at **25%** on hard-heavy GT-20 |
| Hypothesis set (problem-first) | (A) transcript never contains date → OCR/preprocess; (B) date present but label/format miss → Sprint 1 extract/normalize expand; (C) digit glyph confusion → lexicon/post-correct |
| Next action | Forensic sample of GT-20 DOB misses: OCR text contains `DD/MM`? → choose A vs B vs C |
| Engine experiments | Only if forensic says **A** dominates (e.g. try preprocess + optional second engine for date lines) |
| Success | +measurable DOB pp on same GT-20; no SSOT break |
| Forensic (prefix) | 13/15 misses lack date pattern in OCR prefix → Mode A/D lead |

See: `docs/OCR-RESEARCH-LOOP-01-DOB-WEAKNESS.md`

---

## 3. Rejected / parked (not a queue)

| Item | Status | Why parked |
|------|--------|------------|
| EasyOCR / Paddle 3.7 / DocTR 0.12 | NO-GO vintage | Failed critical gate; re-open only for a named weakness with new evidence |
| Engine-first “try Surya next” | Forbidden as roadmap | May be a Loop 01/02 **candidate** if forensic warrants |

---

## 4. Stop conditions

- Critical + text usability toward **90%+** on representative Marathi biodata, **or**
- Consecutive loops with no meaningful measurable uplift under practical effort

---

## 5. Admin comparison surface

Correct candidate shows: field table + **engine metrics** (confidence, time, fields found/missing, critical errors, Judge used?) + per-engine Raw OCR.

---

## Changelog

| Date | Note |
|------|------|
| 2026-07-15 | Ledger opened (engine queue — superseded) |
| 2026-07-15 | Reframed to **problem-driven**; Loop 01 = DOB; Admin metrics |
