# OCR Ensemble — Phase 5 / Blueprint §20.6 Product Owner Visibility

> **Status:** COMPLETE (2026-07-17)  
> **Authority:** `OCR-ENSEMBLE-PIPELINE-BLUEPRINT.md` §7.1, §13.5, §20.5–§20.6 · Phase Contract Phase 5  
> **Production OCR:** unchanged (Tesseract SSOT baseline 98.9% accepted; no Sarvam integration)

## Product Owner path (§20.6)

```text
Admin → Intake & OCR → Bulk Intakes → Batch → Correct candidate
  → OCR comparison table
  → Engine metrics
  → Judge participation
  → Per-engine Raw OCR
  → Human correction form (below panel)
```

Discoverability: **Biodata Intake show** → link **Open OCR comparison + Raw OCR (Correct candidate)** when bulk-linked.

## Visibility checklist (DoD)

| Requirement | Surface | Status |
|-------------|---------|--------|
| Every OCR attempt | Per-engine Raw OCR (`ocr-attempt-raw-transcripts`) | ✅ |
| Raw OCR text per engine | Expandable attempt transcripts | ✅ |
| Extracted structured fields per engine | Comparison columns Tesseract / Second OCR / Sarvam | ✅ |
| Final selected values | Final column (resolved highlight) | ✅ |
| Why each field won | Reason + Source (`vote` / `validator` / `sarvam_judge` / `single_engine` / `manual_override`) + Winner engine | ✅ |
| Engine metrics | Confidence, time, found/missing, critical errors + gap field keys, Judge used? | ✅ |
| Judge participation | Dedicated panel (`ocr-judge-participation`) | ✅ |
| Correct Candidate only | Embedded panel; standalone route redirects | ✅ |
| Human correction / approve | Correct candidate fields form below comparison | ✅ |

## Intentionally unchanged

- No production OCR behaviour change  
- No Sarvam residual / second-pass wiring  
- No OCR research loops  
- Phase 5 remains **read-only** (zero DB writes from viewing comparison)

## Tests

`php artisan test --filter=OcrEnsemblePhase5` — includes `OcrEnsemblePhase5ProductOwnerVisibilityTest`.

## Related

- Panel: `resources/views/admin/intake/partials/ocr-comparison-review-panel.blade.php`  
- Service: `IntakeOcrEnsemblePhase5Service`  
- Metrics: `OcrEnsembleEngineDebugMetricsBuilder`  
- Judge: `OcrEnsembleJudgeParticipationBuilder`
