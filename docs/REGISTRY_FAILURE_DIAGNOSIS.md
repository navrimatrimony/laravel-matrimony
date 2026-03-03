# Critical Registry Failure — Diagnosis (Inspection Only, No Fixes)

## 1) Where the registry is defined

- **Runtime source:** `field_registry` database table, populated by seeders.
- **Definition source:** `database/seeders/FieldRegistryCoreSeeder.php`.
  - The seeder holds a constant `CORE_FIELDS` (array of rows with `field_key`, `field_type` => `'CORE'`, etc.) and upserts them into `field_registry` by `field_key`.
- **Consumption:** `App\Services\MutationService::getCoreFieldKeysFromRegistry()` (and `ConflictDetectionService::getCoreFieldKeysFromRegistry()`) build the list of “registered” core fields by querying:
  - `FieldRegistry::where('field_type', 'CORE')`
  - `->where(function ($q) { $q->where('is_archived', false)->orWhereNull('is_archived'); })`
  - `->whereNull('replaced_by_field')`
  - If the table has an `is_enabled` column: `->where(function ($q) { $q->where('is_enabled', true)->orWhereNull('is_enabled'); })`
  - Then `->pluck('field_key')->values()->all()`.
- So the **effective registry** is whatever is currently in the `field_registry` table and passes these filters, not the seeder file alone.

---

## 2) Full list of registered core fields (from FieldRegistryCoreSeeder)

From `FieldRegistryCoreSeeder::CORE_FIELDS`, the `field_key` values defined in code are:

- full_name  
- gender  
- gender_id  
- date_of_birth  
- marital_status  
- marital_status_id  
- education  
- location  
- caste  
- height_cm  
- profile_photo  
- annual_income  
- country_id, state_id, district_id, taluka_id, city_id  
- religion  
- religion_id  
- caste_id  
- sub_caste_id  
- sub_caste  
- weight_kg  
- complexion  
- complexion_id  
- physical_build  
- physical_build_id  
- blood_group  
- blood_group_id  
- highest_education  
- specialization  
- occupation_title  
- company_name  
- income_currency  
- income_currency_id  
- family_income  
- father_name, father_occupation, mother_name, mother_occupation  
- brothers_count, sisters_count  
- family_type  
- family_type_id  
- work_city_id, work_state_id  
- serious_intent_id  

**Important:** `getCoreFieldKeysFromRegistry()` returns **only** rows where `replaced_by_field` IS NULL. In the seeder, the following have `replaced_by_field` set (so they are **excluded** from the returned list):

- gender (replaced_by_field => 'gender_id')  
- marital_status (replaced_by_field => 'marital_status_id')  
- complexion (replaced_by_field => 'complexion_id')  
- physical_build (replaced_by_field => 'physical_build_id')  
- blood_group (replaced_by_field => 'blood_group_id')  
- income_currency (replaced_by_field => 'income_currency_id')  
- family_type (replaced_by_field => 'family_type_id')  

So **if the DB matches the seeder**, the runtime registry list includes e.g. `gender_id`, `marital_status_id`, `religion_id`, `caste_id`, `complexion_id`, `physical_build_id`, `blood_group_id`, `income_currency_id`, `family_type_id`, and the other keys above that have `replaced_by_field` null.

---

## 3) Full list of DB columns (matrimony_profiles) vs registry

**Source:** `matrimony_profiles` table as implied by create migration, add-column migrations, and `MatrimonyProfile::$fillable` (and other columns like `lifecycle_state`, `deleted_at`, etc.).

**Profile table columns (representative list):**  
id, user_id, full_name, gender_id, date_of_birth, birth_time, marital_status_id, religion_id, caste_id, sub_caste_id, highest_education, country_id, state_id, district_id, taluka_id, city_id, address_line, birth_city_id, birth_taluka_id, birth_district_id, birth_state_id, native_city_id, native_taluka_id, native_district_id, native_state_id, height_cm, weight_kg, profile_photo, complexion_id, physical_build_id, blood_group_id, family_type_id, income_currency_id, work_city_id, work_state_id, serious_intent_id, is_suspended, photo_approved, photo_rejected_at, photo_rejection_reason, is_demo, visibility_override, visibility_override_reason, edited_by, edited_at, edit_reason, edited_source, admin_edited_fields, profile_visibility_mode, contact_unlock_mode, safety_defaults_applied, lifecycle_state, created_at, updated_at, deleted_at, and any other columns added in later migrations.

**“Missing in registry” (when the log appears):**  
A **core** snapshot key (e.g. `marital_status_id`, `gender_id`, `religion_id`, `caste_id`) is present in `proposedCore`, is a real column on `matrimony_profiles`, but is **not** in the array returned by `getCoreFieldKeysFromRegistry()`. So:

- **Registry has it in code (Seeder):** e.g. `marital_status_id`, `gender_id`, `religion_id`, `caste_id` are all in `FieldRegistryCoreSeeder::CORE_FIELDS` with `replaced_by_field` null (so they should be in the runtime list if the DB is correctly seeded).
- **Registry “missing” at runtime:** means the **live** `field_registry` table either:
  - does not have a row for that `field_key`, or  
  - has the row but it is filtered out (e.g. `is_archived` true, `replaced_by_field` set, or `is_enabled` false where the column exists and the query excludes it).

So the “missing” list is **dynamic**: any core column that the snapshot sends and that is not in `getCoreFieldKeysFromRegistry()`’s result will trigger the warning. Typical candidates if the DB was never fully re-seeded with the current seeder: **marital_status_id, gender_id, religion_id, caste_id, sub_caste_id, complexion_id, physical_build_id, blood_group_id, income_currency_id, family_type_id**, plus any other Phase-5B / master lookup columns that exist on the profile table but are missing or filtered out in `field_registry`.

---

## 4) Confirmation whether marital_status_id is missing (in the registry)

- **In the seeder:** `marital_status_id` is present in `CORE_FIELDS` with `replaced_by_field` => null (lines 95–109 of FieldRegistryCoreSeeder.php). So in code it is **not** missing.
- **At runtime:** The log “Core column missing in registry” for `marital_status_id` means that when `getCoreFieldKeysFromRegistry()` runs, **marital_status_id is not** in the returned array. So either:
  - there is no `field_registry` row with `field_key` = `'marital_status_id'`, or  
  - that row is excluded by `is_archived`, `replaced_by_field`, or `is_enabled`.

So: **if the log says marital_status_id is “missing in registry”, then at runtime it is missing from the effective registry list** (even though it is defined in the seeder). That is consistent with the DB state (or filters) not matching the current seeder.

---

## 5) How MutationService treats “Core column missing in registry” (Step 4)

**Location:** `app/Services/MutationService.php` (around lines 253–299).

**When it runs:**  
In the “remaining proposedCore” loop: for each key in `$proposedCore` that is **not** in `$coreFieldKeys`, **is** a column on the profile table, and is not in `$conflictFieldNames`, the code:

1. Logs:  
   `\Illuminate\Support\Facades\Log::warning('Core column missing in registry', ['field' => $fieldKey, 'profile_id' => ..., 'source' => ...])`
2. Then, if not admin and the field is locked, it logs and **skips** that key (`continue`).
3. Otherwise it **continues**: it gets old value, normalizes new value, compares, and if different it **sets the attribute** and writes profile change history. So it **does apply** the value.

So:

- **It does not abort** the mutation.
- **It does not skip** saving that core column unless the field is locked (for manual mode). It **does save** the value after the warning.
- So the warning indicates “this core column is not in the registry list but we are still persisting it.” The “registry failure” is that the registry list is incomplete; it does **not** by itself prevent those core profile columns from being saved.

---

## 6) Exact reason marriages are not persisting (and relation to registry)

- **Marriages** are stored in the **entity** table `profile_marriages`, not in `matrimony_profiles`. They are applied in the **entity sync** step (e.g. `syncEntityDiff` for snapshot key `marriages`), not in the core-field apply step.
- The **“Core column missing in registry”** warning is about **core** keys on **matrimony_profiles** (e.g. `marital_status_id`, `gender_id`, `religion_id`, `caste_id`). So:
  - The **registry issue does not directly block** saving rows into `profile_marriages`.
  - Core profile columns that trigger the warning are still written (unless locked), as explained above.

So the **exact reason marriages are not persisting** is **not** the registry. It is elsewhere in the flow, for example:

- Snapshot for the marriages section not containing a `marriages` key or containing an empty list (e.g. form not submitted or marriage form hidden when marital status is not divorced/separated/widowed), or  
- Entity sync loop never running for the `marriages` key (e.g. key not in snapshot or not in `ENTITY_SYNC_ORDER`), or  
- Table `profile_marriages` missing or schema mismatch, or  
- Another error before or during entity sync.

The registry failure explains why you see warnings for **core profile columns** (marital_status_id, gender_id, etc.); it does **not** explain why **marriages** (entity rows) are not saved. For that, the earlier marriage-specific debug (form, snapshot, entity sync loop, mapSnapshotRowToTable, direct insert) is what matters.

---

## Summary

| Item | Result |
|------|--------|
| **1) Registry defined where** | `field_registry` table; definition in `database/seeders/FieldRegistryCoreSeeder.php`; read by `MutationService::getCoreFieldKeysFromRegistry()` (and ConflictDetectionService). |
| **2) Registered core fields (from seeder)** | Full list in §2; runtime list is only those with `replaced_by_field` null (and not archived, and is_enabled not false when column exists). |
| **3) “Missing” DB columns** | Any core snapshot key that exists on `matrimony_profiles` but is not in the array returned by `getCoreFieldKeysFromRegistry()`; typically Phase-5 *_id and similar if DB not fully seeded. |
| **4) marital_status_id in registry** | Present in seeder with `replaced_by_field` null. If the log says it’s missing, at runtime it is not in the effective registry (DB or filters). |
| **5) Effect of “Core column missing in registry”** | Log warning only; then value is applied (and saved) unless the field is locked. No abort; no silent skip of the write. |
| **6) Marriages not persisting** | Not caused by the registry. Caused by the marriages **entity** path (snapshot content, entity sync, or table/schema). |

No code or schema changes were made; this is diagnosis only.
