# MutationService Refactor Plan — PHASE-5 SSOT Compliance

**Purpose:** Structured analysis and refactor plan only. No code changes until approved.

---

## 1) Missing Duplicate Detection Stage

### SSOT Requirement (PHASE-5 SSOT Part 2, Step 5 — Safe Mutation Pipeline)

- **Step 1** must be **DUPLICATE DETECTION** before any profile creation or mutation.
- Duplicate engine must run:
  - Before profile creation
  - Before profile mutation
  - Before serious_intent linking

**Priority order (strict):**

1. `verified_otp_mobile` exact match → SAME USER (no new profile allowed)
2. `primary_contact_number` exact match → HARD DUPLICATE
3. `full_name` + `date_of_birth` + `father_name` + `district_id` + `caste` → HIGH PROBABILITY DUPLICATE
4. `serious_intent_id` match → HIGH-RISK DUPLICATE

**If duplicate detected:**

- Stop mutation immediately
- Do not create new profile
- Create conflict_record
- Set lifecycle_state = conflict_pending (on existing profile if applicable)
- No auto-merge, no auto-overwrite

### Current State

- MutationService has **no duplicate detection**.
- Flow goes: load intake → profile existence (create draft if none) → conflict detection → apply.
- A new draft profile can be created before any duplicate check; duplicate checks (e.g. same primary_contact_number) are never run.

### Refactor Plan (1)

| Item | Action |
|------|--------|
| 1.1 | Introduce a **DuplicateDetectionService** (or equivalent) that implements the four checks above against existing profiles/users. |
| 1.2 | In MutationService, run duplicate detection **first**, inside the same DB transaction, **before** profile existence check. |
| 1.3 | If duplicate detected: create conflict_record(s), set lifecycle_state = conflict_pending on the existing profile (when applicable), do **not** create a new profile, do **not** apply CORE/entity sync, finalize intake only with link to existing profile and status that reflects “duplicate detected” (per SSOT: no profile creation, mutation stopped). |
| 1.4 | Use snapshot + intake context (e.g. primary_contact_number, full_name, date_of_birth, father_name, district_id, caste, serious_intent_id) as input to duplicate engine; do not rely only on matrimony_profile_id. |
| 1.5 | Document where `verified_otp_mobile` / `father_name` live (user table, profile, or snapshot) and ensure they are available for the duplicate step. |

---

## 2) Core Field Mismatch with SSOT Core Registry

### SSOT Requirement

- **Identity-critical fields** (Critical Field Escalation Matrix):  
  `full_name`, `date_of_birth`, `gender`, `caste`, `sub_caste`, `marital_status`, `primary_contact_number`, `serious_intent_id`
- **Dynamic fields (no escalation):**  
  `annual_income`, `family_income`, `occupation_title`, `company_name`, `work_city_id`, `work_state_id`
- CORE vs ENTITY vs EXTENDED is a **contract**; storage routing must follow it.
- Project has **FieldRegistry** (field_type = CORE | EXTENDED) and **ProfileFieldConfigurationService** (enabled/mandatory/searchable); CORE list should align with a single source (registry or SSOT-derived list), not a second hardcoded list.

### Current State

- MutationService and ConflictDetectionService use a **hardcoded** `CORE_FIELD_KEYS` list:
  - `full_name`, `gender`, `date_of_birth`, `marital_status`, `education`, `location`, `caste`, `height_cm`, `profile_photo`
- **Missing from SSOT identity-critical:** `sub_caste`, `primary_contact_number`, `serious_intent_id`
- **Missing from SSOT dynamic:** `annual_income`, `family_income`, `occupation_title`, etc. (may live in profile_career / CORE per SSOT)
- **Location:** SSOT uses structured location (e.g. district_id, etc.); “location” as a single key may not match schema (country_id, state_id, district_id, taluka_id, city_id).
- No use of FieldRegistry or ProfileFieldConfigurationService for “which CORE fields exist” in mutation path.

### Refactor Plan (2)

| Item | Action |
|------|--------|
| 2.1 | Define **single source** for CORE field keys used in mutation: either (a) FieldRegistry where field_type = 'CORE' and is_enabled, or (b) a single shared constant/helper used by ConflictDetectionService, MutationService, and MatrimonyProfile governance, aligned with SSOT identity-critical + dynamic lists. |
| 2.2 | Add missing identity-critical fields to the CORE set used in mutation/conflict: `sub_caste`, `primary_contact_number` (or contact routing), `serious_intent_id`; ensure they are present in snapshot schema and profile/schema. |
| 2.3 | Map snapshot `core` keys to actual profile columns (e.g. `location` → country_id, state_id, district_id, taluka_id, city_id if that is the SSOT shape). |
| 2.4 | Align ConflictDetectionService CORE list with the same single source so conflict detection and mutation apply the same set of CORE fields. |

---

## 3) Escalation Matrix Missing (serious_intent Handling)

### SSOT Requirement

- **If serious_intent_id IS NULL:** user confirmation required; conflict record created for identity-critical changes.
- **If serious_intent_id IS NOT NULL:** admin resolution mandatory; lifecycle_state = conflict_pending; no update applied until admin decision for identity-critical fields.
- **Category-C (high-sensitivity under serious_intent):**  
  caste, sub_caste, marital_status, gender, date_of_birth → change → conflict mandatory, lifecycle_state = conflict_pending, admin resolution required.
- **Dynamic fields:** even with serious_intent active, direct update allowed with profile_change_history; no lifecycle escalation.

### Current State

- MutationService does **not** read `serious_intent_id` from the profile (or snapshot).
- No branching on “profile has serious_intent_id” vs “profile has no serious_intent_id”.
- All CORE differences are treated the same (ConflictDetectionService creates conflicts; no escalation path for “admin mandatory” vs “user confirmation”).
- No rule that says: “if profile.serious_intent_id is set and proposed change touches caste/sub_caste/marital_status/gender/date_of_birth/full_name/primary_contact_number/serious_intent_id → force conflict and lifecycle conflict_pending”.

### Refactor Plan (3)

| Item | Action |
|------|--------|
| 3.1 | Before applying CORE changes, load **current** profile.serious_intent_id (and keep it in mutation context). |
| 3.2 | Implement **escalation matrix** in mutation path: for each proposed CORE change, classify as identity-critical vs dynamic (per SSOT). |
| 3.3 | If profile has serious_intent_id and proposed change is in Category-C (caste, sub_caste, marital_status, gender, date_of_birth, full_name, primary_contact_number, serious_intent_id): always create conflict_record and do not auto-apply; set lifecycle_state = conflict_pending. |
| 3.4 | If profile has no serious_intent_id and change is identity-critical: create conflict_record and require user confirmation (flow/UI contract); do not auto-apply until resolved. |
| 3.5 | Dynamic fields (annual_income, family_income, occupation_title, etc.): allow update with history only; no lifecycle escalation even when serious_intent_id is set. |
| 3.6 | Ensure ConflictDetectionService (or a shared escalation helper) is aware of serious_intent_id so that conflict creation and “block apply” logic are consistent. |

---

## 4) Dual History System Conflict

### SSOT Requirement

- “profile_change_history entry per field” and “profile_change_history entry mandatory” for mutation.
- “Every mutation must generate history entry.”
- SSOT Part 6 (Day 1) lists “profile_change_history (unified)” as the mutation history table.

### Current State

- **FieldValueHistoryService** writes to **field_value_history** (model FieldValueHistory, table `field_value_history`).
- **MutationService** also writes to **profile_change_history** via `writeProfileChangeHistory()`.
- So for the same mutation we have:
  - FieldValueHistoryService::record(...) → field_value_history
  - writeProfileChangeHistory(...) → profile_change_history
- SSOT specifies one **unified** history for mutation; two tables with overlapping purpose creates ambiguity and risk of divergence.

### Refactor Plan (4)

| Item | Action |
|------|--------|
| 4.1 | Define **single canonical history for mutation**: per SSOT, mutation path must write to **profile_change_history** only (unified). |
| 4.2 | In MutationService, **remove** writes to field_value_history (FieldValueHistoryService::record) for the mutation path, and use **only** profile_change_history with the required columns (profile_id, entity_type, entity_id, field_name, old_value, new_value, changed_by, source, changed_at). |
| 4.3 | For lifecycle_state transition inside mutation, record the transition in profile_change_history (e.g. entity_type = 'matrimony_profile', field_name = 'lifecycle_state') instead of (or in addition to) field_value_history, so one source of truth. |
| 4.4 | Document that FieldValueHistoryService / field_value_history remains the authority for **user/admin-initiated** edits (e.g. profile edit screen, admin override); mutation-initiated changes are recorded only in profile_change_history. |
| 4.5 | If product decision is to keep both tables for audit, define a clear rule: “mutation writes only to profile_change_history; user/admin edits write to field_value_history (and optionally profile_change_history)” and document it in SSOT/code. |

---

## 5) Snapshot-to-Entity Routing Mismatch

### SSOT Requirement

- Parsed/approval snapshot structure (PHASE-5 SSOT Step 2):  
  `core`, `contacts`, `children`, `education_history`, `career_history`, `addresses`, `property_summary`, `property_assets`, `horoscope`, `legal_cases`, `preferences`, `extended_narrative`, `confidence_map`.
- Routing: **CORE** → matrimony_profiles; **CONTACT** → profile_contacts (Step 6); **ENTITY** → normalized tables (Step 7); **EXTENDED** → narrative / extended (Step 8).
- Step 7 entity list: children, education, career, addresses, property_summary, property_assets, horoscope, legal_cases, preferences.
- MutationService must read **snapshot_schema_version** and must not assume-based parse (version-driven handling).

### Current State

- MutationService reads `snapshot['core']`, `snapshot['extended']`, and for entities iterates over **table names**: `profile_contacts`, `profile_children`, `profile_education`, etc., and does `snapshot[$entityKey]` (e.g. `snapshot['profile_children']`).
- SSOT snapshot keys are **not** table names: they are `children`, `education_history`, `career_history`, `addresses`, `property_summary`, `property_assets`, `horoscope`, `legal_cases`, `preferences`; contacts is `contacts`; extended is `extended_narrative`.
- So current code would **never** find `snapshot['profile_children']` or `snapshot['profile_education']`; entity sync is effectively a no-op for real snapshot shape.
- **Contact sync** is a separate step in SSOT (step 6) with “only one primary allowed; primary change → conflict (critical)” and diff logic; current code treats profile_contacts as one of many entities in the same loop.
- **snapshot_schema_version** is not read; no version-based branching for future schema changes.

### Refactor Plan (5)

| Item | Action |
|------|--------|
| 5.1 | Define an explicit **snapshot key → storage** mapping (and optionally → table name): e.g. `core` → matrimony_profiles CORE columns; `contacts` → profile_contacts; `children` → profile_children; `education_history` → profile_education; `career_history` → profile_career; `addresses` → profile_addresses; `property_summary` → profile_property_summary; `property_assets` → profile_property_assets; `horoscope` → profile_horoscope_data; `legal_cases` → profile_legal_cases; `preferences` → profile_preferences; `extended_narrative` → profile_extended_attributes or extended storage. |
| 5.2 | Implement **Step 6 CONTACT SYNC** separately: read `snapshot['contacts']`, diff with profile_contacts, enforce “only one primary”, primary change → conflict, write history per change. |
| 5.3 | Implement **Step 7 NORMALIZED ENTITY SYNC** by reading snapshot keys `children`, `education_history`, `career_history`, `addresses`, `property_summary`, `property_assets`, `horoscope`, `legal_cases`, `preferences` and mapping each to the correct table (profile_children, profile_education, profile_career, profile_addresses, profile_property_summary, profile_property_assets, profile_horoscope_data, profile_legal_cases, profile_preferences). Use diff; no mass truncate; history per change. |
| 5.4 | Implement **Step 8 EXTENDED NARRATIVE SYNC** from `snapshot['extended_narrative']`; history mandatory. |
| 5.5 | Read **snapshot_schema_version** from the intake at start of mutation; branch or validate so that only supported versions are applied (e.g. version 1 = current mapping); reject or defer unknown versions. |
| 5.6 | Remove reliance on `snapshot['profile_*']` keys; use only SSOT-defined keys (`children`, `education_history`, etc.) and the mapping above. |

---

## Execution Order Summary (SSOT Step 5)

After refactor, MutationService MUST execute in this order inside one DB transaction:

1. **Duplicate detection** (new) — stop and conflict_pending if duplicate.
2. **Profile existence** — create draft only if no duplicate and no profile.
3. **Field-level conflict detection** — CORE + EXTENDED, with escalation (serious_intent).
4. **Field lock check** — skip overwrite, create conflict_record if locked.
5. **CORE field apply** — only allowed changes; **profile_change_history** per field (single history).
6. **Contact sync** — snapshot `contacts` → profile_contacts; diff; one primary; history.
7. **Normalized entity sync** — snapshot keys → correct tables; diff; history.
8. **Extended narrative sync** — snapshot `extended_narrative`; history.
9. **Lifecycle transition** — active vs conflict_pending based on conflicts.
10. **Intake finalization** — status, locked, matrimony_profile_id.
11. **mutation_log** (optional) if table exists.

---

## Dependencies and Risks

- **DuplicateDetectionService** does not exist; must be implemented or a dedicated duplicate step added that uses existing DB (users, matrimony_profiles, profile_contacts) and snapshot data.
- **father_name** may not exist on profile or snapshot yet; SSOT duplicate rule requires it; schema or snapshot may need extension.
- **verified_otp_mobile** likely on users table; needs to be available in mutation context when “same user” check runs.
- **profile_change_history** schema must support all entity_type and field_name usages (CORE, lifecycle_state, contacts, entity tables).
- ConflictDetectionService and MatrimonyProfile governance (e.g. GOVERNED_CORE_KEYS) should stay aligned with the single CORE source introduced in plan (2).

---

**End of refactor plan. No code has been modified.**
