# OCR Ensemble Pipeline — Phase Technical Contracts

> **Parent:** `docs/OCR-ENSEMBLE-PIPELINE-BLUEPRINT.md` **v1.0 (DESIGN FROZEN)**  
> **Version:** 1.0  
> **Date:** 2026-07-12  
> **Type:** Technical contract per phase — **not** code.

Each phase is independently shippable behind `intake_ocr_ensemble_enabled`.  
Phases must complete in order unless explicitly noted.

---

## Phase 1 — Foundation: Queue, Preprocess, Primary OCR, Attempts

### Scope

- Admin setting / feature flag `intake_ocr_ensemble_enabled` (default `false`)
- Queue job entry for ensemble pipeline (bulk + single intake paths when flag on)
- OpenCV **minimal v1** preprocessing (EXIF rotate, grayscale/contrast, best-effort text-region crop)
- Primary OCR: Tesseract via existing `TesseractMultiPassOcrService` (enrich, do not replace)
- Persist one `biodata_intake_ocr_attempts` row per intake with timing + `preprocessing_version`
- HTTP upload returns immediately; work async on `bulk-intake` or dedicated queue

### Inputs

| Input | Source |
|-------|--------|
| Uploaded image file | `IntakeCreationService` / bulk batch item |
| `intake_ocr_language_hint` | Admin settings (`mr` / `en` / `mixed`) |
| Feature flag | `intake_ocr_ensemble_enabled` |
| Existing intake / batch item IDs | DB |

### Outputs

| Output | Destination |
|--------|-------------|
| Preprocessed image (transient) | Worker temp storage |
| Tesseract raw text | `biodata_intake_ocr_attempts.raw_text` |
| Attempt metadata | `duration_ms`, `engine_meta_json`, `preprocessing_version`, `engine = laravel_native_ocr` |
| `raw_ocr_text` on intake | Primary transcript at create (existing SSOT) |
| Bulk/item status hint | `ocr_ensemble_processing` → `ocr_ready` (optional v1 label) |

### Success criteria

- [ ] Flag `false`: **zero** behavior change vs today
- [ ] Flag `true`: upload HTTP < 3s; OCR completes in background within ~30s typical
- [ ] Every ensemble intake has ≥1 `ocr_attempt` row
- [ ] OpenCV runs before Tesseract on every ensemble job
- [ ] Worker failure in optional paths logs warning; Tesseract-only still completes job
- [ ] No changes to `ParseIntakeJob`, `BiodataParserService`, or mutation paths in Phase 1

### Out of scope (Phase 1)

- Second OCR engine
- Field extractor / voting
- Sarvam
- Admin comparison table
- `field_resolution_json`
- Changes to `correct-candidate` form logic

### Dependencies

- Existing: `ProcessBulkIntakeBatchItemJob`, `IntakeOcrAttemptRecorder`, `TesseractMultiPassOcrService`
- New: OpenCV integration (PHP extension or sidecar — **decision at implement start**)

---

## Phase 2 — Second OCR Engine (Benchmark-Gated)

### Scope

- **10-image** technology benchmark (record results; no production code until evaluated)
- **50-image** decision benchmark
- If go: HTTP Python OCR sidecar + second `ocr_attempt` per intake
- If no-go: document rejection; pipeline remains Tesseract-only through Phase 5
- Engine identity **technology-neutral** — selected engine name stored in `engine` constant + UI label
- Sidecar down / timeout → log + continue Tesseract-only (**job must not fail**)

### Inputs

| Input | Source |
|-------|--------|
| Preprocessed image from Phase 1 | Worker |
| Golden dataset | #735 ground truth + #736/#737 + expanding set |
| Benchmark spreadsheet | `docs/` or private dataset per golden runbook |

### Outputs

| Output | Destination |
|--------|-------------|
| Benchmark report | `docs/ocr-ensemble-benchmark-v1.md` (or spreadsheet) |
| Second `ocr_attempt` row (if integrated) | `biodata_intake_ocr_attempts` |
| Go/no-go decision | Recorded in benchmark doc + blueprint appendix |

### Success criteria

- [ ] 10 images scored: critical fields (name, DOB, mobile, religion) per engine
- [ ] 50 images scored with go/no-go rule: second engine ≥ **5% uplift** on critical fields vs Tesseract+preprocess
- [ ] If go: second attempt saved for 100% of ensemble jobs when sidecar healthy
- [ ] If go: sidecar failure does not fail intake job
- [ ] If no-go: Phase 3–5 proceed with Tesseract-only voting (single-engine vote = pass-through)

### Out of scope (Phase 2)

- Field voting (Phase 3)
- Sarvam (Phase 4)
- Weight learning
- EasyOCR/Paddle as fixed choice before benchmark completes

### Go / no-go rule (frozen)

```
IF second_engine_critical_field_accuracy >= tesseract_accuracy + 5%
   AND sidecar ops acceptable
THEN integrate
ELSE stay Tesseract-only; re-evaluate in v1.1
```

---

## Phase 3 — Field Extraction, Validators, Voting, Parse Input

### Scope

- Lightweight **Field Extractor** for **16 structured fields** (blueprint §3.1)
- **Shared regex/label logic** with `BiodataParserService` — no duplicate fork
- Per-field: normalize → vote → validator → final candidate
- Persist `field_resolution_json` on intake (column TBD at implement — may use JSON on intake or routing telemetry)
- Assemble `last_parse_input_text` for `ParseIntakeJob`
- Run existing `BiodataParserService` **once** after assembly
- **Gender:** extract if confident; if missing → leave empty; **never** trigger Sarvam
- **Income:** extract + soft validator; admin may verify if low confidence
- **Marital status:** extract + enum validator

### Inputs

| Input | Source |
|-------|--------|
| All `ocr_attempts` for intake | Phase 1 (+ Phase 2 if go) |
| Master data | religions, castes, locations tables |
| Shared parser helpers | Existing parsing services |

### Outputs

| Output | Destination |
|--------|-------------|
| `field_resolution_json` | Intake record |
| Per-field: final, source, reason, candidates | Inside `field_resolution_json` |
| `last_parse_input_text` | Intake — parser input |
| `parsed_json` | Via existing `ParseIntakeJob` |
| `field_confidence_json` | Existing quality signals (extend if needed) |

### 16 fields (frozen)

`full_name`, `date_of_birth`, `gender`, `primary_contact_number`, `height`, `education`, `occupation`, `income`, `religion`, `caste`, `sub_caste`, `state`, `district`, `taluka`, `village`, `marital_status`

### Success criteria

- [ ] No full `BiodataParserService` run per OCR engine
- [ ] All 16 fields produce candidate or explicit `missing` in `field_resolution_json`
- [ ] Vote + validator runs per field; reasons stored
- [ ] Parse job consumes assembled text; `parsed_json` populated
- [ ] Single-engine mode (Phase 2 no-go) still works — vote degrades to single candidate
- [ ] Paragraph fields not voted; may pass through in assembled text body

### Out of scope (Phase 3)

- Sarvam judge calls
- Admin comparison UI
- Weight learning (equal weights)
- Marathi label synonym dictionary (Phase 6+)

---

## Phase 4 — Sarvam Vision Judge

### Scope

- Call Sarvam Document Digitization **only** when trigger rules fire (blueprint §5.1)
- Triggers: **name conflict** OR **DOB missing** OR **mobile missing** OR **religion missing**
- **Gender missing → NOT a trigger**
- Save Sarvam output as `ocr_attempt` (`sarvam_ai_vision`)
- Merge Sarvam field candidates into vote for conflicted/missing fields only
- Re-run field resolution for affected fields; re-assemble parse input if changed
- Optional: re-queue parse if parse input materially changed

### Inputs

| Input | Source |
|-------|--------|
| Original image file | Intake storage |
| `field_resolution_json` with conflicts/missing | Phase 3 |
| Existing `AiVisionExtractionService` Sarvam path | Laravel |

### Outputs

| Output | Destination |
|--------|-------------|
| Sarvam raw text / structured extract | `ocr_attempts` |
| Updated `field_resolution_json` | Intake |
| Updated `last_parse_input_text` | If judge changed fields |
| `sarvam_judge_triggered` flag in metadata | Intake meta / telemetry |
| Cost telemetry | `cost_units` on attempt if available |

### Success criteria

- [ ] Sarvam **not called** when all four trigger fields resolved without conflict
- [ ] Sarvam **called** on synthetic test: DOB missing after Phase 3
- [ ] Gender-only missing intake: Sarvam **not** called
- [ ] Religion "Hindu" with dictionary match: Sarvam **not** called even if confidence low
- [ ] Sarvam attempt always saved when called
- [ ] Trigger rate measurable; target ≤20% on benchmark batch

### Out of scope (Phase 4)

- Sarvam on every upload
- Sarvam for gender, income, marital status alone
- Replacing cheap OCR entirely with Sarvam

---

## Phase 5 — Admin Comparison UI

### Scope

- Comparison table on **`correct-candidate` page only** (v1.0 frozen)
- Columns: Field | Final | Tesseract | Second OCR | Sarvam | Reason
- Read from `field_resolution_json` + `ocr_attempts` — no new write paths
- Bulk list: **status only** (`ocr_ensemble_processing` / `ocr_ready`) — **no** 4-column OCR in dense table
- Marathi/plain field labels where consistent with correction form

### Inputs

| Input | Source |
|-------|--------|
| `field_resolution_json` | Intake |
| `ocr_attempts` | DB |
| Second engine display name | From `engine` constant / config |

### Outputs

| Output | Destination |
|--------|-------------|
| HTML comparison table | `correct-candidate.blade.php` |
| Empty state | "Ensemble not run" when flag off or legacy intake |

### Success criteria

- [x] Table visible on `correct-candidate` for ensemble intakes
- [x] Table **not** on bulk list or intake list
- [x] All 16 fields listed (or subset with data + missing rows)
- [x] Reason column explains vote / validator / sarvam_judge
- [ ] No regression to correction save flow *(staging smoke — ops)*
- [x] Feature flag off: table hidden or shows legacy message

### Out of scope (Phase 5)

- Comparison on intake `show` technical tab
- Inline edit from comparison table (edit stays in existing form)
- Export CSV of comparisons

---

## Cross-phase rules (all phases)

| Rule | Applies |
|------|---------|
| `intake_ocr_ensemble_enabled = false` → legacy path | All |
| `raw_ocr_text` immutable at create | All |
| No `parsed_json` direct mutation | All |
| No `MutationService` / approval bypass | All |
| Benchmark before second engine production | Phase 2 |
| PR references blueprint v1.0 + phase contract | All |

---

## Phase completion order

```
Phase 1 ──► Phase 2 (benchmark) ──► Phase 3 ──► Phase 4 ──► Phase 5
                │
                └── may skip second engine; Phase 3+ continue
```

**Implementation start:** Phase 1 only, after product owner acknowledges v1.0 freeze.

---

## Document history

| Version | Date | Change |
|---------|------|--------|
| 1.0 | 2026-07-12 | Initial phase contracts aligned with blueprint v1.0 freeze |
| 1.0a | 2026-07-14 | Phase 5 success-criteria checkmarks (v1.0 freeze review; contracts unchanged) |
