# OCR Ensemble — Sprint 1 DOB / Null-Candidate Forensics

> **Status:** Evidence + fix list (Blueprint §19.3 Sprint 1)  
> **Date:** 2026-07-15  
> **Authority:** Blueprint §19.6 + `docs/DEVELOPER-OPERATING-CONTRACT.md`  
> **Scope:** Phase 3 DOB path only (OCR text → Extract → Normalize → Vote → Validate). Phase 4 / Sarvam / HTTP remain closed.

---

## 1. Symptom (#771-class)

Observed on staging intake **#771** (and reproduced locally on intakes **460, 472, 492–494**):

```text
candidates.laravel_native_ocr = null
normalized.laravel_native_ocr = null
winning_engine = null
validator.code = no_eligible_candidate
validator.detail = dob_invalid_format   ← MISLEADING (pre-fix)
→ Sarvam Judge → empty value → merge_noop
```

**Phase 4 is not the root cause** (already closed). Empty Final DOB starts in Phase 3 when no eligible DOB candidate exists.

---

## 2. Telemetry bug (instrumentation)

`OcrEnsembleFieldValidator::missingCode('date_of_birth')` previously returned `dob_invalid_format`.

That code means “missing candidate”, **not** “value failed ISO format check”.

| Situation | Pre-fix detail | Post-fix detail |
|-----------|----------------|------------------|
| No extract / null candidate | `dob_invalid_format` | `dob_missing` |
| Winner present but not `Y-m-d` | `dob_invalid_format` (validateDob) | unchanged |

This alone made #771 look like a format/validator reject when extraction never produced a DOB.

---

## 3. Local evidence (2026-07-15)

Local DB: `laravel_matrimony`, 55 OCR-bearing intakes scanned through production extractor.

| Class | Count | Meaning |
|-------|------:|---------|
| Extracted OK | 11 | Clean birth label + date |
| Null but date pattern / fuzzy label in text | 2 | **Fixable in Phase 3** (ids 460, 472) |
| Null, no date pattern in OCR text | 42 | OCR transcript missing DOB → Sprint 2 engine eval |
| Birth-time-only (`जन्म वेळ`) | (subset of null) | Date never in OCR |

### 3.1 Fixable sample — intake 460

OCR line (normalized digits):

```text
अन्म तारीख > 24/10/1938 अन्म वेळ + रात्री 09 वा.45 मि
```

| Stage | Result |
|-------|--------|
| Exact label `जन्म तारीख` | miss (`ज` → `अ`) |
| Date triple | `24/10/1938` present |
| Age gate (18–75) | 1938 → ~88 → reject → candidate null |
| Root | Fuzzy label miss + year glyph OCR (`9`→`3`) |

**Fix:** fuzzy DOB label + one-glyph year recovery → `1998-10-24`.

### 3.2 Fixable sample — intake 472

```text
लर्न तीळ Bi: 02/10/1396
```

Label heavily garbled; date still present as `02/10/1396`. Year one-glyph recover → `1996-10-02`.

### 3.3 Non-fixable in Phase 3 — intake 494

OCR has `जन्म वेळ/वार` (birth time / weekday) and **no** `DD/MM/YYYY`. Extractor correctly returns null. Requires better OCR (Sprint 2), not validator looseness.

### 3.4 Staging #771

Local DB max intake id = 505; **#771 not present locally**. Class behavior matches §3: `candidate=null` then Judge noop. Re-run forensic tool on staging when smoke is needed:

```bash
php tools/ocr-ensemble-forensic-intake-760.php 771
```

---

## 4. Call chain (unchanged architecture)

```text
ocr_attempt.raw_text
  → OcrEnsembleFieldExtractor
       → MarathiOcrFieldRescueService (DOB label)
       → MarathiSeparatedLabelValueExtractor hints
       → OcrEnsembleDobNormalizer::normalize / normalizeFromLines
  → OcrEnsembleFieldNormalizer
  → filterEligible (ISO Y-m-d only)
  → OcrEnsembleFieldValidator
       → no winner → code=no_eligible_candidate, detail=dob_missing
```

---

## 5. Fix list (implemented this sprint)

| # | Fix | File |
|---|-----|------|
| F1 | Telemetry: missing DOB → `dob_missing` | `OcrEnsembleFieldValidator` |
| F2 | Fuzzy DOB label (`अन्म तारीख`, etc.) | `OcrEnsembleDobNormalizer`, `MarathiOcrFieldRescueService` |
| F3 | One-glyph year OCR recovery when age OOR | `OcrEnsembleDobNormalizer` |
| F4 | Unit tests for 460/472-class + telemetry | Phase3 extractor + pipeline tests |

**Explicitly not done (correctly deferred):**

- Loosening age rules below 18 / inventing DOB with no date digits
- Second OCR production path (blocked until Sprint 2 GO)
- Reopening Phase 4 Judge HTTP

---

## 6. Verification

- Local queue: `QUEUE_CONNECTION=database`, worker on `default,bulk-intake` processing jobs
- Additive migration applied: `field_resolution_json`
- Tesseract CLI: v5.4.0 present
- Automated: `php artisan test --filter=OcrEnsemblePhase3`

---

## 7. Sprint 1 DoD

| Item | Status |
|------|--------|
| Written forensic for null-candidate DOB | This doc |
| Fix list implemented + tests | F1–F4 |
| No Phase 4 reopen | Yes |
| Remaining nulls without OCR date | Sprint 2 benchmark input |
