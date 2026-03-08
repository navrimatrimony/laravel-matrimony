# Education / Occupation / Income Engine — Exact Implementation Surface (Repository Facts Only)

**No files were modified. No code generated. Facts only.**

---

## 1. RELEVANT FILES

### A) Current UI / forms that render or process these fields

- **path:** `resources/views/matrimony/profile/wizard/sections/personal_family.blade.php`  
  **purpose:** Wizard section "Personal and family" — education, occupation, income (7 fields), parent-engine, family-overview.  
  **exact lines:** 5–36 (Highest Education, Specialization, Occupation Title, Company Name, Annual Income, Family Income, Income Currency select). 40: `<x-parent-engine :profile="$profile" />`. 44: `<x-family-overview :profile="$profile" />`.  
  **notes:** No namePrefix; raw names `highest_education`, `specialization`, `occupation_title`, `company_name`, `annual_income`, `family_income`, `income_currency_id`. Uses `$currencies` from controller. Good candidate for engine extraction (replace lines 5–36 with one partial).

- **path:** `resources/views/matrimony/profile/wizard/sections/full.blade.php`  
  **purpose:** Full edit — includes personal_family at line 5.  
  **exact lines:** 5: `@include('matrimony.profile.wizard.sections.personal_family')`.  
  **notes:** Parent that would include new engine if personal_family is refactored to use it.

- **path:** `resources/views/intake/preview.blade.php`  
  **purpose:** Intake preview form; education/occupation/income appear in "Other core details" as generic text inputs.  
  **exact lines:** 41–43: `$otherCoreKeys` includes `'annual_income','family_income','highest_education','specialization','occupation_title','company_name','income_currency_id'`. 44–122: `@foreach($otherCoreKeys as $coreKey)` renders `<input type="text" name="snapshot[core][{{ $coreKey }}]" ...>`.  
  **notes:** Intake uses prefixed names `snapshot[core][...]`; no dedicated education/occupation/income UI — good candidate to replace with same engine + namePrefix.

- **path:** `resources/views/matrimony/profile/show.blade.php`  
  **purpose:** Profile show (read + inline edit for some fields).  
  **exact lines:** 233–236: inline edit input for `highest_education`. 445, 451–454, 463–466, 501: display of education/occupation/income and family. 541–542: work_city_id/work_state_id display. 610–622: educationHistory (profile_education), career (profile_career).  
  **notes:** Show page; not primary form. Engine could be reused for an edit block if needed.

- **path:** `resources/views/admin/profiles/show.blade.php`  
  **purpose:** Admin profile show/edit.  
  **exact lines:** 278: `name="highest_education"` input. 507–508: display + admin_edited_fields check.  
  **notes:** Admin context; not wizard/intake.

- **path:** `resources/views/admin/ocr-simulation/index.blade.php`  
  **purpose:** OCR simulation proposed_core form.  
  **exact lines:** 89–90: `proposed_core[highest_education]` input.  
  **notes:** Test/simulation only.

- **path:** `resources/views/components/repeaters/relation-details.blade.php`  
  **purpose:** Relation/spouse rows.  
  **exact lines:** 46, 191: spouse `occupation_title` (relation context, not profile core).  
  **notes:** Not profile core education/occupation/income.

- **path:** `resources/views/dashboard.blade.php`  
  **purpose:** Dashboard profile card.  
  **exact lines:** 110–111: display `$profile->highest_education`.  
  **notes:** Display only.

- **path:** `resources/views/matrimony/profile/index.blade.php`  
  **purpose:** Profile listing search.  
  **exact lines:** 57: filter input `name="education"` (search, not profile field).  
  **notes:** Search filter; key is `education`, not `highest_education`.

**Files NOT FOUND for:** dedicated education/occupation/income form request class; `employment_status`, `current_course`, `current_institute`, `occupation_type`, `highest_education_id` (no matches in repo).

---

## 2. EXISTING REUSABLE PATTERN TO COPY

### basic_info engine
- **files:** `resources/views/matrimony/profile/wizard/sections/basic_info.blade.php`
- **props/variables:** `$namePrefix` (optional), `$profile`, `$genders`, `$birthPlaceDisplay`, `$maritalStatuses`, `$profileMarriages`, `$profileChildren`, `$childLivingWithOptions`. Uses `$corePrefix = $namePrefix ?? ''`; `$oldPrefix` for old() dot notation when prefix set.
- **namePrefix:** Yes. When `$namePrefix === 'snapshot[core]'`, all core field names prefixed (e.g. `snapshot[core][full_name]`); marital_engine gets `namePrefix` `'snapshot'`.
- **values/errors:** Values from `$profile` or `old($oldPrefix.'field', $profile->field)`. Errors `@error($oldPrefix.'field')`.
- **include pattern:** `@include('matrimony.profile.wizard.sections.basic_info', [ 'namePrefix' => 'snapshot[core]', 'profile' => ..., ... ])` (intake); wizard uses same file with no namePrefix (section loaded by name in section.blade.php).
- **line refs:** basic_info.blade.php 6–15 (prefix/name vars), 21–22 (full_name), 134–141 (marital_engine include with namePrefix).

### marital_engine
- **files:** `resources/views/matrimony/profile/wizard/sections/marital_engine.blade.php`
- **props:** `$namePrefix`, `$profile`, `$maritalStatuses`, `$profileMarriages`, `$profileChildren`, `$childLivingWithOptions`. `$namePrefix === 'snapshot'` → names like `snapshot[core][marital_status_id]`, `snapshot[marriages][0][...]`, `snapshot[children][...]`.
- **namePrefix:** Yes (see basic_info).
- **include pattern:** `@include('matrimony.profile.wizard.sections.marital_engine', [ 'namePrefix' => $maritalNamePrefix, 'profile' => $profile, ... ])`.
- **line refs:** marital_engine.blade.php 2–18 (prefix, isSnapshot, coreName, marriagesPrefix), 52 (x-show for status-dependent block).

### physical-engine (component)
- **files:** `resources/views/components/physical-engine.blade.php`
- **props:** `profile`, `values`, `namePrefix` (default `''`). When `namePrefix` set, uses `$n = fn ($base) => $namePrefix . '[' . $base . ']'` for input names; values from `$values` array when prefix, else `old()`/`$profile`.
- **namePrefix:** Yes.
- **include pattern:** `<x-physical-engine :profile="$profile" />` (wizard), `<x-physical-engine namePrefix="snapshot[core]" :values="$coreData" />` (intake).
- **line refs:** physical-engine.blade.php 1–41 (props, $n, value resolution), 44 (wrapper).

### family-overview (component)
- **files:** `resources/views/components/family-overview.blade.php`
- **props:** `profile`, `values`, `namePrefix`. When prefix: names like `namePrefix . '[family_type_id]'`; values from `$values` when prefix else old()/profile.
- **namePrefix:** Yes.
- **line refs:** family-overview.blade.php 1–39 (props, option arrays, name vars, value resolution).

### parent-engine (component)
- **files:** `resources/views/components/parent-engine.blade.php`
- **props:** `profile` only (no namePrefix in current repo).
- **namePrefix:** No.
- **line refs:** parent-engine.blade.php 1–3 (props), 19–28 (father name/occupation inputs with raw names).

### religion-caste-selector (component)
- **files:** `resources/views/components/profile/religion-caste-selector.blade.php`
- **props:** `profile`, `namePrefix` (default `''`). Names: `$nameRel = $namePrefix !== '' ? $namePrefix . '[religion_id]' : 'religion_id'` (and caste, sub_caste_id).
- **namePrefix:** Yes.
- **line refs:** religion-caste-selector.blade.php 1–8, 14–24 (hidden + visible inputs).

### contact-field (component)
- **files:** `resources/views/components/profile/contact-field.blade.php`
- **purpose:** Single contact number + optional WhatsApp; used in contacts, parent-engine.
- **line refs:** Not duplicated here; used by parent-engine and contacts.

### location-typeahead (component)
- **files:** `resources/views/components/profile/location-typeahead.blade.php`
- **purpose:** Residence/work/native/birth/alliance; supports namePrefix for birth/alliance.
- **line refs:** Not duplicated here; used in location, basic_info (birth).

---

## 3. SAVE / VALIDATION FLOW

- **path:** `app/Http/Controllers/ProfileWizardController.php`  
  **method:** `store(Request $request, string $section)` (line 142).  
  **exact lines:** 164: `$snapshot = $this->buildSectionSnapshot($section, $request, $profile);` 175: `$result = app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');`  
  **role in save flow:** Entry for wizard save; no direct profile update for personal-family — snapshot only, then MutationService.  
  **ssot notes:** Compliant; no `profile->update()` for education/occupation/income.

- **path:** `app/Http/Controllers/ProfileWizardController.php`  
  **method:** `buildSectionSnapshot(string $section, Request $request, MatrimonyProfile $profile): ?array` (line 443).  
  **exact lines:** 454: `case 'personal-family': return $this->buildPersonalFamilySnapshot($request, $profile);`  
  **role in save flow:** Dispatches to buildPersonalFamilySnapshot for section `personal-family`.  
  **ssot notes:** Returns snapshot only.

- **path:** `app/Http/Controllers/ProfileWizardController.php`  
  **method:** `buildPersonalFamilySnapshot(Request $request, MatrimonyProfile $profile): array` (line 579).  
  **exact lines:** 581–593: `resolveMasterLookupIds` for `income_currency` → `income_currency_id`, etc. 586–610: `$core` array with `highest_education`, `specialization`, `occupation_title`, `company_name`, `annual_income`, `family_income`, `income_currency_id`, father/mother, family_type_id, family_status, family_values, family_annual_income, weight_kg, physical_build_id. 627–668: education_history, career_history arrays; snapshot structure `['core' => $core, 'children' => ..., 'education_history' => ..., 'career_history' => ...]`.  
  **role in save flow:** Builds the snapshot passed to MutationService. Education/occupation/income are in `core`; education_history and career_history are separate snapshot keys (relational).  
  **ssot notes:** No validation call in this method (unlike buildBasicInfoSnapshot/buildPhysicalSnapshot). No direct update.

- **path:** `app/Services/MutationService.php`  
  **method:** `applyManualSnapshot(MatrimonyProfile $profile, array $snapshot, int $changedByUserId, string $source)` (and internal CORE apply).  
  **exact lines:** 228: `$coreFieldKeys = $this->getCoreFieldKeysFromRegistry();` 231–256: foreach core key, if present in `$proposedCore`, set profile attribute and `writeProfileChangeHistory`. 258–306: fallback for core keys that are profile columns but not in registry. 305: `$profile->save();`.  
  **role in save flow:** Single write path for manual wizard/full; CORE keys (including highest_education, specialization, occupation_title, company_name, annual_income, family_income, income_currency_id) applied from snapshot['core']; history written.  
  **ssot notes:** No direct `update()` on profile for these fields; uses setAttribute + save. Writes to profile_change_history only (line 19 comment).

- **path:** `app/Services/MutationService.php`  
  **method:** `getCoreFieldKeysFromRegistry(): array` (line 1524).  
  **exact lines:** 1526–1536: FieldRegistry CORE, not archived, not replaced; returns field_key list; fallback FALLBACK_CORE_KEYS if empty.  
  **role in save flow:** Determines which keys from snapshot['core'] are applied.  
  **ssot notes:** Registry-driven; education/occupation/income keys present in FieldRegistryCoreSeeder (see Data Structure).

- **path:** `app/Services/ManualSnapshotBuilderService.php`  
  **method:** `buildFullManualSnapshot(Request $request, MatrimonyProfile $profile): array` (line 18).  
  **exact lines:** 39–45: core includes highest_education, specialization, occupation_title, company_name, annual_income, income_currency_id, family_income. 63–64: work_city_id, work_state_id.  
  **role in save flow:** Full edit (updateFull) builds full snapshot then applyManualSnapshot; education/occupation/income are core snapshot.  
  **ssot notes:** No direct update; snapshot only.

- **path:** `app/Http/Controllers/MatrimonyProfileController.php`  
  **method:** `updateFull(Request $request)` (line 223).  
  **exact lines:** 235: `$snapshot = app(\App\Services\ManualSnapshotBuilderService::class)->buildFullManualSnapshot($request, $profile);` then applyManualSnapshot.  
  **role in save flow:** Full form save; same MutationService path.  
  **ssot notes:** Compliant.

- **path:** `app/Http/Controllers/ProfileWizardController.php`  
  **exact lines:** 182–185: `if ($section === 'alliance' && ...) { \DB::table('matrimony_profiles')->where('id', $profile->id)->update(['other_relatives_text' => ...]); }`  
  **role in save flow:** Direct update only for `other_relatives_text` (alliance section), not education/occupation/income.  
  **ssot notes:** Bypass for one field only; not in education/occupation/income surface.

**Form request classes:** NOT FOUND for personal-family or education/occupation/income. Validation for basic-info is inside buildBasicInfoSnapshot (request->validate). buildPersonalFamilySnapshot does not call validate.

**Education/occupation/income fields:** Treated as **core snapshot** (snapshot['core']). education_history and career_history are **relational entities** (separate snapshot keys, sync to profile_education / profile_career). No duplicate JSON blobs for these core fields.

---

## 4. DATA STRUCTURE

### matrimony_profiles (model: MatrimonyProfile)
- **path:** `app/Models/MatrimonyProfile.php`  
  **columns (fillable, lines 102–174):** highest_education (112), specialization (156), occupation_title (157), company_name (158), annual_income (159), family_income (160), income_currency_id (137), work_city_id (172), work_state_id (173). father_name, father_occupation, mother_name, mother_occupation, family_type_id (136).  
  **notes:** family_status, family_values, family_annual_income NOT in fillable; they exist in DB (migration below). MutationService sets attributes directly (setAttribute), so they can be persisted without being in fillable.

- **path:** `database/migrations/2026_03_06_120000_add_family_overview_to_matrimony_profiles.php`  
  **exact lines:** 12–20: add columns family_status, family_values, family_annual_income to matrimony_profiles (string nullable).  
  **notes:** Columns exist in DB.

### profile_education (table)
- **path:** `database/migrations/2026_02_13_000004_create_profile_education_table.php`  
  **columns:** id, profile_id (FK matrimony_profiles), degree, specialization, university, year_completed, timestamps.  
  **exact lines:** 11–23.  
  **notes:** Multi-row per profile; snapshot key `education_history`; not the single "highest_education" core field.

### profile_career (table)
- **path:** `database/migrations/2026_02_13_000005_create_profile_career_table.php`  
  **columns:** id, profile_id (FK), designation, company, location, start_year, end_year, is_current, timestamps.  
  **exact lines:** 11–26.  
  **notes:** Snapshot key `career_history`; not the single occupation_title/company_name core fields.

### master_income_currencies (lookup)
- **path:** `app/Models/MasterIncomeCurrency.php`  
  **table:** master_income_currencies. fillable: code, symbol, is_default, is_active.  
  **exact lines:** 10–21.  
  **notes:** Only lookup used by education/occupation/income UI. No MasterEducation or MasterOccupation in repo.

### FieldRegistry (CORE keys for these fields)
- **path:** `database/seeders/FieldRegistryCoreSeeder.php`  
  **exact lines:** 215: annual_income. 248–254: highest_education, specialization, occupation_title, company_name, income_currency, income_currency_id, family_income (category 'career'). 263–264: work_city_id, work_state_id.  
  **notes:** All are CORE; MutationService applies them from snapshot when present.

---

## 5. DEPENDENCY PATTERN

### Religion → Caste (caste disabled until religion selected)
- **path:** `resources/views/components/profile/religion-caste-selector.blade.php`  
  **exact lines:** 25: Caste input has `disabled` (no dynamic enable in blade). Caste options loaded by JS when religion selected (placeholder "Select religion first, then type to search").  
  **pattern used:** Hidden inputs for IDs; visible typeahead inputs; caste input disabled; JS (religion-caste-selector.js) enables/filters caste by religion_id.  
  **reuse recommendation:** For education/occupation/income there is no current dependency in repo (no "specialization depends on highest_education" or "occupation depends on education"). Only dependency is annual_income/family_income unit on income_currency_id (semantic). If future dependency (e.g. specialization options by education) is added, reuse: same pattern (one field enables/filters another via JS or Alpine) or Alpine x-show like marital_engine.

### Marital status → status details + children block
- **path:** `resources/views/matrimony/profile/wizard/sections/marital_engine.blade.php`  
  **exact lines:** 52: `x-show="statusKey === 'divorced' || statusKey === 'separated' || statusKey === 'widowed'"`. 66, 71, 76, 83: `x-show="statusKey === 'divorced'"` etc. for conditional fields.  
  **pattern used:** Alpine.js x-show driven by statusKey (derived from marital_status_id).  
  **reuse recommendation:** If engine gains conditional blocks (e.g. "employment type" → show company), use same Alpine x-show pattern.

---

## 6. RECOMMENDED IMPLEMENTATION SURFACE

- **new partial path (recommended):**  
  `resources/views/components/education-occupation-income-engine.blade.php`  
  (or `resources/views/matrimony/profile/wizard/sections/education_occupation_income_engine.blade.php` to mirror marital_engine location)

- **parent files to update:**  
  - `resources/views/matrimony/profile/wizard/sections/personal_family.blade.php` — replace lines 5–36 with include of new engine (compact mode); keep parent-engine and family-overview includes.  
  - `resources/views/matrimony/profile/wizard/sections/full.blade.php` — no change if engine is included only from personal_family.  
  - `resources/views/intake/preview.blade.php` — replace the subset of `$otherCoreKeys` that are education/occupation/income with one include of the same engine with `namePrefix="snapshot[core]"` and values from `$coreData`; remove those keys from the generic foreach or pass a filtered list so they are not rendered twice.

- **backend files to update:**  
  - `app/Http/Controllers/ProfileWizardController.php`: getSectionViewData('personal-family') already passes `currencies` (line 324); no change required for compact mode. If engine supports namePrefix, buildPersonalFamilySnapshot must read from either request keys without prefix (wizard) or with prefix (intake); currently intake sends snapshot[core][...] and normalizeApprovalSnapshot merges into snapshot — so for intake, snapshot is already built from request; no change to buildPersonalFamilySnapshot for wizard. If intake switches to engine partial, request keys remain snapshot[core][highest_education] etc.; no backend change for intake save.  
  - No new FormRequest found; optional: add validation in buildPersonalFamilySnapshot for education/occupation/income (max length, numeric for income) to match SSOT.

- **lookup/master sources to reuse:**  
  - `App\Models\MasterIncomeCurrency::where('is_active', true)->get()` — already passed as `$currencies` in getSectionViewData('personal-family'). No other master for education/occupation in repo.

- **compact/full mode feasibility:**  
  - **Compact mode:** Feasible. Current personal_family has 7 fields + currency; same layout in one partial with optional namePrefix is enough for wizard and intake.  
  - **Full mode:** Feasible only in the sense that "full" could show same 7 fields plus e.g. work_city_id/work_state_id (already in location section and in ManualSnapshotBuilder core). No separate "full" UI exists today for education/occupation/income; full edit uses same personal_family include. So "full mode" = same engine with possibly more fields (work location) if moved into this engine; currently work location is in location.blade.php.

- **namePrefix feasibility:**  
  - Yes. Pattern is established: basic_info, physical-engine, family-overview, religion-caste all support namePrefix. New engine should accept `namePrefix` (default `''`); when set, input names `namePrefix . '[highest_education]'` etc.; values from `$values` array when prefix, else `old()`/`$profile`. Intake already uses `snapshot[core][...]`; engine with `namePrefix="snapshot[core]"` would match.

- **exact reasons:**  
  - personal_family.blade.php lines 5–36 are the only wizard UI for these 7 fields; intake preview uses generic text inputs for the same keys in otherCoreKeys. One partial with namePrefix allows wizard (no prefix) and intake (prefix) to share UI and keeps mutations via existing buildPersonalFamilySnapshot / ManualSnapshotBuilder and MutationService only.

---

## 7. RISKS / BLOCKERS

- **blocker:** Direct DB::table update in wizard store (alliance section only).  
  **file:** `app/Http/Controllers/ProfileWizardController.php`  
  **exact lines:** 181–185 (`if ($section === 'alliance' ...) { \DB::table('matrimony_profiles')->where('id', $profile->id)->update(['other_relatives_text' => ...]); }`).  
  **impact:** SSOT bypass for one field (other_relatives_text); does not affect education/occupation/income. Not a blocker for the new engine.

- **blocker:** family_status, family_values, family_annual_income not in MatrimonyProfile $fillable.  
  **file:** `app/Models/MatrimonyProfile.php`  
  **exact lines:** fillable array 102–174; no family_status, family_values, family_annual_income.  
  **impact:** MutationService uses setAttribute + save(), so these columns are still written. No mass-assignment path for them; if any code ever used fill($request->only(...)) for these, they would be ignored. Low risk for current flow; optional fix: add these three to fillable for consistency.

- **risk:** buildPersonalFamilySnapshot does not validate request.  
  **file:** `app/Http/Controllers/ProfileWizardController.php`  
  **exact lines:** 579–614 (no $request->validate() call).  
  **impact:** Invalid data (e.g. non-numeric income) could reach MutationService. Optional: add validation for this section.

- **risk:** work_city_id, work_state_id are in location section UI but in core snapshot and FieldRegistry.  
  **file:** `resources/views/matrimony/profile/wizard/sections/location.blade.php` (work typeahead); ManualSnapshotBuilderService (work_city_id, work_state_id in core).  
  **impact:** If engine is defined as "education/occupation/income" only, do not move work_city_id/work_state_id into the new engine to avoid mixing location with career in one place; leave as is.

- **conflict/history bypass:** None found for education/occupation/income. MutationService applies core and writes profile_change_history. ConflictDetectionService lists annual_income, family_income, occupation_title, company_name (ConflictDetectionService.php 36–40).

---

## 8. FINAL VERDICT

- **Can we safely build this engine now?** YES.

- **If NO, what exact missing facts/files still block safe implementation?** N/A.

- **What exact file paths must be edited when implementation starts?**
  1. **Create:** `resources/views/components/education-occupation-income-engine.blade.php` (or chosen path under sections/ or components/).
  2. **Edit:** `resources/views/matrimony/profile/wizard/sections/personal_family.blade.php` — replace lines 5–36 with include/component call of the new engine (pass profile, currencies; optional namePrefix for future intake).
  3. **Edit (optional for intake):** `resources/views/intake/preview.blade.php` — replace the education/occupation/income subset of the otherCoreKeys loop with one include of the engine with namePrefix="snapshot[core]" and values from $coreData; ensure otherCoreKeys array excludes these keys so they are not rendered twice.
  4. **Edit (optional):** `app/Http/Controllers/ProfileWizardController.php` — add validation inside buildPersonalFamilySnapshot for highest_education (max:255), annual_income/family_income (numeric), etc., if desired.
  5. **Edit (optional):** `app/Models/MatrimonyProfile.php` — add family_status, family_values, family_annual_income to $fillable for consistency.

No other file paths are required for a minimal engine (compact mode, wizard + optional intake with namePrefix). Full mode (e.g. work location in same engine) would require deciding whether to move work fields from location section into this engine and updating location.blade.php and possibly ManualSnapshotBuilderService accordingly; not required for the scope above.
