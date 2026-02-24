# PHASE-5 SSOT v1.1 STRICT — Full Architectural Refactor Plan

## 1) TARGET FINAL DATA MODEL

### 1.1 Tables in use (preferences)

| Table | Purpose | Row cardinality |
|------|---------|------------------|
| `profile_preferred_religions` | religion_id(s) partner preference | Many per profile |
| `profile_preferred_castes` | caste_id(s) partner preference | Many per profile |
| `profile_preferred_districts` | district_id(s) partner preference | Many per profile |
| `profile_preference_criteria` | **NEW** scalar preferences | One per profile |

### 1.2 New table: profile_preference_criteria

```sql
CREATE TABLE profile_preference_criteria (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profile_id BIGINT UNSIGNED NOT NULL UNIQUE,
  preferred_age_min INT UNSIGNED NULL,
  preferred_age_max INT UNSIGNED NULL,
  preferred_income_min DECIMAL(12,2) NULL,
  preferred_income_max DECIMAL(12,2) NULL,
  preferred_education VARCHAR(255) NULL,
  preferred_city_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (profile_id) REFERENCES matrimony_profiles(id) ON DELETE CASCADE,
  FOREIGN KEY (preferred_city_id) REFERENCES cities(id) ON DELETE SET NULL
);
```

### 1.3 Table to remove from active use

- `profile_preferences` — dropped after backfill and code cutover.

### 1.4 Single primary contact (profile_contacts)

- Add unique partial index so at most one row per profile has `is_primary = 1`:
  - MySQL 8.0: unique index on `(profile_id)` where `is_primary = 1` (expression/index not supported in MySQL the same way). Alternative: **application-level enforcement** in MutationService + optional DB trigger, or use a separate `profile_primary_contact` one-row table.
- **Recommended (no trigger):** Unique index on `profile_contacts(profile_id, is_primary)` **does not** enforce “only one true” (multiple 0s allowed). So enforce in service: before writing contacts, set all existing `is_primary = 0` for that profile, then set exactly one proposed row to `is_primary = 1`.

---

## 2) REFACTORED MUTATIONSERVICE DESIGN

### 2.1 Snapshot key mapping (final)

- Remove `'preferences' => 'profile_preferences'` from `SNAPSHOT_KEY_TO_TABLE`.
- Add internal handling for snapshot key `'preferences'`: no longer maps to a single table; handled by a dedicated method that writes to pivots + `profile_preference_criteria`.

### 2.2 New constants

```php
// In MutationService
private const PREFERENCE_PIVOT_TABLES = [
    'religions' => 'profile_preferred_religions',
    'castes'    => 'profile_preferred_castes',
    'districts' => 'profile_preferred_districts',
];
private const PREFERENCE_CRITERIA_TABLE = 'profile_preference_criteria';
```

### 2.3 Remove preferences from SINGLE_ROW_SNAPSHOT_KEYS and ENTITY_SYNC_ORDER

- Remove `'preferences'` from `SINGLE_ROW_SNAPSHOT_KEYS`.
- Remove `'preferences'` from `ENTITY_SYNC_ORDER`.

### 2.4 New method: syncPreferencesFromSnapshot()

**Signature:** `private function syncPreferencesFromSnapshot(MatrimonyProfile $profile, array $proposed): void`

**Behaviour:**

1. **Scalar criteria**  
   - Resolve one row from `$proposed`: `$row = isset($proposed[0]) && is_array($proposed[0]) ? $proposed[0] : $proposed`.  
   - Build payload for `profile_preference_criteria`: `profile_id`, `preferred_age_min`, `preferred_age_max`, `preferred_income_min`, `preferred_income_max`, `preferred_education`, `preferred_city_id` (from `preferred_city_id` or resolve from preferred_city if still sent), `created_at`, `updated_at`.  
   - Upsert by `profile_id`: if row exists UPDATE, else INSERT. Write `profile_change_history` for changed fields.

2. **Pivots**  
   - From same row, read:
     - `preferred_religion_ids` => array of ids → sync to `profile_preferred_religions`
     - `preferred_caste_ids` => array of ids → sync to `profile_preferred_castes`
     - `preferred_district_ids` => array of ids → sync to `profile_preferred_districts`
   - For each pivot: load existing rows for `profile_id`; compute add/remove by id; INSERT new, DELETE removed; write `profile_change_history` for insert/delete as needed.

3. **No writes to** `profile_preferences`.

### 2.5 Call site

- In `applyManualSnapshot` and in `applyApprovedIntake` (Step 7): when `array_key_exists('preferences', $snapshot)` and `is_array($snapshot['preferences'])`, call `$this->syncPreferencesFromSnapshot($profile, $snapshot['preferences'])` instead of going through entity sync.

### 2.6 Single primary contact enforcement

**In `syncContactsFromSnapshot()` (before calling `syncEntityDiff`):**

1. Ensure at most one proposed primary: if multiple entries have `is_primary` truthy, set all but the first to `is_primary = false`.
2. **Before** `syncEntityDiff`: run `DB::table('profile_contacts')->where('profile_id', $profile->id)->update(['is_primary' => false]);` so no existing row is primary.
3. In the proposed list, set exactly one row’s `is_primary` to true (the one chosen in step 1).
4. Then call `syncEntityDiff($profile, 'profile_contacts', $proposed)`.

Result: only one row per profile can have `is_primary = 1` at any time.

### 2.7 Exact method rewrites (summary)

- **syncSingleRowSection:** keep for `property_summary` (and any other single-row section). Do **not** use for preferences.
- **syncPreferencesFromSnapshot:** new; contains criteria upsert + pivot sync; no reference to `profile_preferences`.
- **syncContactsFromSnapshot:** add steps above to enforce single primary before `syncEntityDiff`.

---

## 3) CONTROLLER CHANGES

### 3.1 MatrimonyProfileController@show

**File:** `app/Http/Controllers/MatrimonyProfileController.php`

- **Load preferences for view:**  
  - Remove: `$preferences = DB::table('profile_preferences')->where('profile_id', $profile->id)->first();`  
  - Add: load criteria + pivots (e.g. via a **PreferenceViewService** or inline):
    - `$preferenceCriteria = DB::table('profile_preference_criteria')->where('profile_id', $profile->id)->first();`
    - `$preferredReligionIds = DB::table('profile_preferred_religions')->where('profile_id', $profile->id)->pluck('religion_id')->all();`
    - `$preferredCasteIds = DB::table('profile_preferred_castes')->where('profile_id', $profile->id)->pluck('caste_id')->all();`
    - `$preferredDistrictIds = DB::table('profile_preferred_districts')->where('profile_id', $profile->id)->pluck('district_id')->all();`
  - Pass to view: `preferenceCriteria`, `preferredReligionIds`, `preferredCasteIds`, `preferredDistrictIds` (and optionally hydrated models for labels: e.g. Religion::whereIn('id', $preferredReligionIds)->get() for display).

- **Religion/caste on profile (own biodata):**  
  - Pass relations so blade can use relations: ensure `$profile->load(['religion','caste','subCaste'])` (or include in main `with()`).
  - Blade must use `$profile->religion->label ?? ''`, `$profile->caste->label ?? ''`, `$profile->subCaste->label ?? ''` (not `$profile->religion` / `$profile->caste` / `$profile->sub_caste`).

### 3.2 ProfileWizardController

**File:** `app/Http/Controllers/ProfileWizardController.php`

- **getSectionViewData for about-preferences:**  
  - Replace: `$data['preferences'] = DB::table('profile_preferences')->where('profile_id', $profile->id)->first();`  
  - With: load from `profile_preference_criteria` + three pivot tables; build a structure the wizard form expects, e.g.:
    - `$data['preferenceCriteria'] = DB::table('profile_preference_criteria')->where('profile_id', $profile->id)->first();`
    - `$data['preferred_religion_ids'] = DB::table('profile_preferred_religions')->where('profile_id', $profile->id)->pluck('religion_id')->all();`
    - `$data['preferred_caste_ids'] = ...`
    - `$data['preferred_district_ids'] = ...`
  - So the about-preferences view can pre-fill form from pivot + criteria.

- **buildAboutPreferencesSnapshot:**  
  - Change request shape to match new model:
    - From: single row with `preferred_city`, `preferred_caste`, `preferred_age_min`, etc.  
    - To: e.g. `preferred_religion_ids` (array), `preferred_caste_ids` (array), `preferred_district_ids` (array), `preferred_age_min`, `preferred_age_max`, `preferred_income_min`, `preferred_income_max`, `preferred_education`, `preferred_city_id`.  
  - Build snapshot `preferences` as one structure (array of one row or single associative array) that `syncPreferencesFromSnapshot()` expects: e.g. `['preferred_religion_ids' => [...], 'preferred_caste_ids' => [...], 'preferred_district_ids' => [...], 'preferred_age_min' => ..., ...]`.

### 3.3 ProfileCompletionService

**File:** `app/Services/ProfileCompletionService.php`

- **sectionAboutPreferencesFilled:**  
  - Remove use of `profile_preferences`.  
  - New logic: return true if any of:
    - `profile_preference_criteria` has a row for profile and (preferred_age_min IS NOT NULL OR preferred_education != '' OR preferred_city_id IS NOT NULL), or
    - any of `profile_preferred_religions` / `profile_preferred_castes` / `profile_preferred_districts` has at least one row for profile, or
    - extended narrative (profile_extended_attributes) has content (keep existing narrative check).

---

## 4) BLADE REWRITE STRATEGY

### 4.1 Profile show — own biodata (religion/caste/subcaste)

**File:** `resources/views/matrimony/profile/show.blade.php`

- Replace:
  - `{{ $profile->religion }}` → `{{ $profile->religion->label ?? '' }}`
  - `{{ $profile->caste }}` → `{{ $profile->caste->label ?? '' }}`
  - `{{ $profile->sub_caste }}` → `{{ $profile->subCaste->label ?? '' }}`
- Replace conditionals that check `($profile->religion ?? '') !== ''` with e.g. `$profile->religion_id` or `$profile->religion` presence.
- Remove any hidden/input that still uses `name="caste"` for the profile’s own caste; use religion_id/caste_id/sub_caste_id and relation labels for display only if not editing here.

### 4.2 Profile show — partner preferences block

- Remove use of `$preferences->preferred_city`, `$preferences->preferred_caste`, etc.
- Use `$preferenceCriteria` for: preferred_age_min/max, preferred_income_min/max, preferred_education, preferred_city_id (display city name via City::find or passed hydrated).
- Use `$preferredReligionIds` / `$preferredCasteIds` / `$preferredDistrictIds` with hydrated models (or pre-loaded in controller) to show “Preferred religions: X, Y”, “Preferred castes: A, B”, “Preferred districts: P, Q”.

### 4.3 Wizard about-preferences blade

**File:** `resources/views/matrimony/profile/wizard/sections/about_preferences.blade.php`

- Replace single “preferences” form with:
  - Inputs for scalar criteria: preferred_age_min, preferred_age_max, preferred_income_min, preferred_income_max, preferred_education, preferred_city_id (e.g. dropdown or typeahead).
  - Multi-select (or multi-checkbox) for religions, castes, districts using `preferred_religion_ids[]`, `preferred_caste_ids[]`, `preferred_district_ids[]`.
- Pre-fill from `preferenceCriteria` and `preferred_religion_ids`, `preferred_caste_ids`, `preferred_district_ids` passed from controller.

---

## 5) MIGRATION SEQUENCE

### Phase A — Add new structures (no removal)

1. **Migration:** Create `profile_preference_criteria` table (schema in §1.2).
2. **Code:** Implement `syncPreferencesFromSnapshot()`, preference snapshot build from wizard (new shape), and show/controller loading from criteria + pivots. Keep **reading** from `profile_preferences` in parallel where needed (e.g. completion) so old path still works.
3. **Feature flag (optional):** “Use new preference storage” so only some traffic writes to criteria + pivots and reads from them.

### Phase B — Backfill

4. **Migration or command:** Backfill from `profile_preferences` into `profile_preference_criteria` + pivots:
   - For each row in `profile_preferences`: insert or update `profile_preference_criteria` (map preferred_age_min/max, preferred_income_min/max, preferred_education; preferred_city: resolve city by name to city_id if possible and set preferred_city_id).
   - For preferred_caste / preferred_city: if you have legacy string values, resolve to caste_id / city_id where possible and insert into `profile_preferred_castes` / optionally a city-preference table (if you add one; else store only preferred_city_id in criteria).
   - preferred_religions: if profile_preferences ever had religion preference, backfill into `profile_preferred_religions`; else leave empty.
5. Verify backfill counts and spot-checks.

### Phase C — Cutover and remove legacy

6. **Code cutover:** All reads/writes use only criteria + pivots. Remove all references to `profile_preferences` in:
   - MutationService
   - MatrimonyProfileController@show
   - ProfileWizardController (getSectionViewData, buildAboutPreferencesSnapshot)
   - ProfileCompletionService
   - Any other services or blades that reference profile_preferences.
7. **Migration:** Drop table `profile_preferences`.

### Phase D — Single primary contact

8. **Code:** Enforce single primary in `syncContactsFromSnapshot()` as in §2.6.
9. **Optional DB:** Add a unique index if your DB supports partial unique (e.g. PostgreSQL partial unique on (profile_id) WHERE is_primary = true). If not, rely on application enforcement only.

---

## 6) RISK MITIGATION CHECKLIST

- [ ] Backfill script idempotent and safe to re-run (by profile_id).
- [ ] New `profile_preference_criteria` and pivots validated (foreign keys, nullable, types).
- [ ] MutationService: no code path writes to `profile_preferences` after cutover.
- [ ] Show and wizard: no blade or controller reads from `profile_preferences` after cutover.
- [ ] ProfileCompletionService uses only criteria + pivots + extended narrative for “about preferences” section.
- [ ] Single primary contact: test with 0, 1, and 2 primary contacts in snapshot; assert only one row has is_primary = 1 after sync.
- [ ] Rollback plan tested: re-add profile_preferences from backup and revert code to read/write it if rollback required.
- [ ] Eager loading: show() loads religion, caste, subCaste to avoid N+1 and so blade can use ->label safely.

---

## 7) ROLLBACK PLAN

- **Before dropping profile_preferences:** Keep a backup of the table (e.g. `profile_preferences_backup_YYYYMMDD`).
- **Code rollback:** Revert to a release that still writes and reads `profile_preferences`; redeploy.
- **Data rollback:** If needed, restore `profile_preferences` from backup and point code back to it; no need to reverse backfill into pivots for rollback (pivots can stay).

---

## 8) DB CONSTRAINT RECOMMENDATIONS

- `profile_preference_criteria.profile_id`: UNIQUE (one row per profile).
- `profile_preferred_religions` / `profile_preferred_castes` / `profile_preferred_districts`: composite UNIQUE(profile_id, religion_id) etc. to avoid duplicate pairs.
- **Single primary contact:** Application-level: in `syncContactsFromSnapshot()` set all existing to is_primary=0 then set one to 1. Optional: DB trigger that sets other rows to is_primary=0 when one is set to 1 (MySQL: one trigger per profile; possible but heavier).

---

## 9) FINAL CLEAN ER SUMMARY (PREFERENCES + CONTACT)

```
matrimony_profiles 1 ---- * profile_preferred_religions * ---- 1 religions
matrimony_profiles 1 ---- * profile_preferred_castes     * ---- 1 castes
matrimony_profiles 1 ---- * profile_preferred_districts * ---- 1 districts
matrimony_profiles 1 ---- 1 profile_preference_criteria
matrimony_profiles 1 ---- * profile_contacts  (exactly one is_primary = 1 per profile, enforced in app)
```

- **Removed:** `profile_preferences` (after cutover and drop).
- **New:** `profile_preference_criteria` (single row per profile for scalars + preferred_city_id).
- **Pivots:** profile_preferred_religions, profile_preferred_castes, profile_preferred_districts (many-to-many per profile).
- **Contacts:** profile_contacts with single-primary enforcement in MutationService.

---

## 10) EXACT FILE-LEVEL CHANGES (CHECKLIST)

| File | Change |
|------|--------|
| `app/Services/MutationService.php` | Remove preferences from SINGLE_ROW_SNAPSHOT_KEYS and ENTITY_SYNC_ORDER; remove 'preferences' => 'profile_preferences' from SNAPSHOT_KEY_TO_TABLE; add syncPreferencesFromSnapshot(); in applyManualSnapshot and applyApprovedIntake call syncPreferencesFromSnapshot when snapshot has 'preferences'; in syncContactsFromSnapshot enforce single primary before syncEntityDiff. |
| `app/Http/Controllers/MatrimonyProfileController.php` | show(): replace profile_preferences read with profile_preference_criteria + pivot reads; add with(['religion','caste','subCaste']) or load(); pass preferenceCriteria, preferredReligionIds, preferredCasteIds, preferredDistrictIds (and optionally hydrated labels). |
| `app/Http/Controllers/ProfileWizardController.php` | getSectionViewData('about-preferences'): load criteria + pivots; build preferenceCriteria, preferred_religion_ids, preferred_caste_ids, preferred_district_ids. buildAboutPreferencesSnapshot: build new snapshot shape (preferred_religion_ids, preferred_caste_ids, preferred_district_ids, criteria fields, preferred_city_id). |
| `app/Services/ProfileCompletionService.php` | sectionAboutPreferencesFilled: use profile_preference_criteria + pivot existence + extended narrative only; remove profile_preferences. |
| `resources/views/matrimony/profile/show.blade.php` | Religion/caste/subcaste: use $profile->religion->label ?? '', $profile->caste->label ?? '', $profile->subCaste->label ?? ''. Partner preferences block: use preferenceCriteria and pivot-based data; remove $preferences->preferred_city etc. |
| `resources/views/matrimony/profile/wizard/sections/about_preferences.blade.php` | Form fields for criteria (age, income, education, city) + multi-select for religion_ids, caste_ids, district_ids; names and pre-fill from controller. |
| `database/migrations/xxxx_create_profile_preference_criteria_table.php` | New migration: create profile_preference_criteria (profile_id unique, scalar columns, preferred_city_id). |
| `database/migrations/xxxx_drop_profile_preferences_table.php` | New migration (after cutover): drop profile_preferences. |

No partial implementations: all references to profile_preferences removed from active code paths before drop; single primary contact enforced in one place (syncContactsFromSnapshot).
