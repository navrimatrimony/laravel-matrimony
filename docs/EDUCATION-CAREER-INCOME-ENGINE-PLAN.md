# Education–Career–Income Engine: Full Production Plan

Step-by-step plan for expanding the current **compact** engine into a **compact + full mode** engine with master dropdowns, dependent fields, and history repeaters — without breaking MutationService or SSOT.

---

## 1. Current State vs Target

### 1.1 Already in place (do not break)

| Layer | Keys / structure |
|-------|-------------------|
| **Core snapshot (matrimony_profiles)** | `highest_education`, `specialization`, `occupation_title`, `company_name`, `annual_income`, `family_income`, `income_currency_id` |
| **Snapshot top-level** | `core`, `education_history`, `career_history` |
| **MutationService** | `education_history` → `profile_education`, `career_history` → `profile_career` |
| **profile_education** | `degree`, `specialization`, `university`, `year_completed` |
| **profile_career** | `designation`, `company`, `location`, `start_year`, `end_year`, `is_current` |
| **Request → snapshot** | `buildPersonalFamilySnapshot()` + `ManualSnapshotBuilderService` use same row shapes; MutationService maps `institution` → `university`, `year` → `year_completed` |

### 1.2 Target (spec)

- **Core snapshot block:** Master-driven education + occupation + income (with optional new columns).
- **Optional history block:** `education_history[]`, `career_history[]` (existing repeater contract).
- **Modes:** `compact` (core only) vs `full` (core + history).
- **UI:** 5 visual cards; dependent visibility/labels; single `is_current` per profile for career.

---

## 2. Phase 1 — Migrations & master data

### 2.1 New master tables (if not present)

- **master_education**  
  - Columns: `id`, `name`, `code` (optional), `group` (e.g. `school`, `bachelor`, `master`, `other`), `sort_order`, `is_active`, `timestamps`.  
  - Seed: 10th/SSC, 12th/HSC, ITI, Diploma, Bachelor–* (Arts, Commerce, Science, Engg, BCA, BBA, Pharmacy, Law, Medical, Dental, Agriculture), Master–*, MBA/PGDM, MCA, M.Pharm, M.Ed, LLM, MD/MS, MDS, PhD, CA/CS/CMA, Other.

- **master_occupation_types**  
  - Columns: `id`, `name`, `code` (optional), `sort_order`, `is_active`, `timestamps`.  
  - Seed: Private Job, Government Job, Semi-Government, Business, Self Employed, Professional Practice, Agriculture, Freelancer, Student, Not Working, Other.

- **master_employment_statuses**  
  - Columns: `id`, `name`, `code` (optional), `sort_order`, `is_active`, `timestamps`.  
  - Seed: Full Time, Part Time, Contract, Own Business, Practice, Seasonal, Not Working, Studying.

### 2.2 New columns on `matrimony_profiles` (optional, for full spec)

Only add if you want **core snapshot** to store all of the following (otherwise keep current 7 fields and add only master FKs where needed):

- Education snapshot: `highest_education_id` (FK to `master_education`), `highest_education_other` (text), `education_institution_name`, `education_year_completed`.
- Occupation snapshot: `occupation_type_id` (FK to `master_occupation_types`), `work_city_id`, `work_state_id`, `employment_status_id` (FK to `master_employment_statuses`), `business_nature`, `practice_type`, `agriculture_land_acres`, `current_course`, `current_institute`.

**Recommendation:** Start with **no new profile columns**. Keep existing 7 core fields; add only the three master tables and use them in the UI (e.g. store `highest_education` as text from dropdown label until a later phase adds `highest_education_id`). That keeps MutationService and snapshot core keys unchanged.

---

## 3. Phase 2 — Engine API (props / contract)

### 3.1 Component props

| Prop | Type | Default | Purpose |
|------|------|---------|---------|
| `profile` | `MatrimonyProfile\|null` | `null` | For value source when no prefix. |
| `values` | `array` | `[]` | For value source when `namePrefix` is set (e.g. intake `snapshot[core]`). |
| `currencies` | `Collection\|array` | `[]` | Income currency options; engine can fallback to `MasterIncomeCurrency::where('is_active', true)->get()`. |
| `namePrefix` | `string\|null` | `null` | e.g. `snapshot[core]` for intake; empty for wizard. |
| `mode` | `'compact'\|'full'` | `'compact'` | Compact = core snapshot only; full = core + education_history + career_history. |
| `showHistory` | `bool` | from `mode === 'full'` | When true, render Card 4 (education repeater) and Card 5 (career repeater). |
| `readOnly` | `bool` | `false` | If true, render as labels/read-only (e.g. profile view). |
| `errors` | `array` | `[]` | Field key => message for validation display. |

History arrays use their **own** request keys; when `namePrefix` is set, history still uses top-level keys (e.g. `education_history`, `career_history`) unless the caller passes something like `historyNamePrefix` — so intake can send `snapshot[education_history]`, `snapshot[career_history]` and backend must expect that. Document clearly: wizard = no prefix, intake = `snapshot[core]` for core and `snapshot[education_history]` / `snapshot[career_history]` for history.

### 3.2 Naming helpers (existing + optional)

- `$n($base)` → `namePrefix ? namePrefix.'['.$base.']' : $base` (core fields).
- For history: either same prefix pattern for nested arrays, or separate `historyPrefix` so that `education_history[0][degree]` vs `snapshot[education_history][0][degree]` is explicit.

---

## 4. Phase 3 — UI layout (5 cards)

### 4.1 Card 1 — Highest Education Snapshot

- Highest Education (dropdown from **master_education**; or text input until master exists).
- Specialization (text; visibility per Dependency 2/3).
- Institute / University Name (optional; add to core when column exists).
- Year Completed (optional; add to core when column exists).

### 4.2 Card 2 — Current Occupation Snapshot

- Occupation Type (dropdown from **master_occupation_types**).
- Occupation Title (text; conditional per Dependency 4/5/6/8/9).
- Company / Business Name (or label: “Business Name”, “Clinic / Chamber Name”; conditional; hide for Agriculture / Not Working per Dependency 7/9).
- Work City / Work State (conditional; hide for Student / Not Working).
- Employment Status (dropdown; conditional per Dependency 4).

Optional fields when columns exist: business_nature, practice_type, agriculture_land_acres, current_course, current_institute.

### 4.3 Card 3 — Income Snapshot

- Annual Income (number).
- Income Currency (select; **required if** annual_income or family_income present — Dependency 10).
- Family Income (number).
- Income visibility hint / info text (static copy).

### 4.4 Card 4 — Education History Repeater (full mode only)

- Rows: degree, specialization, university, year_completed (and optional `is_highest` UI-only; no silent overwrite of core snapshot).
- Add / Remove row; names e.g. `education_history[0][degree]`, … or `snapshot[education_history][0][degree]` for intake.

### 4.5 Card 5 — Career History Repeater (full mode only)

- Rows: designation, company, location, start_year, end_year, is_current (only one row with `is_current = true` per profile).
- Add / Remove row; names e.g. `career_history[0][designation]`, … or `snapshot[career_history][0][designation]` for intake.

---

## 5. Phase 4 — Master dropdowns

- **highest_education:** Options from `master_education` (or static list until table exists). Group by `group` if desired (SSC/HSC, Bachelor, Master, Other).
- **occupation_type:** Options from `master_occupation_types`.
- **employment_status:** Options from `master_employment_statuses`.
- **income_currency:** Existing `MasterIncomeCurrency` (reuse; no change).

Store in snapshot/core exactly the keys the backend already expects: e.g. `highest_education` (text or id depending on schema), `income_currency_id`, and when added `occupation_type_id`, `employment_status_id`, etc. No new request key that MutationService doesn’t know.

---

## 6. Phase 5 — Dependent fields (10 rules)

Implement in Blade + minimal JS (or Alpine if project standard):

1. **highest_education = Other** → show `highest_education_other` text input.
2. **highest_education** in bachelor/master/doctorate group → show **Specialization**.
3. **highest_education** in SSC/HSC/ITI → Specialization optional or hidden.
4. **occupation_type** = Private Job / Government / Semi-Government → show occupation_title, company_name, work_city_id, work_state_id, employment_status.
5. **occupation_type** = Business / Self Employed → label “Business Name / Firm Name”; optional business_nature.
6. **occupation_type** = Professional Practice → label “Clinic / Chamber / Office Name”; optional practice_type.
7. **occupation_type** = Agriculture → hide company; show agriculture_land_acres; income fields as now.
8. **occupation_type** = Student → occupation_title optional; hide company; show current_course, current_institute.
9. **occupation_type** = Not Working → hide company, work_city/state; annual_income optional or auto-null.
10. **annual_income** or **family_income** filled → **income_currency** required (server-side validation + client hint).

No JS-only architecture: form still submits full payload; visibility only affects what user sees and what’s sent.

---

## 7. Phase 6 — Repeater structure (SSOT-aligned)

### 7.1 education_history (profile_education)

- Request keys per row: `degree`, `specialization`, `university`, `year_completed` (and `id` for updates). Optional UI-only: `is_highest` (do not overwrite core snapshot silently).
- MutationService already maps `institution` → `university`, `year` → `year_completed`; engine should send `university` and `year_completed` so no mapping needed.

### 7.2 career_history (profile_career)

- Request keys per row: `designation`, `company`, `location`, `start_year`, `end_year`, `is_current` (and `id`). Enforce at most one `is_current = true` (client + server).
- Ensure `ManualSnapshotBuilderService` and `ProfileWizardController` pass `is_current` and `location` into snapshot if they don’t already; MutationService persists to `profile_career` which has these columns.

---

## 8. Phase 7 — Validation

### 8.1 Core (existing + optional new)

- `highest_education` / `highest_education_id`: nullable or required by context.
- `specialization`: conditional (when specialization shown).
- `occupation_type` / `occupation_type_id`: nullable or required by context.
- `occupation_title`, `company_name`: conditional.
- `annual_income`, `family_income`: nullable, numeric.
- `income_currency_id`: required if annual_income or family_income present (already in plan).
- `work_city_id`, `work_state_id`: conditional, exists on respective tables.

### 8.2 Repeaters

- **education_history.***: degree (optional), specialization, university, year_completed.
- **career_history.***: designation, company, location, start_year, end_year, is_current; at most one is_current = true.

---

## 9. Phase 8 — Snapshot sync rules (no silent overwrite)

- **Education:** User can edit core “Highest Education” snapshot independently. If user fills history, optional helper: suggest prefill from latest/highest row — but **no silent overwrite** of core from history.
- **Career:** Current job = row with `is_current = true`. Allowing “Set as current” can prefill core snapshot in the form, but **final apply only via MutationService** (same snapshot flow as now). No direct `profile->update()` for core fields.

---

## 10. Where to use which mode

| Place | Mode | namePrefix | Notes |
|-------|------|------------|--------|
| Wizard (personal_family) | compact | none | Same 7 core fields; no history. |
| Intake preview | full | `snapshot[core]` for core; `snapshot[education_history]` / `snapshot[career_history]` for history | Pass `values` from snapshot; currencies from controller or engine fallback. |
| Full manual edit (profile edit) | full | none | Core + education_history + career_history; same request shape as today. |

---

## 11. Implementation order (recommended)

1. **Migrations + seeds:** master_education, master_occupation_types, master_employment_statuses (no profile columns yet).
2. **Engine props:** Add `mode`, `showHistory`, `readOnly`, `errors`; keep existing `profile`, `values`, `currencies`, `namePrefix`.
3. **Cards 1–3:** Refactor current 3 blocks into 5-card layout; add dropdowns for education/occupation/employment from master (or static list); keep core field names unchanged.
4. **Dependencies:** Implement 10 dependency rules (visibility + labels) in Blade/JS.
5. **Cards 4–5:** Add education_history and career_history repeaters in engine; only render when `showHistory`/full mode; keep row keys aligned with MutationService.
6. **Validation:** Extend core and repeater rules in `buildPersonalFamilySnapshot()` and ManualSnapshotBuilderService (and intake approval) as needed.
7. **Wiring:** Wizard keeps compact; intake preview and full edit use full mode with appropriate prefixes and `values`.

---

## 12. What not to mix in this engine

Per SSOT, keep **out** of this engine: father/mother occupation, property assets detail, horoscope, legal cases, contact numbers, narrative fields. Those stay in their own sections/engines.

---

## 13. Rollback

- Engine: keep current compact engine as default; full mode is additive.
- Migrations: reversible; if new profile columns added later, make them nullable.
- No change to MutationService key names or snapshot top-level keys (`core`, `education_history`, `career_history`); only new optional keys inside `core` if/when you add new columns.

---

**Document version:** 1.0  
**Aligns with:** PHASE-5 SSOT, existing MutationService and profile_education/profile_career schema.
