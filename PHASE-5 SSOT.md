############################################################
PHASE-5 SSOT (DRAFT v1.0) — PART 1
AUTHORITY, SCOPE LOCK, ARCHITECTURE FREEZE
############################################################

Document Type: SINGLE SOURCE OF TRUTH  
Based On: PHASE-5 FINAL BLUEPRINT.md :contentReference[oaicite:0]{index=0}  
Dependency: PHASE-4_SSOT_v1.1.md :contentReference[oaicite:1]{index=1}  
Status: PRE-IMPLEMENTATION LOCK  

============================================================
0️⃣ SSOT SUPREMACY DECLARATION
============================================================

• This PHASE-5 SSOT overrides:
  - Draft blueprint documents
  - Discussion notes
  - Verbal clarifications
  - Architectural assumptions

• PHASE-4 governance laws remain ACTIVE and NON-NEGOTIABLE.

• Phase-5 cannot:
  - Modify OCR governance from Phase-4
  - Modify conflict architecture
  - Modify authority order
  - Introduce hybrid data storage
  - Introduce JSON blob storage
  - Break lifecycle discipline

============================================================
1️⃣ PHASE-5 SCOPE (STRICT & FINAL)
============================================================

Phase-5 implements:

Biodata Intake  
→ AI Structured Parsing  
→ Structured Preview  
→ Explicit User Approval  
→ Conflict-Safe Mutation  
→ Fully Normalized Profile  

It does NOT implement:

❌ AI Matching  
❌ Ranking Engine  
❌ Scoring  
❌ WhatsApp Automation  
❌ Payment Execution  
❌ Matchmaker Network  
❌ Field Redefinition  
❌ Data Migration  

Phase-5 is STRUCTURAL + GOVERNED MUTATION layer only.

============================================================
2️⃣ CORE DESIGN PRINCIPLES (NON-NEGOTIABLE)
============================================================

1) Zero Data Loss  
2) No Silent Overwrite  
3) No Direct update() Calls  
4) No JSON Blob Storage  
5) No Hybrid Model  
6) No Duplicate Profile Creation  
7) Age Never Stored  
8) Intake Immutable  
9) Conflict Mandatory on Critical Changes  
10) All mutations pass through MutationService  

============================================================
3️⃣ FINAL ARCHITECTURE MODEL (FROZEN)
============================================================

Core Table:
- matrimony_profiles

Normalized Relational Entities:
- profile_contacts
- profile_children
- profile_education
- profile_career
- profile_addresses
- profile_photos
- profile_relatives
- profile_visibility_settings
- profile_property_summary
- profile_property_assets
- profile_horoscope_data
- profile_preferences
- profile_legal_cases

Narrative Only:
- profile_extended_attributes

Governance:
- conflict_records
- profile_field_locks
- profile_change_history
- admin_audit_logs

Intake:
- biodata_intakes

Unlock & Engagement Layer:
- contact_unlock_policy
- contact_access_log
- unlock_rules_engine
- user_engagement_stats
- subscription_plan
- user_subscription

Optional:
- mutation_log

❌ JSON arrays prohibited  
❌ Extended table cannot store structured entities  

============================================================
4️⃣ CORE FIELD FREEZE
============================================================

PERSONAL IDENTITY:
- full_name
- gender
- date_of_birth
- height_cm (canonical integer)
- weight_kg
- marital_status
- religion
- caste
- sub_caste
- complexion
- physical_build
- blood_group

EDUCATION & CAREER SNAPSHOT:
- highest_education
- specialization
- occupation_title
- company_name
- annual_income
- income_currency (default INR)
- family_income

LOCATION IDS:
- country_id
- state_id
- district_id
- taluka_id
- city_id
- work_city_id
- work_state_id

FAMILY CORE:
- father_name
- father_occupation
- mother_name
- mother_occupation
- brothers_count
- sisters_count
- family_type

------------------------------------------------------------
AGE RULE (PERMANENT)
------------------------------------------------------------

• age column MUST NOT exist
• age derived at runtime from date_of_birth
• Any stored age = SSOT violation

============================================================
5️⃣ CRITICAL FIELD CONTRACT
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

Rules:

• Always require manual confirmation  
• Never auto-overwrite  
• Conflict record mandatory  
• If serious_intent active → Admin resolution required  

============================================================
6️⃣ CONTACT STRUCTURE RULE
============================================================

Table: profile_contacts

Rules:

• Multi-row allowed  
• Only ONE primary contact allowed  
• Primary contact = critical field  
• OTP mobile stored in users.verification_mobile  
• No contact data in extended table  

============================================================
7️⃣ EXTENDED ATTRIBUTE RESTRICTION
============================================================

profile_extended_attributes allowed ONLY for:

- narrative_about_me
- narrative_expectations
- additional_notes

STRICTLY PROHIBITED inside extended:

❌ children  
❌ property  
❌ horoscope  
❌ legal cases  
❌ education history  
❌ career history  
❌ structured arrays  

============================================================
8️⃣ HEIGHT STORAGE FREEZE
============================================================

• height_cm = single canonical storage  
• No duplicate storage (cm + ft)  
• No feet-inch numeric storage  
• UI derives display format  

============================================================
9️⃣ UNIFIED HISTORY SYSTEM
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
• Applies to CORE + NESTED + CONTACT + EXTENDED  
• Every mutation must generate history entry  

============================================================
🔟 DUPLICATE DETECTION CONTRACT
============================================================

Priority:

1) verified_otp_mobile exact → SAME USER  
2) primary_contact_number exact → HARD DUPLICATE  
3) full_name + DOB + father_name + district_id + caste → HIGH PROBABILITY  
4) serious_intent_id match → HIGH-RISK  

If duplicate detected:

• No profile creation  
• No silent merge  
• Trigger conflict workflow  
• lifecycle_state = conflict_pending  
############################################################
PHASE-5 SSOT ADDENDUM
DUPLICATE DETECTION REFINEMENT — SHARED CONTACT NUMBER CASE
############################################################

Context:

In real-world matrimonial systems, multiple profiles
(e.g., siblings) may share the same parent contact number.

Therefore:

primary_contact_number ALONE must NOT be treated as
a HARD DUPLICATE trigger.

============================================================
UPDATED DUPLICATE PRIORITY ORDER (REFINED)
============================================================

1) verified_otp_mobile exact match
   → SAME USER (strict identity match)

2) primary_contact_number + full_name + date_of_birth exact match
   → HARD DUPLICATE

3) full_name + date_of_birth + father_name + district_id + caste
   → HIGH PROBABILITY DUPLICATE

4) serious_intent_id exact match
   → HIGH-RISK DUPLICATE

============================================================
RULE CLARIFICATION
============================================================

• Same primary_contact_number across multiple profiles is ALLOWED.
• Sibling profiles are valid use cases.
• Parent-managed accounts are valid.
• Shared contact number ≠ identity duplication.

System must NOT block profile creation solely
based on shared contact number.

However:

If primary_contact_number matches AND
full_name + date_of_birth also match,
then treat as HARD DUPLICATE.

============================================================
STRICT PROHIBITIONS
============================================================

❌ Do not mark HARD DUPLICATE based only on contact number.
❌ Do not auto-block sibling profiles.
❌ Do not auto-merge based on contact number alone.

============================================================
END OF REFINEMENT
============================================================

============================================================
1️⃣1️⃣ LIFECYCLE + CONTACT UNLOCK RULE
============================================================

Contact unlock allowed ONLY if:

lifecycle_state = active

Not allowed when:

- draft
- intake_uploaded
- approved_pending_mutation
- conflict_pending
- suspended
- archived

Admin override logged in audit.

============================================================
1️⃣2️⃣ SOFT DELETE REACTIVATION
============================================================

If lifecycle_state = archived_due_to_marriage:

Reactivation requires:

• User request  
• Reason  
• OTP verification  
• Admin approval  
• profile_change_history entry  

Auto-reactivation = forbidden.

============================================================
END OF PART 1
============================================================

Next:
PART 2 — INTAKE PIPELINE GOVERNED FLOW (Upload → Parse → Preview → Approval → Mutation)
############################################################
PHASE-5 SSOT (DRAFT v1.0) — PART 2
INTAKE PIPELINE — GOVERNED FLOW
############################################################

Based On:
- PHASE-5 FINAL BLUEPRINT.md :contentReference[oaicite:0]{index=0}
- PHASE-4_SSOT_v1.1.md :contentReference[oaicite:1]{index=1}

This section defines the ONLY allowed execution flow
for Biodata → Profile Mutation.

Direct profile mutation is permanently forbidden.

All changes MUST pass through MutationService.

============================================================
1️⃣ OVERALL GOVERNED FLOW (LOCKED)
============================================================

STEP 1  → Intake Record Creation  
STEP 2  → AI Structured Parsing  
STEP 3  → User Preview & Manual Review  
STEP 4  → Explicit User Approval  
STEP 5  → Conflict-Safe Mutation  
STEP 6  → Intake Finalization & Lock  

At no stage may MatrimonyProfile be directly updated.

============================================================
2️⃣ STEP 1 — BIODATA INTAKE RECORD CREATION
============================================================

Trigger:
User uploads:

• Image
• PDF
• OR pastes raw text

------------------------------------------------------------
Table: biodata_intakes
------------------------------------------------------------

Mandatory Fields:

- id
- uploaded_by (user_id)
- file_path (nullable)
- original_filename (nullable)
- raw_ocr_text
- intake_status = "uploaded"
- parse_status = "pending"
- approved_by_user = false
- intake_locked = false
- snapshot_schema_version (integer)
- created_at
- updated_at

------------------------------------------------------------
STRICT RULES
------------------------------------------------------------

• raw_ocr_text MUST NEVER be modified.
• Intake record is immutable at RAW level.
• Intake cannot be deleted.
• Intake cannot be edited.
• Every upload creates NEW intake record.
• Intake never overwrites older intake.

------------------------------------------------------------
Lifecycle Impact
------------------------------------------------------------

If no profile exists:
→ lifecycle_state = intake_uploaded

If profile exists:
→ lifecycle_state unchanged.

============================================================
3️⃣ STEP 2 — AI STRUCTURED PARSING
============================================================

AI produces structured JSON:

{
  core: {...},
  contacts: [...],
  children: [...],
  education_history: [...],
  career_history: [...],
  addresses: {...},
  property_summary: {...},
  property_assets: [...],
  horoscope: {...},
  legal_cases: [...],
  preferences: {...},
  extended_narrative: {...},
  confidence_map: { field_name: score }
}

------------------------------------------------------------
Storage
------------------------------------------------------------

biodata_intakes.parsed_json  
biodata_intakes.parse_status = "parsed"

------------------------------------------------------------
STRICT RULES
------------------------------------------------------------

• No profile mutation.
• No conflict generation.
• No lifecycle change (except new-user parsed state).
• parsed_json may be overwritten ONLY by re-parse cycle.
• raw_ocr_text never touched.

============================================================
4️⃣ STEP 3 — PREVIEW SCREEN (MANDATORY GATE)
============================================================

User must see structured preview divided into:

• Core
• Contacts
• Children
• Education
• Career
• Addresses
• Property
• Horoscope
• Legal Cases
• Preferences
• Narrative

------------------------------------------------------------
AI Confidence Enforcement
------------------------------------------------------------

confidence < 0.75  
→ Mandatory review highlight  

0.75 – 0.90  
→ Recommended review  

> 0.90  
→ Normal display  

Critical fields ALWAYS highlighted regardless of confidence.

------------------------------------------------------------
User Allowed Actions
------------------------------------------------------------

• Edit values
• Delete incorrect rows
• Add rows
• Add missing contacts
• Change primary contact

------------------------------------------------------------
IMPORTANT
------------------------------------------------------------

Preview modifies ONLY in-memory snapshot.

Profile table remains untouched.

============================================================
5️⃣ STEP 4 — USER APPROVAL SNAPSHOT
============================================================

User clicks:
[Approve & Apply to Profile]

System stores in biodata_intakes:

- approved_by_user = true
- approved_at
- approval_snapshot_json
- intake_status = "approved"

------------------------------------------------------------
Rules
------------------------------------------------------------

• approval_snapshot_json immutable.
• After approval, preview cannot be edited.
• New intake required for changes.
• No mutation yet executed.

------------------------------------------------------------
Lifecycle
------------------------------------------------------------

New profile:
→ lifecycle_state = approved_pending_mutation

Existing profile:
→ lifecycle_state remains active until mutation step.

============================================================
6️⃣ STEP 5 — SAFE MUTATION PIPELINE (CRITICAL)
============================================================

MutationService MUST execute in strict order.

------------------------------------------------------------
1) DUPLICATE DETECTION
------------------------------------------------------------

Run duplicate engine.

If duplicate detected:

• Stop mutation
• Create conflict_record
• lifecycle_state = conflict_pending
• No profile creation

------------------------------------------------------------
2) PROFILE EXISTENCE CHECK
------------------------------------------------------------

If no profile:
→ Create Draft profile

If profile exists:
→ Prepare update context

------------------------------------------------------------
3) FIELD-LEVEL CONFLICT DETECTION
------------------------------------------------------------

For each CORE field:

If existing_value ≠ new_value:

IF critical:
→ Create conflict_record
→ Do NOT auto-overwrite

IF non-critical:
→ Governance rule decides

------------------------------------------------------------
4) FIELD LOCK CHECK
------------------------------------------------------------

If field locked:
→ Skip overwrite
→ Create conflict_record

------------------------------------------------------------
5) CORE FIELD APPLY
------------------------------------------------------------

• Apply allowed changes
• Write profile_change_history entry per field

------------------------------------------------------------
6) CONTACT SYNC
------------------------------------------------------------

• Replace using diff logic
• Only one primary allowed
• Primary change → conflict (critical)
• Write history entries

------------------------------------------------------------
7) NORMALIZED ENTITY SYNC
------------------------------------------------------------

For:

- children
- education
- career
- addresses
- property_summary
- property_assets
- horoscope
- legal_cases
- preferences

Rules:

• Compare old vs new
• Insert / update carefully
• No silent delete
• History mandatory

------------------------------------------------------------
8) EXTENDED NARRATIVE SYNC
------------------------------------------------------------

• Narrative only
• No structured storage
• History mandatory

------------------------------------------------------------
9) LIFECYCLE TRANSITION
------------------------------------------------------------

If no conflicts:
→ lifecycle_state = active

If conflicts:
→ lifecycle_state = conflict_pending

------------------------------------------------------------
10) MUTATION LOG (OPTIONAL)
------------------------------------------------------------

mutation_log:

- profile_id
- intake_id
- mutation_status
- conflict_detected
- created_at

============================================================
7️⃣ STEP 6 — INTAKE FINALIZATION
============================================================

After mutation completes:

biodata_intakes:

- intake_status = "applied"
- intake_locked = true
- matrimony_profile_id linked

------------------------------------------------------------
Rules
------------------------------------------------------------

• Intake permanently locked.
• Cannot be edited.
• Cannot be deleted.
• Remains audit artifact forever.

============================================================
8️⃣ CRITICAL FIELD ESCALATION
============================================================

If change in:

- full_name
- date_of_birth
- caste
- marital_status
- annual_income
- family_income
- primary_contact_number
- serious_intent_id

Then:

• Conflict mandatory
• Auto-overwrite forbidden
• If serious_intent active:
   → Admin resolution required
   → lifecycle_state = conflict_pending

============================================================
9️⃣ EDIT RESTRICTION RULE
============================================================

If lifecycle_state:

- intake_uploaded
- awaiting_user_approval
- approved_pending_mutation
- conflict_pending

Then:

• Manual edit screen disabled
• Intake resolution required first

============================================================
🔟 ZERO DATA LOSS GUARANTEE
============================================================

During mutation:

• No unrelated field may be modified.
• No silent delete.
• No partial updates.
• History must always exist.

Silent mutation = CRITICAL FAILURE.

============================================================
END OF PART 2
============================================================
############################################################
PHASE-5 SSOT ADDENDUM
BULK BIODATA INTAKE & MASS PROFILE CREATION CONTRACT
############################################################

Status: OFFICIAL EXTENSION
Applies To: Phase-5 Intake Pipeline
Scope: Bulk ingestion (10–500 biodata at a time)

============================================================
1️⃣ PURPOSE
============================================================

Phase-5 supports governed bulk biodata ingestion for:

• Large community uploads
• Offline biodata drives
• Marriage bureau datasets
• CSV / Folder-based intake batches

Bulk mode is NOT a shortcut.

All governance laws remain ACTIVE:
• No silent overwrite
• No direct profile mutation
• No conflict bypass
• No lifecycle bypass
• No JSON blob storage
• No history skipping

Bulk = Multi-Intake Orchestration.
Not Multi-Profile Direct Insert.

============================================================
2️⃣ BULK INTAKE CREATION MODEL
============================================================

New Table: bulk_intake_batches

Columns:

- id
- uploaded_by (admin_id)
- total_files
- total_intakes_created
- total_profiles_created
- total_conflicts_generated
- batch_status (pending/processing/completed/failed)
- ai_cost_estimate
- ai_cost_actual
- created_at
- completed_at

Rules:

• Each biodata still creates ONE biodata_intakes record.
• No profile created at this stage.
• Bulk batch is orchestration container only.
• Intake RAW remains immutable.

============================================================
3️⃣ BULK PARSING CONTRACT
============================================================

AI cost optimization rules:

• Same-format structured biodata may use:
  - Template-based parsing
  - Cached extraction pattern reuse
  - Partial LLM fallback only for ambiguous lines

• Confidence threshold policy remains same.
• Each intake stores independent parsed_json.
• No cross-intake data merging allowed.

Important:

Bulk parsing must NEVER:

❌ Merge multiple biodata into single profile
❌ Share identity-level data across intakes
❌ Skip confidence map
❌ Skip RAW storage

============================================================
4️⃣ BULK APPROVAL MODEL
============================================================

Two Modes Allowed:

MODE-A: Individual Approval
- Each intake manually reviewed.
- Follows standard pipeline.

MODE-B: Assisted Bulk Approval (ONLY for NEW PROFILES)
Conditions:

• No existing profile match
• No duplicate detected
• All critical fields present
• All confidence ≥ 0.90
• No serious_intent_id provided
• No locked fields involved

If all above TRUE:

→ System may auto-mark:
   approved_by_user = true
   intake_status = approved

Otherwise:
→ Manual review mandatory.

Bulk auto-approval NEVER allowed for existing profiles.

============================================================
5️⃣ BULK MUTATION EXECUTION ENGINE
============================================================

Bulk mutation must:

• Execute per-intake MutationService call
• Each intake processed in DB transaction
• Failure of one intake must NOT stop entire batch
• Batch status updated progressively

Execution Rules:

For each intake:

1) Duplicate detection
2) Profile existence check
3) Conflict detection
4) Field lock check
5) Core apply
6) Contact sync
7) Entity sync
8) History write
9) Lifecycle transition
10) Intake finalization

No parallel write on same profile allowed.

Concurrency rule:

• If two intakes target same profile:
  - Queue sequentially
  - Lock profile row during mutation

============================================================
6️⃣ BULK DUPLICATE STRATEGY
============================================================

Within-batch duplicate detection must run BEFORE mutation.

Steps:

• Compare primary_contact_number across batch
• Compare full_name + DOB within batch
• Flag intra-batch duplicates

If intra-batch duplicate found:

• Do NOT create two profiles
• Create conflict_record
• Mark one intake as conflict_pending

============================================================
7️⃣ BULK FAILURE POLICY
============================================================

If intake mutation fails:

• intake_status = failed
• lifecycle unchanged
• error logged in mutation_log
• batch continues

Batch status:

pending → processing → completed/failed

Batch fails ONLY if:

• System-level DB failure
• Transaction engine failure

Individual intake failure ≠ batch failure.

============================================================
8️⃣ BULK COST GOVERNANCE
============================================================

AI cost tracking required:

• ai_cost_estimate calculated before parse
• ai_cost_actual stored after parse

Policy:

• If estimated cost > admin_threshold
   → require admin confirmation

Cost tracking must NEVER:

❌ Skip parsing
❌ Downgrade confidence policy
❌ Skip preview stage (if required)

============================================================
9️⃣ BULK LIFECYCLE RULE
============================================================

New profile flow (bulk):

intake_uploaded
→ parsed
→ approved_pending_mutation
→ active

Conflict case:

approved_pending_mutation
→ conflict_pending

Bulk mode does NOT auto-activate without full governance.

============================================================
🔟 STRICT PROHIBITIONS
============================================================

❌ Direct bulk INSERT into matrimony_profiles
❌ Mass truncate + insert entities
❌ Skipping MutationService
❌ Shared transaction for entire batch
❌ Ignoring duplicate detection
❌ Auto-overwriting critical fields
❌ Parallel mutation on same profile
❌ Skipping profile_change_history

============================================================
END OF BULK CONTRACT
============================================================


Next:
PART 3 — FULLY NORMALIZED ENTITY CONTRACT (Children, Education, Career, Legal, Property, Horoscope, Preferences)
############################################################
PHASE-5 SSOT (DRAFT v1.0) — PART 3
FULLY NORMALIZED ENTITY CONTRACT
############################################################

Based On:
- PHASE-5 FINAL BLUEPRINT.md :contentReference[oaicite:0]{index=0}
- PHASE-4_SSOT_v1.1.md :contentReference[oaicite:1]{index=1}

This section defines the ONLY allowed relational structure
for all repeatable and structured entities.

❌ JSON arrays prohibited  
❌ Structured data inside extended table prohibited  
❌ Hybrid storage prohibited  

All entity mutations must:
• Pass through MutationService  
• Respect conflict detection  
• Respect field locks  
• Write profile_change_history  

============================================================
1️⃣ profile_children
============================================================

Purpose:
Store structured child information.

Columns:

- id
- profile_id (FK → matrimony_profiles.id)
- age
- gender
- living_with (me / other_parent / guardian)
- created_at
- updated_at

Rules:

• One row per child.
• No JSON storage.
• No silent deletion.
• Every update must create history entry.
• If marital_status changes AND children exist → conflict mandatory.

============================================================
2️⃣ profile_education
============================================================

Purpose:
Store full education history.

Columns:

- id
- profile_id
- degree
- specialization
- university
- year_completed
- created_at
- updated_at

Rules:

• highest_education stored in CORE table.
• Multiple rows allowed.
• Diff comparison required during sync.
• No mass truncate + reinsert.
• Deletion requires history entry.

============================================================
3️⃣ profile_career
============================================================

Purpose:
Store career timeline.

Columns:

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

Rules:

• occupation_title stored in CORE.
• Only one is_current = true allowed.
• History entry required for every change.
• No silent replacement.

============================================================
4️⃣ profile_addresses
============================================================

Purpose:
Store structured addresses.

Columns:

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

Rules:

• One row per address_type.
• Work city/state IDs also exist in CORE.
• No silent overwrite.
• Changes must generate history entries.

============================================================
5️⃣ profile_photos
============================================================

Purpose:
Store profile photos.

Columns:

- id
- profile_id
- file_path
- is_primary (boolean)
- uploaded_via (intake/manual)
- approved_status (pending/approved/rejected)
- watermark_detected (boolean)
- created_at
- updated_at

Rules:

• Only one primary photo allowed.
• At least one primary photo required for lifecycle_state = active.
• Intake photos NOT auto-approved.
• Deletion must create history entry.
• No silent primary switch.

Lifecycle Impact:

If no primary photo:
→ lifecycle_state cannot become active.

============================================================
6️⃣ profile_relatives
============================================================

Purpose:
Store non-contact relatives.

Columns:

- id
- profile_id
- relation_type
- name
- occupation
- marital_status
- notes (nullable)
- created_at
- updated_at

Rules:

• No phone numbers here.
• Contacts must use profile_contacts.
• Structured multi-row.
• History required for updates.

============================================================
7️⃣ profile_property_summary (ONE-TO-ONE)
============================================================

Purpose:
One row per profile — property summary.

Columns:

- id
- profile_id (unique)
- owns_house (boolean)
- owns_flat (boolean)
- owns_agriculture (boolean)
- total_land_acres (nullable)
- annual_agri_income (nullable)
- summary_notes (nullable)
- created_at
- updated_at

============================================================
8️⃣ profile_property_assets (MULTI-ROW)
============================================================

Purpose:
Structured assets (vehicle/plot/shop/other) per profile.

Columns:

- id
- profile_id
- asset_type (vehicle/plot/shop/other)
- location (nullable)
- estimated_value (nullable)
- ownership_type (self/joint/parental)
- created_at
- updated_at

Rules:

• Summary row mandatory if property data exists
• Assets optional multi-row
• No structured JSON allowed

============================================================
9️⃣ profile_horoscope_data
============================================================

Purpose:
Store structured horoscope data.

Columns:

- id
- profile_id (unique)
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

Rules:

• Fully normalized.
• No horoscope JSON allowed.
• Optional but structured.
• History mandatory.

============================================================
🔟 profile_preferences
============================================================

Purpose:
Store partner preference structure.

Columns:

- id
- profile_id (unique)
- preferred_city
- preferred_caste
- preferred_age_min
- preferred_age_max
- preferred_income_min
- preferred_income_max
- preferred_education
- created_at
- updated_at

Rules:

• Age stored as min/max only.
• Profile table must not store age.
• Used by future matching engine.
• Structured, no JSON.

============================================================
1️⃣1️⃣ profile_extended_attributes (ONE-TO-ONE)
============================================================

Purpose:
Narrative-only fields. One row per profile.

Columns:

- id
- profile_id (unique)
- narrative_about_me
- narrative_expectations
- additional_notes
- created_at
- updated_at

Rules:

• One row per profile
• No structured JSON
• No key-value dynamic storage
• History mandatory

============================================================
1️⃣2️⃣ profile_legal_cases
============================================================

Purpose:
Store structured legal cases.

Columns:

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

Rules:

• Divorce not stored in marital JSON.
• Fully normalized.
• Children handled separately.
• Active legal case may trigger stricter governance.
• History mandatory.

============================================================
1️⃣3️⃣ profile_contacts (REFERENCE CONTRACT)
============================================================

Separate from relatives.

Columns:

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

Rules:

• Only one primary allowed.
• Primary_contact_number is critical.
• Change requires:
   - Conflict record
   - Manual confirmation
   - History entry
• No contact in extended.

============================================================
1️⃣4️⃣ ENTITY SYNC DISCIPLINE (MANDATORY)
============================================================

During intake mutation:

Order:

1) CORE fields  
2) CONTACTS  
3) CHILDREN  
4) EDUCATION  
5) CAREER  
6) ADDRESSES  
7) PROPERTY SUMMARY  
8) PROPERTY ASSETS  
9) HOROSCOPE  
10) LEGAL CASES  
11) PREFERENCES  
12) EXTENDED NARRATIVE  

For every entity:

• Compare old vs new.
• Insert new rows.
• Update changed rows.
• Soft-delete only with history.
• Never truncate entire table.
• Never mass-reinsert without diff logic.

============================================================
1️⃣5️⃣ STRICT PROHIBITIONS
============================================================

❌ JSON blob storage  
❌ Nested arrays inside extended  
❌ Cascade delete  
❌ Silent overwrite  
❌ Direct DB update bypassing MutationService  
❌ Mass delete + bulk insert shortcut  

============================================================
END OF PART 3
============================================================

Next:
PART 4 — DUPLICATE DETECTION, CONFLICT INTEGRATION & LIFECYCLE ESCALATION MATRIX
############################################################
PHASE-5 SSOT (DRAFT v1.0) — PART 4
DUPLICATE DETECTION, CONFLICT INTEGRATION & LIFECYCLE ESCALATION
############################################################

Based On:
- PHASE-5 FINAL BLUEPRINT.md :contentReference[oaicite:0]{index=0}
- PHASE-4_SSOT_v1.1.md :contentReference[oaicite:1]{index=1}

This section governs:

• Duplicate Detection Engine  
• Conflict Record Generation  
• Critical Field Escalation  
• Serious Intent Protection  
• Lifecycle State Transitions  
• Admin Override Discipline  
• Edge Case Governance  

This layer is NON-OPTIONAL and must execute
before any mutation is applied.

============================================================
1️⃣ DUPLICATE DETECTION — ENGINE CONTRACT (FINAL)
============================================================

Duplicate detection must run:

• Before profile creation  
• Before profile mutation  
• Before serious_intent linking  

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
If Duplicate Detected
------------------------------------------------------------

System must:

• Stop mutation immediately  
• Not create new profile  
• Not auto-merge  
• Not auto-overwrite  
• Create conflict_record  
• lifecycle_state = conflict_pending  
• Notify user  

Message (UI level):
"ही माहिती आधीच प्रणालीमध्ये उपलब्ध आहे."

============================================================
2️⃣ DUPLICATE HANDLING SCENARIOS
============================================================

CASE A — Same User, Same Data

Condition:
Structured snapshot identical.

Action:
• No mutation  
• Intake marked redundant  
• lifecycle_state unchanged  

------------------------------------------------------------

CASE B — Same User, Modified Data

Condition:
Some fields changed.

Action:
• Run conflict detection  
• Critical changes → conflict_record  
• Non-critical → governed update  
• lifecycle_state may change to conflict_pending  

------------------------------------------------------------

CASE C — Different User, Same Primary Contact

Condition:
primary_contact_number match.

Action:
• HARD DUPLICATE  
• No profile creation  
• Admin review mandatory  
• lifecycle_state = conflict_pending  

------------------------------------------------------------

CASE D — High Probability Duplicate

Condition:
Name + DOB + father + caste + district match.

Action:
• Flag as probable duplicate  
• Require confirmation  
• Admin review optional  

============================================================
3️⃣ CONFLICT RECORD GENERATION POLICY
============================================================

Conflict MUST be generated if:

• Existing value ≠ intake value  
• Field is critical  
• Field is locked  
• serious_intent active  
• lifecycle_state not active  

------------------------------------------------------------
ConflictRecord Structure
------------------------------------------------------------

- profile_id
- field_name
- field_type (CORE / CONTACT / ENTITY)
- entity_id (nullable)
- old_value
- new_value
- source (intake/manual/admin)
- resolution_status = pending
- created_at

------------------------------------------------------------
Rules
------------------------------------------------------------

• Conflict NEVER auto-resolved  
• Conflict NEVER auto-overwritten  
• Conflict NEVER deleted  
• Only resolution_status may change  

============================================================
============================================================
4️⃣ CRITICAL FIELD ESCALATION MATRIX (REVISED)
============================================================

IDENTITY-CRITICAL FIELDS:

- full_name
- date_of_birth
- gender
- caste
- sub_caste
- marital_status
- primary_contact_number
- serious_intent_id

------------------------------------------------------------
Escalation Logic
------------------------------------------------------------

IF serious_intent_id IS NULL:

→ User confirmation required  
→ Conflict record created  

IF serious_intent_id IS NOT NULL:

→ Admin resolution mandatory  
→ lifecycle_state = conflict_pending  
→ No update applied until admin decision  

------------------------------------------------------------
DYNAMIC FIELDS (NO ESCALATION)
------------------------------------------------------------

The following fields are ALWAYS allowed to update
(with history), regardless of serious_intent:

- annual_income
- family_income
- occupation_title
- company_name
- work_city_id
- work_state_id

Rules:

• No conflict required
• lifecycle_state unchanged
• profile_change_history entry mandatory
• No silent overwrite allowed


============================================================
############################################################
PHASE-5 SSOT ADDENDUM
DYNAMIC FIELD GOVERNANCE — TRUST-SAFE MUTATION POLICY
############################################################

Context:

Certain fields change naturally over time
(e.g., salary, company, job location).

System must allow legitimate updates
without unnecessarily degrading trust
or forcing lifecycle to conflict_pending.

============================================================
FIELD CLASSIFICATION (TRUST MODEL)
============================================================

CATEGORY-A: STABLE IDENTITY FIELDS (Strict Conflict)

- full_name
- date_of_birth
- gender
- caste
- sub_caste
- marital_status

Rule:
• Change → conflict mandatory
• Auto-overwrite forbidden

------------------------------------------------------------

CATEGORY-B: SEMI-DYNAMIC FIELDS (Controlled Auto-Update)

- annual_income
- family_income
- occupation_title
- company_name
- work_city_id
- work_state_id

Default Rule:
• Change allowed without lifecycle escalation
• profile_change_history entry mandatory
• No silent overwrite (history required)
• Conflict NOT required by default

------------------------------------------------------------

------------------------------------------------------------
CATEGORY-C: HIGH-SENSITIVITY UNDER serious_intent_id
------------------------------------------------------------

If serious_intent_id IS NOT NULL:

The following fields require strict conflict handling:

- caste
- sub_caste
- marital_status
- gender
- date_of_birth

Rule:
• Change → Conflict mandatory
• lifecycle_state → conflict_pending
• Admin resolution required

------------------------------------------------------------
INCOME & JOB CLARIFICATION
------------------------------------------------------------

Fields:

- annual_income
- family_income
- occupation_title
- company_name
- work_city_id
- work_state_id

Even if serious_intent_id IS ACTIVE:

• Direct update allowed
• profile_change_history entry mandatory
• No lifecycle escalation
• No conflict required

Reason:

Income and employment are dynamic life variables.
Natural fluctuations must not degrade trust or block lifecycle.

However:

• All changes must be historically recorded.
• No silent overwrite allowed.
• Admin must always see old vs new values in history.

------------------------------------------------------------
STRICT PROHIBITIONS
------------------------------------------------------------

❌ Do not escalate income changes to conflict solely due to serious_intent.
❌ Do not block lifecycle for natural job/income updates.
❌ Do not delete previous income records.

------------------------------------------------------------
END OF CLARIFICATION
------------------------------------------------------------


------------------------------------------------------------

TRUST PRESERVATION RULE
============================================================

• Historical values must always remain visible to admin.
• System must NEVER delete old income records silently.
• profile_change_history must record:
    - old_value
    - new_value
    - source
    - changed_at

------------------------------------------------------------

OPTIONAL FUTURE EXTENSION (NOT REQUIRED NOW)
============================================================

System may later implement:

• income_change_percentage threshold trigger
• suspicious downward spike detection
• audit flag if income decreases > X%

This is NOT required in Phase-5.
Current rule is sufficient.

------------------------------------------------------------

STRICT PROHIBITIONS
============================================================

❌ Do not treat all income changes as conflict.
❌ Do not block lifecycle for natural job changes.
❌ Do not auto-escalate semi-dynamic updates.
❌ Do not delete historical values.

============================================================
END OF DYNAMIC FIELD POLICY
============================================================



5️⃣ SERIOUS INTENT PROTECTION RULE (REVISED)
============================================================

If serious_intent_id exists:

The following ALWAYS trigger conflict:

• caste change  
• sub_caste change  
• marital_status change  
• gender change  
• date_of_birth change  
• primary_contact change  

------------------------------------------------------------
Income & Job Fields Clarification
------------------------------------------------------------

The following NEVER trigger conflict
solely due to serious_intent:

• annual_income change  
• family_income change  
• occupation_title change  
• company_name change  
• work_city/state change  

These must:

• Write profile_change_history entry  
• Not escalate lifecycle  
• Not require admin resolution  

------------------------------------------------------------
System must:

• Prevent silent update  
• Log every change in history  
• Maintain trust transparency  
• Restore lifecycle_state only when conflicts resolved  

============================================================
END OF REVISION
============================================================

============================================================
6️⃣ FIELD LOCK INTEGRATION
============================================================

Before any mutation:

Check profile_field_locks table.

If field locked:

• Skip overwrite  
• Create conflict_record  
• lifecycle_state = conflict_pending  

Only admin may override locked field.

============================================================
7️⃣ LIFECYCLE STATE EXTENSION (PHASE-5)
============================================================

Valid lifecycle states:

- draft  
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
Transition Rules
------------------------------------------------------------

New profile flow:

intake_uploaded  
→ parsed  
→ awaiting_user_approval  
→ approved_pending_mutation  
→ active  

Conflict flow:

approved_pending_mutation  
→ conflict_pending  
→ active (after resolution)  

------------------------------------------------------------
Strict Rule:

Events (interest, unlock, etc.)
MUST NOT change lifecycle automatically.

============================================================
8️⃣ ADMIN OVERRIDE FLOW
============================================================

When admin resolves conflict:

System must:

1) Update conflict_records.resolution_status  
2) Insert admin_audit_logs entry  
3) Write profile_change_history entry  
4) Apply approved change  
5) lifecycle_state → active  

------------------------------------------------------------
Admin cannot:

• Delete conflict  
• Modify raw intake  
• Skip history  
• Skip audit log  

============================================================
9️⃣ EDGE CASE GOVERNANCE
============================================================

A) Partial Mutation Failure

If any entity fails:

• Entire transaction rolled back  
• No partial update allowed  
• lifecycle_state unchanged  

------------------------------------------------------------

B) Re-Upload During Conflict

If lifecycle_state = conflict_pending:

• New intake allowed  
• Mutation blocked until conflict resolved  

------------------------------------------------------------

C) Manual Edit During Pending Intake

If lifecycle_state:

- awaiting_user_approval  
- approved_pending_mutation  

Manual edit screen must be restricted.

------------------------------------------------------------

D) Re-Parse Same Intake

Allowed only before approval.

After approval:
• Intake locked  
• Re-parse forbidden  

============================================================
🔟 ZERO DATA LOSS ENFORCEMENT
============================================================

System must ensure:

• No entity row silently removed  
• No old value deleted without history  
• No conflict lost  
• No intake deleted  
• No silent mutation  

Violation = SSOT breach.

============================================================
END OF PART 4
============================================================

Next:
PART 5 — AI CONFIDENCE SYSTEM, UNLOCK ENGINE BASE & MATCHING READINESS CONTRACT
############################################################
PHASE-5 SSOT (DRAFT v1.0) — PART 5
AI CONFIDENCE, UNLOCK ENGINE BASE & MATCHING READINESS
############################################################

Based On:
- PHASE-5 FINAL BLUEPRINT.md :contentReference[oaicite:0]{index=0}
- PHASE-4_SSOT_v1.1.md :contentReference[oaicite:1]{index=1}

This section defines:

• AI Confidence Enforcement  
• Field Confirmation Discipline  
• Data Provenance Rules  
• Contact Unlock Base Architecture  
• Rule Engine Base Tables  
• Subscription Base Structure  
• Matching Readiness Guarantees  

Phase-5 does NOT implement monetization,
but prepares the governed base.

============================================================
1️⃣ AI CONFIDENCE SYSTEM — MANDATORY CONTRACT
============================================================

AI structured output MUST include:

{
  core: {...},
  contacts: [...],
  children: [...],
  education_history: [...],
  career_history: [...],
  addresses: {...},
  property_summary: {...},
  property_assets: [...],
  horoscope: {...},
  legal_cases: [...],
  preferences: {...},
  extended_narrative: {...},
  confidence_map: { field_name: score }
}

------------------------------------------------------------
Confidence Threshold Rules
------------------------------------------------------------

confidence < 0.75  
→ Mandatory review highlight  

0.75 ≤ confidence < 0.90  
→ Recommended review  

confidence ≥ 0.90  
→ Normal display  

IMPORTANT:

Even if confidence = 0.99  
User approval is still mandatory.

Auto-activation forbidden.

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

Rules:

• Always highlighted  
• Always require explicit user confirmation  
• Never auto-applied silently  
• Conflict mandatory if changed  

============================================================
3️⃣ DATA PROVENANCE DISCIPLINE
============================================================

Every mutation must track:

- source (ai_intake/manual/admin)
- changed_by
- changed_at

Stored in:

profile_change_history

------------------------------------------------------------
Extended Narrative Must Track:
------------------------------------------------------------

- source
- confidence_score
- approved_by_user

No field may exist without traceability.

============================================================
4️⃣ AI ROUTING CONTRACT
============================================================

AI is responsible ONLY for extraction.

System is responsible for:

• Field classification  
• Storage routing  
• Validation  
• Governance enforcement  

AI must NOT decide storage layer.

Routing must follow:

CORE vs CONTACT vs ENTITY vs EXTENDED contract.

============================================================
5️⃣ CONTACT UNLOCK ENGINE — BASE STRUCTURE
============================================================

Contact unlock allowed ONLY if:

lifecycle_state = active

Unlock forbidden when:

- draft
- intake_uploaded
- approved_pending_mutation
- conflict_pending
- suspended
- archived

(Admin override must be logged.)

------------------------------------------------------------
contact_unlock_policy Table
------------------------------------------------------------

- unlock_mode (free / gamified / paid / hybrid)
- serious_intent_required (boolean)
- minimum_profile_completion_percentage
- waiting_period_hours
- admin_override_allowed
- max_unlocks_per_day
- max_unlocks_per_month
- active_status

Rules:

• Policy must be DB-driven.
• Not hardcoded in controller.
• Version-safe and configurable.

============================================================
6️⃣ CONTACT ACCESS LOG (MANDATORY)
============================================================

contact_access_log:

- viewer_user_id
- target_profile_id
- unlock_mode_used
- unlock_timestamp
- payment_reference (nullable)
- ad_session_id (nullable)
- referral_code (nullable)

Rules:

• Contact never returned via search API.
• Unlock validation must execute first.
• Every access permanently logged.

============================================================
7️⃣ FLEXIBLE RULE ENGINE BASE
============================================================

unlock_rules_engine:

- rule_id
- rule_name
- condition_json
- reward_json
- active_status
- created_at
- updated_at

Example Condition:

{
  "profile_completion": 90,
  "serious_intent": true
}

Example Reward:

{
  "contact_unlock": 1
}

Rules:

• Database-driven.
• Admin-configurable.
• No controller-level hardcoding.
• Must respect lifecycle restrictions.

============================================================
8️⃣ USER ENGAGEMENT BASE TABLE
============================================================

user_engagement_stats:

- user_id
- ads_viewed_count
- referrals_done
- profiles_completed
- daily_login_streak
- unlock_credits_available
- updated_at

Unlock credits:

• Internal virtual count
• Deducted on unlock
• Logged in contact_access_log

============================================================
9️⃣ SUBSCRIPTION BASE TABLES
============================================================

subscription_plan:

- plan_name
- price
- unlock_limit
- validity_days
- priority_support (boolean)
- contact_view_unlimited (boolean)
- active_status

user_subscription:

- user_id
- plan_id
- activated_at
- expires_at
- active_status

Rules:

• Plan cannot override lifecycle_state.
• Plan cannot bypass conflict_pending restriction.
• Plan affects only unlock limits.

============================================================
🔟 MATCHING READINESS GUARANTEE
============================================================

Phase-5 must ensure:

• All searchable fields structured
• No age column
• Income numeric
• Height stored only as height_cm
• Caste structured
• Location stored as IDs
• Preferences relational
• Horoscope normalized
• Legal cases normalized
• No JSON blob dependencies

Matching engine MUST NOT:

• Parse extended narrative
• Depend on unstructured text
• Depend on stored age

============================================================
1️⃣1️⃣ SECURITY CONTRACT
============================================================

API Contract:

GET /api/profile/{id}/contact

Must:

1) Validate lifecycle_state = active  
2) Validate unlock rules  
3) Log contact_access_log  
4) Return contact  

Search API must NEVER include:

• Phone numbers
• Email
• Contact rows

============================================================
1️⃣2️⃣ SYSTEM NEVER DOES (PERMANENT)
============================================================

❌ Auto-activate profile  
❌ Auto-resolve conflict  
❌ Auto-overwrite critical field  
❌ Auto-reactivate archived profile  
❌ Store structured JSON blobs  
❌ Skip history write  
❌ Skip duplicate detection  
❌ Skip conflict detection  

============================================================
1️⃣3️⃣ FINAL PHASE-5 GUARANTEE
============================================================

Raw Marathi Biodata  
→ AI Structured Parsing  
→ User Review  
→ Explicit Approval  
→ Duplicate Check  
→ Conflict Detection  
→ Safe Mutation  
→ Fully Normalized Profile  
→ Lifecycle Governed  
→ Unlock Controlled  
→ Matching Ready  
→ Audit Protected  

Zero silent mutation.  
Zero data loss.  
Zero uncontrolled overwrite.  
Zero JSON blob structure.  

============================================================
END OF PART 5
============================================================

Next:
ATOMIC DAY-WISE IMPLEMENTATION PLAN (Strict Base-First Discipline)
############################################################
PHASE-5 SSOT (DRAFT v1.0) — PART 6
ATOMIC DAY-WISE IMPLEMENTATION PLAN
(STRICT BASE-FIRST DISCIPLINE)
############################################################

Based On:
- PHASE-5 FINAL BLUEPRINT.md :contentReference[oaicite:0]{index=0}
- PHASE-4_SSOT_v1.1.md :contentReference[oaicite:1]{index=1}

CRITICAL DISCIPLINE:

• Assumption-based development = FORBIDDEN
• Each day starts with REAL project status verification
• No day starts on incomplete base
• No partial implementation
• One base layer must be 100% complete before next
• Each day must include:
    - Status inspection
    - Implementation
    - Automated verification
    - Manual test protocol
    - UI stabilization
    - Zero-error confirmation

If base incomplete → same day complete base first.

============================================================
GLOBAL DAILY START PROTOCOL (MANDATORY)
============================================================

At start of EVERY day:

STEP-1: PowerShell status

- php artisan migrate:status
- php artisan route:list
- php artisan config:clear
- php artisan cache:clear

STEP-2: Tinker checks

- Schema::getColumnListing('matrimony_profiles');
- Schema::getColumnListing('conflict_records');
- Schema::getColumnListing('profile_change_history');

If DB connection fails → STOP → fix environment first.

STEP-3: If unclear structure

→ Ask Cursor:
   "या migration file मध्ये काय आहे?"
   "या model मध्ये relationships काय आहेत?"

No architecture change allowed by Cursor.

Only verification.
=========================
############################################################
PHASE-5 SSOT v1.1 — STRUCTURAL CORRECTION PATCH
(MANDATORY APPEND SECTION)
############################################################

This section corrects and finalizes structural gaps
identified during SSOT audit.

This patch overrides any previous ambiguity.

============================================================
1️⃣ LIFECYCLE ENUM — FINAL FREEZE (SINGLE SOURCE)
============================================================

The ONLY valid lifecycle_state values:

- draft
- intake_uploaded
- parsed
- awaiting_user_approval
- approved_pending_mutation
- conflict_pending
- active
- suspended
- archived
- archived_due_to_marriage

No additional states allowed.
No implicit state transitions allowed.

All lifecycle changes must be explicitly controlled
inside governed services only.

============================================================
2️⃣ TRANSACTION BOUNDARY CONTRACT (NON-NEGOTIABLE)
============================================================

MutationService MUST wrap entire mutation in:

DB::transaction()

Transaction scope MUST include:

1) Duplicate detection result locking
2) Conflict record creation
3) Core field updates
4) Contact sync
5) Entity sync
6) Extended sync
7) profile_change_history inserts
8) lifecycle_state transition

Rules:

• If ANY exception occurs → FULL rollback
• No partial entity update allowed
• mutation_log entry must be written AFTER commit
• No history write outside transaction
• Conflict records also inside transaction

Silent partial commit = SSOT violation

============================================================
3️⃣ INDEXING STRATEGY (PRODUCTION REQUIRED)
============================================================

Mandatory Indexes:

matrimony_profiles:
- index(lifecycle_state)
- index(date_of_birth)
- index(caste)
- index(district_id)
- index(serious_intent_id)

Composite Duplicate Index:
- index(full_name, date_of_birth, father_name, district_id, caste)

profile_contacts:
- index(profile_id)
- index(phone_number)
- unique(profile_id, is_primary) WHERE is_primary = true (enforced logically)

conflict_records:
- index(profile_id)
- index(resolution_status)

profile_change_history:
- index(profile_id)
- index(entity_type)

biodata_intakes:
- index(uploaded_by)
- index(intake_status)

All child tables:
- index(profile_id)

No unindexed foreign key allowed.

============================================================
4️⃣ APPROVAL SNAPSHOT VERSIONING
============================================================

biodata_intakes must include:

- approval_snapshot_json
- snapshot_schema_version (integer)

Rules:

• snapshot_schema_version default = 1
• Future schema changes must increment version
• MutationService must read snapshot_schema_version
• No assumption-based parsing allowed

============================================================
5️⃣ VISIBILITY LAYER — NEW STRUCTURE
============================================================

New Table:
profile_visibility_settings (ONE-TO-ONE)

Columns:

- id
- profile_id (unique)
- visibility_scope (public / premium_only / hidden)
- show_photo_to (all / premium / accepted_interest)
- show_contact_to (unlock_only / accepted_interest)
- hide_from_blocked_users (boolean)
- created_at
- updated_at

Rules:

• Visibility ≠ lifecycle_state
• Unlock must also respect visibility settings
• No JSON visibility blob allowed
• Must be relational

============================================================
6️⃣ PROPERTY STRUCTURE REFACTOR
============================================================

Replace single profile_property table with:

--------------------------------------------
A) profile_property_summary (ONE-TO-ONE)
--------------------------------------------

- id
- profile_id (unique)
- owns_house (boolean)
- owns_flat (boolean)
- owns_agriculture (boolean)
- total_land_acres (nullable)
- annual_agri_income (nullable)
- summary_notes (nullable)
- created_at
- updated_at

--------------------------------------------
B) profile_property_assets (MULTI-ROW)
--------------------------------------------

- id
- profile_id
- asset_type (vehicle/plot/shop/other)
- location (nullable)
- estimated_value (nullable)
- ownership_type (self/joint/parental)
- created_at
- updated_at

Rules:

• Summary row mandatory if property data exists
• Assets optional multi-row
• No structured JSON allowed

============================================================
7️⃣ PROFILE_PREFERENCES CARDINALITY
============================================================

profile_preferences = ONE-TO-ONE

Rules:

• Only one preference set per profile
• No multi-preference versions
• Used for matching engine
• No JSON storage

============================================================
8️⃣ PROFILE_HOROSCOPE_DATA CARDINALITY
============================================================

profile_horoscope_data = ONE-TO-ONE

Reason:

• Shaadi.com style single horoscope record
• Matching uses one canonical data set
• No multiple charts allowed
• No JSON storage

============================================================
9️⃣ PROFILE_EXTENDED_ATTRIBUTES DESIGN FREEZE
============================================================

profile_extended_attributes = ONE-TO-ONE

Fixed Columns Only:

- narrative_about_me
- narrative_expectations
- additional_notes

No key-value dynamic rows.
No structured storage.
No array storage.

============================================================
🔟 INTERNATIONAL STATUS POLICY
============================================================

No separate profile_international_status table.

International-related fields must be added
directly to matrimony_profiles if required.

Example fields:

- citizenship_country_id
- current_residence_country_id
- work_visa_type
- nri_status (boolean)

No separate entity allowed for this in Phase-5.

============================================================
1️⃣1️⃣ FINAL GOVERNANCE CONSISTENCY GUARANTEE
============================================================

After this patch:

• No structural ambiguity remains
• All cardinalities fixed
• Transaction boundary fixed
• Indexing defined
• Snapshot versioning defined
• Visibility separated from lifecycle
• Property normalized properly
• Horoscope fixed to one-to-one
• Extended fixed to one-to-one

Any deviation from this patch = SSOT violation.

============================================================
END OF SSOT v1.1 STRUCTURAL PATCH
============================================================


============================================================
DAY 1 — DATABASE FOUNDATION (INTAKE BASE)
============================================================

Goal:
Create ALL Phase-5 base tables (structure only).

Tables:

- biodata_intakes
- profile_change_history (unified)
- mutation_log
- contact_unlock_policy
- contact_access_log
- unlock_rules_engine
- user_engagement_stats
- subscription_plan
- user_subscription

No business logic.
No controllers.
No UI.

------------------------------------------------------------
Completion Criteria
------------------------------------------------------------

• migrate runs successfully
• migrate:status clean
• tinker column listing matches SSOT
• No missing indexes
• No duplicate column
• No JSON blobs
• Foreign keys correct
• Rollback test passes

If even one table incomplete → day not closed.

============================================================
DAY 2 — NORMALIZED ENTITY VALIDATION
============================================================

Goal:
Verify ALL relational tables from Part 3 exist and match SSOT.

Tables:

- profile_children
- profile_education
- profile_career
- profile_addresses
- profile_photos
- profile_relatives
- profile_property_summary
- profile_property_assets
- profile_visibility_settings
- profile_horoscope_data
- profile_preferences
- profile_legal_cases
- profile_contacts
- profile_extended_attributes

------------------------------------------------------------
Completion Criteria
------------------------------------------------------------

• No JSON columns storing arrays
• Correct foreign keys
• No cascade delete
• Indexes on profile_id
• No duplicate storage (height_cm only)

Manual schema comparison must match SSOT exactly.

============================================================
DAY 3 — INTAKE MODEL + IMMUTABILITY LAYER
============================================================

Goal:
Implement BiodataIntake model + immutability enforcement.

Must enforce:

• raw_ocr_text never editable
• intake cannot be deleted
• intake_locked respected
• approval_snapshot_json immutable

No mutation yet.

------------------------------------------------------------
Completion Criteria
------------------------------------------------------------

• Attempted update fails
• Attempted delete fails
• Unit-level test via tinker
• lifecycle_state transitions correct

============================================================
DAY 4 — AI PARSE STORAGE LAYER
============================================================

Goal:
Implement parsed_json storage and re-parse discipline.

Must enforce:

• parsed_json overwritten only before approval
• parse_status transitions correct
• no mutation triggered

No profile updates allowed.

------------------------------------------------------------
Completion Criteria
------------------------------------------------------------

• Re-parse works before approval
• Re-parse blocked after approval
• raw_ocr_text untouched
• intake lifecycle consistent

============================================================
DAY 5 — PREVIEW UI (READ-ONLY STRUCTURED)
============================================================

Goal:
Render structured preview UI.

No mutation allowed.

UI must:

• Highlight low-confidence fields
• Highlight critical fields
• Display normalized sections cleanly
• Production-grade layout
• No hidden fields

------------------------------------------------------------
Completion Criteria
------------------------------------------------------------

• Clean UI
• No console errors
• No broken Blade loops
• All sections visible
• Confidence highlighting works

============================================================
DAY 6 — USER APPROVAL SNAPSHOT SYSTEM
============================================================

Goal:
Implement approval_snapshot_json storage.

Must enforce:

• After approval → preview locked
• Edit disabled
• intake_status = approved
• lifecycle_state = approved_pending_mutation

No profile mutation yet.

------------------------------------------------------------
Completion Criteria
------------------------------------------------------------

• Snapshot immutable
• Second approval blocked
• lifecycle correct

============================================================
DAY 7 — DUPLICATE DETECTION ENGINE
============================================================

Goal:
Implement duplicate detection before mutation.

Checks:

1) OTP mobile
2) primary contact
3) identity composite
4) serious_intent_id

------------------------------------------------------------
Completion Criteria
------------------------------------------------------------

• Duplicate blocks mutation
• lifecycle_state = conflict_pending
• conflict_record created
• No profile creation occurs

============================================================
DAY 8 — CONFLICT RECORD INTEGRATION
============================================================

Goal:
Integrate conflict detection for critical fields.

Must create:

• conflict_records entry
• No overwrite
• lifecycle escalation

------------------------------------------------------------
Completion Criteria
------------------------------------------------------------

• Critical change triggers conflict
• Non-critical allowed
• serious_intent escalation works
• history written

============================================================
DAY 9 — MUTATION SERVICE (CORE FIELDS ONLY)
============================================================

Goal:
Implement governed mutation for CORE fields only.

Must enforce:

• diff comparison
• conflict detection
• field lock check
• profile_change_history write
• transaction wrap

------------------------------------------------------------
Completion Criteria
------------------------------------------------------------

• No direct update() in controller
• history entries created
• locked fields protected
• rollback works on failure

============================================================
DAY 10 — CONTACT SYNC ENGINE
============================================================

Goal:
Implement diff-based contact synchronization.

Rules:

• Only one primary allowed
• Primary change = critical
• No mass truncate

------------------------------------------------------------
Completion Criteria
------------------------------------------------------------

• Diff comparison works
• Primary enforced
• History entries exist
• No duplicate contacts

============================================================
DAY 11 — ENTITY SYNC ENGINE
============================================================

Goal:
Implement sync for:

- children
- education
- career
- addresses
- property_summary
- property_assets
- horoscope
- legal_cases
- preferences

------------------------------------------------------------
Completion Criteria
------------------------------------------------------------

• No truncate + insert shortcut
• Diff-based update
• History written
• Transaction rollback verified

============================================================
DAY 12 — LIFECYCLE & STATE MACHINE HARDENING
============================================================

Goal:
Enforce lifecycle transitions strictly.

Must block:

• Unlock when not active
• Manual edit during pending
• Auto-reactivation

------------------------------------------------------------
Completion Criteria
------------------------------------------------------------

• All transitions tested via tinker
• Invalid transitions blocked
• No hidden auto-changes

============================================================
DAY 13 — CONTACT UNLOCK BASE ENGINE
============================================================

Goal:
Implement unlock policy validation + logging.

Must:

• Validate lifecycle_state
• Validate policy table
• Log contact_access_log

------------------------------------------------------------
Completion Criteria
------------------------------------------------------------

• Unlock blocked when inactive
• Unlock logged
• Policy DB-driven
• No hardcoding

============================================================
DAY 14 — ADMIN RESOLUTION FLOW
============================================================

Goal:
Implement admin conflict resolution.

Must:

• Update conflict_records
• Write admin_audit_logs
• Write profile_change_history
• Restore lifecycle to active

------------------------------------------------------------
Completion Criteria
------------------------------------------------------------

• Admin cannot delete conflict
• Audit log created
• History created
• lifecycle restored

============================================================
DAY 15 — FULL PIPELINE INTEGRATION TEST
============================================================

Goal:
Simulate full flow:

Upload  
→ Parse  
→ Preview  
→ Approve  
→ Duplicate Check  
→ Conflict  
→ Mutation  
→ Active  

------------------------------------------------------------
Completion Criteria
------------------------------------------------------------

• Zero errors
• No partial update
• No data loss
• UI clean
• Manual test checklist passed

============================================================
DAY 16 — PRODUCTION UI CLEANUP
============================================================

Goal:
Polish:

• Preview UI
• Conflict UI
• Intake history page
• Unlock confirmation UI
• Admin conflict resolution page

No hidden/incomplete elements allowed.

------------------------------------------------------------
Completion Criteria
------------------------------------------------------------

• No raw JSON visible
• No debug messages
• Responsive layout
• User/Admin separation clean

============================================================
DAY 17 — FINAL GOVERNANCE AUDIT
============================================================

Checklist:

• No JSON blobs
• No duplicate age storage
• No silent overwrite
• No direct update()
• No cascade delete
• All tables match SSOT
• All lifecycle states respected
• Duplicate detection verified
• Conflict escalation verified
• Unlock restrictions verified
============================================================
PHASE-5 – AI INTAKE COMPLETION PLAN
Day-18 to Day-21 (SSOT Extension Block)
============================================================

GOAL:
Convert Intake Skeleton → Fully SSOT-Compliant AI Intake Engine

Must implement:

Upload
→ OCR (Image/PDF → Text)
→ AI Structured Parsing (Text → Structured JSON)
→ confidence_map generation
→ Structured Preview UI
→ Explicit User Approval
→ Conflict-Safe Mutation
→ Intake Lock

No SSOT deviation allowed.

============================================================
DAY-18 – OCR + AI PARSING ENGINE INTEGRATION
============================================================

OBJECTIVE:
Implement real OCR + AI structured parsing pipeline.

TASKS:

1. OCR Layer
   - Integrate OCR extraction for:
       • Image (JPG, PNG)
       • PDF
   - Extract text → store in raw_ocr_text
   - Remove placeholder 'FILE_UPLOADED'
   - Fail-safe handling if OCR fails.

2. AI Parsing Layer
   - Create AIParsingService
   - Input: raw_ocr_text
   - Output JSON structure:

     {
       core: {...},
       contacts: [...],
       children: [...],
       education: [...],
       career: [...],
       confidence_map: {
         field_name: score (0.0 - 1.0)
       }
     }

   - Ensure schema versioning (snapshot_schema_version)

3. Modify ParseIntakeJob
   - Call OCR (if file)
   - Call AIParsingService
   - Update:
       parsed_json
       parse_status = 'parsed'
       intake_status = 'parsed'

4. Queue validation
   - Confirm queue worker required
   - Fail-safe if queue not running (fallback sync mode allowed)

DELIVERABLE:
Upload → parsed_json populated with structured AI output.

============================================================
DAY-19 – STRUCTURED PREVIEW + CONFIDENCE UI
============================================================

OBJECTIVE:
Implement SSOT-compliant Preview screen.

TASKS:

1. Intake Preview Page Upgrade
   - Display:
       core fields
       children
       contacts
       education
       career

2. Highlight low-confidence fields
   - confidence_map < 0.75 → warning indicator
   - confidence_map < 0.50 → require manual correction

3. Allow user edits BEFORE approval
   - Editable preview fields
   - Store corrected snapshot in approval_snapshot_json

4. Enforce Explicit Approval Rule
   - Approval button disabled unless:
       • User scroll confirmed
       • Mandatory fields reviewed

5. Lifecycle transition:
   parsed → awaiting_user_approval

DELIVERABLE:
Fully functional AI Preview with editable structured data.

============================================================
DAY-20 – APPROVAL → MUTATION → CONFLICT ENGINE
============================================================

OBJECTIVE:
Make Approval trigger real profile mutation.

TASKS:

1. Approval Flow:
   - On approve:
       approved_by_user = true
       approved_at = timestamp
       intake_status = 'approved'

2. MutationService Integration
   - Apply approval_snapshot_json
   - Compare with existing profile
   - Detect conflicts using ConflictDetectionService
   - If conflict:
         lifecycle_state = 'conflict_pending'
     Else:
         lifecycle_state = 'active'

3. Lock intake:
   - intake_locked = true
   - Prevent re-edit

4. Conflict UI:
   - Display diff view
   - Allow resolution by user/admin

DELIVERABLE:
Approval → Conflict-Safe Mutation fully working.

============================================================
DAY-21 – STABILITY, VALIDATION & SSOT HARDENING
============================================================

OBJECTIVE:
Production-stable AI Intake Engine.

TASKS:

1. Full End-to-End Test:
   - New user
   - Upload biodata
   - OCR extract
   - AI parse
   - Preview
   - Approve
   - Profile created/updated

2. Edge Case Testing:
   - Blank file
   - Corrupt file
   - Low confidence fields
   - Conflict scenario

3. Security & Governance Validation:
   - Ensure:
       No direct profile overwrite
       MutationService only entry point
       Approval mandatory
       No bypass route

4. Performance Check:
   - Queue performance
   - AI latency handling
   - Timeout fallback

5. SSOT Compliance Audit:
   - Verify:
       AI Structured Parsing implemented
       confidence_map present
       Explicit Approval enforced
       Conflict-Safe Mutation active
       Intake Lock enforced

DELIVERABLE:
Phase-5 officially AI-enabled and SSOT-complete.

############################################################
PHASE-5B — CORE ALIGNMENT & FULL PROFILE COVERAGE BLOCK
(STRUCTURAL COMPLETION EXTENSION)
############################################################

Purpose:
Complete Phase-5 structural + functional alignment.

Goal:
Backend SSOT model + User/Admin manual profile UI
must be fully consistent and governance-safe.

No partial structure allowed.
No hidden fields.
No mismatch between SSOT and database.

############################################################
DAY-22 — CORE TABLE ALIGNMENT (MATRIMONY_PROFILES)
############################################################

Objective:
Align matrimony_profiles table with SSOT Core Field Registry.

Tasks:

1) Add missing SSOT core fields:

PERSONAL:
- religion
- sub_caste
- weight_kg
- complexion
- physical_build
- blood_group

EDUCATION & CAREER SNAPSHOT:
- highest_education
- specialization
- occupation_title
- company_name
- annual_income
- income_currency (default INR)
- family_income

FAMILY CORE:
- father_name
- father_occupation
- mother_name
- mother_occupation
- brothers_count
- sisters_count
- family_type

LOCATION (WORK):
- work_city_id
- work_state_id

2) Migration discipline:
- No dropping existing columns.
- No renaming silently.
- Add indexes where required.
- No age column.

3) Update MatrimonyProfile model:
- $fillable update
- casts update
- lifecycle validation intact

Completion Criteria:

✔ All SSOT core fields exist in matrimony_profiles
✔ No duplicate meaning fields
✔ No JSON blob columns
✔ migrate:status clean
✔ Schema::getColumnListing matches SSOT


############################################################
DAY-23 — MODEL GOVERNANCE ALIGNMENT
############################################################

Objective:
Ensure all core fields respect governance rules.

Tasks:

1) ConflictDetectionService update:
   - Ensure new core fields included.
   - Critical vs dynamic classification applied.

2) FieldLockService alignment:
   - Core fields lockable.
   - Respect lifecycle rules.

3) profile_change_history coverage:
   - Ensure new fields generate history entries.
   - No silent overwrite.

4) MutationService verification:
   - Core diff comparison includes all new fields.
   - Transaction boundary intact.

Completion Criteria:

✔ All new fields pass through MutationService
✔ Conflict created when required
✔ Dynamic fields update without escalation
✔ History entries verified
✔ No direct update() bypass


############################################################
DAY-24 — FULL MANUAL PROFILE EDIT UI EXPANSION
############################################################

Objective:
Manual profile edit screen must expose ALL SSOT fields.

Scope:

1) Core Profile Edit Form:
   - All personal fields
   - All family fields
   - Income fields
   - Work location fields
   - Snapshot education/career fields

2) Nested Entities CRUD Sections:

   CHILDREN:
   - Add child
   - Edit child
   - Delete child

   EDUCATION:
   - Add multiple rows
   - Edit rows
   - Delete rows

   CAREER:
   - Timeline add/edit
   - is_current validation

   PROPERTY:
   - Summary edit
   - Asset rows add/remove

   HOROSCOPE:
   - Structured edit

   PREFERENCES:
   - Structured edit

   EXTENDED:
   - Narrative fields edit

3) UI Rules:

- No raw JSON visible.
- No hidden backend-only fields.
- Respect lifecycle restrictions.
- Disable edit when:
    lifecycle_state in:
    intake_uploaded
    awaiting_user_approval
    approved_pending_mutation
    conflict_pending

Completion Criteria:

✔ User can manually fill all SSOT fields
✔ Nested entity CRUD functional
✔ No bypass of governance
✔ Clean Blade layout
✔ No console errors


############################################################
DAY-25 — ADMIN COVERAGE + FULL SYSTEM TEST
############################################################

Objective:
Admin + User + Intake full alignment test.

Tasks:

1) Admin Profile View:
   - All core fields visible.
   - Nested entities visible.
   - Change history visible.

2) Admin Conflict Resolution:
   - Works with new fields.
   - Writes audit log.
   - Lifecycle restored properly.

3) Full Integration Test:

Manual Create →
Manual Edit →
AI Intake →
Conflict →
Resolution →
Unlock →
Lifecycle transitions.

4) Terminal Validation:

- php artisan migrate:status
- route:list
- Schema checks
- No direct update() for core fields outside MutationService
  (except documented legacy paths if retained)

Completion Criteria:

✔ No missing fields
✔ No structural mismatch
✔ No lifecycle violation
✔ No conflict skip
✔ No history skip
✔ No JSON blob storage


############################################################
FINAL DECLARATION CONDITION
############################################################

Phase-5B complete ONLY if:

✔ Core table matches SSOT
✔ Model governance aligned
✔ Manual CRUD complete
✔ Intake pipeline stable
✔ Admin resolution stable
✔ Lifecycle state machine respected
✔ No field invisible in UI but present in DB
✔ No DB column unused
✔ No structural ambiguity remains

After this:
Phase-5 officially declared:
STRUCTURALLY + FUNCTIONALLY COMPLETE.
############################################################
END OF PHASE-5B EXTENSION
############################################################

============================================================
FINAL PHASE-5 STATE
============================================================

✔ OCR active
✔ AI Structured Parsing active
✔ confidence_map enforced
✔ Editable Preview active
✔ Explicit Approval required
✔ Conflict-Safe Mutation enforced
✔ Intake Lock after approval
✔ Lifecycle transitions correct
✔ Fully SSOT compliant

============================================================

------------------------------------------------------------
Only after this:
Phase-5 SSOT declared LOCKED.

============================================================
END OF ATOMIC DAY PLAN
============================================================

# PROFILE EDITING ARCHITECTURE – FINAL (LOCKED)

## 1. Single Editing System

Profile creation and editing is wizard-driven only.

Registration must redirect to:
`/matrimony/profile/wizard/basic-info`

The following routes are permanently disallowed:
- matrimony.profile.create
- matrimony.profile.store
- Any alternate edit blade

No duplicate UI for profile editing is allowed.

---

## 2. Religion / Caste / Subcaste – Normalized Model

### Database

matrimony_profiles:
- religion_id (FK)
- caste_id (FK)
- sub_caste_id (FK)

No raw string caste, religion, or subcaste columns allowed.

---

### UI Component

Religion/Caste/Subcaste selector must:

- Use hidden ID inputs
- Use search-based dropdown UI
- Load castes dynamically by religion
- Load subcastes dynamically by caste
- Require minimum 2 characters for subcaste search
- Show "Add new subcaste" only if:
  - No results
  - No exact match
  - Input length ≥ 2

---

### Add New Subcaste Rules

POST /api/v1/sub-castes

Creates:
- status = pending
- is_active = 0
- created_by_user_id = auth user

Admin approval required before activation.

---

## 3. Component Governance

Religion/Caste/Subcaste must exist as a single reusable Blade component.

- No inline duplication
- No separate implementation for create/edit
- Must not depend on sibling stacking order
- JS must be centralized

---

## 4. Admin Master Data Rules

Admin may:
- Create religion
- Create caste (unique per religion)
- Create subcaste
- Merge subcastes
- Approve pending subcastes
- Soft disable records

Hard delete is not allowed.

---

## 5. Mutation Discipline

All profile updates must pass through MutationService.

Direct DB::table writes for profile data are disallowed.

---

## 6. Freeze Clause

Any future modification to religion/caste/subcaste system
requires SSOT update before implementation.

Violation of this rule is considered architectural breach.