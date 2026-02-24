# PHASE-5 MUTATION — STRICT COMPLIANCE VERIFICATION REPORT

**Scope:** Contract (docs/PHASE-5_MUTATION_EXECUTION_CONTRACT.md) vs current codebase.  
**Purpose:** Identify mismatches and missing infrastructure only. No refactoring suggestions.

---

## SECTION-A: CONTRACT INTERNAL CONSISTENCY CHECK

### A.1 Logical contradictions

| Item | Finding |
|------|--------|
| Step 9 vs §6 | **Minor ambiguity:** In §1 step 9 description it says "Else → set profile.lifecycle_state = active" without qualifying the suspended/archived case. §6 explicitly adds "No conflicts, but profile is suspended, archived, or archived_due_to_marriage → Leave unchanged." So the step 9 one-line description is incomplete; the binding rule is in §6. **Verdict:** No contradiction; §6 overrides. |
| Intake when duplicate | Step 1 and Step 10 and §7 row 3 and "Intake behavior when duplicate detected" all state: do NOT set applied, do NOT set locked. **Verdict:** Consistent. |
| Escalation owner | §2 and §3 state ConflictDetectionService owns escalation; MutationService only reacts. **Verdict:** Consistent. |

**Conclusion:** No logical contradictions. One minor ambiguity (step 9 short description) resolved by §6.

---

### A.2 Step ordering conflicts

| Check | Finding |
|-------|--------|
| Step 1 before 2 | Duplicate detection before profile existence. Contract is explicit. **Verdict:** No conflict. |
| Step 10 when duplicate | Contract says skip steps 2–9, run step 10 only (intake finalization without applied/locked), then commit. Step 10 requires "profile exists" to set matrimony_profile_id; on duplicate an *existing* profile is referenced, so profile is available. **Verdict:** No conflict. |
| mutation_log after commit | Step 12 is "After commit." §7 row 7 says on exception "No mutation_log write." **Verdict:** No conflict. |

**Conclusion:** No step ordering conflicts.

---

### A.3 Ambiguous lifecycle rules

| Rule | Finding |
|------|--------|
| "No conflicts" + draft profile | Contract step 9: "Else → set profile.lifecycle_state = active." New draft profile has lifecycle_state = draft. §6 table: "No conflicts, and profile not suspended/archived → active." Draft is not in the "leave unchanged" list, so mutation would set draft → active. **Verdict:** Unambiguous. |
| "No conflicts" + suspended/archived | §6: leave unchanged; do NOT auto-activate. **Verdict:** Unambiguous. |
| Conflict from step 4 (lock only) | Step 9: "If any conflict_record created in this run (steps 3, 4, 6) → conflict_pending." Lock-only conflicts (step 4) are included. **Verdict:** Unambiguous. |

**Conclusion:** No ambiguous lifecycle rules.

---

### A.4 Intake state ambiguity

| Scenario | Contract | Finding |
|----------|---------|--------|
| Duplicate detected | Do NOT applied, do NOT locked; may set matrimony_profile_id. | Explicit in step 1, step 10, §7 row 3, and binding paragraph. |
| No duplicate, no conflicts | intake_status = applied, intake_locked = true. | Explicit in step 10. |
| No duplicate, conflicts | "leave intake_status/intake_locked per policy (e.g. applied_with_conflicts)." | Policy is not defined in contract; value set is implementation-defined. **Verdict:** Intent clear; exact enum/value not fixed in contract. |

**Conclusion:** No material intake state ambiguity except that "per policy" for conflict-without-duplicate is not enumerated (acceptable for contract).

---

## SECTION-B: CODEBASE COMPATIBILITY CHECK

### B.1 DuplicateDetectionService

| Contract requirement | Codebase | Verdict |
|----------------------|----------|--------|
| Service exists, called at step 1 | **No class or file** named DuplicateDetectionService or equivalent. | **MISSING.** |
| Returns duplicate_type (SAME_USER \| HARD \| HIGH_PROBABILITY \| HIGH_RISK) and existing profile_id | N/A — service absent. | **MISSING.** |
| Inputs: snapshot + intake context (verified_otp_mobile, primary_contact_number, full_name, date_of_birth, father_name, district_id, caste, serious_intent_id) | verified_otp_mobile: not found on profile; users table not inspected. father_name: not in MatrimonyProfile fillable or common migrations. primary_contact_number: contract implies profile_contacts or profile; profile has contact_number; profile_contacts has phone_number, is_primary. | **Data source for verified_otp_mobile and father_name unclear; DuplicateDetectionService absent.** |

---

### B.2 ConflictDetectionService

| Contract requirement | Codebase | Verdict |
|----------------------|----------|--------|
| **Owns** Escalation Matrix (identity-critical vs dynamic; serious_intent_id branch) | **Does not.** No reference to serious_intent_id. No identity-critical vs dynamic classification. No branching on profile.serious_intent_id. Creates conflict for every CORE/EXTENDED value difference (except locked). | **NOT COMPLIANT.** |
| Receives profile + proposed CORE + proposed EXTENDED | Signature: detect(MatrimonyProfile $profile, array $proposedCore, array $proposedExtended). | **Supported.** |
| Respects ProfileFieldLockService (skip locked) | Skips locked fields (does not create conflict for them). | **Supported.** |
| Returns created ConflictRecord[] | Returns array of ConflictRecord. | **Supported.** |
| Identity-critical fields: full_name, date_of_birth, gender, caste, sub_caste, marital_status, primary_contact_number, serious_intent_id | CORE_FIELD_KEYS = full_name, gender, date_of_birth, marital_status, education, location, caste, height_cm, profile_photo. **Missing:** sub_caste, primary_contact_number, serious_intent_id. **Extra in code:** education, location, height_cm, profile_photo (contract identity-critical does not list these; contract dynamic lists annual_income, etc.). | **CORE list mismatch; escalation logic absent.** |
| Dynamic fields: no conflict for value diff | Service creates conflict for any value difference; no "dynamic" pass-through. | **NOT COMPLIANT.** |

---

### B.3 ProfileFieldLockService

| Contract requirement | Codebase | Verdict |
|----------------------|----------|--------|
| isLocked(profile, field_key) | Exists; uses profile_field_locks table. | **Supported.** |
| Mutation uses only isLocked (read-only for apply) | N/A — caller responsibility. | **Supported.** |
| Must NOT create conflict records; must NOT change lifecycle | Service does not create conflicts or change lifecycle. | **Supported.** |

**Conclusion:** ProfileFieldLockService is contract-compliant for mutation use.

---

### B.4 FieldRegistry / CORE field source

| Contract requirement | Codebase | Verdict |
|----------------------|----------|--------|
| Single source for CORE keys (contract §2/refactor plan) | ConflictDetectionService uses private const CORE_FIELD_KEYS. MatrimonyProfile has GOVERNED_CORE_KEYS. No use of FieldRegistry for CORE list in ConflictDetectionService or MutationService. | **No single source; hardcoded lists in code.** |
| field_registry table | Exists (field_key, field_type CORE|EXTENDED, etc.). | **Table exists.** |
| Contract identity-critical + dynamic field sets | Not represented in FieldRegistry or any single constant; ConflictDetectionService list is a different, fixed set. | **Schema/config does not encode contract identity-critical vs dynamic.** |

---

### B.5 profile_change_history schema

| Contract required column | Migration profile_change_history | Verdict |
|---------------------------|-----------------------------------|--------|
| profile_id | foreignId('profile_id') | **Present.** |
| entity_type | string('entity_type') | **Present.** |
| entity_id | unsignedBigInteger('entity_id')->nullable() | **Present.** |
| field_name | string('field_name') | **Present.** |
| old_value | longText('old_value')->nullable() | **Present.** |
| new_value | longText('new_value')->nullable() | **Present.** |
| changed_by | foreignId('changed_by')->nullable() | **Present.** |
| source | string('source') | **Present.** |
| changed_at | timestamp('changed_at') | **Present.** |

**Conclusion:** profile_change_history schema supports all contract-required columns.

---

### B.6 biodata_intakes schema

| Contract requirement | Migration biodata_intakes | Verdict |
|----------------------|----------------------------|--------|
| approval_snapshot_json | longText('approval_snapshot_json')->nullable() | **Present.** |
| approved_by_user | boolean('approved_by_user')->default(false) | **Present.** |
| intake_status | string('intake_status')->default('uploaded') | **Present.** (Value 'applied' is string; not enum.) |
| intake_locked | boolean('intake_locked')->default(false) | **Present.** |
| snapshot_schema_version | unsignedInteger('snapshot_schema_version')->default(1) | **Present.** |
| matrimony_profile_id | foreignId('matrimony_profile_id')->nullable() | **Present.** |

**Conclusion:** biodata_intakes contains snapshot_schema_version and all intake fields required by the contract.

---

### B.7 lifecycle_state enum

| Contract enum values (§6) | Migration 2026_02_16 (lifecycle_state ENUM) | MatrimonyProfile::LIFECYCLE_STATES | Verdict |
|---------------------------|----------------------------------------------|------------------------------------|--------|
| draft, intake_uploaded, parsed, awaiting_user_approval, approved_pending_mutation, conflict_pending, active, suspended, archived, archived_due_to_marriage | Same 10 values, same order. | Same 10 values. | **Fully aligned.** |

**Conclusion:** lifecycle_state enum and model constant contain all contract-required states.

---

### B.8 conflict_records schema (for mutation-created conflicts)

| Contract / SSOT | Migration conflict_records | Verdict |
|-----------------|-----------------------------|--------|
| profile_id, field_name, field_type, old_value, new_value, source, detected_at, resolution_status | All present. field_type enum: CORE, EXTENDED. source enum: OCR, USER, ADMIN, MATCHMAKER, SYSTEM. | **Supported for CORE/EXTENDED.** |
| field_type CONTACT / ENTITY (SSOT Part 4) | Enum is only CORE, EXTENDED. No CONTACT or ENTITY. | **CONTACT/ENTITY conflict type not in schema if required.** |
| entity_id (nullable) in ConflictRecord (SSOT refactor plan) | No entity_id column. | **Missing if entity-scoped conflicts are required.** |

---

### B.9 MutationService (current)

| Contract requirement | Current MutationService | Verdict |
|----------------------|--------------------------|--------|
| Step 1: DuplicateDetectionService | Not called; no duplicate detection. | **MISSING.** |
| Step 0: snapshot_schema_version read and allowed-set check | Does not read or enforce snapshot_schema_version. | **MISSING.** |
| Step 9: Do not set active when profile is suspended/archived/archived_due_to_marriage | setLifecycleStateWithHistory(profile, 'active') or conflict_pending; no check for current state suspended/archived. | **MISSING.** |
| Step 10: When duplicate, do NOT applied, do NOT locked | When hasConflicts, does not set applied/locked; when duplicate, flow is different — duplicate path does not exist. | **N/A (no duplicate path).** |
| History: profile_change_history only; no field_value_history for mutation | Writes profile_change_history; also calls FieldValueHistoryService::record for lifecycle and CORE (dual write). | **Contract says mutation path must NOT write to field_value_history; current code does.** |
| Snapshot keys: use SSOT keys (children, education_history, …) | Uses table names (profile_children, …) and snapshot[$entityKey]; does not use snapshot['children'], etc. | **MISMATCH.** |

---

## SECTION-C: IMPLEMENTATION READINESS SCORE (0–100)

### C.1 Duplicate stage readiness — **0**

| Criterion | Status |
|-----------|--------|
| DuplicateDetectionService exists | No. |
| Duplicate checks (verified_otp_mobile, primary_contact_number, composite, serious_intent_id) implemented | No. |
| Input data available (father_name, verified_otp_mobile) | father_name not on profile; verified_otp_mobile not verified on users. |
| Integration in MutationService step 1 | No. |
| Intake behavior when duplicate (not applied, not locked) | Not testable; path absent. |

**Score: 0/100.** No duplicate detection infrastructure; data sources for some inputs missing or unclear.

---

### C.2 Escalation readiness — **15**

| Criterion | Status |
|-----------|--------|
| ConflictDetectionService implements identity-critical vs dynamic | No. |
| ConflictDetectionService uses profile.serious_intent_id | No. |
| Identity-critical list (contract) present in code | No; code has different CORE list. |
| Dynamic fields: no conflict for value diff, apply with history | No; service creates conflict for every diff. |
| ConflictDetectionService exists and is callable with profile + proposed | Yes. |
| Profile has serious_intent_id | Yes (fillable). |

**Score: 15/100.** Service exists and is callable; escalation matrix and field classification are not implemented; CORE list does not match contract.

---

### C.3 History readiness — **70**

| Criterion | Status |
|-----------|--------|
| profile_change_history table exists with required columns | Yes (entity_type, entity_id, source, changed_at, etc.). |
| Mutation path writes only to profile_change_history (no field_value_history) | No; MutationService also writes to field_value_history via FieldValueHistoryService. |
| One row per logical change | Implementable; schema supports. |
| changed_by nullable, source = MUTATION | Schema allows; implementation must set. |

**Score: 70/100.** Schema is ready; dual-write to field_value_history violates contract and must be removed for full compliance.

---

### C.4 Snapshot routing readiness — **25**

| Criterion | Status |
|-----------|--------|
| Snapshot keys: core, contacts, children, education_history, career_history, addresses, property_summary, property_assets, horoscope, legal_cases, preferences, extended_narrative | Contract defined; MutationService currently uses table names (profile_*) not these keys. |
| Read snapshot_schema_version; enforce allowed set | Not implemented; column exists. |
| Entity tables exist (profile_contacts, profile_children, profile_education, …) | profile_contacts, profile_children, profile_education exist; others not all verified. |
| Routing logic: key → table mapping | Not implemented per contract keys. |

**Score: 25/100.** Snapshot schema version and key structure exist in DB/contract; routing and version enforcement are not implemented in code.

---

### C.5 Lifecycle enforcement readiness — **50**

| Criterion | Status |
|-----------|--------|
| lifecycle_state enum has all 10 values | Yes. |
| MatrimonyProfile::LIFECYCLE_STATES / LIFECYCLE_TRANSITIONS aligned | Yes. |
| Step 9: set conflict_pending when conflicts | Implemented in current MutationService. |
| Step 9: do NOT set active when profile is suspended/archived/archived_due_to_marriage | Not implemented; no check. |
| Lifecycle change written to profile_change_history | Contract requires; current code also writes to field_value_history. |

**Score: 50/100.** Enum and model constants are correct; suspended/archived no-auto-activate rule is missing; history target is dual (should be profile_change_history only).

---

## SUMMARY TABLE

| Area | Readiness | Blocking gaps |
|------|-----------|----------------|
| Duplicate stage | 0 | No DuplicateDetectionService; father_name/verified_otp_mobile source unclear. |
| Escalation | 15 | No escalation matrix in ConflictDetectionService; CORE list mismatch; no serious_intent_id branch. |
| History | 70 | profile_change_history schema OK; mutation path must stop writing to field_value_history. |
| Snapshot routing | 25 | Version enforcement missing; snapshot keys in code are table names, not contract keys. |
| Lifecycle enforcement | 50 | suspended/archived no-auto-activate missing; single history store not enforced. |

---

**END OF REPORT.**  
No code or refactoring suggested; mismatches and missing infrastructure only.
