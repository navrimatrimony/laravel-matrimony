# INTAKE NORMALIZATION SSOT (Single Source of Truth)

## Purpose

This document defines **non-negotiable rules** for how **controlled** user input
(intake forms, biodata OCR, manual entry, or API) is turned into **canonical
master IDs** (or safe null), in line with **PHASE-5 SSOT** (no silent overwrite,
mutations only via `MutationService`, intake raw preserved elsewhere).

---

## Core rule (MUST follow)

**All intake snapshot normalization for controlled fields MUST go through:**

`App\Services\Parsing\IntakeControlledFieldNormalizer`

— as the **orchestrator** for the parsed snapshot (`normalizeSnapshot`,
`normalizeCore`, `normalizeHoroscopeRows`, `normalizeEducationRows`, …).

**The engine** for many `master_*` lookups is:

`App\Services\ControlledOptions\ControlledOptionNormalizer`

— invoked **from** `IntakeControlledFieldNormalizer` (e.g. `resolveCoreField` →
`resolveControlledCoreValue`). That is **not** a bypass; it is the **approved**
sub-step.

**Domain engines** (when the canonical row is **not** a generic `key`/`label`
master row):

- `App\Services\EducationService` — `education_degrees` matching
- `App\Services\OccupationService` — occupation search / custom rows

These MUST be called **from** the intake orchestration path (or from
`ControlledOptionNormalizer` only if explicitly extended), not ad hoc from
controllers for **intake resolution**.

---

## Public API (actual vs aspirational)

**Today (codebase):** there is **no** `resolve(string $field, string $value)`
on `IntakeControlledFieldNormalizer`. Resolution is **section-based**:
`normalizeCore`, `normalizeEducationRows`, `normalizeCareerRows`, etc.

**Roadmap:** a thin `resolveControlledIntake(string $logicalField, string $raw)`
(or equivalent) **may** be added as a **facade** that delegates to the same
pipeline steps below — update this doc when it exists.

Until then, “single entry” means: **do not add new intake resolution paths
outside** `IntakeControlledFieldNormalizer` + approved engines above.

---

## Allowed pipeline (per controlled value)

Every **new** implementation for controlled intake resolution SHOULD follow
this order (where applicable):

1. **Normalize text** — trim, collapse whitespace, safe punctuation (reuse
   existing helpers where present).
2. **Alias lookup (DB)** — when an alias table exists for that domain
   (`religion_aliases`, `caste_aliases`, `sub_caste_aliases`, `city_aliases`,
   future `education_*_aliases`, etc.).
3. **Exact match** — key/label/`code`/`title`/`name_mr` as defined for that
   table (domain service or `ControlledOptionNormalizer`).
4. **Fallback** — bounded `LIKE` / normalized token match **only inside** the
   approved service (never unbounded in a controller).
5. **Unmatched** — structured log (and optionally a future `unknown_intake_*`
   table); **do not** silently pretend a match.

**Note:** Steps 2–4 may be no-ops for a given field until alias tables exist.

---

## “Forbidden” queries — scope (IMPORTANT)

**FORBIDDEN for intake → canonical ID resolution:**

- Ad hoc `where('name', …)` / `where('label', …)` / raw `LIKE` in **controllers**
  or random services to “guess” a master row for **intake**.

**ALLOWED (and required today):**

- **Search/list UIs** (e.g. degree combobox, occupation typeahead) may use
  `EducationService::searchDegrees*`, `OccupationService::search`, etc. Those
  are **not** the same as intake canonicalization; they must not become the
  only path that sets profile FKs from OCR text without going through intake
  normalization.

This distinction MUST stay in the doc to avoid false “violations” when reading
the codebase.

---

## Alias system

Alias tables **in this repo** (domain tables; avoid duplicate parallel systems):

- `religion_aliases`, `caste_aliases`, `sub_caste_aliases` — wired into
  `ControlledOptionNormalizer::resolveControlledCoreValue` via
  `ControlledMasterDbAliasResolver` (after engine match, before static PHP maps).
  Caste/sub-caste alias queries use `religion_id` / `caste_id` from core when
  present to reduce ambiguity.
- `city_aliases` — consumed for **birth** / **native** place text via
  `App\Services\Location\LocationNormalizationService` (called from
  `IntakeControlledFieldNormalizer`). **Current address** / `addresses[]` /
  **work location** FK wiring is still separate (next steps below).
- `education_degree_aliases` — read in `EducationService::findDegreeMatch`
  (intake `education_history` → `degree_id`).
- `occupation_master_aliases` — read in `OccupationService::findOccupationMasterForIntake`
  (intake `career_history` → `occupation_master_id`).

**Normalization:** `App\Support\MasterData\MasterDataAliasNormalizer` aligns
stored `normalized_alias` with import/sync and provides lookup candidates for
intake (including a punctuation-tolerant variant for OCR-heavy strings).

Generic polymorphic `aliases(field, canonical_id, …)` remains **optional**;
prefer domain tables + FKs.

---

## Alias management

Aliases MUST be addable **without** redeploying PHP for routine OCR variants:

1. **Artisan command** (phase 1)
2. **Admin UI** (later)

---

## Unmatched input logging

Minimum today: **structured application log** (field key, raw fragment, stage).

Optional later: dedicated `unknown_intake_*` table for analytics — additive
migration only.

---

## Field scope

**In scope (controlled / master):** education degree, occupation (engine),
religion, caste, subcaste, location resolution where FKs exist, mother tongue,
diet, lifestyle flags, horoscope masters, etc.

**Out of scope:** full name, bio, full address narrative, raw relative notes,
numeric height/income amounts — extraction/validation, not alias tables.

---

## Location

Birth / native place: **`LocationNormalizationService`** (alias match +
hierarchy + confidence gate) from **`IntakeControlledFieldNormalizer`**.
**Current residence** (`addresses[]`, core `city_id` / hierarchy) and **work
location** (`work_location_text` → `work_city_id` / related) — still to wire
through the same service + orchestrator (see roadmap steps below).

---

## Completion status (honest)

The **intake normalization engine is not “finished”** as a closed product: core
horoscope/lifestyle masters still rely on engine synonyms + exact match; city /
address FK resolution is separate; unknown-intake analytics and admin/Artisan
alias tooling are optional follow-ups.

**Guard:** `.cursor/rules/INTAKE-NORMALIZATION-ENGINE.mdc` — do not bypass the
orchestrator + approved services for new controlled intake resolution.

---

## Freeze

- Respect PHASE-5: **no** alternate mutation paths; **no** JSON blob profile
  storage for these entities.
- Any new controlled resolution MUST extend the **documented** pipeline above,
  not duplicate it.

---

## Final principle

**User input may vary. Canonical IDs must come from one governed path.**

---

## Implementation roadmap (step-by-step — या फाइलनुसार पुढे करायचे)

खालील क्रम **PHASE-5** (additive only, `MutationService` / wizard flow bypass
नको) आणि वरील pipeline नियमांनुसार ठेवा. एक पाऊल पूर्ण झाल्यावर ही सूची
अपडेट करा.

### Step 1 — SSOT व doc sync (लहान, पण आवश्यक)

- [ ] ह्या फाइलमधील “Completion status” आणि Location/alias bullets सध्याच्या
      code शी पुन्हा एकदा जुळवा (stale टिपा काढा).
- [ ] नवीन domain add झाल्यावर “Alias system” व “Field scope” अपडेट करा.

### Step 2 — Current address / `addresses[]` (intake)

- [ ] `IntakeControlledFieldNormalizer` मध्ये (किंवा एकच helper) `addresses`
      rows साठी text → canonical IDs: **`LocationNormalizationService`**
      वापर (city / hierarchy जिथे FKs आहेत).
- [ ] IDs confidence खाली असतील तर raw `address_line` / narrative **preserve**
      (pipeline step 5).
- [ ] `MutationService` / approve path सोबत snapshot shape तपासा (आज birth/native
      सारखे gate असतील तर ते extend).

### Step 3 — Work location (`work_location_text`, career row location)

- [ ] Core / `career_history` मधील work location string → `work_city_id` /
      `work_state_id` (जे columns snapshot मध्ये वापरले जातात ते) —
      **`LocationNormalizationService`** + orchestrator.
- [ ] Confidence खाली → फक्त text; IDs set करू नका.

### Step 4 — Unknown intake (logging → optional DB)

- [ ] आज: `Log::debug` (birth/native). पुढे: **structured log channel** (field,
      raw, intake/profile id, stage) — DB नको.
- [ ] नंतर (optional): additive **`unknown_intake_*`** table + idempotent insert
      — analytics साठी; migration फक्त additive.

### Step 5 — Horoscope controlled masters (alias strategy)

- [ ] OCR variants जास्त असतील तर: **DB alias tables** per master (किंवा एक
      narrow `horoscope_master_aliases`) — engine synonyms शी duplicate logic
      टाळा.
- [ ] `ControlledOptionNormalizer` / engine मधूनच lookup; controller मध्ये
      नवीन `LIKE` नको.

### Step 6 — `resolveControlledIntake` facade (optional)

- [ ] Doc मधील roadmap: thin `resolveControlledIntake($logicalField, $raw)` जो
      **same** section methods ला delegate करेल — duplicate resolver नको.
- [ ] अस्तित्वात आल्यावर ही फाइल + `.cursor/rules` अपडेट करा.

### Step 7 — Alias operations (ops)

- [ ] **Artisan:** `alias:add` (किंवा domain-specific commands) — deploy शिवाय
      alias add.
- [ ] **Admin UI** — नंतर; DB constraints (unique `normalized_alias` इ.) पाळा.

### Step 8 — Tests आणि regression

- [ ] प्रत्येक नवीन step साठी **Feature tests** (`tests/Feature/Intake/` किंवा
      domain folder) — unmatched, confidence edge, Marathi/Unicode.
- [ ] Full intake apply एकदा smoke (approve → profile columns).

### Step 9 — Location-specific SSOT (optional split)

- [ ] फक्त location साठी लहान `docs/LOCATION-INTAKE-SSOT.md` — ह्या फाइलमधून
      link करा; मुख्य नियम इथेच राहतील.

---

**सध्याची स्थिती (लहान सारांश):** birth/native + `LocationNormalizationService`
+ confidence + unknown log — **पूर्ण**; वरील Step 2–3 पुढचे मोठे काम.

END OF DOCUMENT
