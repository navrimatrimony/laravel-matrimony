############################################################
PHASE-5 FINAL BLUEPRINT — PART 1
VISION, PRINCIPLES, ARCHITECTURE FREEZE
############################################################

Document Status: CLEAN REBUILD  
Structural Freeze: v2 (All contradictions resolved)  
Scope: Biodata Intake → AI Structured Parsing → Conflict-Safe Profile Creation  
Dependency: Phase-4 Governance (Field Registry, Locking, Conflict, Lifecycle, History)

============================================================
1️⃣ PHASE-5 CORE VISION
============================================================

Phase-5 चा मुख्य उद्देश:

Raw Marathi Biodata (Image / PDF / Text)
→ Zero Data Loss
→ AI Structured Parsing
→ User Verified Preview
→ Conflict-Safe Mutation
→ Fully Normalized MatrimonyProfile
→ Duplicate-Safe
→ Governance-Compliant
→ AI-Ready
→ Future Matching-Ready

Phase-5 हे feature-addition नाही.
Phase-5 हे STRUCTURAL UPGRADE आहे.

------------------------------------------------------------

User ला खालील गोष्टी करता याव्यात:

• Biodata image upload
• PDF upload
• Raw text paste
• AI structured preview पाहणे
• Low-confidence fields review करणे
• Missing contacts add करणे
• Approve & Apply to Profile करणे
• Existing profile safe update करणे
• Duplicate detection मिळणे
• Conflict record resolve करणे

------------------------------------------------------------

Strict Rule:
Direct profile mutation = PERMANENTLY FORBIDDEN.

All mutations must pass intake governance pipeline.

============================================================
2️⃣ NON-NEGOTIABLE DESIGN PRINCIPLES
============================================================

• No data loss
• No assumption-based mapping
• No structured JSON blobs
• No silent overwrite
• No direct update() calls
• No duplicate profile creation
• No auto-activation without approval
• No cascade delete
• No age storage
• No hybrid model

============================================================
3️⃣ FINAL ARCHITECTURE MODEL (OPTION-C — FROZEN)
============================================================

Core Tables
+
Fully Normalized Nested Entity Tables
+
Limited Extended Narrative Table
+
Unified Change History Table
+
Conflict Records
+
Field Lock Governance
+
Duplicate Detection Engine
+
Intake Workflow Layer

------------------------------------------------------------

❌ Hybrid Model = Rejected  
❌ JSON Blob Storage = Rejected  
❌ Extended as catch-all = Rejected  

============================================================
4️⃣ DATA LAYER STRUCTURE OVERVIEW
============================================================

PRIMARY ENTITY:

matrimony_profiles

Linked Layers:

profile_contacts
profile_children
profile_education
profile_career
profile_addresses
profile_photos
profile_relatives
profile_property
profile_horoscope_data
profile_preferences
profile_legal_cases
profile_extended_attributes (Narrative only)
profile_change_history (Unified)
conflict_records
biodata_intakes
mutation_log (optional)

============================================================
5️⃣ CANONICAL FIELD CLASSIFICATION (FROZEN)
============================================================

Every field must belong to ONE of these:

A) CORE (Searchable / Structured / Matching relevant)
B) NORMALIZED NESTED ENTITY
C) CONTACT
D) EXTENDED NARRATIVE (rare text only)

• No structured entity stored in extended.
• No JSON array storage allowed.
• Extended limited to narrative only.

System must NEVER guess category.

Field routing must strictly follow contract.

============================================================
6️⃣ CORE FIELD REGISTRY — FINAL FREEZE
============================================================

-----------------------------------------
A) PERSONAL IDENTITY
-----------------------------------------

- full_name
- gender
- date_of_birth
- height_cm (integer, canonical storage)
- height_display_format derived at UI level
- Only one canonical numeric format stored (centimeter)
- weight_kg
- marital_status
- religion
- caste
- sub_caste
- complexion (fair / wheatish / dark / other)
- physical_build (slim / athletic / average / heavy)
- blood_group

-----------------------------------------
B) EDUCATION & CAREER (PRIMARY SNAPSHOT)
-----------------------------------------

- highest_education
- specialization
- occupation_title
- company_name
- annual_income
- income_currency (default = INR)
- family_income

Rule: income_currency default = INR. All income comparison normalized to INR internally.

-----------------------------------------
C) LOCATION (STRUCTURED IDS)
-----------------------------------------

- country_id
- state_id
- district_id
- taluka_id
- city_id
- work_city_id
- work_state_id

-----------------------------------------
D) FAMILY CORE
-----------------------------------------

- father_name
- father_occupation
- mother_name
- mother_occupation
- brothers_count
- sisters_count
- family_type

------------------------------------------------------------

⚠️ AGE RULE (PERMANENT)

- age column must NEVER exist.
- age must ALWAYS be derived from date_of_birth at runtime.
- No stored age allowed.

============================================================
7️⃣ CRITICAL FIELD LIST (IDENTITY-LEVEL)
============================================================

Critical Fields:

- full_name
- date_of_birth
- gender
- religion
- caste
- sub_caste
- marital_status
- annual_income
- family_income
- primary_contact_number
- serious_intent_id

Critical fields:

• Always require manual confirmation
• Never auto-overwrite
• Conflict record mandatory on change
• Strict governance if serious_intent active

============================================================
8️⃣ CONTACT STRUCTURE (MULTI-ROW — NORMALIZED)
============================================================

profile_contacts

Each profile may have multiple contact rows.

Fields:

- relation_type
- contact_name
- phone_number
- is_primary
- visibility_rule
- verified_status

Rules:

• Only ONE primary contact allowed
• OTP mobile stored in users table (verification_mobile)
• No contact stored in extended attributes
• relation_type must be controlled enum

============================================================
9️⃣ EXTENDED TABLE — STRICT RESTRICTION
============================================================

profile_extended_attributes

Allowed ONLY for:

- narrative_about_me
- narrative_expectations
- additional_notes
- rare_custom_fields

------------------------------------------------------------

❌ No structured array
❌ No horoscope
❌ No children
❌ No property
❌ No legal case
❌ No education history
❌ No career history

------------------------------------------------------------

Structured repeatable entities must ALWAYS use normalized tables.

============================================================
🔟 FULL NORMALIZATION FREEZE
============================================================

The following must ALWAYS be relational:

1) profile_children
2) profile_education
3) profile_career
4) profile_addresses
5) profile_photos
6) profile_relatives
7) profile_horoscope_data
8) profile_property
9) profile_preferences
10) profile_legal_cases

------------------------------------------------------------

No structured JSON blob storage allowed anywhere.

============================================================
1️⃣1️⃣ HEIGHT STORAGE FREEZE
============================================================

• height_cm (integer, canonical storage)
• height_display_format derived at UI level
• Only one canonical numeric format stored (centimeter)
• No feet_inch numeric format; no duplicate storage (cm + ft)

============================================================
1️⃣2️⃣ UNIFIED HISTORY SYSTEM (REPLACES MULTIPLE TABLES)
============================================================

profile_change_history

Columns:

- profile_id
- entity_type
- entity_id
- field_name
- old_value
- new_value
- changed_by
- source (intake/manual/admin)
- changed_at

Rules:

• Append-only
• No delete
• Every mutation must generate entry
• Applies to core + nested + extended

------------------------------------------------------------

This replaces:

- field_value_history
- attribute_value_history

============================================================
1️⃣3️⃣ DUPLICATE DETECTION — FINAL CONTRACT
============================================================

Priority Order:

1) verified_otp_mobile exact match → SAME USER
2) primary_contact_number exact match → HARD DUPLICATE
3) full_name + date_of_birth + father_name + district_id + caste → HIGH PROBABILITY DUPLICATE
4) serious_intent_id match → HIGH-RISK DUPLICATE

------------------------------------------------------------

If duplicate detected:

• Do NOT create new profile
• Trigger conflict workflow
• Notify user
• Allow merge logic (future-ready)

============================================================
1️⃣4️⃣ CONTACT UNLOCK + LIFECYCLE RULE
============================================================

Contact unlock allowed ONLY if:

lifecycle_state = active

If lifecycle_state:

- draft
- intake_uploaded
- awaiting_user_approval
- approved_pending_mutation
- conflict_pending
- suspended
- archived

→ Contact unlock NOT allowed
(Except admin override)

============================================================
PROFILE VISIBILITY LAYER
============================================================

-----------------------------------------
Fields:
-----------------------------------------

- visibility_scope (public / limited / hidden)
- show_photo_to (all / verified_only / paid_only)
- show_contact_to (locked_by_default)
- hide_from_blocked_users (boolean)

-----------------------------------------
Clarification:
-----------------------------------------

Lifecycle_state governs governance.
Visibility governs UI exposure.
They are independent layers.

============================================================
1️⃣5️⃣ SOFT DELETE REACTIVATION POLICY
============================================================

If lifecycle_state = archived_due_to_marriage:

Reactivation requires:

• User request
• Reason submission
• OTP verification
• Admin approval
• profile_change_history entry

System must NEVER auto-reactivate.

============================================================
END OF PART 1
============================================================

Next: PART 2 — INTAKE PIPELINE (Upload → Parse → Preview → Approval → Mutation)
############################################################
PHASE-5 FINAL BLUEPRINT — PART 2
INTAKE PIPELINE (UPLOAD → PARSE → PREVIEW → APPROVAL → MUTATION)
############################################################

Dependency:
• Phase-4 Governance Layer
• ConflictDetectionService
• FieldLockService
• profile_change_history
• lifecycle_state system

Strict Rule:
Direct MatrimonyProfile::update() = FORBIDDEN.

All mutations MUST pass through MutationService.

============================================================
1️⃣ OVERALL PIPELINE FLOW
============================================================

STEP-1  → Biodata Intake Record Creation  
STEP-2  → AI Structured Parsing  
STEP-3  → Structured Preview Screen  
STEP-4  → Explicit User Approval  
STEP-5  → Safe Mutation Pipeline  
STEP-6  → Intake Finalization & Lock  

At NO stage is direct profile mutation allowed
before approval + governance checks.

============================================================
2️⃣ STEP-1: BIODATA INTAKE RECORD CREATION
============================================================

Trigger:
User uploads:
• Image
• PDF
• OR pastes raw text

------------------------------------------------------------

Table: biodata_intakes

Stored Fields:

- id
- uploaded_by (user_id)
- file_path (nullable if raw text)
- original_filename (nullable)
- raw_ocr_text
- intake_status = "uploaded"
- parse_status = "pending"
- approved_by_user = false
- intake_locked = false
- created_at
- updated_at

------------------------------------------------------------

STRICT RULES:

• raw_ocr_text must NEVER be modified.
• Intake record is IMMUTABLE at raw level.
• Deletion not allowed.
• Editing raw text not allowed.
• Each upload creates NEW intake record.

------------------------------------------------------------

Lifecycle transition:

No profile yet:
→ lifecycle_state = intake_uploaded

Existing profile:
→ lifecycle_state unchanged at this stage.

============================================================
3️⃣ STEP-2: AI STRUCTURED PARSING
============================================================

AI generates structured JSON:

{
  core: { ... },
  contacts: [ ... ],
  children: [ ... ],
  education_history: [ ... ],
  career_history: [ ... ],
  addresses: { ... },
  property: { ... },
  horoscope: { ... },
  legal_cases: [ ... ],
  preferences: { ... },
  extended_narrative: { ... },
  confidence_map: { field_name: score }
}

------------------------------------------------------------

Storage:

biodata_intakes.parsed_json
biodata_intakes.parse_status = "parsed"

------------------------------------------------------------

Important:

• No profile mutation happens here.
• No conflict detection yet.
• Only parsing + storage.
• parsed_json can be overwritten ONLY by re-parse cycle.
• raw_ocr_text NEVER touched.

------------------------------------------------------------

Lifecycle transition:

→ lifecycle_state = parsed
(Only if new profile creation flow)

============================================================
4️⃣ STEP-3: PREVIEW SCREEN (USER REVIEW)
============================================================

User sees structured preview divided into sections:

• Core Details
• Contacts
• Children
• Education
• Career
• Addresses
• Property
• Horoscope
• Legal Cases
• Preferences
• Narrative Sections

------------------------------------------------------------

UI RULES:

• confidence < 0.75 → Mandatory review highlight
• 0.75–0.90 → Recommended review highlight
• ≥ 0.90 → Normal display (still requires approval)

• Critical fields always highlighted for confirmation.
• Missing critical fields flagged.
• User can:
   - Edit values
   - Delete incorrect rows
   - Add new rows
   - Add missing contacts
   - Change primary contact

------------------------------------------------------------

Preview does NOT modify profile.
Preview modifies only in-memory + approval snapshot candidate.

============================================================
5️⃣ STEP-4: USER APPROVAL RECORD
============================================================

User clicks:
[Approve & Apply to Profile]

System stores in biodata_intakes:

- approved_by_user = true
- approved_at timestamp
- approval_snapshot_json
- intake_status = "approved"

------------------------------------------------------------

Rules:

• approval_snapshot_json is IMMUTABLE.
• After approval, preview cannot be edited.
• Any change requires new intake cycle.

------------------------------------------------------------

Lifecycle transition:

If new user:
→ lifecycle_state = approved_pending_mutation

If existing profile:
→ lifecycle_state remains active until mutation.

============================================================
6️⃣ STEP-5: SAFE MUTATION PIPELINE (CRITICAL)
============================================================

MutationService executes STRICT order:

------------------------------------------------------------
1) DUPLICATE DETECTION
------------------------------------------------------------

Run duplicate detection contract.

If duplicate detected:

• Stop mutation.
• Trigger conflict workflow.
• lifecycle_state = conflict_pending
• User notified.
• No direct profile creation.

------------------------------------------------------------
2) PROFILE EXISTENCE CHECK
------------------------------------------------------------

If no profile exists:
→ Create Draft profile instance.

If profile exists:
→ Prepare update context.

------------------------------------------------------------
3) CONFLICT DETECTION (FIELD LEVEL)
------------------------------------------------------------

For each CORE field:

If existing_value ≠ new_value:

• If field is critical:
   - Create conflict_record
   - Do NOT auto-overwrite

• If non-critical:
   - Conflict record optional based on governance rules

------------------------------------------------------------
4) FIELD LOCK CHECK
------------------------------------------------------------

For each field:

If locked:
• Skip overwrite
• Create conflict_record if change attempted

------------------------------------------------------------
5) CORE FIELD MUTATION
------------------------------------------------------------

• Apply allowed updates
• Create profile_change_history entry per field

------------------------------------------------------------
6) CONTACT SYNC
------------------------------------------------------------

• Replace contact rows using sync logic
• Only one primary allowed
• Conflict if primary contact changed (critical)
• History entries created

------------------------------------------------------------
7) NORMALIZED ENTITY SYNC
------------------------------------------------------------

For each entity type:

• children
• education
• career
• addresses
• property
• horoscope
• legal_cases
• preferences

Rules:

• Insert new rows
• Update existing rows carefully
• Never silent delete without history
• Every change creates profile_change_history entry

------------------------------------------------------------
8) EXTENDED NARRATIVE SYNC
------------------------------------------------------------

Only narrative fields allowed.

No structured JSON allowed.

------------------------------------------------------------
9) LIFECYCLE TRANSITION
------------------------------------------------------------

If no conflict:
→ lifecycle_state = active

If conflict exists:
→ lifecycle_state = conflict_pending

------------------------------------------------------------
10) MUTATION LOG ENTRY (OPTIONAL)
------------------------------------------------------------

Insert row into mutation_log:

- profile_id
- intake_id
- mutation_status
- conflict_detected
- created_at

============================================================
7️⃣ STEP-6: INTAKE FINALIZATION
============================================================

After mutation completes:

biodata_intakes:

- intake_status = "applied"
- intake_locked = true
- matrimony_profile_id linked

------------------------------------------------------------

Rules:

• Intake cannot be edited.
• Intake cannot be deleted.
• Intake remains permanent audit artifact.

============================================================
8️⃣ CRITICAL FIELD CONFLICT POLICY
============================================================

If change detected in:

- full_name
- date_of_birth
- caste
- marital_status
- annual_income
- family_income
- primary_contact_number
- serious_intent_id

Then:

• Conflict record mandatory.
• Auto-overwrite forbidden.
• If serious_intent active:
   → Admin resolution required.
   → lifecycle_state = conflict_pending.

============================================================
9️⃣ EDIT RESTRICTION POLICY
============================================================

If profile lifecycle_state:

- intake_uploaded
- awaiting_user_approval
- approved_pending_mutation
- conflict_pending

Then:

• Manual edit screen restricted.
• User must resolve intake first.
• Direct edits disabled.

============================================================
🔟 AI CONFIDENCE POLICY (ENFORCED HERE)
============================================================

Critical fields:
→ Always manual confirm.

Non-critical:

confidence < 0.75
→ Mandatory review highlight

0.75–0.90
→ Recommended review

>0.90
→ Auto-fill allowed
→ But approval still mandatory

Profile NEVER auto-activates without explicit approval.

============================================================
1️⃣1️⃣ DATA LOSS ZERO-TOLERANCE RULE
============================================================

During mutation:

• Existing valid data must never be deleted silently.
• Unrelated fields must remain untouched.
• No partial updates allowed.
• History must always be written.

Silent data loss = CRITICAL FAILURE.

============================================================
1️⃣2️⃣ STRICT PROHIBITIONS
============================================================

❌ Direct update() in controller
❌ Skipping conflict detection
❌ Skipping field lock check
❌ Skipping history write
❌ Auto-activating without approval
❌ Auto-reactivating soft-deleted profile
❌ JSON blob storage for structured entities

============================================================
END OF PART 2
============================================================

Next:
PART 3 — NORMALIZED ENTITY STRUCTURES (Children, Career, Legal, Property, etc.)
############################################################
PHASE-5 FINAL BLUEPRINT — PART 3
FULLY NORMALIZED ENTITY STRUCTURES
############################################################

Structural Rule:
All repeatable / structured entities MUST be stored in
separate relational tables.

❌ No structured JSON blobs  
❌ No nested arrays in extended table  
❌ No hybrid storage  

All mutations governed by:
• ConflictDetectionService
• FieldLockService
• profile_change_history
• lifecycle_state rules

============================================================
1️⃣ profile_children
============================================================

Purpose:
Store structured child details.

-----------------------------------------
Columns:
-----------------------------------------

- id
- profile_id (FK → matrimony_profiles.id)
- age
- gender
- living_with (me / other_parent / guardian)
- created_at
- updated_at

-----------------------------------------
Rules:
-----------------------------------------

• Created only if children_exist = true
• Each child = separate row
• No JSON array storage
• No silent deletion — history entry required
• Update must create profile_change_history entry

-----------------------------------------
Conflict Trigger:
-----------------------------------------

If marital_status changes AND children exist
→ Conflict mandatory

============================================================
2️⃣ profile_education
============================================================

Purpose:
Store multi-education history.

-----------------------------------------
Columns:
-----------------------------------------

- id
- profile_id
- degree
- specialization
- university
- year_completed
- created_at
- updated_at

-----------------------------------------
Rules:
-----------------------------------------

• highest_education stored in CORE
• Detailed history here
• Multiple rows allowed
• Replace logic must use diff comparison
• Deletion requires history entry

============================================================
3️⃣ profile_career
============================================================

Purpose:
Store career history timeline.

-----------------------------------------
Columns:
-----------------------------------------

- id
- profile_id
- designation
- company
- location
- start_year
- end_year (nullable)
- is_current (boolean)
- created_at
- updated_at

-----------------------------------------
Rules:
-----------------------------------------

• occupation_title in CORE
• History stored here
• Only one is_current = true allowed
• Updates create change history entry

============================================================
4️⃣ profile_addresses
============================================================

Purpose:
Store native / current / work addresses.

-----------------------------------------
Columns:
-----------------------------------------

- id
- profile_id
- address_type (native / current / work)
- village
- taluka
- district
- state
- country
- pin_code
- created_at
- updated_at

-----------------------------------------
Rules:
-----------------------------------------

• Multiple address types allowed
• One row per type
• Work location IDs stored in CORE
• No silent overwrite

============================================================
5️⃣ profile_photos
============================================================

Purpose:
Store profile photos.

-----------------------------------------
Columns:
-----------------------------------------

- id
- profile_id
- file_path
- is_primary (boolean)
- uploaded_via (intake/manual)
- approved_status (pending/approved/rejected)
- watermark_detected (boolean)
- created_at
- updated_at

-----------------------------------------
Rules:
-----------------------------------------

• Minimum 1 primary required
• Only one primary allowed
• Intake photo NOT auto-approved
• User must confirm final primary photo
• Deletion requires history entry

-----------------------------------------
Lifecycle Impact:
-----------------------------------------

If no primary photo:
→ profile cannot be active

============================================================
6️⃣ profile_relatives
============================================================

Purpose:
Store structured relative data (non-contact).

-----------------------------------------
Columns:
-----------------------------------------

- id
- profile_id
- relation_type
- name
- occupation
- marital_status
- notes (optional)
- created_at
- updated_at

-----------------------------------------
Rules:
-----------------------------------------

• Contacts stored in profile_contacts
• Relatives here without phone
• Structured multi-row

============================================================
7️⃣ profile_property
============================================================

Purpose:
Store property & agriculture structure.

-----------------------------------------
Columns:
-----------------------------------------

- id
- profile_id
- property_type (house/flat/agricultural/commercial/other)
- property_ownership (self/joint/parental/rented)
- land_acres (nullable)
- land_type (bagayat/jirayat/mixed)
- irrigation_available (boolean)
- vehicle_type (nullable)
- additional_assets_note (nullable)
- created_at
- updated_at

-----------------------------------------
Rules:
-----------------------------------------

• Agriculture treated as property sub-structure
• No extended storage allowed
• Structured numeric fields required
• Update requires change history entry

============================================================
8️⃣ profile_horoscope_data
============================================================

Purpose:
Future horoscope compatibility engine support.

-----------------------------------------
Columns:
-----------------------------------------

- id
- profile_id
- rashi
- nakshatra
- charan
- gan
- nadi
- mangal_dosh_type
- devak
- kul
- gotra
- created_at
- updated_at

-----------------------------------------
Rules:
-----------------------------------------

• Dedicated normalized table
• Removed from extended layer permanently
• No JSON storage
• Optional but structured

============================================================
9️⃣ profile_preferences
============================================================

Purpose:
Store partner preference structure.

-----------------------------------------
Columns:
-----------------------------------------

- id
- profile_id
- preferred_city
- preferred_caste
- preferred_age_min
- preferred_age_max
- preferred_income_min
- preferred_income_max
- preferred_education
- created_at
- updated_at

-----------------------------------------
Rules:
-----------------------------------------

• Age stored as min/max (derived comparison)
• No age column in profile itself
• Used for future matching engine

============================================================
profile_international_status (OPTIONAL — NOT CORE)
============================================================

Purpose:
Optional international / NRI status. Not matching-mandatory.

-----------------------------------------
Columns:
-----------------------------------------

- profile_id
- nri_status (boolean)
- visa_status (nullable)
- passport_available (boolean)
- created_at
- updated_at

-----------------------------------------
Rules:
-----------------------------------------

• Not critical
• Not matching mandatory
• Optional structured table

============================================================
🔟 profile_legal_cases
============================================================

Purpose:
Store structured legal matters.

-----------------------------------------
Columns:
-----------------------------------------

- id
- profile_id
- case_type (divorce/separation/other)
- court_name
- case_number
- case_stage
- next_hearing_date
- notes
- active_status (boolean)
- created_at
- updated_at

-----------------------------------------
Rules:
-----------------------------------------

• Divorce details NOT nested in marital JSON
• Fully normalized
• Children handled separately
• Active legal case may trigger stricter governance

============================================================
1️⃣1️⃣ profile_contacts (REFERENCE REMINDER)
============================================================

Separate from relatives.

-----------------------------------------
Columns:
-----------------------------------------

- id
- profile_id
- relation_type
- contact_name
- phone_number
- is_primary
- visibility_rule
- verified_status
- created_at
- updated_at

-----------------------------------------
Critical:
-----------------------------------------

Primary contact number = critical field

Change requires:
• Conflict record
• Manual confirmation
• History entry

============================================================
1️⃣2️⃣ ENTITY MUTATION CONTRACT
============================================================

During intake apply:

1) CORE fields
2) CONTACT rows
3) CHILDREN
4) EDUCATION
5) CAREER
6) ADDRESSES
7) PROPERTY
8) HOROSCOPE
9) LEGAL CASES
10) PREFERENCES
11) EXTENDED NARRATIVE

Each mutation must:

• Compare old vs new
• Generate conflict if mismatch
• Respect locks
• Write profile_change_history
• Never silent delete
• Never partial update

============================================================
1️⃣3️⃣ STRICT PROHIBITIONS
============================================================

❌ No JSON array storage
❌ No structured data inside extended attributes
❌ No cascade delete
❌ No silent replacement of rows
❌ No mass truncate + reinsert without diff logic
❌ No update bypassing MutationService

============================================================
END OF PART 3
============================================================

Next:
PART 4 — DUPLICATE DETECTION, CONFLICT SYSTEM INTEGRATION & GOVERNANCE EDGE CASES
############################################################
PHASE-5 FINAL BLUEPRINT — PART 4
DUPLICATE DETECTION, CONFLICT INTEGRATION & GOVERNANCE EDGE CASES
############################################################

Dependency:
• Phase-4 ConflictRecord system
• Authority Order (Admin > User > Matchmaker > OCR/System)
• Field Locking System
• profile_change_history
• lifecycle_state governance

This section defines:

• Duplicate Detection Engine (Final Contract)
• Conflict Generation Policy
• Serious Intent Protection
• Lifecycle Escalation Rules
• Admin Override Flow
• Edge Case Governance

============================================================
1️⃣ DUPLICATE DETECTION — FINAL ENGINE CONTRACT
============================================================

Duplicate detection runs BEFORE profile creation
and BEFORE mutation.

------------------------------------------------------------
Priority Order (STRICT)
------------------------------------------------------------

1) verified_otp_mobile exact match  
   → SAME USER (no new profile allowed)

2) primary_contact_number exact match  
   → HARD DUPLICATE

3) full_name + date_of_birth + father_name + district_id + caste  
   → HIGH PROBABILITY DUPLICATE

4) serious_intent_id match  
   → HIGH-RISK DUPLICATE

------------------------------------------------------------

If duplicate detected:

• Do NOT create new profile
• Do NOT auto-merge
• Do NOT overwrite silently
• Trigger conflict workflow
• Show user message:
  "ही माहिती आधीच नोंदलेली आहे."

------------------------------------------------------------

If identical structured data:

→ No mutation
→ Show:
  "ही माहिती आधीच उपलब्ध आहे."

============================================================
2️⃣ DUPLICATE HANDLING SCENARIOS
============================================================

-----------------------------------------
CASE-1: Same User Re-Uploads Same Data
-----------------------------------------

Condition:
Structured JSON identical.

Action:
• No mutation
• No conflict
• Intake marked as redundant
• lifecycle_state unchanged

-----------------------------------------
CASE-2: Same User Uploads Updated Data
-----------------------------------------

Condition:
Same user, some fields changed.

Action:
• Run conflict detection
• Critical changes → conflict_record
• Non-critical → allowed update (governed)
• lifecycle_state may move to conflict_pending

-----------------------------------------
CASE-3: Different User, Same Primary Contact
-----------------------------------------

Condition:
primary_contact_number match.

Action:
• HARD DUPLICATE
• No new profile creation
• Admin review required
• lifecycle_state = conflict_pending

-----------------------------------------
CASE-4: High Probability Duplicate
-----------------------------------------

Condition:
Name + DOB + father + caste + district match.

Action:
• Flag probable duplicate
• Ask user for confirmation
• Admin review optional

============================================================
3️⃣ CONFLICT GENERATION POLICY
============================================================

Conflict must be generated if:

• Existing value ≠ new intake value
• Field is critical
• Field is locked
• Serious intent active
• Lifecycle not active

------------------------------------------------------------

ConflictRecord must store:

- profile_id
- field_name
- field_type (CORE / ENTITY / CONTACT)
- old_value
- new_value
- source (intake/manual/admin)
- resolution_status = pending
- created_at

------------------------------------------------------------

Conflict NEVER auto-resolved.
Conflict NEVER auto-overwritten.
Conflict NEVER deleted.

============================================================
4️⃣ CRITICAL FIELD ESCALATION MATRIX
============================================================

If change attempted in:

- full_name
- date_of_birth
- caste
- marital_status
- annual_income
- family_income
- primary_contact_number
- serious_intent_id

------------------------------------------------------------

Then:

IF serious_intent_id IS NULL:
→ User confirmation required
→ Conflict record created

IF serious_intent_id IS NOT NULL:
→ Admin resolution mandatory
→ lifecycle_state = conflict_pending

============================================================
5️⃣ SERIOUS INTENT PROTECTION
============================================================

If profile has serious_intent_id set:

The following changes ALWAYS trigger conflict:

• income change
• family_income change
• marital_status change
• primary_contact change
• caste change

------------------------------------------------------------

System must:

• Prevent silent update
• Require admin resolution
• Log admin decision in admin_audit_logs
• Create profile_change_history entry

============================================================
6️⃣ LIFECYCLE STATE TRANSITIONS (PHASE-5 EXTENDED)
============================================================

New lifecycle states introduced:

- intake_uploaded
- parsed
- awaiting_user_approval
- approved_pending_mutation
- conflict_pending
- active
- suspended
- archived
- archived_due_to_marriage

------------------------------------------------------------

Transition Rules:

New user flow:

intake_uploaded  
→ parsed  
→ awaiting_user_approval  
→ approved_pending_mutation  
→ active  

Existing profile with conflict:

approved_pending_mutation  
→ conflict_pending  
→ active (after resolution)

------------------------------------------------------------

Strict Rule:

Events (interest, unlock, etc.)
MUST NOT auto-change lifecycle.

============================================================
7️⃣ FIELD LOCK INTEGRATION
============================================================

Before mutation of ANY field:

Check profile_field_locks table.

If locked:

• Skip overwrite
• Generate conflict_record
• lifecycle_state = conflict_pending

Locked fields may be overridden ONLY by admin.

============================================================
8️⃣ SOFT DELETE SAFETY
============================================================

If lifecycle_state = archived:

• Intake cannot auto-reactivate
• User cannot auto-reactivate
• Admin approval required

If archived_due_to_marriage:

Reactivation requires:

• Reason submission
• OTP verification
• Admin approval
• profile_change_history entry

============================================================
9️⃣ CONTACT UNLOCK RESTRICTION
============================================================

Contact unlock allowed ONLY if:

lifecycle_state = active

If lifecycle_state:

- draft
- intake_uploaded
- awaiting_user_approval
- approved_pending_mutation
- conflict_pending
- suspended
- archived

→ Unlock forbidden

(Admin override allowed)

============================================================
🔟 ADMIN OVERRIDE FLOW
============================================================

When admin resolves conflict:

System must:

1) Update conflict_records.resolution_status
2) Log in admin_audit_logs
3) Update profile_change_history
4) Apply approved change
5) lifecycle_state → active

------------------------------------------------------------

Admin cannot:

• Delete conflict records
• Modify raw intake
• Skip history entry

============================================================
1️⃣1️⃣ GOVERNANCE EDGE CASES
============================================================

-----------------------------------------
A) Partial Mutation Failure
-----------------------------------------

If any entity mutation fails:

• Entire mutation rolled back
• No partial update allowed
• lifecycle_state unchanged

-----------------------------------------
B) Repeated Conflict Resolution
-----------------------------------------

Same conflict cannot be resolved twice.

System must enforce idempotency.

-----------------------------------------
C) Re-Upload During Conflict
-----------------------------------------

If lifecycle_state = conflict_pending:

• New intake allowed
• But mutation blocked until previous conflict resolved

-----------------------------------------
D) Manual Edit During Intake Pending
-----------------------------------------

If intake in:

- awaiting_user_approval
- approved_pending_mutation

Manual edit screen restricted.

-----------------------------------------
E) Re-Parse of Same Intake
-----------------------------------------

Allowed only before approval.
After approval → locked.

============================================================
1️⃣2️⃣ ZERO DATA LOSS GUARANTEE
============================================================

At no stage should:

• Old value be deleted without history
• Entity row be silently removed
• Extended narrative be overwritten without entry
• Conflict record be lost
• Intake be deleted

Silent data mutation = Critical Governance Failure.

============================================================
END OF PART 4
============================================================

Next:
PART 5 — AI CONFIDENCE SYSTEM, UNLOCK ENGINE BASE, & FUTURE MATCHING READINESS
############################################################
PHASE-5 FINAL BLUEPRINT — PART 5
AI CONFIDENCE SYSTEM, UNLOCK ENGINE BASE & FUTURE MATCHING READINESS
############################################################

Dependency:
• Phase-4 Governance
• Intake Pipeline (Part 2)
• Normalized Model (Part 3)
• Conflict System (Part 4)

This section defines:

• AI Confidence Contract (Final)
• Field Confirmation Rules
• Data Provenance Tracking
• Contact Unlock Base Architecture
• Unlock Rules Engine (DB-driven)
• Engagement Layer
• Subscription Base Tables
• Matching Readiness Constraints

============================================================
1️⃣ AI CONFIDENCE SYSTEM — FINAL CONTRACT
============================================================

AI output must include:

{
  core: { ... },
  contacts: [ ... ],
  children: [ ... ],
  education_history: [ ... ],
  ...
  confidence_map: {
    field_name: score (0.00–1.00)
  }
}

------------------------------------------------------------

Confidence Score Ranges:

confidence < 0.75  
→ Mandatory review highlight

0.75 ≤ confidence < 0.90  
→ Recommended review highlight

confidence ≥ 0.90  
→ Auto-fill allowed
→ But user approval still mandatory

------------------------------------------------------------

System must NEVER:

• Auto-activate profile
• Auto-approve intake
• Auto-overwrite critical field

User approval is always required.

============================================================
2️⃣ CRITICAL FIELD CONFIRMATION RULE
============================================================

Critical Fields:

- full_name
- date_of_birth
- gender
- religion
- caste
- sub_caste
- marital_status
- annual_income
- family_income
- primary_contact_number
- serious_intent_id

------------------------------------------------------------

Rules:

• Always highlighted
• Always require explicit confirmation
• Even if confidence = 0.99
• Never auto-apply silently

============================================================
3️⃣ DATA PROVENANCE TRACKING
============================================================

Each field mutation must track:

- source (ai_intake/manual/admin)
- changed_by
- changed_at

Stored via:

profile_change_history

------------------------------------------------------------

For extended narrative:

profile_extended_attributes must also track:

- source
- confidence_score
- approved_by_user

------------------------------------------------------------

No field can exist without traceability.

============================================================
4️⃣ AI ROUTING CONTRACT (STRICT)
============================================================

AI must NOT decide storage layer.

System routes fields strictly using:

• Canonical Field Registry
• Core vs Entity vs Contact vs Extended contract

AI only extracts.
System classifies.

============================================================
5️⃣ CONTACT UNLOCK ENGINE — BASE ARCHITECTURE
============================================================

Phase-5 does NOT fully implement monetization.

But base structure must exist.

------------------------------------------------------------
Unlock allowed ONLY if:

lifecycle_state = active

------------------------------------------------------------

contact_unlock_policy:

- unlock_mode (free / gamified / paid / hybrid)
- serious_intent_required (boolean)
- minimum_profile_completion_percentage
- waiting_period_hours
- admin_override_allowed
- max_unlocks_per_day
- max_unlocks_per_month

------------------------------------------------------------

System must validate unlock via policy engine
before returning contact data.

============================================================
6️⃣ CONTACT ACCESS LOG
============================================================

contact_access_log:

- viewer_user_id
- target_profile_id
- unlock_mode_used
- unlock_timestamp
- payment_reference (nullable)
- ad_session_id (nullable)
- referral_code (nullable)

------------------------------------------------------------

Rules:

• Contact details never returned in search API.
• Contact fetch must call unlock validation first.
• All access logged permanently.

============================================================
7️⃣ FLEXIBLE RULES ENGINE (DB-DRIVEN)
============================================================

unlock_rules_engine:

- rule_id
- rule_name
- condition_json
- reward_json
- active_status

------------------------------------------------------------

Example Rule:

{
 "condition": {
   "profile_completion": 90,
   "serious_intent": true
 },
 "reward": {
   "contact_unlock": 1
 }
}

------------------------------------------------------------

Rules must be:

• Database-driven
• Not hardcoded
• Version-safe
• Admin-configurable (future-ready)

============================================================
8️⃣ USER ENGAGEMENT LAYER
============================================================

user_engagement_stats:

- ads_viewed_count
- referrals_done
- profiles_completed
- daily_login_streak
- unlock_credits_available

------------------------------------------------------------

Unlock Credits:

• Internal virtual currency
• Deducted on contact unlock
• Logged in contact_access_log

============================================================
9️⃣ OFFER CAMPAIGN BASE
============================================================

offer_campaign:

- campaign_name
- start_date
- end_date
- unlock_bonus_count
- eligibility_condition
- active_status

Example:

"Join today and get 2 free unlocks"

------------------------------------------------------------

Offers must NOT bypass governance.
Offers must respect lifecycle rules.

============================================================
🔟 SUBSCRIPTION PLAN BASE TABLES
============================================================

subscription_plan:

- plan_name
- price
- unlock_limit
- validity_days
- priority_support (boolean)
- contact_view_unlimited (boolean)

user_subscription:

- user_id
- plan_id
- activated_at
- expires_at
- active_status

------------------------------------------------------------

Rules:

• Plan must not override lifecycle_state
• Plan must not bypass conflict_pending restriction
• Plan only affects unlock limits

============================================================
1️⃣1️⃣ MATCHING READINESS GUARANTEE
============================================================

Phase-5 must ensure:

• All searchable fields structured
• No age storage (DOB-based comparison)
• Income numeric
• Height canonical numeric
• Caste normalized
• Location structured via IDs
• Preferences stored relationally
• Horoscope normalized
• Legal cases normalized

------------------------------------------------------------

Matching engine must NOT:

• Parse extended JSON blobs
• Depend on narrative fields
• Depend on unstructured text

============================================================
1️⃣2️⃣ SECURITY CONTRACT
============================================================

Contact details must NEVER:

• Be included in search result API
• Be included in public profile API
• Be returned without unlock validation

API Contract:

GET /api/profile/{id}/contact

→ Validate lifecycle_state
→ Validate unlock rules
→ Log access
→ Return contact

============================================================
1️⃣3️⃣ SYSTEM NEVER DOES
============================================================

❌ Auto-activate profile
❌ Auto-resolve conflict
❌ Auto-overwrite critical field
❌ Auto-reactivate archived profile
❌ Store structured arrays as JSON blob
❌ Skip history write
❌ Skip conflict detection
❌ Skip duplicate detection

============================================================
1️⃣4️⃣ FINAL PHASE-5 GUARANTEE
============================================================

Raw Marathi Biodata
→ AI Structured Parsing
→ User Review
→ Conflict-Safe Mutation
→ Fully Normalized Profile
→ Duplicate-Safe
→ Lifecycle-Governed
→ Unlock-Controlled
→ Matching-Ready
→ Audit-Protected

Zero data loss.
Zero silent mutation.
Zero JSON blob structure.
Zero uncontrolled overwrite.

============================================================
END OF PART 5
============================================================

Next:
ATOMIC DAY-WISE IMPLEMENTATION PLAN
