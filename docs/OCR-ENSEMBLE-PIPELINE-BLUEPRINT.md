# OCR Ensemble Pipeline — Design Blueprint

> **STATUS: DESIGN FROZEN (v1.0)**  
> **Version:** 1.0  
> **Frozen:** 2026-07-12  
> **Audience:** Product owner, developers, reviewers  
> **Type:** Design contract only — **not** implementation instructions.

**Implementation या document च्या v1.0 review आणि explicit sign-off शिवाय बदलू नये.**  
नवीन requirements = नवीन version (v1.1+) किंवा change request.

**Guiding principle:**

> कोणताही नवीन OCR engine किंवा preprocessing step प्रत्यक्ष benchmark मध्ये मोजता येण्याजोगी सुधारणा दाखवतो तेव्हाच production pipeline मध्ये जोडला जाईल.

---

## 0. एका वाक्यात उद्देश

**एकाच biodata image वर preprocessing + primary OCR + (benchmark-proven) second OCR + field-wise voting + validators + Sarvam judge (फक्त conflict/missing वर) → विश्वासार्ह parse input → existing parser → admin comparison — सर्व काही queue मध्ये, feature flag खाली.**

**Primary goals (priority order):**

1. **Accuracy** — structured fields जास्तीत जास्त बरोबर.
2. **Sarvam cost कमी** — full-page vision फक्त जेव्हा cheap path अपुरा.
3. **Self-improving foundation** — सर्व OCR attempts + field resolution save, analytics नंतर.

**Primary goal नाही:** upload request वर सर्व काही sync (20–30s background wait acceptable).

---

## 1. Design freeze नियम

| नियम | अर्थ |
|------|------|
| हा document = **design contract** | Code या PR या implementation या blueprint बदल नाही review शिवाय |
| Technology-neutral second engine | Blueprint मध्ये कोणताही engine (Paddle, EasyOCR, इ.) **final** मानला जात नाही |
| OCR ≠ Parser | Full `BiodataParserService` ensemble loop मध्ये चालवणार नाही |
| Parser regex reuse | Field extractor **shared** logic — duplicate regex नाही |
| Bulk intake authority chain कायम | `IntakeCreationService` → `ParseIntakeJob` → existing approval path |
| `raw_ocr_text` immutable (Phase 1) | Immutable policy document नंतर formalize; Phase 1 मध्ये break नको |
| Feature flag mandatory | `intake_ocr_ensemble_enabled` — production default `false` |

---

## 2. System architecture

### 2.1 High-level flow

```
Upload (admin bulk / single intake / future mobile capture)
        ↓
HTTP response immediately ("queued" / batch created)
        ↓
Queue worker: OcrEnsemblePipelineJob (name TBD at implement time)
        ↓
┌───────────────────────────────────────────────────────────────┐
│  OCR ENSEMBLE PIPELINE (new)                                  │
│                                                               │
│  Image                                                        │
│    ↓                                                          │
│  OpenCV preprocessing (mandatory minimal v1)                  │
│    ↓                                                          │
│  Primary OCR → Tesseract (existing multipass enriched, not     │
│                replaced blindly)                              │
│    ↓                                                          │
│  [If enabled after benchmark] Second OCR engine               │
│    ↓                                                          │
│  Save each attempt → biodata_intake_ocr_attempts              │
│    ↓                                                          │
│  Field Extractor (15–17 structured fields only)               │
│    → per-engine candidates                                    │
│    ↓                                                          │
│  Per-field: normalize → vote → validator → final candidate    │
│    ↓                                                          │
│  Sarvam Vision Judge? (only on trigger rules — §5)          │
│    ↓                                                          │
│  Assemble canonical parse input text + field_resolution_json  │
└───────────────────────────────────────────────────────────────┘
        ↓
Existing ParseIntakeJob → BiodataParserService (rules path)
        ↓
parsed_json + field_confidence_json (existing)
        ↓
Admin review (bulk correct-candidate / intake show)
        ↓
Comparison table (§7)
```

### 2.2 OCR आणि Parser वेगळे (mandatory)

**चुकीचे (non-goal):**

```
Tesseract  → BiodataParserService → vote
Paddle     → BiodataParserService → vote
Sarvam     → BiodataParserService → vote
```

**योग्य:**

```
Each OCR engine → raw text only
        ↓
Field Extractor (lightweight, shared patterns)
        ↓
Candidates per field per engine
        ↓
Vote + validators
        ↓
Final field map + assembled parse input string
        ↓
BiodataParserService (once)
```

### 2.3 Integration points (existing — extend only)

| Component | Role |
|-----------|------|
| `IntakeCreationService` | Upload SSOT; dispatches ensemble when flag on |
| `ProcessBulkIntakeBatchItemJob` | Bulk queue entry |
| `biodata_intake_ocr_attempts` | Per-engine raw text + metadata |
| `TesseractMultiPassOcrService` | Primary OCR — enrich, do not fork duplicate |
| `AiVisionExtractionService` | Sarvam judge path (existing doc-digitization) |
| `ParseIntakeJob` | Unchanged contract; consumes ensemble parse input |
| `BiodataParserService` | Single parse after ensemble |
| `BulkIntakeCandidateCorrectionService` | Admin correction unchanged |

---

## 3. Scope — structured fields only

### 3.1 In scope (Phase 1–5 ensemble)

| # | Field key (conceptual) | Notes |
|---|------------------------|-------|
| 1 | `full_name` | Name |
| 2 | `date_of_birth` | DOB |
| 3 | `gender` | Male / Female / dictionary |
| 4 | `primary_contact_number` | Mobile |
| 5 | `height` | ft/in or cm normalized |
| 6 | `education` | Abbreviation normalize |
| 7 | `occupation` | Job line |
| 8 | `income` | Annual / other income — validator optional; admin may verify |
| 9 | `religion` | Master dictionary |
| 10 | `caste` | Master table lookup |
| 11 | `sub_caste` | Master / fuzzy |
| 12 | `state` | Location master |
| 13 | `district` | Location master |
| 14 | `taluka` | Location master |
| 15 | `village` | Location master |
| 16 | `marital_status` | Enum list — **matching-critical**; keep in scope |

**Total: 16 structured fields** — **Income** आणि **Marital Status** दोन्ही scope मध्ये राहतात (design review confirmed). Exact SSOT keys at implement time must match `BiodataParserService` / correction form.

### 3.2 Explicitly out of scope (ensemble voting)

| Out of scope | Reason |
|--------------|--------|
| `अपेक्षा` / expectations paragraph | Line breaks differ per engine |
| `स्वतःबद्दल` / about self | Unstructured prose |
| `कौटुंबिक माहिती` narrative blocks | Paragraph compare unreliable |
| Siblings / relatives full lists | Semi-structured; parser + manual review |
| Horoscope detail beyond religion-adjacent | Phase 2+ optional |
| Full text majority vote | Rejected by design |

Paragraph content may still appear in **assembled parse input** from primary OCR text for parser consumption; ensemble **does not vote** on paragraph fields.

---

## 4. Engine policy

### 4.1 Primary OCR (fixed for Phase 1)

| Item | Policy |
|------|--------|
| Engine | **Tesseract** (`mar` / `mar+eng` per `intake_ocr_language_hint`) |
| Preprocessing | **OpenCV mandatory minimal v1** before Tesseract |
| Existing multipass | Retain and extend; do not replace with naive single-pass |
| Output | Raw text → `biodata_intake_ocr_attempts` (`engine = laravel_native_ocr`) |

### 4.2 OpenCV preprocessing — mandatory minimal v1

| Step | Phase 1 |
|------|---------|
| EXIF auto-rotate | Yes |
| Grayscale + contrast | Yes |
| Text-region crop (photo strip exclusion) | Yes — best-effort |
| Deskew / shadow / border AI | No (later phase) |

Preprocessing version string stored on attempt (`preprocessing_version`).

### 4.3 Second OCR engine — benchmark-selected (technology-neutral)

Blueprint **does not** mandate Paddle, EasyOCR, or any vendor.

```
Primary OCR (Tesseract) stable in production
        ↓
10-image POC (technology check)
        ↓
50-image benchmark (decision)
        ↓
IF second engine ≥ agreed uplift on critical fields
        THEN select and integrate (HTTP sidecar if required)
        ELSE remain Tesseract-only + Sarvam judge
```

| Candidate | Evaluate via benchmark only |
|-----------|----------------------------|
| PaddleOCR | Yes |
| EasyOCR | Yes |
| Other | Only if benchmark proves value |

**Integration pattern (when selected):**

```
Laravel queue worker
        ↓ HTTP
Python OCR sidecar (new service — not embedded in PHP)
        ↓
Raw text + optional per-field hints
        ↓
Laravel saves second ocr_attempt
```

**Fallback:** If sidecar down or timeout → log warning → continue with Tesseract-only → **job must not fail**.

### 4.4 Sarvam — not a daily OCR runner

Sarvam Document Digitization = **Judge / tie-breaker / gap-filler** only (§5).

### 4.5 Mobile ML Kit

**Phase 1 non-goal.** May be recorded as optional `ocr_attempt` evidence later; not equal voter weight (737 test: run-on risk).

---

## 5. Sarvam judge policy

### 5.1 When Sarvam runs

Sarvam Vision **only** when **all** cheap paths exhausted **and** any trigger below is true:

| Trigger | Definition |
|---------|------------|
| Name conflict | Normalized name differs across engines AND validator cannot resolve |
| DOB missing | No valid DOB candidate after vote + validator |
| Mobile missing | No valid 10-digit Indian mobile after vote + validator |
| Religion missing | No dictionary match after vote |

**Gender missing is NOT a Sarvam trigger** (design review v1.0): gender confidently मिळाला → वापरा; नाही → रिकामा ठेवा; admin review मध्ये ठरवा.

### 5.2 When Sarvam does NOT run

| Condition | Action |
|-----------|--------|
| All critical fields valid after vote | Skip Sarvam |
| Validators pass (e.g. religion = Hindu, dictionary match) | Skip Sarvam even if confidence low |
| Tesseract = second engine on field | Skip Sarvam for that field |
| Feature flag off | Existing path only |

### 5.3 Cost model (reference)

| Scenario | Sarvam calls / 1000 biodata |
|----------|----------------------------|
| All files | 1000 × ₹0.50 = ₹500 |
| ~15% trigger rate | ~150 × ₹0.50 = **₹75** |
| Target | **≤ 20%** trigger rate after ensemble mature |

### 5.4 Sarvam output use

| Use | Allowed |
|-----|---------|
| Fill missing / resolve conflict fields | Yes |
| Replace entire cheap OCR blindly | No |
| Save as `ocr_attempt` (`sarvam_ai_vision`) | Yes |
| Ground truth for benchmark analytics | Yes |
| Auto-trust without validator | No |

---

## 6. Voting policy (field-wise)

### 6.1 Per-field pipeline (same pattern every field)

```
For each structured field:
        ↓
Collect candidates from each OCR attempt
        ↓
Normalize (field-specific)
        ↓
Vote (weighted when weights exist; equal until benchmark)
        ↓
Validator (field-specific)
        ↓
Final value OR mark missing/conflict
```

### 6.2 Field-specific strategies (contract)

| Field | Normalize | Vote | Validator |
|-------|-----------|------|-------------|
| Name | Strip चि/श्री; Devanagari cleanup | Majority / weighted | Min length; no pure digits |
| DOB | `DD/MM/YYYY`; digit homoglyphs | Majority | Age 18–80; valid calendar |
| Gender | Dictionary map | Majority | Enum: male/female — missing OK; **no Sarvam** |
| Mobile | Digits only | Regex-valid wins | `^[6-9]\d{9}$` |
| Height | ft/in → cm band | Majority | 4'0"–7'0" or cm equivalent |
| Education | BE, M.Com, GDC&A aliases | Majority | Known abbrev set |
| Occupation | Trim; English line | Longest valid line | Non-empty |
| Income | Digit + comma normalize | Majority | Positive; plausible range |
| Religion | Dictionary | Majority | Master list |
| Caste | Master fuzzy | Majority | `castes` table |
| Subcaste | Master fuzzy | Majority | subcaste table |
| State/District/Taluka/Village | Master lookup | Majority + hierarchy | Parent-child valid |
| Marital status | Enum map | Majority | never_married / widowed / divorced |

**Shared logic:** Extractor MUST reuse patterns from `BiodataParserService` / existing parsing helpers — **no duplicate regex fork**.

### 6.3 Confidence

| Phase | Policy |
|-------|--------|
| Phase 1–4 | **Validators > confidence scores** |
| Later | Per-engine confidence calibration (non-goal now) |

Example: Religion "Hindu" at 60% confidence but dictionary match → **accept, no Sarvam**.

### 6.4 Conflict → Sarvam

Only unresolved after vote + validator → Sarvam judge for that intake (not per-field API spam if batch extraction supports full page).

---

## 7. Admin UI

### 7.1 Comparison table (required)

**Location (v1.0 frozen):** **फक्त** Bulk **`correct-candidate`** (Review / Correct Candidate) page.

| Surface | Comparison table |
|---------|------------------|
| `correct-candidate` | **Yes** — primary debugging/review UI |
| Bulk intake list / dense table | **No** |
| Admin intake list | **No** |
| Intake `show` technical tab | **No** (v1.0) — link to correct-candidate if needed |

| Column | Content |
|--------|---------|
| Field | Marathi/plain label |
| Final | Ensemble winner |
| Tesseract | Candidate or — |
| Second OCR | Candidate or — (label engine name dynamically) |
| Sarvam | Candidate or — |
| Reason | e.g. `2/2 vote`, `regex valid`, `dictionary`, `sarvam_judge`, `manual_override` |

### 7.2 Processing status

Bulk row / intake must show:

| Status | Meaning |
|--------|---------|
| `ocr_ensemble_processing` | Worker running |
| `ocr_ready` | Ensemble done, parse queued/done |
| Existing statuses | Unchanged |

Admin wait 20–30s is acceptable; UI must show progress, not hang silently.

### 7.3 Debug

Link to existing technical tab (`parse_input_source`, `ocr_attempt` count) — extend, do not replace.

---

## 8. Storage contract

### 8.1 `biodata_intake_ocr_attempts` (existing table — extend usage)

Each engine run saves one row minimum:

| Column (existing) | Ensemble use |
|-------------------|--------------|
| `engine` | `laravel_native_ocr`, `second_ocr_*` (TBD constant), `sarvam_ai_vision` |
| `raw_text` | Full OCR output |
| `quality_score` | Engine-reported if available |
| `field_scores_json` | Per-field scores when available |
| `duration_ms` | Timing |
| `preprocessing_version` | OpenCV pipeline version |
| `engine_meta_json` | Sidecar version, layout hint, errors |
| `is_primary` | Which attempt supplied primary raw transcript |
| `selected_reason` | Why primary selected |

**New engine constant** for second OCR added at integration time — not named in blueprint.

### 8.2 Field resolution (new logical artifact)

**Name:** `field_resolution_json` (on `biodata_intakes` or nested in routing telemetry — **exact column decision at implement review**).

Per field:

```json
{
  "full_name": {
    "final": "चि अविनाश अर्जुन खोडवे",
    "source": "vote",
    "winning_engine": "second_ocr",
    "confidence": 0.92,
    "reason": "2/2 agree after normalize",
    "candidates": {
      "tesseract": "…",
      "second_ocr": "…",
      "sarvam": null
    }
  }
}
```

### 8.3 Parser input

| Artifact | Rule |
|----------|------|
| `raw_ocr_text` | Immutable upload record — primary OCR text at create time (existing SSOT) |
| `last_parse_input_text` | Ensemble-assembled canonical text fed to parser |
| Assembly | Structured field winners + remaining primary OCR body for parser context |

Exact assembly format defined at implement time; must not break `ParseIntakeJob` quality gates.

### 8.4 Benchmark dataset (POC)

| Artifact | Purpose |
|----------|---------|
| Golden images + expected fields | 735 (Sarvam ground truth), 736, 737 + expanding set |
| Stored outside production DB | `docs/` or private dataset path per existing golden dataset runbook |

---

## 9. Feature flag

| Key | Default | When true |
|-----|---------|-----------|
| `intake_ocr_ensemble_enabled` | `false` | New pipeline runs in queue for new uploads |

| Environment | Expected |
|-------------|----------|
| Production | `false` until POC + review sign-off |
| Staging / test batch | `true` for benchmark batches |

Rollback = set flag `false`; zero migration rollback required.

---

## 10. POC rules

### 10.1 Two-stage benchmark (mandatory before second engine)

| Stage | Size | Purpose | Go / no-go |
|-------|------|---------|------------|
| **Technology check** | **10 images** | Preprocess + Tesseract vs candidate second engine | Second engine shows ≥5% uplift on critical fields? |
| **Decision** | **50 images** | Statistical weights, Sarvam trigger rate | Integrate second engine or stay Tesseract-only |

**Seed set must include:**

| Intake | Layout type | Role |
|--------|-------------|------|
| #735 | Table | Sarvam ground truth |
| #736 | Table | Bulk Tesseract baseline |
| #737 | Table | Mobile ML Kit (reference only) |
| +7 varied | Photo-right + table mix | Layout stress |

### 10.2 Metrics per field

| Metric | Formula (conceptual) |
|--------|----------------------|
| Field accuracy | % exact or normalized match vs ground truth |
| Critical field set | name, DOB, mobile, religion, gender |
| Sarvam trigger rate | % intakes hitting judge rules |
| Cost per 1000 | Sarvam calls × ₹0.50 |

### 10.3 Policy document

**No 100-page policy doc before POC.** POC spreadsheet/results **become** the living policy appendix.

---

## 11. Implementation phases (ordered — v1.0)

Detailed per-phase contracts: **`docs/OCR-ENSEMBLE-PHASE-CONTRACTS.md`**

| Phase | Deliverable | Second engine? |
|-------|-------------|----------------|
| **1** | Feature flag, queue, OpenCV v1, Tesseract, save `ocr_attempts` | No |
| **2** | 10→50 benchmark; integrate **second OCR** if proven (HTTP sidecar) | Conditional |
| **3** | Field extractor (16 fields), validators, voting, `field_resolution_json`, parse input assembly | Uses 1–2 engines |
| **4** | Sarvam judge (triggers §5 only) | Judge only |
| **5** | Admin comparison table on `correct-candidate` + processing statuses | No |
| **6+** | Weight learning, layout detection, Marathi normalizer, LLM cleanup | Later (non-goals) |

**Coding starts only after v1.0 sign-off (§16).**

---

## 12. Non-goals (Phase 1–5)

| Non-goal | Notes |
|----------|-------|
| Paragraph field voting | अपेक्षा, स्वतःबद्दल, etc. |
| Weight learning / auto weights | Phase 7 |
| Layout AI classification | Phase 7 |
| Self-learning loop automation | Phase 7 |
| LLM text correction (image-less) | Phase 7 |
| EasyOCR / Paddle without benchmark | Forbidden |
| ML Kit as ensemble voter | Ignored Phase 1 |
| Confidence calibration research | Ignored |
| Full immutable `raw_ocr_text` policy rewrite | Deferred formal doc |
| Replacing `BiodataParserService` | Forbidden |
| Sync OCR on HTTP upload | Forbidden |
| Cross-repo Flutter changes | Out of scope unless separate task |

---

## 13. Success criteria

### 13.1 Phase 1 complete when

- [x] `intake_ocr_ensemble_enabled` exists; default `false`
- [x] Upload returns immediately; work runs on `bulk-intake` (or dedicated) queue
- [x] OpenCV minimal preprocessing runs before Tesseract
- [x] At least one `biodata_intake_ocr_attempts` row per intake with timing + version
- [x] Failure in optional path does not fail entire job (Tesseract fallback)
- [x] Existing bulk flow works unchanged when flag `false`

### 13.2 Phase 2 complete when

- [x] 10-image technology check completed and recorded
- [x] 50-image decision benchmark completed (or second engine rejected with documented reason)
- [x] If go: second engine sidecar integrated with Tesseract fallback; if no-go: documented stay on Tesseract-only

### 13.3 Phase 3 complete when

- [x] 16 structured fields extracted to candidates without running full parser per engine
- [x] Field-wise vote + validator produces `field_resolution_json`
- [x] Assembled parse input reaches `ParseIntakeJob` and produces `parsed_json`
- [x] Gender missing does not block pipeline; no Sarvam for gender alone

### 13.4 Phase 4 complete when

- [x] Sarvam runs **only** on: name conflict OR DOB missing OR mobile missing OR religion missing
- [x] Gender missing does **not** trigger Sarvam
- [x] Sarvam skip verified when engines agree on all triggered-critical fields
- [x] Sarvam attempt saved in `ocr_attempts`

### 13.5 Phase 5 complete when

- [x] Admin comparison table visible **only** on `correct-candidate`
- [x] Columns: Field, Final, Tesseract, Second OCR, Sarvam, Reason
- [x] `ocr_ensemble_processing` status visible in bulk UI (list row — status only, not full table)

### 13.6 Program success (post 50-image benchmark)

| Metric | Target |
|--------|--------|
| Critical field accuracy (ensemble) | ≥ 90% vs ground truth |
| vs Tesseract-only baseline | ≥ 10% uplift |
| Sarvam trigger rate | ≤ 20% of production volume |
| Admin manual fix rate | ↓ measurable vs baseline |

---

## 14. Risks and mitigations

| Risk | Severity | Mitigation |
|------|----------|------------|
| Second engine weak on Devanagari | High | 10-image POC before any integration |
| Python sidecar ops burden | Medium | Health check; Tesseract-only fallback |
| VPS CPU saturation | Medium | Queue concurrency limit; dedicated worker |
| Duplicate parser regex bugs | High | Shared extractor module contract |
| Scope creep | High | This blueprint freeze + non-goals |
| Sarvam cost overrun | Medium | Trigger rules §5; monitor trigger rate |

---

## 15. Authority and SSOT

```
Upload file + metadata
        ↓
IntakeCreationService (unchanged entry)
        ↓
OcrEnsemblePipeline (new, flag-gated)
        ↓
ParseIntakeJob (unchanged job, new parse input source)
        ↓
approval_snapshot_json (admin correction — unchanged)
        ↓
IntakeApprovalService → MutationService (unchanged)
```

- Ensemble improves **machine read** quality; it does **not** bypass approval or mutation governance.
- Bulk `item_status` remains technical; business screening unchanged.

---

## 16. Design review sign-off (v1.0)

| # | Question | v1.0 |
|---|----------|------|
| 1 | OCR ≠ Parser separation | ✅ |
| 2 | 16 fields incl. income + marital_status | ✅ |
| 3 | Sarvam triggers (no gender missing) | ✅ |
| 4 | Second engine benchmark-gated | ✅ |
| 5 | OpenCV mandatory minimal v1 | ✅ |
| 6 | Feature flag + fallback | ✅ |
| 7 | Comparison table **only** `correct-candidate` | ✅ |
| 8 | Non-goals | ✅ |
| 9 | Success criteria | ✅ |
| 10 | POC 10 → 50 before second engine | ✅ |
| 11 | Benchmark-only production additions | ✅ |

**Status:** Design frozen v1.0 — implementation may begin per phase contracts.  
**PR rule:** Each PR references `OCR-ENSEMBLE-PIPELINE-BLUEPRINT.md` v1.0 + relevant phase in `OCR-ENSEMBLE-PHASE-CONTRACTS.md`.

---

## 17. Reference tests (existing intakes)

| ID | Path | Value |
|----|------|-------|
| 735 | Sarvam judge / ground truth | Avinash Khode — table biodata |
| 736 | Bulk Tesseract | Same biodata — baseline |
| 737 | Mobile ML Kit | Same biodata — negative reference for voter use |

---

## 18. Document history

| Version | Date | Change |
|---------|------|--------|
| 0.1 | 2026-07-12 | Initial blueprint draft |
| **1.0** | **2026-07-12** | **DESIGN FROZEN** — gender not Sarvam trigger; comparison table only correct-candidate; income+marital_status confirmed; guiding principle; phase order aligned with phase contracts |
| 1.0a | 2026-07-14 | §13 acceptance checkmarks only (implementation freeze review; design unchanged) |
| 1.0b | 2026-07-14 | **§19 Post-v1.0 architecture roadmap LOCKED** — Phase 4 transport closed; Sprint 1→4 order (Phase3 forensics → engine eval → optional multi-OCR → knowledge) |

**Related:** `docs/OCR-ENSEMBLE-PHASE-CONTRACTS.md`  
**Readiness package:** `OCR-ENSEMBLE-PRODUCTION-READINESS-REVIEW.md`, `OCR-ENSEMBLE-IMPLEMENTATION-CHECKLIST.md`, `OCR-ENSEMBLE-TEST-PLAN.md`, `OCR-ENSEMBLE-BLUEPRINT-v1.1-ADDENDUM.md`

---

## 19. Post-v1.0 architecture roadmap (LOCKED — 2026-07-14)

> **Purpose:** Debugging mode बंद; architecture mode. Goal drift टाळण्यासाठी खालील क्रम **locked** आहे.  
> **Does not change** v1.0 design freeze (§1–§16). Production engine additions अजूनही **benchmark GO** नंतरच (§ guiding principle).

### 19.1 Product identity (non-negotiable)

हा **generic OCR project नाही**.

हा **Marathi Matrimony OCR Platform** आहे:

- Finite domain fields (धर्म, जात, शिक्षण, गाव/तालुका/जिल्हा, उंची, रक्तगट, मंगळ/राशी, …)
- स्वस्त offline primary path
- Sarvam = **Judge only** (OCR engine नाही)
- Human approval → knowledge improve (nurture later; governed SSOT)

**Primary goal (restated):** कमी खर्च + जास्त अचूकता + हळूहळू स्वतः सुधारणारी biodata OCR प्रणाली.

### 19.2 Phase 4 — CLOSED (transport / Judge)

Proven (validation intake **#771** and prior forensics):

| Fact | Status |
|------|--------|
| HTTP transport / model path | Closed — Judge returns **HTTP 200** |
| Soft-fail `http_400` investigation | Closed |
| Judge execute + attempt persist | Proven |
| `merge_noop` + `empty_sarvam_value` | Understood (empty Judge value when Phase 3 had no valid DOB) |
| Phase 4 as root cause of empty Final DOB | **Not at fault** |

**Do not reopen** HTTP / logging / Judge client forensics unless a **new** transport regression appears.

Sarvam remains: **Judge, not OCR.**

### 19.3 Locked sprint order (do not rearrange casually)

```
Sprint 1 — Phase 3 Validator / Extract Forensics
        ↓
Sprint 2 — OCR Engine Evaluation (benchmark only; no production integration)
        ↓
Sprint 3 — Second (and later) OCR into production ensemble IFF Sprint 2 GO
        ↓
Sprint 4 — Knowledge / Learning layer (design + SSOT-governed)
```

#### Sprint 1 — Phase 3 DOB / candidate forensics

- Focus: why `#771`-class intakes have `candidates.laravel_native_ocr = null` → `no_eligible_candidate` / `dob_invalid_format`.
- Path: OCR text → Extractor → Normalizer → Voter → Validator → FR.
- Out of scope: Phase 4, HTTP, merge, logging sinks.

#### Sprint 2 — OCR Engine Evaluation (**benchmark only**)

- **No production code path for new engines** until written GO in a new benchmark report.
- Candidates to evaluate (examples; not pre-crowned winners): Tesseract (baseline), PaddleOCR v5, EasyOCR, DocTR.
- Dataset: real Marathi biodata (suggest 100 → 200 → 500 as budget allows).
- Metrics example: Marathi text, English, digits/DOB/mobile, tables/layout, latency/cost.
- Phase 2 (2026-07-13) **NO-GO** remains valid for **that** EasyOCR/Paddle snapshot; it is **not** a permanent ban on any future engine generation. Re-benchmark required.

#### Sprint 3 — Multi-OCR vote in production

- Only engines with Sprint 2 **GO**.
- Add `ocr_attempt` rows + Phase 3 multi-engine vote; Phase 5 Second OCR column fills when present.
- Still behind feature flag; Tesseract fallback mandatory.

#### Sprint 4 — Knowledge / Learning

- Master dictionary + approval feedback (e.g. `96 Kuli` variants, city OCR noise → approved value).
- Must respect PHASE-5 SSOT / MutationService / approval_snapshot — no silent overwrite.
- Was listed as Phase 6+/7 non-goal in v1.0; this sprint **designs** it — implementation only after explicit phase contract.

### 19.4 What “done” looks like for the near term

| Milestone | Done when |
|-----------|-----------|
| Sprint 1 | Written forensic for DOB null-candidate cases + fix list (implement separately) |
| Sprint 2 | New benchmark doc + GO/NO-GO per engine |
| Sprint 3 | Second engine integrated only if GO |
| Sprint 4 | Learning design signed; then implement |

### 19.5 Explicitly deferred / rejected for cost

- Google Vision / Azure / AWS Textract as **ensemble voters** — out (cost).
- Integrating a new OCR into production **without** Sprint 2 GO — forbidden.
- Replacing Judge with full-page paid vision as default OCR — forbidden.

---

*End of blueprint v1.0 + §19 post-v1.0 locked roadmap — implementation per phase contracts + this §19 order.*
