# PHASE-5 MUTATION EXECUTION CONTRACT

**Document type:** Architecture freeze  
**Purpose:** Strict execution contract for the Safe Mutation Pipeline. No code; binding specification only.  
**Source:** PHASE-5 SSOT (Parts 2, 4, 6), MUTATION_SERVICE_REFACTOR_PLAN.md  

---

## 1) EXACT EXECUTION ORDER INSIDE DB::transaction

The following steps MUST execute in this exact order. All steps (except where noted) run **inside** a single `DB::transaction()`.

| Step | Name | Description |
|------|------|-------------|
| 0 | **Pre-transaction** | Load BiodataIntake by ID; validate approval_snapshot_json present, approved_by_user = true, intake_status ≠ applied, intake_locked = false (or per policy). Read snapshot_schema_version. If invalid → throw; do NOT begin transaction. |
| 1 | **Duplicate detection** | Call DuplicateDetectionService with snapshot + intake context. If duplicate detected → **HARD STOP**: create conflict_record(s), set lifecycle_state = conflict_pending on existing profile (if any), do NOT create profile, do NOT run steps 2–9, skip to step 10 (intake finalization). **Intake when duplicate:** do NOT set intake_status = applied; do NOT set intake_locked = true; only link matrimony_profile_id if existing profile exists; then commit. |
| 2 | **Profile existence** | If intake.matrimony_profile_id set → load profile with lockForUpdate. Else → create draft MatrimonyProfile (user_id = intake.uploaded_by, lifecycle_state = draft), link intake.matrimony_profile_id, save intake. |
| 3 | **Field-level conflict detection** | Call ConflictDetectionService with profile + proposed CORE + proposed EXTENDED from snapshot. ConflictDetectionService **owns** the Escalation Matrix (see §3); MutationService only reacts to returned conflict records. Collect created conflict records; set conflict_field_names. |
| 4 | **Field lock check** | For each CORE/EXTENDED field in proposed set: call ProfileFieldLockService.isLocked(profile, field_key). If locked → create conflict_record, add to conflict set; do NOT overwrite. |
| 5 | **CORE field apply** | For each CORE field in snapshot: if not in conflict set and not locked, and (existing ≠ proposed): set attribute on profile; write **one** profile_change_history row per changed field. Then profile->save() once. |
| 6 | **Contact sync** | Read snapshot key `contacts`. Diff with profile_contacts. Enforce: only one primary; primary change → conflict (critical), create conflict_record. Apply insert/update/delete by diff; write profile_change_history per change. No mass truncate. |
| 7 | **Normalized entity sync** | For each entity in **Snapshot key → entity routing table** (see §5): read snapshot key, diff with target table, insert/update (no silent delete without history). Write profile_change_history per change. Order: children, education_history, career_history, addresses, property_summary, property_assets, horoscope, legal_cases, preferences. |
| 8 | **Extended narrative sync** | Read snapshot key `extended_narrative`. Apply to profile_extended_attributes (or defined extended storage). Write profile_change_history. |
| 9 | **Lifecycle transition** | If any conflict_record created in this run (steps 3, 4, 6) → set profile.lifecycle_state = conflict_pending. Else → set profile.lifecycle_state = active. Write profile_change_history for lifecycle_state change. |
| 10 | **Intake finalization** | Set intake.matrimony_profile_id = profile.id when a profile exists. If duplicate was detected (step 1 HARD STOP): do NOT set intake_status = applied; do NOT set intake_locked = true. If no duplicate and no conflicts → intake_status = applied, intake_locked = true; if no duplicate but conflicts → leave intake_status/intake_locked per policy (e.g. applied_with_conflicts). Save intake. |
| 11 | **Commit** | End transaction. |
| 12 | **Mutation log (optional)** | **After** commit: if mutation_log table exists, insert one row (intake_id, profile_id, mutation_status, conflict_detected, created_at). |

**Rule:** No step may be reordered. No step may be skipped except by Hard STOP (see §7). No return/exit inside transaction except via throw (full rollback).

---

## 2) SERVICE LAYERING BOUNDARIES

| Service | Responsibility | Called by MutationService at step | Must NOT |
|---------|----------------|-----------------------------------|----------|
| **DuplicateDetectionService** | Run duplicate engine: verified_otp_mobile, primary_contact_number, full_name+DOB+father_name+district_id+caste, serious_intent_id. Return: duplicate_type (SAME_USER \| HARD \| HIGH_PROBABILITY \| HIGH_RISK) and existing profile_id if applicable. | Step 1 (before profile creation) | Create profile; mutate profile; write history. |
| **ConflictDetectionService** | **Owns** the Escalation Matrix: compare profile current vs proposed CORE + proposed EXTENDED; apply identity-critical vs dynamic and serious_intent_id rules; create ConflictRecord rows for mismatches; respect ProfileFieldLockService (skip locked). Return created ConflictRecord[]. MutationService only reacts to this result. | Step 3 | Apply values to profile; change lifecycle; run duplicate logic. |
| **ProfileFieldLockService** | isLocked(profile, field_key), assertNotLocked(profile, fields, actor), applyLocks(profile, fields, type, actor), removeLock(profile, field_key). Mutation uses only isLocked. | Steps 4, 5 (read-only for apply decision) | Create conflict records; change lifecycle. |
| **MutationService** | Orchestrate steps 0–12; read snapshot; route snapshot keys to storage; write profile_change_history; set lifecycle_state; finalize intake. | — | Bypass duplicate detection; bypass conflict detection; write to field_value_history for mutation path; reorder steps. |
| **ProfileLifecycleService** | Canonical lifecycle transitions (transitionTo) for admin/user-initiated changes. Mutation MAY use same transition rules but writes lifecycle_state directly inside its own transaction with profile_change_history. | Optional reference for allowed transitions only | Be used to persist mutation lifecycle inside MutationService transaction (MutationService owns lifecycle write in step 9). |

**Boundary rule:** DuplicateDetectionService and ConflictDetectionService do NOT call each other. MutationService is the only caller that runs both. ProfileFieldLockService is read-only from MutationService for the apply path (no applyLocks during mutation).

---

## 3) ESCALATION MATRIX — CONFLICTDETECTIONSERVICE OWNS; MUTATIONSERVICE ONLY REACTS

- **Owner:** ConflictDetectionService **fully owns** the Escalation Matrix. MutationService does **not** implement escalation logic; it only calls ConflictDetectionService (step 3) and **reacts** to the returned conflict records (e.g. by skipping apply in step 5 for those fields and by setting lifecycle in step 9 when conflicts exist).

**Matrix (binding, implemented inside ConflictDetectionService):**

| Profile state | Field category | Action (ConflictDetectionService creates conflict_record or not) |
|---------------|----------------|------------------------------------------------------------------|
| serious_intent_id IS NULL | Identity-critical (full_name, date_of_birth, gender, caste, sub_caste, marital_status, primary_contact_number, serious_intent_id) | Create conflict_record; MutationService will not auto-apply. |
| serious_intent_id IS NULL | Dynamic (annual_income, family_income, occupation_title, company_name, work_city_id, work_state_id) | Do not create conflict for value diff; MutationService may apply (subject to lock check). |
| serious_intent_id IS NOT NULL | Identity-critical (same list) | Create conflict_record; MutationService will not auto-apply; step 9 will set conflict_pending. |
| serious_intent_id IS NOT NULL | Dynamic (same list) | Do not create conflict for value diff; MutationService may apply; no lifecycle escalation. |

**Boundary:**  
- ConflictDetectionService must receive **profile.serious_intent_id** and **proposed CORE** and classify each field as identity-critical vs dynamic; it creates ConflictRecords accordingly.  
- MutationService must not duplicate this logic; it only uses the returned conflict set to decide what to apply (step 5) and which lifecycle state to set (step 9).

---

## 4) UNIFIED HISTORY WRITE POLICY

- **Single history store for mutation:** `profile_change_history` only. No write to `field_value_history` for mutation-originated changes.

**When to write:**

| Event | Table | Required columns (conceptual) |
|-------|--------|-------------------------------|
| Any CORE field change (step 5) | profile_change_history | profile_id, entity_type=matrimony_profile, entity_id=profile.id, field_name, old_value, new_value, changed_by=null, source=MUTATION, changed_at |
| lifecycle_state change (step 9) | profile_change_history | profile_id, entity_type=matrimony_profile, entity_id=profile.id, field_name=lifecycle_state, old_value, new_value, changed_by=null, source=MUTATION, changed_at |
| Contact sync change (step 6) | profile_change_history | profile_id, entity_type=profile_contacts, entity_id=contact_row.id, field_name, old_value, new_value, source=MUTATION, changed_at |
| Entity sync change (step 7) | profile_change_history | profile_id, entity_type=<table_name>, entity_id=row.id, field_name, old_value, new_value, source=MUTATION, changed_at |
| Extended narrative change (step 8) | profile_change_history | profile_id, entity_type=profile_extended_attributes (or defined), entity_id, field_name, old_value, new_value, source=MUTATION, changed_at |

**Rules:**

- One row per logical change (one field, one old_value, one new_value).
- No mutation path write to field_value_history.
- User/admin-initiated edits (profile edit screen, admin override) continue to use FieldValueHistoryService / field_value_history per existing governance; they are out of scope of this contract.

---

## 5) SNAPSHOT KEY → ENTITY ROUTING TABLE

Approval snapshot lives in `biodata_intakes.approval_snapshot_json`. Structure (SSOT):

| Snapshot key | Storage target | Cardinality | Step |
|--------------|----------------|-------------|------|
| core | matrimony_profiles (CORE columns) | One row per profile | 5 |
| contacts | profile_contacts | Multi-row | 6 |
| children | profile_children | Multi-row | 7 |
| education_history | profile_education | Multi-row | 7 |
| career_history | profile_career | Multi-row | 7 |
| addresses | profile_addresses | Multi-row (by address_type) | 7 |
| property_summary | profile_property_summary | One-to-one | 7 |
| property_assets | profile_property_assets | Multi-row | 7 |
| horoscope | profile_horoscope_data | One-to-one | 7 |
| legal_cases | profile_legal_cases | Multi-row | 7 |
| preferences | profile_preferences | One-to-one | 7 |
| extended_narrative | profile_extended_attributes (or defined extended store) | One-to-one / narrative | 8 |
| confidence_map | Not persisted; audit/display only | — | — |

**Rules:**  
- MutationService MUST use these keys to read from the snapshot. It MUST NOT use table names (e.g. profile_children) as snapshot keys.  
- **snapshot_schema_version enforcement:** Read `snapshot_schema_version` from the intake at step 0 (pre-transaction). The contract defines an **allowed set** of versions (e.g. [1]). If `snapshot_schema_version` is null, missing, or not in the allowed set → **Hard STOP**: throw before starting the transaction; do NOT apply any snapshot data. No assumption-based parsing; only versions explicitly supported may be applied. Adding a new version requires a contract and implementation update.

---

## 6) LIFECYCLE TRANSITION MATRIX

**Allowed lifecycle_state values (enum, single source):**  
draft, intake_uploaded, parsed, awaiting_user_approval, approved_pending_mutation, conflict_pending, active, suspended, archived, archived_due_to_marriage.

**Transitions allowed (from MatrimonyProfile::LIFECYCLE_TRANSITIONS):**

| From | To (allowed) |
|------|----------------------|
| draft | (none in model; mutation may set draft on create) |
| approved_pending_mutation | active, conflict_pending |
| conflict_pending | active (after resolution; not by mutation) |
| active | suspended, archived, archived_due_to_marriage |

**Mutation step 9 rules:**

| Condition | Set lifecycle_state to |
|-----------|------------------------|
| Any conflict_record created in this run (steps 3, 4, or 6) | conflict_pending |
| No conflicts, and profile not suspended/archived | active |
| No conflicts, but profile is suspended, archived, or archived_due_to_marriage | Leave unchanged (do NOT auto-activate) |

**Lifecycle edge-case rule:**  
- Profiles in **suspended**, **archived**, or **archived_due_to_marriage** MUST NOT be auto-activated by mutation. If current profile.lifecycle_state is one of these, step 9 MUST NOT set lifecycle_state = active; it MUST leave the state unchanged (or set conflict_pending only when conflicts exist). Reactivation is only via explicit admin/user flow, not mutation.

**Rules:** Mutation MUST NOT set any state not in the enum. Mutation MUST NOT transition to active if conflicts exist. Mutation MUST NOT transition to active when current state is suspended, archived, or archived_due_to_marriage. Lifecycle change MUST be written to profile_change_history.

---

## 7) HARD STOP CONDITIONS

When any of the following occur, mutation MUST stop as specified. No partial apply. No silent overwrite.

| # | Condition | Action |
|---|-----------|--------|
| 1 | Intake not found or invalid (no approval_snapshot_json, approved_by_user ≠ true, intake_status = applied, or intake_locked = true per policy) | Throw before transaction; do NOT start transaction. |
| 2 | **snapshot_schema_version not supported:** value is null, missing, or not in the contract’s allowed set (e.g. [1]) | Throw before transaction; do NOT apply any snapshot; do NOT start transaction. No assumption-based parsing. |
| 3 | Duplicate detected (DuplicateDetectionService returns duplicate) | Inside transaction: create conflict_record(s), set existing profile.lifecycle_state = conflict_pending if applicable; do NOT create new profile; do NOT run steps 2 (profile create), 3–9. Run step 10 (intake finalization) **only**: may set intake.matrimony_profile_id to existing profile id; **must NOT** set intake_status = applied; **must NOT** set intake_locked = true. Then commit. |
| 4 | Same user, same data (CASE A): snapshot identical to existing profile | No mutation; mark intake redundant; do NOT apply steps 5–9; lifecycle unchanged; finalize intake; commit. |
| 5 | Profile load failed (matrimony_profile_id set but profile missing) | Throw; rollback. |
| 6 | Profile in suspended, archived, or archived_due_to_marriage and step 9 would set active | Do NOT set lifecycle_state = active; leave unchanged. (Not a full stop; step 9 enforces this rule.) |
| 7 | Any unhandled exception in steps 1–10 | Rollback entire transaction; rethrow. No mutation_log write. |

**Intake behavior when duplicate detected (binding):**  
- When duplicate is detected (row 3), intake MUST NOT be marked applied and MUST NOT be locked.  
- Allowed: set intake.matrimony_profile_id to the existing profile id (for audit/link).  
- Forbidden: intake_status = applied; intake_locked = true.

**Prohibitions (violation = contract breach):**

- Do NOT create a new profile when duplicate is detected.
- Do NOT set intake_status = applied or intake_locked = true when duplicate is detected.
- Do NOT apply CORE/entity/contact when field is in conflict set or locked.
- Do NOT write mutation history to field_value_history.
- Do NOT skip profile_change_history for any applied change.
- Do NOT set lifecycle_state = active when conflicts were created in this run.
- Do NOT set lifecycle_state = active when current profile state is suspended, archived, or archived_due_to_marriage.
- Do NOT run steps 5–9 when duplicate detected (except as in row 3 above).
- Do NOT apply snapshot when snapshot_schema_version is unsupported or missing.

---

**END OF CONTRACT**

This document is the architecture freeze for the Phase-5 Safe Mutation Pipeline. Implementation MUST conform to sections 1–7. Any deviation requires SSOT and this contract to be updated first.
