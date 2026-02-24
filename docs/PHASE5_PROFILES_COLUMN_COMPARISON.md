# Phase-5: matrimony_profiles vs SSOT Expected Fields — Comparison Report

## Source of truth
- **DB columns:** From `Schema::getColumnListing('matrimony_profiles')` (live schema after all migrations).
- **Migrations:** Create table `2025_12_31_053712`, then alter via: add columns (photo, height, visibility, admin edit, demo, lifecycle, location hierarchy, women safety, serious_intent, Phase5B fields, religion/caste IDs, master FKs), drop string columns `2026_02_21_063444`.
- **Model:** `app/Models/MatrimonyProfile.php` — `$fillable`, `$casts`, mutators.

## Expected structured fields list (38)
```
full_name, gender_id, date_of_birth, height_cm, weight_kg, marital_status_id,
religion_id, caste_id, sub_caste_id, complexion_id, physical_build_id, blood_group_id,
highest_education, specialization, occupation_title, company_name, annual_income,
income_currency_id, family_income, country_id, state_id, district_id, taluka_id, city_id,
work_city_id, work_state_id, father_name, father_occupation, mother_name, mother_occupation,
brothers_count, sisters_count, family_type_id, lifecycle_state, serious_intent_id,
edited_by, edited_at, edited_source, edit_reason
```

---

## 1) Missing fields (expected but not in DB)
**None.** All 38 expected fields exist on `matrimony_profiles`.

---

## 2) Extra / legacy fields (in DB but not in expected list)
Columns present in the table that are not in the SSOT “structured fields” list:

| Column | Note |
|--------|------|
| `id` | PK — system |
| `user_id` | FK — system |
| `created_at`, `updated_at`, `deleted_at` | Timestamps — system |
| `education` | Legacy string; normalized path uses `highest_education` (Phase5B). Still in use in some flows. |
| `profile_photo` | Phase-5 but not in “structured data” list. |
| `is_suspended` | Moderation. |
| `photo_approved`, `photo_rejected_at`, `photo_rejection_reason` | Photo moderation. |
| `is_demo` | Demo flag. |
| `visibility_override`, `visibility_override_reason` | Search visibility override. |
| `profile_visibility_mode` | Visibility mode. |
| `contact_number` | Denormalized; normalized data in `profile_contacts`. |
| `contact_unlock_mode` | Contact unlock. |
| `safety_defaults_applied` | Women-safety. |
| `admin_edited_fields` | JSON — admin edit tracking. |

---

## 3) Half-implemented (exist in DB + expected, but not in `$fillable` / not cast)
These are in the expected list and in the DB, but **not** in `MatrimonyProfile::$fillable`:

| Column | In fillable? | In $casts? | Note |
|--------|--------------|------------|------|
| `weight_kg` | No | No | Set via MutationService / snapshot; not mass-assignable. |
| `highest_education` | No | No | Same. |
| `specialization` | No | No | Same. |
| `occupation_title` | No | No | Same. |
| `company_name` | No | No | Same. |
| `annual_income` | No | No | Same. |
| `family_income` | No | No | Same. |
| `father_name` | No | No | Same. |
| `father_occupation` | No | No | Same. |
| `mother_name` | No | No | Same. |
| `mother_occupation` | No | No | Same. |
| `brothers_count` | No | No | Same. |
| `sisters_count` | No | No | Same. |
| `work_city_id` | No | No | Same. |
| `work_state_id` | No | No | Same. |
| `lifecycle_state` | No | No | Set via mutator / ProfileLifecycleService; intentionally not fillable. |

So **16** expected fields are in DB but not in `$fillable` (15 data fields + `lifecycle_state`). None of these have custom `$casts`; scalar types are fine without casts.

---

## 4) Columns that exist in DB but not used in model
- **In DB, in expected, not in fillable:** The 16 above. They **are** used elsewhere: MutationService (core apply / snapshot), ProfileCompletenessService, DuplicateDetectionService, ConflictDetectionService, ManualSnapshotBuilderService, ProfileWizardController, etc. So they are “used in app” but not mass-assignable on the model.
- **In DB, not in expected, not in fillable:** None that are clearly “never used”; e.g. `education` is still read/synced in places.
- **Conclusion:** No column is “in DB but completely unused”. The gap is “expected field in DB but not in model `$fillable`”.

---

## 5) Risky legacy columns
| Column | Risk |
|--------|------|
| `education` | Overlaps with `highest_education`; some code still syncs/writes it. Risk of dual source of truth. |
| `contact_number` | Denormalized; real source is `profile_contacts`. Can drift from contacts. |
| `profile_visibility_mode` | Policy/visibility; ensure single source for “who can see” rules. |
| `contact_unlock_mode` | Same; ensure alignment with contact visibility / unlock logic. |

No other columns flagged as high-risk for Phase-5 SSOT.

---

## 6) Final structural completeness percentage
- **Expected fields (38):** All present in DB → **100%** for “expected columns exist”.
- **Model alignment:** 22 of 38 expected fields are in `$fillable` (or handled by mutator, e.g. `lifecycle_state`). The other 15 data fields are set only via `setAttribute` / MutationService / snapshot, not via mass assignment.
  - If “complete” = all expected fields either fillable or explicitly handled (mutator/service): **100%** (all are written somewhere).
  - If “complete” = all expected fields in `$fillable`: **22/38 ≈ 57.9%** (or 23/38 ≈ 60.5% counting `lifecycle_state` as intentionally not fillable).

**Summary metric used here:**  
- **Structural completeness (DB): 100%** — all 38 expected columns exist.  
- **Model fillable completeness: ~58%** — 15 expected data columns are not in `$fillable` (by design for mutation path).

---

*Generated from migration chain and `app/Models/MatrimonyProfile.php`.*
