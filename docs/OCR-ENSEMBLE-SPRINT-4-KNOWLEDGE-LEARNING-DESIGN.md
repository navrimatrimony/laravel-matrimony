# OCR Ensemble — Sprint 4 Knowledge / Learning Design

> **Status:** **4a+4b COMPLETE** (2026-07-15) — design signed + SSOT guard tests; **4c deferred**; no AI learning enablement  
> **Authority:** Blueprint §19.3 Sprint 4 + §19.4 + PHASE-5 SSOT + `INTAKE-NORMALIZATION-SSOT.md` + Day-31 safety audit  
> **Prerequisite:** Sprint 2 **CLOSED** (all engines GO/NO-GO); Sprint 3 **SKIPPED** (no second-engine GO)  
> **Implementation:** 4b landed; further ensemble injection only under Phase Contract **4c**

---

## 1. Purpose

Define a single, SSOT-safe **Knowledge / Learning layer** for biodata OCR → structured fields:

1. **Master dictionary** (curated aliases / controlled masters)  
2. **Approval feedback** (`approval_snapshot` vs parse → correction observations → optional patterns)  
3. Clear **read** and **write** paths that never silently overwrite profiles or `raw_ocr_text`

Examples in scope: `96 Kuli` / कुळी spelling variants; city / place OCR noise → approved canonical value.

---

## 2. Goals / non-goals

### In scope (design)

| Item | Intent |
|------|--------|
| Unify existing Day-31 learning + master aliases under one contract | No parallel “second brain” |
| Specify where knowledge may influence ensemble vs post-parse only | Explicit stages |
| Field coverage matrix for ensemble critical + community fields | Gaps visible |
| Governance, kill-switches, promotion gates | Stay disabled until 4b+ |
| Worked examples (`96 Kuli`, city) proving SSOT | Profile unchanged until approve + MutationService |

### Out of scope (forbidden without a new Blueprint amendment)

- Sprint 3 multi-OCR vote / second engine production path  
- Weight learning / layout AI / full-page LLM cleanup  
- Auto-enabling `ai_generalize_*` or learning promotion flags  
- Direct `profile->update()` or bypass of `MutationService`  
- Rewriting `raw_ocr_text` or destroying OCR attempts  
- Destructive / type-changing migrations (PHASE-5)  
- Replacing `IntakeControlledFieldNormalizer` with a parallel resolver

---

## 3. Inventory (reuse — do not duplicate)

### Approval feedback (exists)

| Piece | Role |
|-------|------|
| `IntakeApprovalService` | On approve: core parse ≠ snapshot → `ocr_correction_logs` + threshold patterns / conflicts |
| `ocr_correction_logs` / `_actor_archive` | Observation store |
| `ocr_correction_patterns` | `frequency_rule` / `ai_generalized` |
| `ocr_pattern_conflicts` | Competing corrected values — **no overwrite** |
| `OcrNormalize::applyBaselinePatterns` / `sanityCheckLearnedValue` | Narrow read + sanity |
| `NightlyOcrLearningJob` | Insert-only AI generalize (config **off**) |
| Admin `OcrPatternController` | Toggle `is_active` only |
| Audits | `IntakeLearning*AuditCommand` — read-only readiness |

### Master dictionary (exists)

| Piece | Role |
|-------|------|
| `IntakeControlledFieldNormalizer` | **Sole** intake orchestrator for controlled fields |
| Alias tables + `MasterDataAliasNormalizer` / `ControlledMasterDbAliasResolver` | religion / caste / sub_caste / location / … |
| `LocationSuggestionPatternLearningService` | Admin place-suggestion rank patterns (adjacent) |
| Seeders e.g. `96 Kuli` under Maratha | Curated master truth |

### Ensemble hardcodes (exists; quarantine or migrate later)

| Piece | Role |
|-------|------|
| `OcrEnsembleCommunityExtractor` | e.g. `मटाठा`→`मराठा`, N-कुळी regex — **not** a learning store |
| `OcrDomainIntelligenceService` | Parse-input enhance only; never mutates raw OCR |

**Authority docs:** `DAY31_FULL_INTEGRATION_SAFETY_AUDIT.md`, `SSOT_DAY31_INTAKE_APPROVE_FLOW.md`, `INTAKE-NORMALIZATION-SSOT.md`, `INTAKE_LEARNING_CANDIDATE_RULES_DRY_RUN_RUNBOOK.md`.

---

## 4. System context

```text
Image
  → OCR attempt(s) [Tesseract today; Sprint 3 N/A]
  → Ensemble extract / normalize / vote / validate → field_resolution
  → Parser / preview (parsed_json)
       │  READ: baseline patterns (narrow), domain enhance (no raw rewrite)
       ▼
Human / Suchak review → approval_snapshot_json
       │  WRITE: correction logs / patterns / conflicts (existing Day-31)
       ▼
MutationService::applyApprovedIntake → profile SSOT
       │
       ▼
Post-apply / next intake
  READ: IntakeControlledFieldNormalizer (aliases → master IDs)
  READ (future 4b): optional pattern hints at agreed stage only
```

**Law:** Knowledge may **hint** or **normalize text → IDs**; it must **never** write profile columns itself.

---

## 5. Sources of truth (priority)

| Rank | Source | Mutability | Use |
|------|--------|------------|-----|
| 1 | Reviewed `approval_snapshot` + MutationService apply | Human / pipeline | Profile SSOT |
| 2 | Master DB + curated aliases | Admin / seeder | Controlled canonical IDs |
| 3 | `frequency_rule` patterns (active) | Threshold from logs; admin toggle | Narrow text normalize |
| 4 | `ai_generalized` patterns | Insert-only; flag off today | Candidates only until promoted |
| 5 | Ensemble hardcoded maps | Code change + PR | Temporary OCR noise rescue |

Conflicting corrected values → **conflict row**, not silent winner swap (Day-31).

---

## 6. Write path (observations → knowledge)

**Trigger:** first successful approve path that compares `parsed_json.core` vs approval snapshot core (existing).

**Rules (locked):**

1. Log every material core diff (field_key, original, corrected, actor archive).  
2. Threshold strengthen (≥5 historical default) → create/bump `frequency_rule` **or** open `ocr_pattern_conflicts`.  
3. Never overwrite an existing pattern’s `corrected_value` with a competitor.  
4. Never write learning from unverified OCR providers as truth — only approval snapshot diffs.  
5. Never update profiles from learning jobs.  
6. `ai_generalized` remains insert-only and **disabled** until Phase Contract 4b+ explicitly enables it with readiness audits green.  
7. Alias promotion (OCR noise → `*_aliases` row) is **admin/curated**, not auto from logs in 4a.

---

## 7. Read path (where knowledge may apply)

| Stage | Allowed today | Sprint 4 design decision |
|-------|---------------|---------------------------|
| Ensemble extract (Phase 3) | Hardcoded community maps only | **4a:** keep as-is; **do not** inject frequency patterns into voters yet |
| Parser / `OcrNormalize` | Baseline patterns for selected fields (DOB/height/BG/…) | **4a:** document; no new fields without matrix + tests |
| `IntakeControlledFieldNormalizer` | Alias → master ID | **Primary** path for `96 Kuli` / city canonicalization |
| Profile DB | MutationService only | Unchanged |

**Rationale:** Injecting noisy learned patterns into ensemble voters before Sprint 2-quality OCR uplift risks amplifying wrong majors. Post-parse controlled normalization is already SSOT-aligned.

---

## 8. Field coverage matrix (ensemble-facing)

| Field / concept | Master alias | Frequency pattern | Ensemble hardcode | Gap / 4b candidate |
|-----------------|:------------:|:-----------------:|:-----------------:|--------------------|
| religion | ✓ | rare | ✓ community | Prefer alias over harden |
| caste / sub_caste (`96 Kuli`) | ✓ seed + alias | possible | ✓ कuli variants | Curate aliases; pattern only if sanity passes |
| gender | limited | baseline | extract | keep baseline careful |
| date_of_birth | n/a | baseline | Sprint 1 normalizer | learning secondary to OCR quality |
| mobile | n/a | baseline | extract | no auto “fix” invent digits |
| city / village / location | `location_aliases` + suggestion patterns | separate | weak | Prefer location alias pipeline |
| education / occupation | domain services | limited | — | stay in controlled normalizer |

---

## 9. Worked examples

### 9.1 `96 Kuli`

1. OCR / extract yields `96 कुली` / `96 Kuli` variant.  
2. Preview shows unresolved or near-match text.  
3. Reviewer sets sub_caste to canonical master (`96 Kuli` / seed ID).  
4. Approve → MutationService applies profile; optional correction log if parse text differed.  
5. **Next intake:** `IntakeControlledFieldNormalizer` + alias/exact match resolves without profile write from learning job.  
6. Ensemble hardcode may still help extract; **long-term** move stable variants into **alias table**, not more PHP hardcodes.

### 9.2 City OCR noise

1. OCR: garbled place string.  
2. Reviewer picks correct location / writes approved place.  
3. Approve → profile via MutationService; location suggestion patterns may rank (existing location learning) — **not** a blank check to rewrite other intakes.  
4. Curated `location_aliases` (admin) is the durable dictionary for geo text → `addresses.id`.

**SSOT check:** After steps 1–3, profile columns change **only** through `applyApprovedIntake`. Learning tables may gain rows; other users’ profiles untouched.

---

## 10. Governance & ops

| Control | Behavior |
|---------|----------|
| Admin pattern UI | `is_active` toggle only |
| `config/ocr.php` `ai_generalize_*` | Default **off** |
| Learning readiness audits | Must stay green / dry-run before any promotion flag |
| Conflict queue | Resolve via conflict table + human; no silent merge |
| Kill switch | Disable pattern application flags / deactivate rows |

---

## 11. PHASE-5 compliance checklist

- [x] No silent profile overwrite  
- [x] Mutations only via existing approval → `MutationService`  
- [x] Raw OCR immutability  
- [x] Additive schema only if 4b needs tables (prefer reuse)  
- [x] No parallel controlled-field resolver  
- [x] Competing corrections → conflict, not overwrite  
- [x] AI generalize insert-only + gated  
- [x] Sprint 3 not implied  

---

## 12. Phase contracts

### Phase Contract 4a — Design (this document)

| Item | Value |
|------|-------|
| Deliverable | This signed design + checklist update |
| Code | **None required** |
| Done when | Doc merged/committed; Sprint 4 status = design signed |
| Date | 2026-07-15 |

### Phase Contract 4b — First implementation slice

| Item | Value |
|------|-------|
| Deliverable | SSOT guard Feature tests — learning job never mutates profiles |
| Code | `tests/Feature/Intake/OcrKnowledgeLearningSsotGuardTest.php` |
| Flags | No production enablement of `ai_generalize_*` |
| Done when | Tests green (2026-07-15) |
| Explicitly deferred | Alias seed churn; ensemble voter injection (4c); AI flag on |

### Phase Contract 4c — Ensemble read-path (future)

Only if metrics justify: bounded pattern/alias assist inside ensemble **normalize** (not raw OCR), behind feature flag, with GT regression on Batch-001 / GT-20.

---

## 13. Open questions (non-blocking for 4a)

1. Should Suchak corrections weight equal to end-user approvals for pattern thresholds?  
2. City: prefer `location_aliases` exclusively vs also `ocr_correction_patterns`? (Design default: **aliases first**.)  
3. When to allow ensemble voter injection (4c) — after next OCR GO or after alias coverage KPIs?

Escalate to product only if 4b would change business truth rules (actor weight, auto-alias promotion).

---

## 14. Definition of Done

| Milestone | Status |
|-----------|--------|
| Sprint 2 all engines GO/NO-GO | **Done** |
| Sprint 3 | **Skipped** |
| Sprint 4 **design signed** (4a) | **Done** (this doc) |
| Sprint 4 **implement** (4b SSOT guards) | **Done** — Feature tests; flags unchanged |
| Sprint 4 **4c ensemble read-path** | **Deferred** — needs metrics / explicit start |

**Near-term Blueprint §19.4:** “Learning design signed” = **satisfied**. Implementation is a subsequent contract.

---

## 15. OCR Knowledge Base (Blueprint §20.7 — design expansion)

> Learning USP is **not** aliases-only. Human corrections must teach the OCR pipeline over time without silent profile writes.

### 15.1 Desired flow

```text
Human Approval (review snapshot)
  → OCR Knowledge Candidate (observation + optional draft pattern)
  → Confidence / sample count
  → Human or policy Review (promotion_status)
  → Reusable Pattern / curated alias
  → Next OCR / normalize / extract assist (feature-flagged)
```

**Not:** approval → immediate silent rewrite of other profiles.

### 15.2 Memory categories (target coverage)

| Category | Examples | Today |
|----------|----------|-------|
| Correction memory | wrong → corrected field pairs | `ocr_correction_logs` / patterns |
| Surnames / names | OCR noise → canonical name tokens | Partial via logs |
| Villages / places | place OCR → location alias | `location_aliases` + suggestion patterns |
| Castes / sub-castes | `96 Kuli`, कुली/कुळी | aliases + hardcodes |
| Degrees / occupations | education OCR | domain normalizers |
| OCR confusion pairs | मटाठा→मराठा | community extractor / patterns |
| Digits | ८/३, O/0 | sparse baselines |
| Dates | label+format rescue | Sprint 1 normalizer |
| Mixed Marathi–English | Adv./डॉ. titles, codes | name normalizers |

### 15.3 Phase Contract 4d (design only until started)

- Extend promotion UX: Knowledge Candidate queue (reuse `promotion_status` / conflicts)  
- Category tags on patterns (additive column OK; no destructive type change)  
- Read-path still post-parse-first (4c before ensemble injection)  
- No auto-enable of `ai_generalize_*`

---

## Changelog

| Date | Change |
|------|--------|
| 2026-07-15 | Initial design signed (4a); Sprint 2 close / Sprint 3 skip recorded |
| 2026-07-15 | 4b SSOT guard tests landed (`OcrKnowledgeLearningSsotGuardTest`) |
| 2026-07-15 | §15 OCR Knowledge Base expansion (aliases-only rejected; candidate→review→pattern) |
