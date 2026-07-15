# OCR Research Phase — Ledger & Kickoff (§20)

> **Approved Goal:** Blueprint §20 — best practical OCR for Marathi biodata (Find **or** Build).  
> **Status:** **In Progress** (2026-07-15)  
> **Not:** §19 Sprint-complete = Vision-complete.

---

## 1. Baseline locked from Sprint 2 (GT-20)

| Engine | Critical % | Decision |
|--------|-----------:|----------|
| Tesseract | 42.11% | Production primary (until new GO) |
| EasyOCR | 27.37% | NO-GO (this vintage) |
| PaddleOCR 3.7 | 14.74% | NO-GO (this vintage) |
| DocTR 0.12 | 4.21% | NO-GO (this vintage) |

Re-benchmark required before any production GO for a new or upgraded engine.

---

## 2. Research queue (ordered)

Execute locally; score vs Batch-001 / GT-20 (expand GT when labels available). Reject quickly.

| Priority | Candidate / track | Next action |
|----------|-------------------|-------------|
| P0 | **Preprocessing** (deskew / contrast / denoise / crop for phone biodata) | Measure Tesseract Δ on GT-20 with/without preprocess sidecar |
| P0 | **Lexicon / post-correct** (digits, DOB, mobile, 96 Kuli aliases) | Bound experiments; SSOT aliases — no silent profile writes |
| P1 | **Surya OCR** | Install short-path venv; smoke 1 image; GT-20 if smoke OK |
| P1 | **PaddleOCR refresh / PP-OCRv5 configs** | Re-run only if newer stack ≠ Sprint 2 snap |
| P2 | Indic / Devanagari-specialized models (IndicOCR, Kraken fine-tune) | Feasibility + data estimate |
| P2 | TrOCR / transformer OCR | GPU/time cost; prototype if CPU viable |
| P3 | Heavy doc parsers (GOT / MinerU / Nougat / Florence / Qwen-OCR class) | Ops+license+cost gate before deep bench |
| Ongoing | Ensemble voting research | Offline only until second engine GO |

---

## 3. Success yardstick

- Direction: **90%+ usable OCR text** on representative Marathi biodata (expand labeled set; report both **text CER/WER-style** proxy and **critical field** accuracy).
- Sprint 2 GT-20 critical field % stays the formal gate for production second OCR.
- Stop condition: consecutive candidates with no meaningful uplift under practical compute/time.

---

## 4. Ops / Admin

See Blueprint §20.5. Per-engine Raw OCR is on Correct candidate (2026-07-15).

---

## Changelog

| Date | Note |
|------|------|
| 2026-07-15 | Ledger opened; §20 accepted; Admin Raw OCR UI shipped |
