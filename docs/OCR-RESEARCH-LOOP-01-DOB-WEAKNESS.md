# OCR Research Loop 01 — DOB weakness (problem-driven)

> **Status:** **COMPLETE** (2026-07-15)  
> **Product Goal:** still **In Progress** — Loop Complete ≠ Goal Complete (DOC §18)  
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

## 2. Outcome

| Result | Detail |
|--------|--------|
| Forensic | Most early “misses” were Mode B (date in raw); residual Mode A: `28.pdf`, `D (8).jpeg` |
| Accepted | Label/month/glued-year; PDF raster+GS; ITRANS reject; bare तारीख; 14→11; multipass valid-date scoring |
| Rejected | Wide month invent; truncated-year invent; DPI/preset-only for `28.pdf`; invent day on `D (8)` |
| Ledger | Technique register in `docs/OCR-RESEARCH-PHASE-LEDGER.md` |

---

## 3. Exit (met)

Loop 01 closes when: DOB mode mix documented + measured interventions accepted/rejected with evidence. **Met.**

Next: Loop 02 — date-band / region OCR for remaining Mode A (not a new engine sprint).
