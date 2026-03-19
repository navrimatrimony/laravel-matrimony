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
Store extended family (paternal and maternal) — one row per relative.
UI: two sections — Paternal (relatives_parents_family) and Maternal (relatives_maternal_family).
MutationService expects a single "relatives" array; wizard/intake merge both sources.

Columns:

- id
- profile_id
- relation_type (string — see dropdowns below)
- name
- occupation (nullable)
- marital_status (nullable)
- city_id (nullable, FK cities)
- state_id (nullable, FK states)
- contact_number (nullable)
- notes (nullable)
- is_primary_contact (boolean, default false — maternal section only in UI)
- created_at
- updated_at

Rules:

• Structured multi-row; one row per person (e.g. 4 uncles = 4 rows).
• History required for updates.
• Primary contact: only one is_primary_contact=true per profile (UI shows checkbox in maternal section).

----------------------------------------
Paternal dropdown (relatives_parents_family)
----------------------------------------
Source: ProfileWizardController relationTypesParentsFamily / IntakeController same list.
Filter: parentsFamilyTypes — rows with these relation_type go to Paternal section.

| value                    | label                          |
|--------------------------|--------------------------------|
| native_place             | Native Place                   |
| paternal_grandfather     | Paternal Grandfather           |
| paternal_grandmother     | Paternal Grandmother          |
| paternal_uncle           | Paternal Uncle (chulte)        |
| wife_paternal_uncle      | Wife of Paternal Uncle (chulti)|
| paternal_aunt            | Paternal Aunt (atya)           |
| husband_paternal_aunt    | Husband of Paternal Aunt      |
| Cousin                   | Cousin                         |

(No "Other" option — all other relatives go in the separate Other Relatives engine: other_relatives_text, आडनाव/गाव.)

----------------------------------------
Maternal dropdown (relatives_maternal_family)
----------------------------------------
Source: ProfileWizardController relationTypesMaternalFamily / IntakeController same list.
Filter: maternalFamilyTypes — rows with these relation_type go to Maternal section.

| value                    | label                          |
|--------------------------|--------------------------------|
| maternal_address_ajol    | Maternal address (Ajol)       |
| maternal_grandfather     | Maternal Grandfather           |
| maternal_grandmother     | Maternal Grandmother           |
| maternal_uncle           | Maternal Uncle (mama)          |
| wife_maternal_uncle      | Maternal Uncle's wife (mami)   |
| maternal_aunt            | Maternal Aunt (mavshi)         |
| husband_maternal_aunt    | Husband of Maternal Aunt       |
| maternal_cousin         | Cousin                         |

(No "Other" option — all other relatives go in the separate Other Relatives engine: other_relatives_text, आडनाव/गाव.)

----------------------------------------
Field mapping (per relative row)
----------------------------------------
Same fields for all relation_type values except address-only types.

Standard rows (all types except native_place and maternal_address_ajol):
- relation_type  → dropdown selection (above)
- name           → relative's name
- contact_number → mobile
- occupation     → job/business
- address        → location (city_id, state_id; UI: location typeahead, alliance context)
- notes          → additional info / notes
- is_primary_contact → checkbox (Maternal section only)

Address-only rows (only these two types show Relation + Address only; name/occupation/contact/notes hidden):
- native_place        (Paternal): only relation_type + address (वडिलांचे मूळ गाव)
- maternal_address_ajol (Maternal): only relation_type + address (माहेरचा पत्ता — अजोल)

Component: resources/views/components/repeaters/relation-details.blade.php (showMarried=false, addressOnlyRelationValue set per section).
Wizard: relatives.blade.php (Paternal, addressOnlyRelationValue=native_place); alliance.blade.php (Maternal, addressOnlyRelationValue=maternal_address_ajol).

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


---

# PHASE-5B ADDENDUM: MARITAL STATUS DOMAIN ENGINE (PRODUCTION-GRADE RULE)

## Governance Seal
Marital Status is treated as a DOMAIN STATE MACHINE.
UI must NEVER render all marital timeline fields together.
Rendering must be STATUS-SPECIFIC and SERVER-DRIVEN.

JS-based field hiding is NOT considered production-grade and must not be used
as the primary logic layer.

---

## 1. Authoritative Source of Truth

Authoritative Field:
    matrimony_profiles.marital_status_id

Authoritative Key:
    $profile->maritalStatus->key

All marital timeline rendering MUST depend on the canonical status key,
NOT on UI text labels.

---

## 2. Status-Specific Rendering Rule

The marriages section MUST render using status-based partials:

Structure:

resources/views/matrimony/profile/wizard/sections/marriages/
    index.blade.php
    married.blade.php
    divorced.blade.php
    separated.blade.php
    widowed.blade.php
    never_married.blade.php

index.blade.php MUST contain:

    $statusKey = $profile->maritalStatus?->key;

    if ($statusKey === 'divorced') → render divorced.blade.php
    if ($statusKey === 'separated') → render separated.blade.php
    if ($statusKey === 'widowed') → render widowed.blade.php
    if ($statusKey === 'married') → render married.blade.php
    if ($statusKey === 'never_married' OR 'unmarried') → render nothing

No generic shared template with JS toggling is allowed.

---

## 3. Visibility Matrix (MANDATORY)

### never_married / unmarried
Render:
    NOTHING
Must enforce:
    profile_marriages table must be empty.

---

### married
Render:
    - Marriage Year
Hide:
    - Divorce Year
    - Separation Year
    - Spouse Death Year
    - Legal Status

---

### separated
Render:
    - Marriage Year
    - Separation Year
    - Legal Status
Hide:
    - Divorce Year
    - Spouse Death Year

Children section visible.

---

### divorced
Render:
    - Marriage Year
    - Divorce Year
    - Divorce Type
    - Legal Status

Children section visible.

Hide:
    - Separation Year
    - Spouse Death Year

---

### widowed
Render:
    - Marriage Year
    - Spouse Death Year

Children section visible.

Hide:
    - Divorce Year
    - Legal Status
    - Separation Year

---

## 4. Backend Enforcement Rules (NON-NEGOTIABLE)

MutationService MUST enforce:

If marital_status = never_married:
    → profile_marriages rows must be zero.

If marital_status = widowed:
    → divorce_year must be null.

If marital_status = divorced:
    → spouse_death_year must be null.

If marital_status changes:
    → validate existing timeline consistency.
    → create ConflictRecord if invalid coexistence detected.

UI logic is NOT sufficient.
Backend validation is mandatory.

---

## 5. UX Rule

Marital status change MUST trigger deterministic re-render.

Recommended Production Approach:
    Dropdown change → auto-submit → server re-render partial.

Avoid:
    Complex JS hide/show logic.
    Mixed-field rendering.
    Text-based key detection.

---

## 6. Production Standard Reference Alignment

This architecture aligns with industry matrimonial systems:
    - Status-based profile rendering
    - Domain-driven conditional sections
    - Clean separation of marital states
    - Deterministic governance

This rule supersedes any previous toggle-based implementation.

---

FINAL PRINCIPLE:

Marital timeline is a DOMAIN MODEL,
not a UI toggle problem.

Any future modification must preserve:
    - Single Source of Truth
    - Status-specific rendering
    - Backend validation supremacy
    - Logical field coexistence constraints

---


############################################################
PHASE-5C — INTELLIGENT OCR CORRECTION ENGINE (REWRITTEN – GOVERNED VERSION)
############################################################

Status:
Extension Layer Above Phase-5 Intake Pipeline

This layer:
• DOES NOT modify MutationService
• DOES NOT modify lifecycle engine
• DOES NOT modify conflict engine
• DOES NOT modify parsed_json
• DOES NOT modify raw_ocr_text
• DOES NOT bypass approval gate

Purpose:
Reduce OCR + AI parsing error rate over time
using user-confirmed corrections only.

------------------------------------------------------------
CORE PRINCIPLE
------------------------------------------------------------

System learns ONLY from:
User-approved corrections.

System NEVER learns from:
• AI guesses
• Rejected values
• Unapproved preview edits

Approval is the only truth source.

############################################################
C1 — CORRECTION LOGGING (STRICTLY PRE-APPROVAL CAPTURE)
############################################################

New Table:
ocr_correction_logs

Columns:

- id
- intake_id (FK → biodata_intakes.id)
- field_key (indexed)
- original_value (text)
- corrected_value (text)
- ai_confidence_at_parse (decimal 3,2 nullable)
- snapshot_schema_version
- created_at

Rules:

• Insert-only table
• No update allowed
• No delete allowed
• No JSON column allowed
• No profile mutation triggered
• No lifecycle change triggered

------------------------------------------------------------
LOGGING TRIGGER POINT
------------------------------------------------------------

During Approval Click:

Compare:
approval_snapshot_json value
VS
parsed_json value

IF different:

→ Insert correction log row

IMPORTANT:

• parsed_json remains unchanged
• raw_ocr_text remains unchanged
• Only correction log inserted
• Must execute inside DB transaction
• One log per field per intake

############################################################
C2 — EXACT MATCH PATTERN FORMATION (NON-AI ENGINE)
############################################################

New Table:
ocr_correction_patterns

Columns:

- id
- field_key (indexed)
- wrong_pattern (text)
- corrected_value (text)
- usage_count (integer)
- pattern_confidence (decimal 3,2)
- source (enum: frequency_rule / ai_generalized)
- is_active (boolean)
- created_at
- updated_at

------------------------------------------------------------
FREQUENCY RULE
------------------------------------------------------------

Group correction logs by:

field_key + original_value + corrected_value

If usage_count >= 10:

Insert pattern:

wrong_pattern = original_value
corrected_value = corrected_value
pattern_confidence = 0.80
source = frequency_rule
is_active = true

STRICT RULES:

• Only exact match allowed
• No regex
• No normalization
• No fuzzy matching
• No auto-rewrite of old intakes
• No AI involvement here

############################################################
C3 — PREVIEW SUGGESTION INJECTION LAYER
############################################################

Trigger:
Before preview render.

Process:

For each parsed field value:

Check ocr_correction_patterns:

WHERE
field_key matches
AND wrong_pattern equals current value
AND is_active = true

------------------------------------------------------------
Suggestion Logic
------------------------------------------------------------

IF pattern_confidence < 0.90:

→ Show suggestion badge:
   “Suggested Correction Available”

→ Do NOT auto-apply
→ User must accept manually

------------------------------------------------------------

IF pattern_confidence >= 0.90
AND usage_count >= 25:

→ Auto-fill preview editable value
→ Mark visually:
   “System Suggested (Review Recommended)”

CRITICAL:

• parsed_json NOT modified
• raw_ocr_text NOT modified
• approval still mandatory
• lifecycle unchanged
• User can revert suggestion

############################################################
C4 — NIGHTLY AI GENERALIZATION JOB (ISOLATED)
############################################################

Purpose:
Convert high-frequency exact patterns into broader normalization rules.

New Job:
NightlyOcrLearningJob

------------------------------------------------------------
Execution Rules
------------------------------------------------------------

• Runs once per day (cron)
• Batch-based only
• Never per upload
• Never blocking intake
• Token budget capped
• If AI fails → no impact on intake

------------------------------------------------------------
Flow
------------------------------------------------------------

1) Fetch patterns with usage_count >= threshold
2) Send batch to AI:
   “Generalize normalization rule safely”
3) Validate AI output manually in code
4) Insert new row into ocr_correction_patterns:

   source = ai_generalized
   pattern_confidence = AI returned value
   is_active = true

STRICT RULES:

• Must NOT modify existing pattern rows
• Must NOT auto-disable frequency_rule patterns
• Must NOT modify any past intake
• Must NOT modify parsed_json

############################################################
C5 — ADMIN GOVERNANCE PANEL
############################################################

Admin Capabilities:

• View patterns
• Filter by field_key
• See usage_count
• See pattern_confidence
• See source type
• Disable pattern (is_active = false)

Admin CANNOT:

❌ Edit wrong_pattern
❌ Edit corrected_value
❌ Edit usage_count
❌ Delete correction logs
❌ Modify parsed_json
❌ Modify intake

All admin actions must write admin_audit_logs entry.

############################################################
C6 — ZERO GOVERNANCE INTERFERENCE GUARANTEE
############################################################

Phase-5C must NOT:

❌ Trigger conflict record
❌ Modify lifecycle_state
❌ Modify MutationService
❌ Modify approval_snapshot_json after lock
❌ Auto-approve intake
❌ Modify serious_intent logic
❌ Modify duplicate detection

It is strictly a suggestion layer.

############################################################
C7 — DATA IMMUTABILITY GUARANTEE
############################################################

Immutable Always:

• raw_ocr_text
• parsed_json (after parse)
• approval_snapshot_json (after approval)
• profile_change_history
• conflict_records
• ocr_correction_logs

Only:

ocr_correction_patterns.is_active
may change via admin toggle.

############################################################
C8 — SUCCESS METRIC
############################################################

Phase-5C considered effective only if:

• OCR repeat error rate reduces over time
• Suggestion acceptance rate increases
• AI parsing cost reduces
• Zero silent overwrite incidents
• Zero lifecycle violations

############################################################
END OF PHASE-5C (REWRITTEN GOVERNED VERSION)
############################################################


############################################################
PHASE-5C — DAYWISE IMPLEMENTATION PLAN
STARTING FROM DAY-26
INTELLIGENT OCR CORRECTION ENGINE
############################################################

IMPORTANT:

• Phase-5 must already be stable.
• Intake → Parse → Preview → Approve → Mutation working.
• No lifecycle bug pending.
• No conflict bug pending.

Phase-5C must NOT begin on unstable base.

============================================================
DAY-26 — CORRECTION LOGGING FOUNDATION
============================================================

GOAL:
Create ocr_correction_logs table + safe logging trigger.

STEP-1:
Create migration:

Table: ocr_correction_logs

Columns:
- id
- intake_id (FK)
- field_key (indexed)
- original_value (text)
- corrected_value (text)
- ai_confidence_at_parse (decimal 3,2 nullable)
- snapshot_schema_version
- created_at

Rules:
• No update route
• No delete route
• No JSON column
• Index on field_key
• FK on intake_id
• No cascade delete

STEP-2:
Modify approval flow:

On approval click:

Compare:
approval_snapshot_json
vs
parsed_json

If different:
Insert ONE correction row per field.

IMPORTANT:
• Must run inside same DB transaction.
• parsed_json unchanged.
• approval_snapshot_json unchanged.
• lifecycle unchanged.

COMPLETION CHECK:
✔ Logs inserted only when user changed value.
✔ No logs when no correction.
✔ No mutation triggered.
✔ No lifecycle change.


============================================================
DAY-27 — FREQUENCY PATTERN ENGINE (EXACT MATCH ONLY)
============================================================

GOAL:
Create ocr_correction_patterns table + frequency builder.

STEP-1:
Create migration:

Table: ocr_correction_patterns

Columns:
- id
- field_key (indexed)
- wrong_pattern (text)
- corrected_value (text)
- usage_count (integer)
- pattern_confidence (decimal 3,2)
- source (frequency_rule / ai_generalized)
- is_active (boolean)
- created_at
- updated_at

No delete route allowed.

STEP-2:
Create console command:

php artisan ocr:build-frequency-patterns

Logic:
Group ocr_correction_logs by:
field_key + original_value + corrected_value

If usage_count >= 10:
Insert pattern row:

wrong_pattern = original_value
corrected_value = corrected_value
pattern_confidence = 0.80
source = frequency_rule
is_active = true

STRICT RULES:
• Exact string match only.
• No regex.
• No normalization.
• No fuzzy match.

COMPLETION CHECK:
✔ Patterns created after threshold.
✔ No mutation triggered.
✔ No lifecycle touched.


============================================================
DAY-28 — PREVIEW SUGGESTION INJECTION LAYER
============================================================

GOAL:
Inject correction suggestions during preview render.

STEP-1:
Before preview blade render:

For each parsed field:
Check ocr_correction_patterns:

WHERE
field_key matches
AND wrong_pattern equals current value
AND is_active = true

STEP-2:
Suggestion logic:

IF pattern_confidence < 0.90:
Show badge:
"Suggested Correction Available"

User must manually accept.

IF pattern_confidence >= 0.90
AND usage_count >= 25:

Auto-fill editable preview field.
Mark visually:
"System Suggested (Review Recommended)"

STRICT:
• parsed_json NOT modified.
• raw_ocr_text NOT modified.
• approval still mandatory.

COMPLETION CHECK:
✔ Suggestions visible.
✔ No auto-approval.
✔ No lifecycle change.
✔ User can revert suggestion.


============================================================
DAY-29 — NIGHTLY AI GENERALIZATION JOB
============================================================

GOAL:
Implement safe batch AI normalization.

STEP-1:
Create job:
NightlyOcrLearningJob

STEP-2:
Job runs once per day (scheduler).

Flow:
1) Fetch patterns with usage_count >= threshold
2) Send batch to AI:
   "Safely generalize normalization pattern"
3) Validate output in code
4) Insert new pattern row:

source = ai_generalized
pattern_confidence = AI returned value
is_active = true

STRICT:
• Must NOT modify existing patterns.
• Must NOT delete logs.
• Must NOT modify parsed_json.
• Must NOT touch intake.

COMPLETION CHECK:
✔ AI job runs isolated.
✔ Intake pipeline unaffected.
✔ No lifecycle interference.


============================================================
DAY-30 — ADMIN GOVERNANCE PANEL
============================================================

GOAL:
Admin control over patterns.

Admin can:

• View patterns
• Filter by field_key
• See usage_count
• See pattern_confidence
• See source type
• Toggle is_active

Admin cannot:

❌ Edit wrong_pattern
❌ Edit corrected_value
❌ Delete correction logs
❌ Modify intake

All actions must write admin_audit_logs entry.

COMPLETION CHECK:
✔ Admin toggle works.
✔ No mutation interference.
✔ Audit log created.


============================================================
DAY-31 — FULL INTEGRATION & SAFETY AUDIT
============================================================

GOAL:
Ensure Phase-5C does NOT break Phase-5.

Test:

Upload →
Parse →
Preview →
Accept suggestion →
Approve →
Mutation →
Lifecycle

Also test:

Conflict scenario
Duplicate scenario
Serious intent scenario
Locked field scenario

Validation checklist:

✔ No silent overwrite
✔ No lifecycle auto-change
✔ No conflict auto-resolution
✔ No parsed_json mutation
✔ No approval bypass
✔ No MutationService bypass

If any violation → Phase-5C rollback.

Only after passing:
Phase-5C declared ACTIVE.

############################################################
DAY 31 — PART 2 (SSOT ADDENDUM + CURSOR IMPLEMENTATION PROMPT)
Project: laravel-matrimony (Laravel 12.50.0)

====================================================================
A) SSOT मध्ये Day 31 Part 2 म्हणून “मस्ट-फॉलो” पॉईंट्स (कॉपी-पेस्ट)
====================================================================

## Day 31 Part 2 — Profile Forms Canonicalization + Reusable Select Engines (Production Grade)

### 1) Single Source of Truth (SSOT) — Profile Edit/Wizard/Intake Preview
- “एकच field एका वेळेस एकाच ठिकाणी editable” हा नियम बंधनकारक.
  - उदाहरण: primary_contact_number / marriage+children सारखी duplication परवानगी नाही.
  - Duplicate UI असल्यास: एका ठिकाणी editable, दुसरीकडे read-only किंवा पूर्णपणे हटवणे.

### 2) No reload / No auto-submit dependency UX
- Shaadi.com सारखा अनुभव: dropdown बदलल्यावर page reload/auto-submit/fetch partial करायचे नाही.
- Dependent fields client-side (Alpine/JS) ने show/hide होतील, पण:
  - Parent field च्या लगेच खाली (adjacent) grouped block मध्येच child fields रेंडर होतील
  - Layout break (parent नंतर unrelated fields मध्ये child दिसणे) ही production-grade violation मानली जाईल

### 3) Canonical Field Catalog + Section Layout
- “Field Catalog” (एकच यादी) ठरवली जाईल:
  - प्रत्येक field: key, label, input_type, options_source, validation, dependency_rules, section_key, display_order
- Profile Edit, Wizard, Intake Preview — तिन्ही ठिकाणी हेच catalog/layout वापरायचे.
- कुठेही “manual one-off field list” ठेवायची नाही (drift टाळण्यासाठी).

### 4) Reusable Select Engines (एकदाच बनवा, सगळीकडे वापरा)
- Address/Location typeahead engine (काही अक्षरे टाइप → dropdown suggestion):
  - Profile address, birth place, native place, work location, relatives address — सर्वत्र एकच reusable component/JS.
- Religion/Caste/Subcaste cascading selects:
  - Wizard/Edit/Preview सर्वत्र एकच reusable component/JS.
- या engines चे UI/behavior/labels सर्व views मध्ये consistent असणे बंधनकारक.

### 5) Mass-assignment safety + SSOT Mutation Governance
- DB मध्ये अस्तित्वात असलेला आणि UI वर edit होणारा कोणताही field “save” न होणे ही production-grade defect मानली जाईल.
- PHASE-5 नियम:
  - Profile data वर direct update() कॉल नाही
  - सर्व mutations MutationService मधूनच
  - Zero data loss, no silent overwrite, conflict records mandatory (critical changes)
- म्हणून forms “request → MutationService → governed apply” मार्गानेच persist होतील.

### 6) Full Edit Parity
- “edit-full” हा screen “no missing field” guarantee देईल:
  - Photo sectionसह सर्व sections included असतील
  - Wizard मध्ये जे fields आहेत ते पूर्ण edit मध्येही (same components वापरून) दिसतील

--------------------------------------------------------------------
B) CURSOR PROMPT (एकाच वेळी सर्व files पाहून deterministic बदल करा)
--------------------------------------------------------------------

GOAL:
1) Profile Edit / Wizard / Intake Preview मध्ये fields missing/duplicate/drift शून्य करणे.
2) Parent-child dependencies adjacent ठेवणे (no reload / no auto-submit).
3) Address typeahead engine + Religion/Caste/Subcaste engine reuse करून “एकदाच बदल → सगळीकडे लागू” करणे.
4) PHASE-5 SSOT governance (MutationService only, no direct update()) कायम ठेवणे.

IMPORTANT CONSTRAINTS:
- कोणताही DB column delete/rename नाही.
- Structured entities JSON blob मध्ये नाही.
- Profile writes साठी direct Model::update()/save() वापरू नका; MutationService वापरा.
- Existing intake raw text immutable.

-------------------------------------------------
1) DATA CONSISTENCY FIX — Fillable mismatch (HIGH PRIORITY)
-------------------------------------------------
OBSERVED:
matrimony_profiles columns मध्ये आहेत पण MatrimonyProfile::getFillable() मध्ये नाहीत:
- specialization
- occupation_title
- company_name
- annual_income
- family_income
- father_name
- father_occupation
- mother_name
- mother_occupation
- brothers_count
- sisters_count
- work_city_id
- work_state_id
(+ weight_kg वगैरे आधीच fillable मध्ये आहेत; ते ठीक)

ACTION:
- File: app/Models/MatrimonyProfile.php
- Edit: protected $fillable = [...] मध्ये वरील missing fields add करा.
- Location reference: $fillable array block शोधा (string: "serious_intent_id" जवळ array end आहे).
- Before/After snippet (exact add):
  Before (end portion example):
    "safety_defaults_applied",
    "serious_intent_id",
  ]
  After:
    "safety_defaults_applied",
    "serious_intent_id",

    // Education/Career + Family fields (DB columns exist)
    "specialization",
    "occupation_title",
    "company_name",
    "annual_income",
    "family_income",
    "father_name",
    "father_occupation",
    "mother_name",
    "mother_occupation",
    "brothers_count",
    "sisters_count",
    "work_city_id",
    "work_state_id",
  ]

NOTE:
- हे add केल्याने mass assignment allow होईल; पण writes अजूनही MutationService मधूनच होतात याची खात्री करा.
- जर कुठे direct update() दिसला तर तो MutationService route मध्ये migrate करा (Step 4).

-------------------------------------------------
2) CANONICAL UI COMPONENTS — Extract & Reuse (Address + Religion/Caste)
-------------------------------------------------

2.1 Address/Location Typeahead Engine (Single component)
TASK:
- Repo मध्ये जिथे “गाव नाव टाइप केलं की dropdown suggestions” हे आधी implement आहे ते शोधा.
  Search keywords:
  - "typeahead"
  - "location"
  - "native_city_id"
  - "birth_city_id"
  - "district_id" "taluka_id" "city_id"
  - JS: "fetch(" "/api/" "suggest"
  - Blade: "@push('scripts')" "Alpine.data" "x-data"
- त्या existing working logic ला “reusable Blade component + single JS module” मध्ये extract करा.

CREATE/REFactor TARGET (pick best fit based on existing style):
Option A (Recommended if Alpine already used):
- resources/views/components/location-typeahead.blade.php
- resources/js/location-typeahead.js (or existing app.js module)
- Component props:
  - name/id (e.g. native_city_id)
  - initial_value (selected id + label)
  - context (birth/native/work/relative/address) to drive placeholder/endpoint params
- Ensure: same component used in:
  - profile edit (section where current address is)
  - wizard sections needing place selection
  - intake preview display (read-only view uses same label resolver helper)

2.2 Religion/Caste/Subcaste Cascading Engine (Single component)
TASK:
- Existing dropdowns कुठे आहेत ते शोधा:
  - religion_id, caste_id, sub_caste_id
- Create reusable component:
  - resources/views/components/religion-caste-selector.blade.php
  - JS module to load dependent options (caste by religion, subcaste by caste)
- Ensure the same component is used in:
  - profile edit
  - wizard
  - intake preview (read-only uses same resolver/helper)

OUTPUT RULE:
- “कुठे dropdown आहे, कुठे नाही” drift 0 करा: एकदा component वापरला की तिन्ही ठिकाणी same control.

-------------------------------------------------
3) DEPENDENCY LAYOUT — Parent-child adjacent grouping (NO reload)
-------------------------------------------------
USER REQUIREMENT:
- Dropdown change → auto-submit/fetch partial NO.
- पण child fields parent च्या लगेच खाली grouped असावेत.

IMPLEMENTATION:
- Parent field markup आणि child block markup same partial मध्ये ठेवा.
- Child blocks hide/show with Alpine:
  - Example pattern:
    <div x-data="{ marital_status_id: '{{ old(...) ?? $profile->marital_status_id }}' }">
      <!-- parent select -->
      <select x-model="marital_status_id" ...>...</select>

      <!-- child block immediately after -->
      <div x-show="marital_status_id == SOME_VALUE" x-cloak>
         <!-- marriage details -->
      </div>

      <div x-show="marital_status_id == OTHER_VALUE" x-cloak>
         <!-- children fields -->
      </div>
    </div>
- IMPORTANT: child blocks never placed after unrelated rows/columns.

Apply this specifically to:
- marital_status_id → marriage/children related fields/sections
- any other dependencies found in report/config

-------------------------------------------------
4) DUPLICATION REMOVAL — Decide single editable location
-------------------------------------------------
4.1 Primary contact duplication
- Search in views for "primary_contact" / "contact_number".
- Choose ONE canonical place:
  - If Contacts section exists: keep editable there, remove editable control from basic-info (replace with read-only display).
  - Or vice-versa (but only one editable).
- Ensure same mapping used in wizard + full edit.

4.2 Marriage/children duplication
- Remove duplication between basic_info partial and marriages section.
- Pick one canonical section:
  - Prefer: keep marriage/children in marriages section.
  - In basic-info show only marital_status_id (and read-only summary, optional) — BUT no duplicate inputs.

-------------------------------------------------
5) CANONICAL FIELD CATALOG + LAYOUT — Stop drift across 3 UIs
-------------------------------------------------
You already have routes:
- admin/profile-field-config (index/update)
Use it as canonical order/visibility store.

TASK:
- Find existing implementation behind:
  - route: admin/profile-field-config.index / update
- Create “FieldCatalog” class (or config) that is the single definition of:
  - field key
  - input type
  - option source
  - dependencies
  - section placement
- Then:
  - Wizard renders section by catalog
  - Edit-full renders by same catalog (all sections)
  - Intake preview renders by same catalog (read-only)

IMPORTANT:
- Do NOT store structured entities as JSON blob. Catalog is config/metadata only.

-------------------------------------------------
6) MUTATION GOVERNANCE — Ensure forms persist via MutationService
-------------------------------------------------
TASK:
- Locate POST endpoints:
  - matrimony.profile.store
  - matrimony.profile.update-full
  - matrimony.profile.wizard.save (POST)
- Verify controller actions:
  - No direct $profile->update([...]) or save() for profile data fields.
  - All changes pass through MutationService.
- If any direct update exists:
  - Replace with MutationService call.
  - Ensure conflict handling for critical fields is enforced as per SSOT (no silent overwrite).

-------------------------------------------------
7) FULL EDIT PARITY — Photo missing fix
-------------------------------------------------
OBSERVED:
- edit-full blade मध्ये photo section include नाही (report).

TASK:
- Find: resources/views/.../full.blade.php (exact path per repo)
- Add include for photo component/section in correct order (near end, before submit).
- Ensure same photo component is used in wizard upload-photo route too (no drift).

-------------------------------------------------
8) VERIFICATION (MUST RUN)
-------------------------------------------------
Run and paste results:
1) php artisan test (if tests exist)
2) php artisan route:list | findstr /I "matrimony/profile/wizard matrimony/profile/edit matrimony/profile/edit-full"
3) Manual smoke checklist:
   - Edit-full: NO missing field (photo included)
   - Wizard: religion/caste/subcaste controls appear where expected
   - Address typeahead works in:
     a) own address
     b) birth place
     c) native place
     d) work location
     e) relative address (if UI exists)
   - Dependent fields: marital_status change shows/hides immediately WITHOUT reload and child fields are adjacent.
   - Save persists for: specialization, occupation_title, company_name, annual_income, family_income, parents names/occupations, brothers_count, sisters_count, work_city_id, work_state_id.

-------------------------------------------------
9) ROLLBACK NOTES
-------------------------------------------------
- If UI breaks: rollback the new components (location-typeahead, religion-caste-selector) usage and revert to previous inline implementations.
- If save logic breaks: revert controller mutation wiring to previous stable commit, but keep $fillable additions (safe) only if MutationService controls actual writes.

DELIVERABLES FROM CURSOR:
- List of modified files with brief reason
- Confirmation: No direct update() on profile writes (or list where it was fixed)
- Screenshots (optional): edit-full and wizard showing aligned fields

====================================================================
END
====================================================================
############################################################




============================================================
PHASE-5 TEMPORARY GOVERNANCE REDUCTION MODE (TGRM)
============================================================

Effective: Day-28 (Temporary Stabilization Phase Only)
Status: STRICTLY TEMPORARY
Expires: End of Phase-5C

Purpose:
To stabilize Apply Pipeline due to runtime instability,
the following components are temporarily disabled:

1) ConflictDetectionService execution inside MutationService
2) Field Lock Enforcement
3) profile_change_history writes during intake mutation

STRICT LIMITATIONS:

• Duplicate Detection remains ACTIVE.
• Lifecycle transitions must still use ProfileLifecycleService.
• No direct controller mutation allowed.
• No schema changes allowed.
• No JSON storage allowed.
• mutation_log must still be written.
• Intake finalization must still follow approval pipeline.

CRITICAL WARNING:

This mode is temporary stabilization only.
Before Phase-5C completion:

• ConflictDetectionService must be fully restored.
• Field Lock enforcement must be restored.
• profile_change_history must be restored.
• Full SSOT compliance must be re-validated.

Operating beyond Phase-5C in TGRM mode
is considered governance violation.
---------------------------------
CURSOR PROMPT — Day 31 Part 2 Addendum: Reusable Sibling Details Engine (SSOT-compliant)

GOAL
Implement a reusable “Sibling Details Engine” for the profile wizard/full edit:
- User can add optional sibling entries.
- For each entry:
  - relation_type: Brother or Sister
  - sibling_name (required only if the entry is created; otherwise section can be empty)
  - married_toggle (on/off)
  - If married_toggle = ON: show spouse sub-form immediately below (optional)
    - spouse_name, spouse_address (location engine), spouse_contact, spouse_occupation
  - Sibling extra fields (optional):
    - sibling_occupation, sibling_city (location engine), sibling_contact, sibling_notes (free text)
- Everything optional overall; user can skip entire section.
- Must be reusable: can be reused later for relatives (mama/kaka/etc.) without rewriting UI logic.

NON-NEGOTIABLE CONSTRAINTS (PHASE-5 SSOT)
- No JSON blob storage for structured entities (siblings/spouse must NOT be stored as JSON).
- No direct update()/save() on profile data. All mutations must go through MutationService.
- Zero data loss: do not silently overwrite existing sibling entries; handle create/update/delete explicitly.
- Intake raw text immutable (not part of this change).

STEP 1 — Confirm existing schema (DO THIS FIRST)
Search the repo for any existing siblings tables/models:
- migrations: database/migrations/*sibling*
- models: app/Models/*Sibling*
- relationships on MatrimonyProfile model
If siblings already exist, adapt instead of creating new tables.

If NO siblings schema exists, create normalized tables:

STEP 2 — Create normalized DB tables (NO JSON)
2.1 Migration: create_matrimony_profile_siblings_table
Columns (suggested):
- id (PK)
- matrimony_profile_id (FK -> matrimony_profiles.id, indexed)
- relation_type (enum/string: 'brother'|'sister')  [string ok, enforce validation]
- name (nullable string)
- is_married (boolean, default null or false)
- occupation_title (nullable string)
- city_id (nullable FK to cities or existing location id strategy)
- contact_number (nullable string)
- notes (nullable text)
- sort_order (int, default 0)
- created_at/updated_at/deleted_at (soft deletes recommended)

2.2 Migration: create_matrimony_profile_sibling_spouses_table
- id (PK)
- sibling_id (FK -> matrimony_profile_siblings.id, unique index if 1 spouse per sibling)
- name (nullable string)
- occupation_title (nullable string)
- contact_number (nullable string)
- address_line (nullable string OR reuse location engine fields if available)
- city_id/state_id/district_id/taluka_id (ONLY if your location engine uses those)
- created_at/updated_at/deleted_at

IMPORTANT:
- Use the same location ID strategy that already exists (you already have a typeahead engine).
- DO NOT introduce parallel location fields if a standard exists.

STEP 3 — Models + Relationships
- app/Models/MatrimonyProfileSibling.php (SoftDeletes)
- app/Models/MatrimonyProfileSiblingSpouse.php (SoftDeletes)
Relationships:
- MatrimonyProfile hasMany siblings()
- MatrimonyProfileSibling belongsTo profile(), hasOne spouse()

STEP 4 — MutationService (SSOT governance)
Add Mutation(s) for siblings:
- Create sibling
- Update sibling
- Delete sibling (soft delete)
- Upsert spouse for sibling (create/update/clear)
Rules:
- Do not overwrite siblings list blindly.
- Use stable identifiers per row (id).
- When UI sends removed rows: mark deleted_at (soft delete).
- Track edited_source/edited_by consistent with existing profile edit patterns.
- If your project uses conflict_records for critical changes, apply the same governance rules (at minimum, avoid silent destructive overwrites).

STEP 5 — Reusable UI Component (Blade + JS/Alpine)
Create a reusable component that can be used in wizard + full edit:

Option A (Recommended): Blade component
- resources/views/components/repeaters/sibling-details.blade.php
Inputs:
- initial siblings array (from DB)
- location engine config (so it can reuse the same typeahead component you already have)
Behavior:
- “Add Sibling” button adds a new row UI.
- Each row shows:
  - Relation select (Brother/Sister)
  - Name input
  - Married toggle (switch)
  - If married ON: spouse block appears directly below (no reload)
  - Sibling extra fields: occupation, city (typeahead), contact, notes
  - Remove row button (marks for deletion)

IMPORTANT UX RULES
- The spouse block must be immediately below the married toggle within the same row (adjacent grouping).
- Everything optional: do not show validation errors unless user entered partial data that violates format.
- Keep it compact: avoid large vertical spacing.

STEP 6 — Reuse the existing Location Typeahead Engine everywhere
You said:
- address, birth place, native place, job location, relatives address — should use the same engine.
So inside sibling-details component, DO NOT build a new city dropdown.
Instead:
- Use the already-existing location-typeahead component/JS module (extract it if it is currently embedded in one page).
- Same for spouse city/address.
Outcome: “update one place → works everywhere”.

STEP 7 — Controller wiring (Wizard + Full Edit)
Find the routes already present:
- matrimony/profile/wizard/full (or wizard sections)
- matrimony/profile/update-full
Integrate:
- Render siblings component in the “Siblings” section.
- On submit, map request payload to MutationService calls.

Request payload design (NO JSON storage, but request can send arrays):
- siblings[0][id]
- siblings[0][relation_type]
- siblings[0][name]
- siblings[0][is_married]
- siblings[0][occupation_title]
- siblings[0][city_id]
- siblings[0][contact_number]
- siblings[0][notes]
- siblings[0][spouse][name]
- siblings[0][spouse][occupation_title]
- siblings[0][spouse][contact_number]
- siblings[0][spouse][address_line] / location ids

Server-side validation:
- relation_type in [brother,sister] if present
- contact number format if present
- if spouse fields present but is_married is false: either ignore spouse fields or set is_married true; pick one consistent rule (prefer ignore spouse unless married ON)

STEP 8 — Keep counts separate (brothers_count, sisters_count)
You already have:
- brothers_count, sisters_count columns in matrimony_profiles.
Do NOT auto-derive counts from siblings list unless you explicitly choose to and document it.
Simplest:
- Keep counts as separate summary fields (fast for matching).
- Sibling details list is optional enrichment (can be empty even if counts > 0).

STEP 9 — Verification Checklist
Run:
- php artisan migrate
- php artisan test (if present)
Manual:
1) Wizard/full “Siblings” loads with zero entries by default.
2) Add Sister:
   - enter name
   - toggle married ON -> spouse block appears immediately below
   - fill spouse optional fields
   - save -> persists -> reload page -> data still shown
3) Toggle married OFF -> spouse block hides; saving clears spouse OR keeps but not shown (choose one rule; recommended: clear spouse on save if married OFF)
4) Location typeahead works for sibling city and spouse address/city (reused engine).
5) Remove sibling entry -> soft deleted (not hard delete), and it disappears on reload.
6) No direct profile->update() used for this feature; MutationService only.

DELIVERABLES
- List of files changed (paths)
- Migration names
- New models/services
- Where the reusable component is used (wizard + full edit)

END
============================================================
END OF TEMPORARY GOVERNANCE REDUCTION MODE
============================================================

DAY-32 (SSOT ADD) — Contact Request System (Consent + Privacy + Governance)
Goal

Interest accept = mutual allowed only.
Contact details default hidden.
Contact reveal only via explicit receiver consent through “Request Contact” flow.

---------------------------------------------------------------------
Day-32 Implementation Order (क्रम — या क्रमाने implement करावे)
---------------------------------------------------------------------

Step 1 — Policy & schema
- Add config/settings for communication policy (contact_request_mode, reject_cooldown_days, pending_expiry_days, grant_duration_options). Use config file or dedicated table; default values per SSOT.
- Migrations: contact_requests table (sender_id, receiver_id, reason, requested_scopes, status, cooldown_ends_at, etc.), contact_grants table (request_id, granted_scopes, valid_until, revoked_at, etc.). Ensure admin_audit_logs exists for policy changes.

Step 2 — Models & state machine
- Models: ContactRequest, ContactGrant (or equivalent). Relationships to User/MatrimonyProfile.
- Enforce state machine (pending → accepted|rejected|expired|cancelled; accepted → revoked|expired). Cooldown check on create (reject_cooldown_days). Pending expiry job or check (pending_expiry_days).

Step 3 — Core service layer
- Service(s): create contact request (validate mutual if policy, cooldown, max per day), approve (create grant, set valid_until from receiver choice), reject (set cooldown_ends_at), cancel, revoke grant, expire. Contact reveal logic: return only approved scopes for a valid grant; default hidden otherwise.

Step 4 — Viewer profile page (Sender) — Section A
- Profile show (viewer = sender): Button states from request/grant state (Request Contact | Request Sent (Pending) | View Contact | Request Rejected | Request Expired | Contact no longer available). Request Contact modal: reason dropdown (required), requested scopes (Email/WhatsApp/Call). Submit → service create. Cancel request if pending.

Step 5 — Receiver inbox — Section B
- Receiver UI: Tabs "Requests (Pending)" and "Access Granted (Active)". Pending: cards with sender snapshot, reason, requested scopes; Approve / Reject. Approve modal: duration (once / 7 days / 30 days from policy), approved scopes (receiver may reduce). Active: list who has access + scopes + granted_at + valid_until; Revoke action. All actions via service layer; audit where required.

Step 6 — Partial reveal & revoke — Sections C, D
- Sender "View Contact": show only approved scopes (email/phone/WhatsApp as per grant), valid_until, revoke notice. Report abuse affordance. After revoke: sender sees "Contact no longer available"; viewing disabled.

Step 7 — Notifications & audit — Sections G, H
- Notifications: receiver (new request), sender (accepted / rejected / revoked / expired). Optional: in-app first; email later. Receiver "Contact Requests & Access" view: history, active grants, revoke from same screen. All state changes and revokes produce audit records.

Step 8 — Admin policy & governance — Section I + Admin block (A–K)
- Admin UI: edit communication policy (contact_request_mode, reject_cooldown_days, pending_expiry_days, max_requests_per_day, grant_duration_options, allowed_contact_scopes). Every change → admin_audit_logs (admin_user_id, reason, previous_value, new_value). Optional: user-level restrictions (F), emergency controls (I), investigation tools (H). No silent overwrite of existing grants/requests.

Dependency rule: Step 1 → 2 → 3 must be done before any UI (4–8). Steps 4–6 can be parallel after 3; 7–8 after 4–6 or in parallel where no UI dependency.

---------------------------------------------------------------------

A) Viewer Profile Page (Sender side)
Button states

No request/grant exists → Request Contact

Existing request = pending → Request Sent (Pending) (+ optional Cancel)

Request/grant = accepted AND grant valid → View Contact

Request = rejected → Request Rejected (Cooling period active) + show cooldown_ends_at

Request = expired → Request Expired (allowed to request again if not blocked by cooldown)

Grant = revoked → Contact no longer available (new request allowed only if cooldown rules allow)

Request Contact modal

Why are you requesting contact? (required dropdown)

Talk to family

Meet

Need more details

Discuss marriage timeline

Other (requires short text)

Requested scopes (checkboxes; sender request)

Email

WhatsApp

Call

B) Receiver Inbox → Requests (Receiver side)
Tabs

Requests (Pending)
Card shows: sender snapshot + reason + requested scopes
Actions: Approve / Reject

Access Granted (Active)
Shows: who has access + scopes + granted_at + valid_until
Action: Revoke access

Approve modal (advanced consent)

Receiver chooses:

Approval duration:

Approve once

Approve for 7 days

Approve for 30 days

Approved scopes (receiver may reduce)

Email only / WhatsApp only / Call only / combination

C) Partial Reveal Rules (Scope-based contact visibility)

Sender sees only approved scopes.

UI must display: shared scope + validity (valid_until) + revoke notice.

“Call/WhatsApp only” is policy+UI guidance (cannot technically stop misuse), therefore include “Report abuse” affordance.

D) Revoke Access (Receiver control)

Receiver can revoke any active grant anytime.

After revoke:

Sender UI shows Contact no longer available

Viewing contact must be disabled

Audit event recorded

E) State Machine (Requests + Grants)
contact_requests.status

pending

accepted

rejected

expired

revoked

cancelled

Allowed transitions

pending → accepted | rejected | expired | cancelled

accepted → revoked | expired (grant validity ends)

rejected → (no new request allowed until cooldown ends)

expired/revoked/cancelled → new request allowed only if policy allows

F) Cooling Period Policy (Reject cooldown)
Rule

If receiver rejects a contact request, sender cannot submit another request to the same receiver until cooldown_ends_at.

Default

reject_cooldown_days = 90 (3 months)

Enforcement

On request creation:

If now < cooldown_ends_at for that sender→receiver pair → block with clear UI message

UI must show “Cooling period ends on {date}”.

G) Notifications (Required)

Receiver: “New contact request received”

Sender: “Request accepted” (include approved scopes + validity)

Sender: “Request rejected” (include cooldown end date)

Sender: “Access revoked”

Sender: “Request expired”

H) Audit & Transparency (Receiver)

Receiver must have a “Contact Requests & Access” view:

Who requested my contact (history)

Who currently has access (active grants)

When accepted/rejected/revoked/expired

Revoke button from the same screen

I) Admin Policy Settings (Governance-controlled)

Admin can configure (policy-only; must be audited):

reject_cooldown_days (default 90; allowed range 7–365)

pending_expiry_days (default 7)

max_requests_per_day_per_sender (optional anti-spam)

Enable/disable “Approve once / 7 days / 30 days” options (optional)
All admin changes must write admin_audit_logs with reason.

Non-goals (Day-32 scope boundaries)

No monetization/credits integration unless explicitly planned.

No architecture refactor; implement as minimal additions within SSOT governance.

No silent overwrites; all changes must be auditable.

DAY-32 (SSOT ADD) — Admin Governance & Policy Control for Communication

Goal
Provide centralized, auditable admin control over Interest, Messaging, and Contact Request systems
without violating SSOT principles (no silent overwrite, full auditability, policy-driven behavior).

All communication behavior must be governed by configurable policies rather than hard-coded rules.

---------------------------------------------------------------------

A) Communication Policy Settings (Admin Controlled)

Admin may configure the following platform communication rules.

chat_requires_mutual_interest
    true  → messaging allowed only after mutual interest
    false → messaging allowed without mutual interest

contact_request_mode
    mutual_only     → contact request allowed only after mutual interest
    direct_allowed  → direct contact request allowed without mutual interest
    disabled        → contact request system disabled globally

allow_contact_request
    true / false

allow_messaging
    true / false

All changes must be recorded in admin_audit_logs with reason.

---------------------------------------------------------------------

B) Contact Request Policy Controls

Admin configurable policies:

reject_cooldown_days
    default: 90
    allowed range: 7–365

pending_expiry_days
    default: 7
    allowed range: 1–30

max_requests_per_day_per_sender
    default: system defined
    purpose: anti-spam protection

allowed_contact_scopes
    email
    phone
    whatsapp

Admin may enable or disable specific scopes.

---------------------------------------------------------------------

C) Contact Grant Duration Options (Admin Configurable)

Admin controls which approval duration options are available to receivers.

grant_duration_options

    approve_once
    approve_7_days
    approve_30_days

Admin may enable or disable any option.

Receiver may reduce scope but cannot exceed allowed options.

---------------------------------------------------------------------

D) Messaging Safety Controls

Admin may configure messaging safety limits.

max_messages_per_day_per_sender
max_conversations_per_day
message_rate_limit

Optional abuse protection features:

phone_number_detection_warning
email_detection_warning
external_contact_warning

These warnings do not block messages but provide safety guidance.

---------------------------------------------------------------------

E) Anti-Spam Controls

Admin may enforce system-wide limits:

max_interest_per_day_per_sender
max_contact_requests_per_day_per_sender
max_messages_per_day_per_sender

System must block actions exceeding limits and show clear UI feedback.

---------------------------------------------------------------------

F) Admin Communication Restrictions

Admin may restrict individual users for safety or abuse control.

Possible restrictions:

disable_interest_sending
disable_contact_requests
disable_messaging
rate_limit_messaging
temporary_communication_ban

All restrictions must be auditable.

---------------------------------------------------------------------

G) Contact Access Administrative Override

Admin may perform controlled overrides:

revoke_contact_access
extend_contact_access
expire_contact_access

All overrides must create an audit record and must never silently overwrite existing grants.

---------------------------------------------------------------------

H) Investigation & Transparency Tools (Admin View)

Admin panel must provide visibility into communication events.

Admin must be able to view:

Interest history
Contact request history
Contact access grants
Contact revocations
Message conversation metadata

Timeline example:

Interest Sent
Interest Accepted
Contact Request Created
Request Approved / Rejected
Contact Access Granted
Contact Access Revoked
Contact Access Expired

---------------------------------------------------------------------

I) Global Emergency Controls

Admin may temporarily enforce system-wide safety measures:

disable_contact_requests_globally
disable_messaging_globally
restrict_new_accounts_messaging

All emergency actions must be logged in admin_audit_logs.

---------------------------------------------------------------------

J) Governance Requirements

All admin policy changes must:

1. Write entry to admin_audit_logs
2. Record admin_user_id
3. Record change reason
4. Record previous_value and new_value

No policy change may silently alter existing user grants or requests.

---------------------------------------------------------------------

K) Non-Goals (Admin Governance Scope)

This section does NOT introduce:

Monetization rules
Credits or paywall logic
Architecture refactoring
Untracked policy overrides

All communication features must remain compliant with SSOT mutation governance.
--------------------------------------
# DAY-33 (SSOT ADD) — P3: Who Viewed My Profile (Views Logging + Count/List + Privacy + Governance)

**PROJECT:** Laravel Matrimony  
**MODE:** PHASE-5 SSOT STRICT  
**SCOPE:** Spec + Governance + Test Rules (No code here)  
**GOAL:** Provide production-grade “Who viewed my profile” with correct privacy, abuse safety, and analytics-friendly signals.

---

## 1) Core Outcomes
1) Log profile views when a logged-in user views another user’s profile (profile show page).
2) Show:
   - **View Count** (“X people viewed your profile”)
   - **Recent Viewers List** (optional but recommended; last N viewers with name/photo + viewed_at)
3) Enforce privacy + block rules:
   - Self-view not logged.
   - Blocked users excluded.
   - **Admin-originated views MUST never appear to users** (count or list).
4) Support analytics / AI matching:
   - If one user views another user’s profile multiple times in a day (e.g., 4 times), that **must be counted for AI matching signals**.

---

## 2) Logged-in Only
- Only **authenticated viewers** produce view logs.
- Guest/unregistered visitors are not logged (no stable identity).

---

## 3) Two-Signal Model (Matrimony-specific requirement)
Matrimony needs **two different view metrics**:

### A) “Who Viewed Me” (User-facing)
- Purpose: user trust + transparency.
- Must be **privacy-safe** and not spam-inflated.
- Uses **deduped unique views** (see Dedup Policy).

### B) “AI View Signals” (Internal analytics / matching)
- Purpose: ranking/matching signals.
- Must capture **repeat views** (e.g., 4 views in a day counts as 4).
- Not shown directly to user (internal counters/aggregates).

> Therefore: **User-facing view count/list ≠ AI view count** by design.

---

## 4) Storage (Additive Only)
Create a new table (example name) `profile_views` (additive migration; no existing tables altered).

### 4.1) profile_views (raw event log)
Minimum logical fields:
- viewer_user_id
- viewed_user_id
- viewed_at (timestamp)
- viewer_type (enum): user | admin | system
- is_anonymous (boolean) — only if anonymous browsing is supported
- metadata (optional): source surface (search/profile/dashboard), request_id, etc.

**Rules:**
- Self-view (viewer_user_id == viewed_user_id) → DO NOT log.
- viewer_type != user (admin/system) → DO log only if needed for internal audit, BUT MUST be excluded from all user-facing counts/lists.

### 4.2) Optional aggregation tables (recommended for scale)
If required for performance, introduce additive aggregates:
- `profile_view_daily_stats`:
  - viewer_user_id, viewed_user_id, date, view_count (for AI signals)
- `profile_view_unique_daily` (or a derived index):
  - viewer_user_id, viewed_user_id, date (unique view marker for user-facing dedupe)

> Aggregates are optional initially, but **policy must define the two-signal model**.

---

## 5) User-Facing “Who Viewed Me” Display Rules

### 5.1) User-facing Unique Count
- Count should represent **unique viewers** within a defined window (default 30 days).
- Multiple visits by the same viewer in a day count as **1** for user-facing count/list.

### 5.2) Recent Viewers List
- Show last N unique viewers (default N=10) with:
  - viewer name/photo summary
  - “viewed_at” (latest time within the window)
- Exclude:
  - blocked users (either direction)
  - suspended/deactivated accounts
  - viewer_type != user (admin/system)
  - anonymous viewers (if policy excludes them from list)

### 5.3) Where to show
- Dashboard card: “Recent profile views” (last 5–10)
- Dedicated page: “Who viewed my profile” (paginated)
- Both are recommended.

---

## 6) AI Matching / Analytics Signal Rules (Repeat Views)
- For AI signals, **every view event counts**, including multiple views per day.
- Example: Same viewer opens the same profile 4 times in one day → AI daily count = 4.
- This signal is stored/aggregated separately and is not shown directly to end user.

---

## 7) Dedup Policy (User-facing only)
**User-facing dedupe key:** (viewer_user_id, viewed_user_id, date)

- If a viewer views the same profile multiple times in the same day:
  - user-facing unique count/list → count as 1
  - AI view count → count as N (actual number)

---

## 8) Admin/System Views Policy (Hard Rule)
- Any view performed by:
  - admin accounts
  - system/service accounts
  - moderation review tools
  MUST **never** appear in:
  - “Who viewed me” count
  - “Who viewed me” list
  - notifications (if any for views)
- This rule is enforced regardless of reason (support, moderation, testing).

> Demo testing note: demo users should behave like real users. If admin tests flows, those admin views must remain invisible to users.

---

## 9) Block & Privacy Rules (Both Directions)
### 9.1) Block enforcement
If A blocks B OR B blocks A:
- Do not log B→A view events for user-facing features
- Do not show any historical B views in A’s who-viewed screens
- Optionally also exclude from AI signals (policy decision):
  - Recommended: exclude from user-facing; for AI signals, exclude if block is active (to avoid unfair signals)

### 9.2) Anonymous browsing (Optional)
If supported:
- Policy toggles:
  - anonymous_browsing_enabled
  - include_anonymous_in_count (true/false)
  - show_anonymous_in_list (always false; list requires identity)
- Default safe policy:
  - anonymous views can increase count optionally, but cannot appear in list.

---

## 10) Retention & Purge (Mandatory Governance)
- retention_days default: 30 (or 90 based on product decision)
- A scheduled purge job deletes raw view events older than retention_days.
- Aggregates may retain longer if needed for analytics (policy-defined).

---

## 11) Rate Limits / Abuse Safety
- Apply rate limiting on view logging writes (per viewer).
- Prevent automated refresh spamming from overwhelming DB.
- Dedupe already reduces user-facing inflation; rate limit protects infrastructure.

---

## 12) Performance Requirements (Minimum)
- Indexes required on:
  - (viewed_user_id, viewed_at)
  - (viewer_user_id, viewed_user_id, viewed_at)
  - (viewer_type)
- Dashboard card may use caching (5–10 minutes) to avoid heavy counts.

---

## 13) Admin Policy Settings (Configurable, Audited)
Admin Panel → Settings → “Who Viewed Policy”
- retention_days (default 30/90)
- user_facing_unique_window = daily (fixed) OR hours-based (optional; default daily)
- recent_viewers_limit (default 10)
- list_enabled (default true)
- anonymous_browsing_enabled (default false)
- include_anonymous_in_count (default false)
- include_blocked_in_ai_signals (default false)
- exclude_admin_views_always (hard true; not configurable)

**Governance:**
- Any settings change requires:
  - admin_audit_logs entry
  - reason mandatory
  - old_value → new_value diff
  - admin_id + timestamp

---

## 14) Acceptance Tests (Demo User Testing Rules)

### T1) Self-view not logged
- User opens own profile → no record; no count change.

### T2) Unique vs repeat views
- User A opens User B profile 4 times same day:
  - Who-viewed (B): A appears once (unique)
  - AI signal: daily count = 4

### T3) Admin view invisibility
- Admin opens user profile for any reason:
  - User-facing who-viewed count/list: NO change; admin never appears

### T4) Block behavior
- A blocks B:
  - B viewing A should not appear in A’s list/count
  - Existing historical B views should disappear from A’s view screens
  - AI signals: excluded if policy says exclude when blocked

### T5) Retention
- Views older than retention_days are purged and no longer count.

### T6) Suspended/deactivated viewers
- If viewer account suspended/deactivated:
  - Exclude from list and count.

---

## Non-goals (Day-34)
- No “Who viewed” monetization/credits gating.
- No new recommendation algorithms; only logging and policy-based display.
- No architecture refactor; additive tables only; SSOT governance enforced.

----------------------------------------------------------------------

# DAY-34 (SSOT ADD) — Unified Photo Upload Engine (User + Admin)

**PROJECT:** Laravel Matrimony  
**MODE:** PHASE-5 SSOT STRICT  
**SCOPE:** Single reusable photo engine for all profile images (user + admin). Mobile-app-first UX, WebP storage, crop/rotate, multi-slot gallery.

Goal: Avoid fragmented image handling. All profile-related photos (primary photo, extra photos, admin replacements) MUST go through a single, well-governed engine.

----------------------------------------------------------------------
1) Core Outcomes
----------------------------------------------------------------------

1) Single engine for ALL profile photos
   - One backend pipeline + one frontend component used everywhere:
     - User: profile photo (primary) + extra photos (up to 5 slots total)
     - Admin: photo moderation, replacement, emergency fixes
   - No ad-hoc uploads, no custom per-page image logic.

2) Mobile-app-first UX
   - Majority of users use the website inside a mobile app/webview.
   - Engine MUST be:
     - Touch-friendly (large buttons, safe tap targets, no tiny controls)
     - Performant on mid-range phones and 4G connections
     - Resilient to unstable networks (retry-friendly, small payloads)

3) Quality vs size target
   - Stored images in WebP (or equivalent modern format).
   - Target: typical stored image ≤ 200 KB, but visually high quality on mobile.
   - Downscale oversized originals; avoid serving multi-MB images.

4) Basic edits built-in
   - User can:
     - Crop
     - Rotate (90° steps)
     - Zoom/pan inside crop box
   - Future-safe: engine is extendable to filters/auto-enhance if needed.

5) Multi-photo support with primary slot
   - Each profile can have up to 5 photos.
   - Exactly one “primary” photo (used in search cards, profile header).
   - User can reorder photos (drag-drop or up/down arrows) and reselect primary.

----------------------------------------------------------------------
2) UX Requirements (User-Side, Mobile-First)
----------------------------------------------------------------------

2.1) Layout
-----------
- Top section:
  - Primary photo preview (circle mask for profile header, but internal storage keeps square/portrait).
  - Clear label: “Your primary photo”.
- Below:
  - 4 additional slots (thumbnails) with simple labels:
    - “Add photo”
    - “Change”
    - “Remove”

2.2) Actions
------------
For each slot:
- Upload / Replace
  - Opens system picker (camera/gallery) in mobile webview-friendly way.
- Edit
  - Opens crop/rotate modal.
- Remove
  - Soft-remove from profile gallery; does not delete original file history (if audit required) but removes link from profile_photos.

2.3) Cropper UX
----------------
- Single cropper component (e.g., Cropper.js or equivalent) wrapped in our own thin UI:
  - Large, edge-to-edge crop area for touch gestures.
  - Buttons:
    - “Rotate 90°”
    - “Reset”
    - “Save”
    - “Cancel”
- Aspect ratios:
  - Primary photo:
    - Stored internally as square or portrait (e.g., 4:5).
    - Displayed as circle in many places, so safe cropping area must account for that.
  - Extra photos:
    - Can be same ratio or “free” within min size limits.

2.4) Error feedback (Mobile-friendly)
-------------------------------------
- Clear, single-line messages:
  - “Photo too small. Please upload a clearer photo (at least 800×800).”
  - “File too large. Please choose a smaller photo or crop before upload.”
  - “Upload failed. Tap to retry.”
- Never show raw exception messages; always user-facing translations.

----------------------------------------------------------------------
3) Backend Storage & Processing (Additive Only)
----------------------------------------------------------------------

3.1) Existing constraints
-------------------------
- PHASE-5 PROTECTION DIRECTIVE:
  - Existing columns/tables MUST NOT be dropped, repurposed, or type-changed.
  - New behavior MUST be additive.

3.2) Tables (additive)
----------------------
- Existing:
  - `matrimony_profiles.profile_photo`:
    - Continues to hold the primary photo filename (backward compatible).
- New (additive):
  - `profile_photos`:
    - id
    - profile_id (FK → matrimony_profiles)
    - path_full (string)   — Web-optimized main version (e.g., 1200px longest edge)
    - path_thumb (string)  — Thumbnail (e.g., 300px)
    - is_primary (boolean, default false)
    - sort_order (integer, default 0)
    - created_at, updated_at
  - Optional later:
    - `profile_photo_audit_log` (additive) if high-governance for image changes is required.

3.3) Processing pipeline
------------------------
Upload flow:
- Step 1: Accept original upload (jpeg/png/webp/heic):
  - Validate mime + size + basic dimension check (e.g., min 800×800).
- Step 2: Normalize EXIF orientation.
- Step 3: Apply crop/rotate parameters from client:
  - Either:
    - Client sends cropped image (blob) → backend just validates + re-encodes.
    - OR client sends crop box/rotation metadata → backend performs crop.
- Step 4: Resize & encode:
  - Full image:
    - Max dimension: e.g., 1200 px longest edge.
    - Encode WebP with quality tuned for ≤ 200 KB typical size.
  - Thumb:
    - E.g., 300×300 square WebP.
- Step 5: Save files to disk/storage and persist `profile_photos` row.
- Step 6: If `is_primary = true`:
  - Update `matrimony_profiles.profile_photo` to point to that file’s full version.

3.4) Quality/size tuning
------------------------
- Photo-encoding policy:
  - Prefer **WebP** for all served images (with optional future AVIF).
  - Quality balancing:
    - Target ~150 KB median for full-size profile photos.
    - Hard cap: 200 KB (beyond that, re-attempt encode at lower quality or slightly smaller dimensions).
- For low-bandwidth users (app webview):
  - `srcset`/`sizes` can serve smaller variants on mobile widths when needed.

----------------------------------------------------------------------
4) Governance & Safety
----------------------------------------------------------------------

4.1) Moderation flags (re-use existing)
---------------------------------------
- `matrimony_profiles` existing moderation columns remain source of truth:
  - `photo_approved` (boolean)
  - `photo_rejected_at`
  - `photo_rejection_reason`
  - `is_suspended`
- Engine updates these via existing services; engine itself does NOT bypass moderation rules.

4.2) Admin mode
----------------
- The same engine MUST support an “admin mode”:
  - Admin can:
    - Change primary photo.
    - Add/remove extra photos.
    - Crop/rotate before approving.
  - Admin overrides MUST:
    - Write to audit logs (e.g., `admin_audit_logs`) with:
      - admin_id
      - action_type (photo_replace / crop / remove)
      - affected profile_id
      - reason
      - timestamps

4.3) Suspended / hidden profiles
--------------------------------
- When profile is not visible (`ProfileLifecycleService::isVisibleToOthers` = false):
  - Engine MUST NOT allow new photos to be visible to others until moderation/lifecycle allows.
  - User MAY still upload/re-crop (subject to product decision), but visibility is governed separately.

----------------------------------------------------------------------
5) API & Reuse (Engine Integration)
----------------------------------------------------------------------

5.1) HTTP endpoints (additive)
------------------------------
Add new routes (user-authenticated, additive only):
- `POST   /photos`          — Upload + create new `profile_photos` row.
- `PUT    /photos/{photo}`  — Re-crop / rotate existing photo.
- `DELETE /photos/{photo}`  — Remove a photo (soft delete or hide from gallery; actual delete policy per governance).
- `PUT    /photos/reorder`  — Update `sort_order` + primary flag.

Admin variants:
- `POST   /admin/photos/{profile}`          — Admin-triggered upload for specific profile.
- `PUT    /admin/photos/{photo}/moderate`   — Approve/reject/replace actions (always audited).

5.2) Frontend component contract
--------------------------------
Single JS/Blade component (`<x-photo-uploader />` or equivalent) config:
- Inputs:
  - `maxSlots` (default 5)
  - `allowMultiple` (true/false)
  - `initialPhotos` (JSON: id, urls, is_primary, sort_order)
  - `mode` = user | admin
  - `aspectRatio` options for primary vs extra photos.
- Emits:
  - Events for:
    - `photoUploaded` (with new photo data)
    - `photoUpdated`
    - `photoRemoved`
    - `orderChanged`

5.3) App/webview usage
----------------------
- Engine MUST work well inside an in-app webview:
  - Avoid complex multi-window flows.
  - Keep modals simple and single-screen.
  - Large buttons and obvious calls-to-action (Upload, Crop, Save).

----------------------------------------------------------------------
6) Non-Goals (Day-34 Photo Engine)
----------------------------------------------------------------------

- No AI-based beauty filters or auto-face-beautification in Phase-5.
- No client-side heavy ML models (must remain light enough for mid-range phones).
- No external CDN vendor lock-in; design must work with local storage first, CDN optional.
- No new database-level hard constraints that would break existing photos (engine must respect PHASE-5 additive rules).

----------------------------------------------------------------------
7) Admin Photo Engine Settings (Panel)
----------------------------------------------------------------------

7.1) Photo verification & visibility policy
------------------------------------------
- Setting: `photo_approval_required` (already present)
  - false (default): User uploads photo → visible immediately after upload.
  - true: User uploads photo → hidden until admin approves.

- New settings (additive via AdminSetting):
  - `photo_primary_required` (default true)
    - true: profile cannot be considered “complete/live” without a primary photo.
    - false: profiles may be live without a photo (not recommended).
  - `photo_max_per_profile` (default 5)
    - Soft enforcement in UI/service; engine disallows more than this slots.

7.2) Size & quality controls
----------------------------
- `photo_max_upload_mb` (default 8)
  - Validation limit for original uploads (e.g., 8 MB).
- `photo_max_edge_px` (default 1200)
  - Longest side after resize; preserves enough quality for mobile.
- `photo_webp_quality_high` (default 80)
  - Initial WebP encode quality.
- `photo_target_max_kb` (default 200)
  - If encoded file > target_max_kb:
    - Re-encode at slightly lower quality (e.g., 70).
    - If still larger, accept as-is (do NOT corrupt image).

7.3) Moderation & abuse safety
------------------------------
- `photo_auto_suspend_threshold` (optional; default null)
  - If set to N, and a profile has ≥ N rejected photos within a rolling window (e.g., 30 days), system:
    - MAY auto-flag or auto-suspend profile (configurable action).
- `photo_rejection_reasons` (stored as JSON in AdminSetting)
  - Array of strings used as quick-pick reasons in admin UI:
    - e.g. “Group photo (no clear face)”, “Not a real person”, “Low resolution / blurry”, “Objectionable content”.

7.4) Admin override permissions (policy, not RBAC implementation)
-----------------------------------------------------------------
- Define which admin roles MAY:
  - a) approve/reject photos.
  - b) directly replace primary photo (upload on behalf of user).
  - c) add/remove extra photos.
- Every override MUST:
  - Write to `admin_audit_logs` with:
    - admin_id
    - action_type (photo_approve, photo_reject, photo_replace, photo_remove)
    - profile_id
    - reason
    - timestamps

7.5) User-facing messaging config
---------------------------------
- `photo_guidelines_text` (short text shown below uploader)
  - e.g. “Clear face, no sunglasses, no group photos, no watermarks.”
- `photo_reject_default_message`
  - Default user-visible message if admin does not provide a custom reason.
- `photo_under_review_text`
  - Text for “under review” badge in dashboard/profile when approval_required = true.

----------------------------------------------------------------------
# DAY-35 (SSOT ADD) — Smart Biodata Intake Engine (AI + Rules + Governance)
----------------------------------------------------------------------

**PROJECT:** Laravel Matrimony  
**MODE:** PHASE-5 SSOT STRICT  
**SCOPE:** Unified biodata intake engine for PDF/image/text uploads, AI-assisted parsing, rule-based normalization, and admin-governed limits.

Goal: Make it extremely easy for users to upload any biodata format, while keeping AI costs under control via caching, rules, and versioning.

----------------------------------------------------------------------
1) Core Outcomes
----------------------------------------------------------------------

1) Single intake engine entry-point
   - One upload UI for biodata:
     - PDF (scanned biodata)
     - Images (photos/scans of biodata pages)
     - Direct text (copy-paste or manual typing)
   - Every upload creates a `biodata_intakes` record (already exists) with clear status transitions:
     - uploaded → parsed → reviewed → attached_to_profile / discarded.

2) AI + rules two-layer parsing
   - AI layer:
     - Extracts structured JSON (name, DOB, gender, religion, caste, education, job, family, expectations, contact hints, etc.) + per-field confidence scores.
   - Rules layer:
     - Uses master tables, regexes, and known patterns to normalize and validate:
       - e.g., map free-text caste to `master_castes`, enforce date formats, validate phone/email patterns.
   - UI shows:
     - High-confidence suggestions (green)
     - Medium (yellow, “please check”)
     - Missing/low-confidence fields (red, manual input)

3) Feedback loop for continuous improvement
   - User/Admin actions are interpreted as signals:
     - Keeping AI suggestion as-is → implicit “correct”.
     - Editing a suggested value → implicit “correction”.
     - Adding a value where AI left it empty → “missed field”.
   - These signals are stored (internally) as training/evaluation data:
     - “raw_text + final approved JSON + parser_version”.
   - Over time:
     - Stable mapping patterns are converted into hard rules.
     - AI is reserved for ambiguous or narrative sections.

4) Cost-aware design
   - Avoid re-running AI on the same content:
     - For identical or near-identical files (hash match), reuse previous parse result (for that parser_version) instead of calling AI again.
   - As rule coverage increases:
     - Reduce AI calls to only:
       - New templates/layouts (no matching hash history)
       - Fields where rules explicitly mark “AI assist needed” (e.g., narratives).

----------------------------------------------------------------------
2) Upload UX (User-Side, Mobile-First)
----------------------------------------------------------------------

2.1) Input types
----------------
- PDF:
  - Single or small multi-page document (max pages configurable).
- Images:
  - One or more photos of biodata (front/back, multi-page).
- Text:
  - Paste or type biodata text; optional section headers suggested (Name, DOB, Education, Job, Family, Expectations).

2.2) Flow
---------
- Step 1: User picks source (PDF / Images / Text).
- Step 2: Upload/enter content.
- Step 3: Engine creates intake record and (if auto-parse enabled) schedules parsing job.
- Step 4: After parsing:
  - User sees side-by-side:
    - Original (thumbnail or text)
    - Parsed fields + confidence chips.
  - User can accept/edit field-by-field and then “Apply to my profile” (subject to existing governance rules).

----------------------------------------------------------------------
3) Storage, Versioning & Caching
----------------------------------------------------------------------

3.1) Versioning
---------------
- Every intake parse stores:
  - `parser_version` (e.g., `ai_v1`, `ai_v2`, `rules_only`).
  - `parsed_json` (structured fields + confidence map).
  - `parse_status` (`pending`, `success`, `error`).
  - `last_error` (short code/message for debugging).
- When a new parser version is introduced:
  - Existing intakes are NOT auto-reparsed unless explicitly triggered.
  - Admin can choose to re-parse individual intakes with the new version.

3.2) Content hashing & AI cache
-------------------------------
- For each uploaded intake, compute a stable hash of the canonicalized content:
  - PDF: per-page text + layout summary or full text concatenation.
  - Images: OCR text + basic layout fingerprint.
  - Text: normalized text (trimmed, normalized whitespace).
- Before calling AI:
  - Check if there is an existing parse result with:
    - same `content_hash`
    - same `parser_version`
  - If yes:
    - Reuse that parse result (no AI call, only cheap DB read).
  - If no:
    - Call AI and store result with `content_hash` and `parser_version` for future reuse.

3.3) Deduplication across users
-------------------------------
- If multiple users upload the exact same biodata file (e.g., same family template):
  - They will share the same cached parse result.
  - Profile-specific corrections still stored per-intake; cache only stores “first guess” from AI.

----------------------------------------------------------------------
4) Admin Controls (Intake Engine Settings)
----------------------------------------------------------------------

4.1) Upload limits & anti-abuse
-------------------------------
- Configurable via AdminSetting + admin panel:
  - `intake_max_pdf_mb` (default 10–15 MB).
  - `intake_max_pdf_pages` (default 5–10 pages).
  - `intake_max_images_per_intake` (default 5–10).
  - `intake_max_daily_per_user` (default 3–5).
  - `intake_max_monthly_per_user` (default 10–20).
  - Optional `intake_global_daily_cap` for infrastructure safety.
- Behaviour when limit exceeded:
  - Friendly message to user.
  - Internal log/flag for admin to review potential abuse.

4.2) AI & OCR configuration
---------------------------
- Settings:
  - `intake_auto_parse_enabled` (bool, default true).
  - `intake_active_parser` (enum: `rules_only`, `ai_v1`, `ai_v2`, ...).
  - `intake_ocr_provider` (enum: `tesseract`, `cloud_vision`, `off`).
  - `intake_ocr_language_hint` (`mr`, `en`, `mixed`).
  - `intake_parse_retry_limit` (max retries per intake).

4.3) Confidence & auto-apply policy
-----------------------------------
- Per-field configuration:
  - `intake_confidence_high_threshold` (e.g., 0.85).
  - `intake_auto_apply_fields` (set of fields where high-confidence values can be auto-prefilled on profile).
- Hard safety:
  - Contact fields (`phone`, `email`, `address`) never auto-applied; always user-reviewed.

4.4) Review workflow
--------------------
- Toggle:
  - `intake_require_admin_before_attach` (bool).
    - true: attaching intake to profile always requires admin approval.
    - false: trusted users may attach directly (still auditable).
- Admin UI:
  - See intake list with:
    - status, parser_version, parse_status, last_error, confidence summary.
  - Actions:
    - Parse/re-parse with selected version.
    - Approve/Reject intake mapping.
    - Attach to profile / Replace selected fields on a profile.

4.5) Privacy & retention
------------------------
- Settings:
  - `intake_file_retention_days` (e.g., 90).
  - `intake_keep_parsed_json_after_purge` (bool).
- Behaviour:
  - After retention_days, original files (PDF/images) are deleted.
  - Parsed JSON may be kept longer for audit/training if policy allows.
  - SSOT must clearly document user-visible consent text about this behaviour.

----------------------------------------------------------------------
5) Observability & Metrics
----------------------------------------------------------------------

- For each intake:
  - Store:
    - `parse_duration_ms`
    - `ai_calls_used` (count)
    - `fields_auto_filled` vs `fields_manually_edited`
  - Admin dashboard card:
    - Last 7/30 days:
      - # of intakes
      - parse success rate
      - average AI calls per intake
      - percentage of fields where AI suggestion was accepted without change.
- Use these metrics to:
  - Identify fields where rules can replace AI.
  - Track reduction in AI calls over time as rules harden.

APPEND THE FOLLOWING BLOCK AT THE VERY END OF "PHASE-5 SSOT.md" WITHOUT MODIFYING OR REINTERPRETING ANY EARLIER SSOT RULES.

############################################################
PHASE-5 SSOT ADD — PARTNER PREFERENCES ENGINE (RICH STRUCTURED MODEL)
############################################################

Purpose:
Partner Preferences must move from a minimal flat form to a fully structured, smart, guided, SSOT-governed engine.
This engine is part of Phase-5 manual profile editing / wizard editing scope.
This engine does NOT implement AI matching, ranking, scoring, or recommendation ordering.
It only captures, validates, governs, and stores structured partner preference data.

============================================================
1️⃣ GOVERNANCE STATUS
============================================================

Partner Preferences are a STRUCTURED relational entity.
They MUST NOT be stored in JSON blobs.
They MUST NOT be stored inside profile_extended_attributes.
They MUST NOT be silently inferred and persisted without explicit user save.
All mutations MUST pass through MutationService.
No direct update() calls allowed on preference data.
No silent overwrite allowed.
Where applicable, preference changes must write profile_change_history entries.

This SSOT addendum extends the existing "profile_preferences" entity only.
It does not weaken or override:
- Zero Data Loss
- No Silent Overwrite
- No JSON Blob Storage
- Intake Immutable
- Conflict-Safe Mutation
- MutationService-only discipline
- Age never stored on profile core table

============================================================
2️⃣ ENGINE GOAL
============================================================

The Partner Preferences engine must be:
- Structured
- Smartly auto-suggested
- Fully editable by user
- Non-destructive
- Wizard-compatible
- Manual edit compatible
- Future-ready for matching systems
- Fully normalized
- Fully auditable

Important distinction:
AUTO-SUGGESTION is allowed.
AUTO-SAVE is NOT allowed.

Meaning:
The system may compute recommended default preferences from the user's own filled profile data,
but those values become persistent only after explicit user save.

============================================================
3️⃣ AUTO-SUGGESTION / AUTOFILL LAW
============================================================

The engine may generate suggestion values based on current profile data, such as:
- date_of_birth derived age band logic
- height_cm based range suggestion
- religion / caste / sub_caste suggestion modes
- current country/state/district/city based location suggestions
- education snapshot based education preference suggestions
- annual_income / family_income based income preference suggestions
- marital_status based openness suggestions

However:
- Suggested values are UI defaults only until saved.
- Suggested values must not mutate DB on page load.
- Suggested values must not overwrite existing saved preferences unless user explicitly saves.
- Existing saved preferences always take priority over generated suggestions.
- If saved preferences exist, UI must load saved values first.
- If saved preferences do not exist, UI may load computed suggestions as unsaved defaults.

Transparency rule:
UI should clearly communicate that some values are "Suggested from your profile".

============================================================
4️⃣ STRUCTURE MODEL — PROFILE_PREFERENCES (EXPANDED)
============================================================

"profile_preferences" remains a one-to-one profile entity.

It must support rich structured fields for partner preferences.

A. AGE / HEIGHT
- preferred_age_min
- preferred_age_max
- preferred_height_min_cm
- preferred_height_max_cm

B. COMMUNITY / IDENTITY
- preferred_religion
- preferred_caste
- preferred_sub_caste
- preferred_mother_tongue

C. LOCATION
- preferred_country_id
- preferred_state_id
- preferred_district_id
- preferred_city_id

D. EDUCATION & CAREER
- preferred_education_min
- preferred_education
- preferred_working_with
- preferred_profession_area
- preferred_occupation_title
- preferred_income_min
- preferred_income_max
- preferred_income_currency

E. MARITAL / FAMILY / LIFESTYLE
- preferred_marital_status
- preferred_profile_managed_by
- preferred_diet

F. OPENNESS / STRICTNESS FLAGS
For each major filter, structured strictness must be supported.
Allowed values:
- must_match
- preferred
- open

At minimum strictness columns must exist for:
- religion_strictness
- caste_strictness
- location_strictness
- education_strictness
- income_strictness
- marital_status_strictness
- diet_strictness

G. QUICK TOGGLES
Structured boolean flags allowed:
- prefer_same_city
- prefer_same_district
- prefer_same_state
- open_to_relocation
- income_not_important

H. PRESET MODE
A structured preset field is allowed:
- preference_preset

Allowed preset values:
- traditional
- balanced
- broad
- custom

No JSON arrays.
No JSON rule bundles.
No mixed serialized structures.

If in future multi-select support is required for categories like caste / mother tongue / profession,
that must be implemented through normalized relational design, NOT JSON blobs.

============================================================
5️⃣ NORMALIZATION RULE
============================================================

If any preference requires multiple selectable values, SSOT law is:

Single-value preference:
- store directly in profile_preferences

Multi-value preference:
- create a dedicated normalized child table
- one row per selected option
- no CSV strings
- no JSON arrays

Examples of future-valid normalized child tables if needed:
- profile_preference_mother_tongues
- profile_preference_castes
- profile_preference_professions
- profile_preference_locations

Do NOT prematurely add child tables unless actual UI / business need exists.
But under no condition may multi-value data be stored as JSON blob.

============================================================
6️⃣ AGE LAW
============================================================

Profile core age must never be stored.
Age continues to be derived from date_of_birth only.

Partner preference age range IS allowed as explicit structured preference:
- preferred_age_min
- preferred_age_max

These are preference values, not stored profile age.
This does NOT violate "Age Never Stored" because the prohibition applies to profile core age as a stored attribute.

============================================================
7️⃣ UI / UX REQUIREMENTS
============================================================

Partner Preferences engine must be upgraded from a flat form into sectioned smart UI.

Required sections:

1. Basic Preferences
- Age range
- Height range
- Marital status preference

2. Community Preferences
- Religion
- Caste
- Sub-caste
- Mother tongue

3. Location Preferences
- Country
- State
- District / City
- Same city / district / state shortcuts
- Relocation openness

4. Education & Career Preferences
- Minimum education
- Education preference
- Profession area
- Working with
- Occupation
- Income range
- Income not important toggle

5. Lifestyle / Other Preferences
- Diet
- Profile managed by

6. Flexibility / Strictness
- Must Match
- Preferred
- Open

The UI must support:
- smart suggested defaults
- editable controls
- section-wise open behavior
- preset-based quick fill
- explicit save only

The UI must not:
- show raw JSON
- auto-save on page load
- silently replace saved user values
- hide important filters inside opaque structures

============================================================
8️⃣ PRESET LAW
============================================================

The engine may provide quick presets:

- traditional
- balanced
- broad
- custom

Preset behavior:
- Preset may fill or suggest multiple structured fields at once in UI
- Preset must remain fully editable
- Preset application must not persist until save
- If user manually changes fields after preset, preset may become "custom"

Preset definitions must remain deterministic and code-driven.
No AI-generated hidden preset logic.
No non-auditable black-box preset mutation.

============================================================
9️⃣ VALIDATION LAW
============================================================

Validation examples:
- preferred_age_min <= preferred_age_max
- preferred_height_min_cm <= preferred_height_max_cm
- preferred_income_min <= preferred_income_max
- strictness values limited to:
  must_match / preferred / open
- preset limited to:
  traditional / balanced / broad / custom
- foreign keys must be valid where IDs are used
- nullable fields allowed where "open" behavior applies

Open behavior law:
If a field strictness is "open", its exact value may be null.
If a field strictness is "must_match" or "preferred", the corresponding structured value should be present where logically required.

============================================================
🔟 MUTATION SERVICE LAW
============================================================

All partner preference persistence must flow through MutationService.

MutationService responsibilities:
- load current saved preference state
- compare incoming structured preference payload
- persist only approved changes
- write history entries
- preserve transactional integrity
- prevent silent overwrite
- remain compatible with lifecycle rules

Direct model save/update bypass is prohibited for preference mutations.

If preference editing is treated as dynamic/non-critical in conflict policy,
that classification must be explicit and documented in code.
If any preference field is classified as conflict-sensitive in future,
that classification must be added consciously, not implicitly.

============================================================
1️⃣1️⃣ INTAKE / OCR / AI BOUNDARY
============================================================

This preference engine does NOT require OCR extraction.
It does NOT require AI parsing.
It does NOT require AI-generated partner recommendations.

If in future biodata or user text is used to suggest preferences,
that suggestion must still respect:
- explicit user review
- explicit user save
- no auto-persist
- no JSON blobs
- MutationService-only mutation

============================================================
1️⃣2️⃣ WIZARD + MANUAL EDIT PARITY
============================================================

The same structured preference model must be used in:
- profile wizard partner preferences tab
- full manual profile edit UI
- admin read visibility (as applicable)

No structural mismatch allowed between:
- DB schema
- Model
- Validation rules
- Mutation payload
- Blade/UI fields
- Admin/user displays

============================================================
1️⃣3️⃣ ADMIN / AUDIT VISIBILITY
============================================================

Admin should be able to view structured partner preferences clearly.
Admin UI must not depend on raw JSON inspection.
Preference changes should remain auditable through standard governance layers.

============================================================
1️⃣4️⃣ PHASE-5 IMPLEMENTATION SCOPE FOR THIS ENGINE
============================================================

This addendum authorizes and requires:
- schema expansion of profile_preferences
- model alignment
- structured validation
- MutationService integration
- smart autofill/suggestion logic
- sectioned wizard UI
- manual edit UI parity
- history-safe persistence
- terminal/schema verification

This addendum does NOT authorize:
- AI matching
- ranking
- recommendation scoring
- automated partner selection
- JSON fallback storage

============================================================
1️⃣5️⃣ COMPLETION CRITERIA
============================================================

Partner Preferences Engine is complete only if:

✔ profile_preferences schema matches SSOT structured model
✔ No JSON blob storage used
✔ Smart suggestions appear without auto-saving
✔ Saved preferences always override fresh suggestions
✔ Wizard UI supports all structured fields
✔ Manual edit UI supports all structured fields
✔ MutationService is the only write path
✔ Validation covers ranges / strictness / enums / FK integrity
✔ profile_change_history coverage verified
✔ No direct update() bypass
✔ No lifecycle violation introduced
✔ No structural mismatch between UI and DB
✔ No hidden uneditable structured preference field remains

############################################################
END OF SSOT ADD — PARTNER PREFERENCES ENGINE
############################################################
/*
|--------------------------------------------------------------------------
| config/app.php – उपलब्ध भाषा जाहीर करा
|--------------------------------------------------------------------------
*/
'locale' => env('APP_LOCALE', 'en'),
'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
'available_locales' => [
    'en' => 'English',
    'mr' => 'मराठी',
],

/*
|--------------------------------------------------------------------------
| app/Http/Middleware/LocaleMiddleware.php – वापरकर्त्याची भाषा session वरून सेट करा
|--------------------------------------------------------------------------
*/
<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Support\Facades\App;

class LocaleMiddleware
{
    public function handle($request, Closure $next)
    {
        $locale = session('locale', config('app.locale'));
        App::setLocale($locale);
        return $next($request);
    }
}

/*
|--------------------------------------------------------------------------
| app/Http/Kernel.php – web middleware group मध्ये LocaleMiddleware नोंदवा
|--------------------------------------------------------------------------
*/
protected $middlewareGroups = [
    'web' => [
        // इतर middleware…
        \App\Http\Middleware\LocaleMiddleware::class,
    ],
];

/*
|--------------------------------------------------------------------------
| app/Http/Controllers/LocaleController.php – भाषा बदलणारा नियंत्रक
|--------------------------------------------------------------------------
*/
<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function switch(Request $request)
    {
        $locale = $request->input('locale');
        if (array_key_exists($locale, config('app.available_locales'))) {
            session(['locale' => $locale]);
        }
        return back();
    }
}

/*
|--------------------------------------------------------------------------
| routes/web.php – भाषा बदलण्यासाठी POST route
|--------------------------------------------------------------------------
*/
use App\Http\Controllers\LocaleController;
Route::post('/locale', [LocaleController::class, 'switch'])->name('locale.switch');

/*
|--------------------------------------------------------------------------
| resources/views/layouts/navigation.blade.php – Navigation मध्ये language switcher
|--------------------------------------------------------------------------
| खालील dropdown component user dropdown जवळ ठेवावा. Tailwind classes आणि
| Blade components विद्यमान UI प्रमाणे वापरले आहेत.
*/
<x-dropdown align="left" width="36" class="ml-3">
    <x-slot name="trigger">
        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium
                      rounded-md text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 hover:text-gray-700
                      dark:hover:text-gray-300 focus:outline-none transition">
            {{ strtoupper(app()->getLocale()) }}
            <svg class="ms-1 h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
        </button>
    </x-slot>
    <x-slot name="content">
        <form method="POST" action="{{ route('locale.switch') }}">
            @csrf
            @foreach(config('app.available_locales') as $code => $label)
                <button type="submit" name="locale" value="{{ $code }}"
                    class="w-full text-left px-4 py-2 text-sm
                           {{ app()->getLocale() === $code ? 'bg-gray-100 dark:bg-gray-700 font-semibold' : '' }}">
                    {{ $label }}
                </button>
            @endforeach
        </form>
    </x-slot>
</x-dropdown>

/*
|--------------------------------------------------------------------------
| Resources/lang फोल्डर्स – अनुवाद strings
|--------------------------------------------------------------------------
| resources/lang/en आणि resources/lang/mr directories तयार करा.
| प्रत्येकात messages.php सारखे PHP array फाइल्स ठेवा. उदा.:
| <?php
| return [
|     'welcome' => 'Welcome',
|     'logout' => 'Log Out',
| ];
| मराठी फाईलमध्ये:
| <?php
| return [
|     'welcome' => 'आपले स्वागत आहे',
|     'logout' => 'बाहेर पडा',
| ];
|
*/
DAY-NEXT (SSOT ADD) — Multilanguage UI Stabilization + Centralized Translation Direction

Goal
Website ची primary/base language English राहील.
User ने एकदा language निवडली (English / Marathi) की तो website मधे कुठल्याही page, section, wizard, component, modal, dropdown, card, dashboard, profile view, search result, action button, alert, auth page, admin-facing user UI preview, mobile nav, desktop nav, pagination, validation message, CTA, badge, empty state, comparison block, match block, notification UI, contact flow, shortlist flow, who-viewed flow, biodata upload flow, profile wizard flow, profile show flow, interest flow — कुठेही असला तरी त्याला निवडलेल्या language मधेच UI दिसली पाहिजे.

This day is ONLY about UI language consistency, translation architecture direction, and centralized control direction.
This day does NOT mean full content translation of user-entered data.
User-entered values (names, addresses, caste values, free text, company names, occupations entered as data, etc.) should remain as stored data unless separately designed.
Only system/UI labels, headings, buttons, helper text, alerts, messages, statuses, menus, and framework-facing interface text are in scope here.

A) Core Product Rule — Language persistence everywhere
1. Language selection once made must persist across the full website experience.
2. Selected language must apply consistently on:
   - guest pages
   - authenticated pages
   - profile wizard
   - profile show pages
   - search/listing pages
   - all reusable Blade components
   - all dropdowns/modals
   - auth screens
   - contact request flows
   - shortlist / block / who-viewed / interest flows
   - notification UI
   - intake / biodata upload flows
   - desktop navigation and mobile navigation
3. User should never see mixed-language UI because one page/component forgot translation plumbing.
4. Default language remains English.
5. Marathi is a selectable UI language layer, not a separate product branch.

B) Current Reality Acknowledgement
1. Locale selection and persistence may be centralized via locale middleware / session handling.
2. Translation storage may be centralized in lang files.
3. But translation usage is still incomplete if UI strings are not consistently key-driven.
4. Therefore this SSOT day formalizes the move from scattered translation usage to disciplined centralized translation usage.

C) Frozen Direction — Key-based translation architecture
1. UI translation system must move toward key-based translation usage.
2. Raw hardcoded UI strings should be progressively reduced.
3. Preferred direction:
   - use structured translation keys
   - example pattern:
     - nav.search_profiles
     - profile.full_name
     - wizard.save_next
     - actions.send_interest
     - contact.request_contact
     - match.location
4. Translation files are the central store for UI labels/messages.
5. New UI work should prefer key-based translation instead of adding new hardcoded English strings directly in views.
6. Existing raw strings may be migrated incrementally, but direction is frozen toward centralized key usage.

D) Non-negotiable UX rule
1. User language selection must be respected across full navigation.
2. If a user changes language on one page, the next pages they visit must also reflect the same selected language.
3. No page should silently fall back to English unless:
   - the translation truly does not exist yet, or
   - the text is user-entered data, or
   - the text is intentionally excluded from UI translation scope.
4. “Works on some pages only” is not acceptable as final behavior.

E) Scope definition — What MUST be translatable
System/UI text includes, but is not limited to:
- menu items
- headings
- labels
- section titles
- helper text
- placeholder text
- CTA buttons
- status pills
- flash messages
- empty states
- comparison text
- action confirmations
- privacy/contact policy text
- authentication prompts
- validation-facing UI copy
- wizard step names
- pagination labels
- shortlist/block/report/contact/interest labels
- notifications UI labels

F) Scope definition — What is NOT automatically translated by this day
The following are not automatically translated just because locale changed:
- names
- addresses
- profile-entered free text
- company names
- custom biodata content
- imported OCR content
- values stored as user data unless mapped separately
This day is about system UI language, not automatic semantic translation of stored profile data.

G) Centralized control direction (Frozen)
1. Translation behavior must remain compatible with future centralized admin control.
2. Future admin control direction is frozen as an important design consideration.
3. Any multilanguage implementation done now must NOT block future admin-managed control for:
   - translation text management
   - language enable/disable control
   - translation quality review
   - fallback control
   - controlled rollout of translated UI sections
4. Do not hard-design today’s implementation in a way that makes future admin control difficult.
5. Frozen direction: centralized governance/admin control remains an intended evolution path.

H) Registry / governance direction
1. Translation should move toward a governed key registry mindset.
2. Teams/developers should be able to know:
   - which keys exist
   - which areas use which keys
   - which Marathi translations are missing
   - which UI still has raw strings
3. Exact tooling can be decided later, but this governance direction is frozen.

I) Quality rules
1. Marathi UI should sound natural for matrimony context, not robotic literal translation.
2. English remains source/base language unless changed later.
3. Terminology should stay consistent across pages.
4. Same concept must not appear with multiple conflicting Marathi labels in different screens unless intentionally approved.
5. User should feel the site is one coherent bilingual product, not partially translated screens.

J) Implementation discipline
1. Do not treat locale switching alone as “multilanguage complete”.
2. Locale persistence + translation coverage + consistent key usage together are required.
3. Any new page/component added in future should follow the same language system from the beginning.
4. Avoid one-off page-specific hacks.

K) Acceptance intent for this SSOT day
This day will be considered aligned only when:
1. Default language is English.
2. User can switch to Marathi.
3. Selected language persists across the site.
4. Major UI areas do not randomly revert to English.
5. Translation architecture direction is clearly centralized and key-based.
6. Future admin control direction remains preserved and unblocked.

L) Frozen statement
For this product, multilanguage UI is not “page-by-page optional decoration”.
It is a whole-site behavior.
Once a user selects a language, the product must consistently behave in that language across the full website UI surface, while remaining compatible with future centralized admin control and governance.`

-----------------

--------------
############################################################
DAY-36 — CENTRALIZED CONTROLLED OPTION ENGINE (FINAL)
############################################################

Objective:
Create one centralized, reusable Controlled Option Engine for all master-backed / dropdown-backed profile fields across:

- Wizard manual entry
- Intake preview / approval
- OCR / AI normalization
- Admin edit flows
- Mutation-time entity sync

This day formalizes the rule:

If a field is governed by allowed options / master data,
no out-of-list value may ever be saved.

This applies to both:
- text input that must normalize to a canonical option
- numeric IDs that must resolve only to valid active rows

------------------------------------------------------------
WHY THIS DAY IS REQUIRED
------------------------------------------------------------

Current system already has partial hardening:
- active-only validation on many controlled fields
- MutationService active master resolution in key areas
- horoscope-specific OCR normalization
- localized EN/MR option rendering

But enforcement is still distributed across:
- controllers
- validation rules
- intake normalization
- mutation helpers
- Blade/UI label mapping

This day centralizes that logic into one governed engine so that:

✔ same rule applies everywhere
✔ OCR and manual input behave consistently
✔ English/Marathi labels are resolved centrally
✔ inactive options are rejected uniformly
✔ new dropdown fields can be added without re-implementing logic

------------------------------------------------------------
SSOT NON-NEGOTIABLE RULES
------------------------------------------------------------

This engine must obey all Phase-5 laws:

• No direct DB update bypassing MutationService
• No silent overwrite
• No JSON blob storage for structured entities
• Intake raw text remains immutable
• approval_snapshot_json remains immutable after approval
• All profile mutations remain governed
• Conflict handling and lifecycle rules remain intact
• No schema drift unless explicitly required and justified
• No field may save arbitrary text if it is a controlled-option field

------------------------------------------------------------
ENGINE SCOPE
------------------------------------------------------------

This engine governs only CONTROLLED OPTION FIELDS.

A controlled-option field means any field whose value must come from:

1) a master table row
2) a fixed allowlist
3) a governed dropdown/select/radio option universe

This includes (as applicable in current project):

CORE / SNAPSHOT FIELDS
- gender_id
- marital_status_id
- complexion_id
- blood_group_id
- physical_build_id
- religion_id
- caste_id
- sub_caste_id
- income_currency_id
- working_with_type_id
- profession_id
- income_range_id (if static list only)
- college_id (if governed)
- family_type_id (if governed)

HOROSCOPE
- rashi_id
- nakshatra_id
- gan_id
- nadi_id
- yoni_id
- mangal_dosh_type_id

PREFERENCES
- preferred_religion_ids
- preferred_caste_ids
- other master-backed preference IDs

ENTITY TABLE LOOKUPS
- address_type_id
- contact_relation_id
- child_living_with_id
- asset_type_id
- ownership_type_id
- legal_case_type_id
- any future master-backed nested entity lookup

NOT IN SCOPE:
- free-text narrative fields
- devak / kul / gotra / notes / company_name / address_line
- location tables that are not governed by active/inactive unless explicitly designed so
- raw OCR text storage

------------------------------------------------------------
FINAL ENGINE CONTRACT
------------------------------------------------------------

A single reusable service layer must exist for controlled options.

Preferred implementation shape:

1) ControlledOptionRegistry
2) ControlledOptionEngine
3) ControlledOptionLabelResolver

The exact class names may vary,
but the architectural responsibilities must remain separate and clear.

============================================================
1️⃣ CONTROLLED OPTION REGISTRY
============================================================

Goal:
Declare all controlled-option fields in one place.

For each field, registry must define:

- field key
- target storage column / id column
- source type:
  - master_table
  - static_allowlist
- source table name (if master-backed)
- key column (default: key)
- label column (default: label)
- id column (default: id)
- active column presence (usually is_active)
- whether numeric posted ids must be active-only
- whether text normalization is allowed
- whether OCR synonym mapping is allowed
- strict allowlist keys (if field must be narrower than DB rows)
- translation namespace for labels
- whether field is single-value or multi-value

Examples:

- horoscope.nadi
  - source: master_nadis
  - strict keys: [adi, madhya, antya]
  - translation namespace: components.horoscope.options.nadi

- horoscope.gan
  - source: master_gans
  - strict keys: [deva, manav, rakshasa]
  - translation namespace: components.horoscope.options.gan

- basic.gender
  - source: master_genders
  - active-only yes

- education.profession
  - source: professions
  - active-only yes

Registry becomes the SSOT inside code for all controlled fields.

============================================================
2️⃣ CONTROLLED OPTION ENGINE
============================================================

Goal:
Centralize normalization + validation + active lookup rules.

Engine must support these inputs:

- raw text
- canonical key
- numeric ID
- array of numeric IDs (multi-select)
- OCR-derived text
- preview snapshot values
- wizard submitted values

Engine must provide these behaviors:

A) TEXT → CANONICAL KEY
- normalize Marathi/English text
- collapse punctuation/noise
- apply field-specific synonym mapping
- match only within that field’s allowed universe
- never cross-map values between unrelated fields

B) KEY → ACTIVE MASTER ID
- resolve canonical key to row id
- only active rows allowed when source supports is_active
- if strict allowlist exists, key must belong to it

C) NUMERIC ID → ACTIVE MASTER ID
- if numeric ID posted directly, revalidate it
- row must exist
- row must be active if source supports is_active
- if strict allowlist exists, row key must satisfy it

D) MULTI-SELECT ARRAYS
- validate each item individually
- reject inactive/disallowed IDs
- preserve only valid values
- never auto-insert arbitrary values

E) UNMATCHED VALUES
- must remain unmatched
- must NOT auto-map to “other”
- must NOT silently map to nearest guess
- must remain reviewable in intake preview if applicable

============================================================
3️⃣ LABEL RESOLUTION (ENGLISH + MARATHI)
============================================================

Goal:
All controlled-option labels must resolve centrally.

Rules:

- DB stores canonical IDs / keys only
- UI label must be resolved from translation key where available
- DB label may be fallback only
- English + Marathi labels must be available for all user-visible controlled fields
- No ad-hoc per-Blade option label logic for governed fields

Preferred pattern:

field registry declares translation namespace

Example:
- components.horoscope.options.nadi.adi
- components.horoscope.options.gan.rakshasa
- profile.options.gender.male
- profile.options.marital_status.divorced

Engine / label resolver must expose:
- label(field, key, locale)
- options(field, locale)
- optionsWithIds(field, locale)

============================================================
4️⃣ VALIDATION INTEGRATION RULE
============================================================

Goal:
Controllers must stop hand-writing repeated validation logic.

Where possible, validation for controlled fields should be generated from the centralized registry/engine.

Examples:
- activeExistsRule(fieldKey)
- multiActiveExistsRule(fieldKey)
- normalizeAndValidate(fieldKey, input)

Completion target:
No repeated scattered `Rule::exists(...)->where(is_active, true)` for the same governed field unless temporarily unavoidable.

This must reduce duplication while preserving safety.

============================================================
5️⃣ MUTATION INTEGRATION RULE
============================================================

Goal:
MutationService remains final write guard.

Even if controller validation exists,
MutationService must still re-check controlled-option values before persistence.

Reason:
UI validation is not enough.
Mutation layer must remain source-of-truth guard.

Rules:
- No controlled FK written without final engine validation
- Numeric ID direct-post loopholes must remain closed
- entity sync helpers must reuse centralized controlled-option resolution
- no arbitrary text written into FK columns

============================================================
6️⃣ OCR / INTAKE INTEGRATION RULE
============================================================

Goal:
Controlled-option normalization must work for intake approval safely.

Rules:
- raw_ocr_text remains untouched forever
- parsed_json may carry sanitized free-text
- approval snapshot may normalize controlled options before mutation
- normalization must be field-specific
- no wrong-field leakage allowed

Example:
Input:
"नाड २ आध्य गण :- राक्षस"

Expected structured meaning:
- nadi => adi
- gan => rakshasa

Forbidden:
- rakshasa saved into nadi
- nadi auto-mapped to other
- arbitrary text saved into FK field

============================================================
7️⃣ ADMIN CONTROL CONTRACT
============================================================

Goal:
Admin must control governed options safely.

Admin must be able to:
- activate/deactivate master options
- manage aliases / synonyms where appropriate
- inspect which fields are governed by which master source
- see whether an option is user-visible
- see whether an option is OCR-normalizable
- preview EN/MR label rendering

Admin must NOT be able to:
- bypass mutation governance
- force silent overwrite
- create uncontrolled field behavior

If synonym editing is exposed,
it must be logged and deterministic.

============================================================
8️⃣ IMPLEMENTATION SEQUENCE
============================================================

This day must be implemented in order:

STEP 1:
Create centralized registry for controlled-option fields.

STEP 2:
Create centralized engine for:
- key resolution
- numeric active revalidation
- strict allowlist enforcement
- multi-select validation

STEP 3:
Create centralized label resolver for EN/MR.

STEP 4:
Wire wizard validation to use centralized controlled-option rules where safe.

STEP 5:
Wire MutationService resolvers to use centralized engine.

STEP 6:
Wire intake approval normalizer to use same field registry/engine.

STEP 7:
Update user-visible dropdown rendering to use centralized option provider.

STEP 8:
Add admin visibility / inspection support if small safe pass allows;
otherwise schedule as next day.

============================================================
9️⃣ COMPLETION CRITERIA
============================================================

This day is complete ONLY if:

✔ All obvious controlled-option fields are declared in one registry
✔ Same field behaves consistently in wizard, intake, admin, mutation
✔ Inactive options cannot be saved
✔ Out-of-list values cannot be saved
✔ Numeric forged IDs cannot bypass active checks
✔ OCR normalization is field-specific and deterministic
✔ No wrong-field leakage
✔ English + Marathi option labels resolve centrally
✔ MutationService still owns final write governance
✔ No direct DB update bypass introduced
✔ No schema drift unless explicitly approved
✔ No unrelated tests broken

============================================================
🔟 VERIFICATION CHECKLIST
============================================================

Must verify with:

1) php artisan optimize:clear
2) php artisan test

Manual proofs:

A) Horoscope
- OCR text with "आध्य"
- OCR text with "राक्षस"
- invalid nadi text
- forged inactive nadi_id
- forged active but disallowed nadi key

B) Core profile fields
- inactive gender_id
- inactive marital_status_id
- inactive complexion_id
- inactive profession_id
- inactive religion/caste/sub_caste IDs

C) Preferences
- inactive preferred religion/caste IDs
- invalid multi-select values

D) Nested entities
- invalid address_type_id
- invalid contact_relation_id
- invalid asset_type_id
- invalid legal_case_type_id

E) Localization
- same field options visible correctly in EN
- same field options visible correctly in MR

============================================================
1️⃣1️⃣ FAILURE CONDITIONS
============================================================

This day is NOT complete if even one of the following remains:

❌ same controlled field has different rules in wizard vs mutation
❌ inactive option can still be posted directly as numeric ID
❌ “other” is used as silent fallback for strict fields
❌ OCR text still leaks into wrong controlled field
❌ option labels still depend on scattered Blade-specific logic
❌ admin change to option status bypasses governance
❌ direct controller update bypass reappears
❌ repeated field-specific hacks continue without registry migration path

============================================================
1️⃣2️⃣ POST-DAY OUTCOME
============================================================

After this day, controlled-option behavior becomes a platform capability.

Meaning:
- any future dropdown/master field can be onboarded by registry entry
- OCR normalization can reuse the same engine
- EN/MR options remain consistent everywhere
- SSOT compliance becomes easier to maintain

############################################################
END OF DAY-36
############################################################

============================================================
NEXT DAY — INTAKE: DIRECT FORM-TO-DATABASE (SINGLE SOURCE OF TRUTH)
============================================================

Goal:
- One unique form for profile data. One source of truth: database.
- Intake flow: parsed JSON → map once into DB columns → save to profile + related tables. No intermediate approval_snapshot_json schema; no separate "apply" translation layer.
- Edit flow: same form loads from and saves to DB (unchanged).
- Eliminates "intake correct, edit wrong" class of bugs (snapshot vs DB shape mismatch).

Scope:
- Refactor same files (IntakeController, MutationService, related). Do not create parallel new files that duplicate form logic.
- Add direct DB path: on intake approve, map parsed/edited data to profile + entities and persist directly (reuse existing MutationService entity sync logic; drop snapshot as interchange format).
- Remove or bypass: approval_snapshot_json as canonical stored shape; two-step "approve then apply" where apply reads snapshot and maps again to DB.
- Backup already taken: tag `intake-snapshot-flow-backup-before-db-form-refactor`. Rollback = checkout that tag.

Constraints (unchanged):
- Phase-5 protection: no delete/rename/repurpose of existing DB columns or tables; additive only.
- All mutations still via MutationService (or equivalent single authority). No direct profile update() bypass.
- raw_ocr_text immutable; conflict/lifecycle rules unchanged.

Success criteria:
- Intake approve: form data (or parsed JSON merged with form) written directly to matrimony_profiles + profile_* tables. Full profile wizard shows same data without snapshot→DB mapping bugs.
- No code path that "reads old intake/snapshot file and keeps patching there"; single path = form ↔ DB.

############################################################
END OF NEXT-DAY PLAN (Intake direct DB form)
############################################################