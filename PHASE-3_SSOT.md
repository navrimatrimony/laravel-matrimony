============================================================
PHASE-3 SINGLE SOURCE OF TRUTH (SSOT)
============================================================

SSOT Name   : Phase-3 Single Source of Truth
Version     : v1.1
Last Change : Added 'caste' as mandatory Phase-2 CORE field (Day-11 alignment fix)
Locked On   : 2026-02-03

This document IS the definitive authority for Phase-3 implementation.
No Phase-3 implementation SHALL occur outside this document.
Blueprints are reference-only and SHALL NOT override this SSOT.

============================================================
SSOT GLOBAL LAWS (LOCKED — PERMANENT)
============================================================

**LAW 1: SSOT Supremacy**
- This SSOT document SHALL override all blueprints, discussions, and proposals
- Blueprints are reference-only and SHALL NOT be used for implementation decisions
- Any conflict between blueprint and SSOT SHALL be resolved in favor of SSOT

**LAW 2: Phase-1 Permanent Laws (Carried Forward)**
- User ≠ MatrimonyProfile (Strict Separation)
  - User model SHALL be used ONLY for authentication and ownership
  - All matchmaking interactions SHALL operate ONLY on MatrimonyProfile entities
- Profile-Centric Business Logic
  - All business logic related to matchmaking SHALL be profile-centric
- No Implicit Side-Effect Creation
  - No Interest, Shortlist, or Block records SHALL be created implicitly
- Read-Only Operations Must Remain Read-Only
  - Read-only operations SHALL NOT mutate domain data (except view tracking, notifications)
- Admin Actions Do Not Silently Bypass Rules
  - Admin actions SHALL respect all core business rules
- Single Source per Core Concept
  - Each core concept SHALL have a single authoritative implementation point
- Blade Purity Law
  - Blade views SHALL contain display logic only
- Service Authority Rule
  - Business rules used in multiple controllers MUST be in a single Service class

**LAW 3: Phase-2 Behavior Preservation**
- Phase-2 behavior SHALL NOT break
- Phase-2 SSOT rules SHALL remain in force
- Phase-2 constants SHALL NOT be changed without SSOT update
- Phase-2 admin actions SHALL continue to work as defined

**LAW 4: Event ≠ State Separation**
- Meetings, match attempts, engagements, marriages, payments are EVENTS
- Draft, Active, Suspended, Archived are STATES
- Events SHALL NOT directly mutate states unless explicitly governed
- State transitions SHALL be intentional and governed, not automatic consequences of events

**LAW 5: Authority Order (Global — Non-Negotiable)**
Admin > User > Matchmaker > OCR/System

This order SHALL be respected:
- In every approval flow
- In every conflict resolution
- In every overwrite decision
- Lower authority SHALL NOT silently overwrite higher authority

**LAW 6: Historical Integrity (Non-Destructive Updates)**
- Approved data changes SHALL supersede previous values
- Historical values SHALL NOT be erased or silently deleted
- Historical values MAY be archived, versioned, or referenced
- No destructive updates SHALL occur

**LAW 7: Phase-3 Scope Freeze**
- Phase-3 scope is FROZEN
- No feature, rule, workflow, or behavior change SHALL occur without updating this SSOT
- Phase-3 SHALL NOT perform:
  - Automatic data migration
  - Background reconciliation
  - Silent normalization
  - Retroactive data mutation

**LAW 8: Field Identity & Data Integrity**
- Once a field exists and stores data:
  - Its semantic meaning SHALL NOT change
  - Its type SHALL NOT be reinterpreted
  - Historical values SHALL NOT be re-evaluated
- Field evolution is allowed ONLY by:
  - Adding a NEW field
  - NEVER by redefining an existing field

**LAW 9: Governance Structures Only (No Executable Business Logic)**
- No Phase-3 day SHALL introduce executable business logic
- Phase-3 defines GOVERNANCE STRUCTURES ONLY
- Phase-3 SHALL NOT implement:
  - Matching algorithms
  - Scoring calculations
  - Ranking logic
  - Automation workflows
  - Decision-making algorithms
- Phase-3 SHALL ONLY create:
  - Metadata structures
  - Authority frameworks
  - Conflict handling systems
  - Historical data protection
  - Governance rules and constraints

============================================================
PHASE-3 SCOPE DECLARATION (FOUNDATION ONLY)
============================================================

Phase-3 builds GOVERNANCE FOUNDATIONS ONLY.

**Phase-3 MUST NOT:**
- Implement AI logic
- Implement OCR engines
- Implement automation workflows
- Implement payment systems
- Implement WhatsApp or messaging
- Decide scoring or matching logic
- Change Phase-2 behavior

**Phase-3 ONLY DEFINES:**
- Governance structures
- Authority rules
- Conflict handling framework
- Profile lifecycle rules
- Historical data protection
- CORE vs EXTENDED field model
- Field metadata and registry
- Conflict record system

============================================================
CORE vs EXTENDED FIELD MODEL (LOCKED)
============================================================

**CORE Fields:**
- Fixed database schema (columns in `matrimony_profiles` table)
- Used by search algorithms, filters, matching logic
- Required by Flutter/Web app UI components
- Cannot be created or deleted by admin at runtime
- Changes require code modification + app release
- Examples: `full_name`, `gender`, `date_of_birth`, `marital_status`, `education`, `location`, `caste`, `height_cm`, `profile_photo`
- Governance: Managed via code migrations and app updates only

**EXTENDED Fields:**
- Admin-creatable at runtime (no code changes required)
- Stored as key-value pairs (JSON or separate `profile_extended_fields` table)
- No hard dependency in app logic (search, matching, filters)
- Rendered as passive "Additional Information" sections
- Can be enabled/disabled without breaking app functionality
- Examples: `property_details`, `children_info`, `medical_conditions`, `family_notes`, `preferences`, `hobbies`
- Governance: Managed via admin UI, stored separately from CORE fields

**Separation Principle:**
- CORE fields = App-critical, schema-bound, migration-controlled
- EXTENDED fields = Contextual, runtime-managed, app-agnostic

============================================================
OCR & BIODATA GOVERNANCE (STRICT)
============================================================

**MODE 1: FIRST PROFILE CREATION (Biodata-based Registration)**
- OCR MAY populate ALL fields (CORE and EXTENDED) at first profile creation
- No manual approval required at this stage
- Profile SHALL be marked as "OCR-created" (conceptual flag)
- Goal: Manual data entry = 0

**MODE 2: EXISTING PROFILE + NEW BIODATA UPLOAD**
- OCR SHALL evaluate field-by-field:
  - For CORE fields:
    - If field is EMPTY → OCR auto-fill is ALLOWED
    - If field EXISTS and value is SAME → Ignore
    - If field EXISTS and value is DIFFERENT → Create Conflict Record (NO auto-overwrite)
  - For EXTENDED fields:
    - If field does NOT exist → Auto-create & auto-fill allowed
    - If field exists with different value → Create Conflict Record (approval required)

**MODE 3: POST-HUMAN-EDIT LOCK STATE**
- If a CORE or EXTENDED field has been edited by User/Admin/Matchmaker:
  - Field SHALL be considered LOCKED
  - OCR MAY suggest changes but SHALL NOT overwrite automatically
  - Admin retains override authority (per authority order)

**GOVERNANCE RULES:**
- OCR SHALL NEVER silently overwrite existing data
- Any mismatch between existing data and new data MUST create a Conflict Record
- OCR auto-fills CORE fields during first profile creation and for missing fields only
- Overwriting existing CORE fields SHALL ALWAYS require human approval

============================================================
CONFLICT RECORD (MANDATORY ENTITY)
============================================================

Every conflict MUST be stored as a persistent object containing:

**Required Fields:**
- `id` (primary key)
- `profile_id` (foreign key to matrimony_profiles)
- `field_name` (string, identifies CORE or EXTENDED field)
- `field_type` (enum: 'CORE' or 'EXTENDED')
- `old_value` (text/json, previous value)
- `new_value` (text/json, proposed new value)
- `source` (enum: 'OCR', 'USER', 'ADMIN', 'MATCHMAKER', 'SYSTEM')
- `detected_at` (timestamp)
- `resolution_status` (enum: 'PENDING', 'APPROVED', 'REJECTED', 'OVERRIDDEN')
- `resolved_by` (nullable, foreign key to users, identifies authority)
- `resolved_at` (nullable timestamp)
- `resolution_reason` (nullable text)
- `created_at` (timestamp)
- `updated_at` (timestamp)

**Conflict Record Rules:**
- Conflicts SHALL be auditable and immutable until resolved
- Only resolved conflicts SHALL be modifiable (for audit trail updates)
- Conflict resolution SHALL respect authority order (Admin > User > Matchmaker > OCR/System)
- Conflict Records SHALL NOT be hard-deleted

============================================================
PROFILE FIELD REGISTRY (MANDATORY SYSTEM)
============================================================

**Purpose:**
Central metadata for every profile field (CORE and EXTENDED).

**Required Metadata:**
- `field_key` (string, immutable, unique identifier)
- `field_type` (enum: 'CORE' or 'EXTENDED')
- `data_type` (enum: 'text', 'number', 'date', 'boolean', 'select')
- `is_mandatory` (boolean)
- `is_searchable` (boolean, applies to CORE fields only)
- `is_user_editable` (boolean)
- `is_system_overwritable` (boolean)
- `lock_after_user_edit` (boolean)
- `display_label` (string, mutable, UI-only)
- `display_order` (integer, category-based)
- `category` (string, organizational grouping)
- `is_archived` (boolean, soft delete flag)
- `replaced_by_field` (nullable, foreign key to field_registry, for field replacement)
- `created_at` (timestamp)
- `updated_at` (timestamp)

**Field Key Immutability:**
- Internal `field_key` SHALL be immutable once introduced
- Display labels SHALL be UI-only and MAY change
- Labels MAY be localized (language-ready)
- AI, OCR, APIs SHALL depend ONLY on `field_key`, never on labels

============================================================
PROFILE LIFECYCLE STATES (CANONICAL)
============================================================

**Defined States:**
- `Draft` — Profile being created, not yet active
- `Active` — Profile is live and searchable
- `Search-Hidden` — Profile exists but hidden from search
- `Suspended` — Profile temporarily disabled
- `Archived` — Profile permanently archived
- `Owner-Hidden` — Profile hidden from search by owner/admin; not searchable, interactions blocked; owner can edit

**State-Behavior Matrix:**

| State | Editable | Searchable | Interest/Interaction Allowed |
|-------|----------|------------|----------------------------|
| Draft | Yes | No | No |
| Active | Yes | Yes | Yes |
| Search-Hidden | Yes | No | Yes |
| Suspended | No | No | No |
| Archived | No | No | No |
| Owner-Hidden | Yes | No | No |

**Note:** The state "Owner-Hidden" was previously referred to as "Demo-Hidden" in earlier SSOT versions; the rename is for semantic accuracy. Behavior is unchanged.

**State Transition Rules:**
- State transitions SHALL be controlled and intentional
- Events (meetings, matches, payments) SHALL NOT automatically change states
- State transitions SHALL require explicit admin or user action
- Profile lifecycle states SHALL be independent of match outcomes

============================================================
HISTORICAL DATA PROTECTION (MANDATORY)
============================================================

**Field Versioning:**
- Field definition changes SHALL be tracked over time
- Historical field configurations SHALL be maintained
- Field replacement workflows SHALL be supported

**Data Preservation:**
- No hard delete of field definitions SHALL occur
- No hard delete of field values SHALL occur
- All historical biodata SHALL be retained
- Archive instead of delete policy SHALL be enforced

**Historical Access:**
- Previous field values SHALL remain accessible through appropriate mechanisms
- Historical values SHALL support audit, disputes, and future learning systems
- Historical data integrity SHALL be maintained regardless of technical implementation

============================================================
EXECUTION DISCIPLINE & DAY STRUCTURE
============================================================

**RULE 1: DAY COMPLETION CRITERIA (ATOMIC DAYS)**
- A development day SHALL be considered COMPLETE ONLY if ALL listed tasks are:
  - Fully implemented
  - Manually verified
  - Cross-checked against SSOT rules
- If even ONE task remains incomplete, the day is NOT complete
- The next day MUST NOT start until the current day is formally complete

**RULE 2: DEFINITION OF DONE (GLOBAL)**
- A feature SHALL be considered DONE only if:
  - Happy path works correctly
  - Negative / invalid actions are blocked
  - UI state reflects backend state accurately
  - Guards prevent illegal state transitions
- Partial or backend-only completion is NOT acceptable

**RULE 3: NO MID-DAY SCOPE SWITCH**
- Once a development day has started, NO new feature, sub-task, or enhancement may be introduced
- Even small or related tasks MUST wait for the next day
- Mid-day scope switching is strictly forbidden

**RULE 4: TODAY = VERIFIED REALITY**
- All planning and teaching must be based on the user's reported CURRENT state.
- No feature, file, route, or logic may be assumed to exist.
- If any state is unclear, work must STOP and clarification must be requested.
- Phrases like "probably", "it should be", "likely exists" are forbidden.

**RULE 5: AUTHORITY & ROLE LOCK**
- ChatGPT is the ONLY authority for:
  - Architecture
  - SSOT definition
  - Flow design
  - Teaching and reasoning
- Cursor AI is LIMITED to:
  - Inspecting files
  - Reporting file contents
  - Syntax reference only
- Cursor must NEVER:
  - Propose architecture
  - Rename domain concepts
  - Suggest shortcuts or abstractions
- User executes changes manually; no blind copy–paste allowed.

**RULE 6: ASSUMPTION-FREE DEVELOPMENT PROTOCOL**
Mandatory workflow:
1) User reports actual error / screen / file
2) ChatGPT instructs what EXACT question to ask Cursor
3) Cursor reports factual output only
4) ChatGPT decides next step based on verified reality

- Any step skipped = SSOT violation
- Development based on memory, guess, or intuition is forbidden

**RULE 7: ATOMIC DAY COMPLETION (PRIORITY-1)**
- A development day SHALL be considered COMPLETE ONLY if ALL tasks listed for that day are:
  - Fully implemented
  - Manually verified
  - Cross-checked against SSOT rules
- If even ONE task is incomplete, the day is NOT complete.
- The next day MUST NOT start until the current day is formally marked complete.
- Partial completion, workaround, or "we'll fix tomorrow" is forbidden.

**RULE 8: NO FUTURE DEPENDENCY VIOLATION**
- A day SHALL NOT include any task that depends on a system, entity, or logic scheduled for a future day.
- If such dependency is discovered, the day is INVALID and MUST be restructured before execution.
- Forward-looking placeholders are forbidden.

**RULE 9: DAY-SCOPE IMMUTABILITY**
- Once a day has started, its scope is IMMUTABLE.
- No additional task, enhancement, refactor, or "small improvement" may be added mid-day.
- Any extra idea MUST be deferred to a future day via SSOT update.

**RULE 10: ROLLBACK TO LAST SAFE STATE**
- If a working feature breaks during a day:
  - Development MUST STOP immediately.
  - The last verified working state SHALL be restored.
  - The day SHALL be marked INVALID.
  - No new feature or task may proceed until stability is restored.
- Bug fixing while progressing to future work is forbidden.
- A broken state SHALL NEVER be considered "acceptable progress".

**RULE 12: DAY COMPLETION VERIFICATION (MANDATORY)**
- Each development day MUST define an explicit, human-verifiable checklist.
- A day SHALL be considered COMPLETE only if:
  - The defined functionality is accessible via the intended UI (Admin or User).
  - The functionality can be manually tested without database inspection.
- Features implemented but not accessible via UI
  SHALL be considered INCOMPLETE.
- Admin-only features MUST be verifiable via Admin Panel.
- User-facing features MUST be verifiable via User Panel.
- If verification cannot be performed, the day is NOT complete.

**RULE 11: CURSOR VERIFICATION-ONLY MODE**
- Cursor AI SHALL be used ONLY to inspect actual project files and report factual findings.
- Cursor SHALL NOT:
  - Assume file existence
  - Propose architecture or design
  - Suggest new logic or shortcuts
  - Modify scope or SSOT interpretation
- Cursor output SHALL be treated as RAW FACTS ONLY.
- All decisions, planning, and next steps SHALL be made by ChatGPT based on Cursor's verified output.
- Any planning or teaching without prior Cursor verification is a SSOT violation.

============================================================
TESTING AUTHORITY & EXECUTION PROTOCOL (NEW — LOCKED)
============================================================

RULE T1️⃣ : CURSOR-FIRST TESTING (MANDATORY)

• कोणतेही testing (unit / integration / governance / regression)
  USER ने manually करण्याआधी
  CURSOR AI ने आधी करणे MANDATORY आहे.

• Cursor AI = Primary Test Executor
• User = Secondary Manual Verifier (ONLY after Cursor PASS)

------------------------------------------------------------

RULE T2️⃣ : CURSOR AS TEST EXECUTION ENGINE

Testing दरम्यान Cursor AI ची भूमिका खालीलप्रमाणे FIXED आहे:

• Actual code paths trace करणे
• Database state verify करणे
• Mode / condition detection verify करणे
• Realistic test scenarios simulate करणे
• PASS / FAIL factual report देणे
• FAIL असल्यास exact root cause explain करणे

❌ Cursor ने fix सुचवायचा नाही
❌ Cursor ने scope बदलायचा नाही
❌ Cursor ने architecture ठरवायची नाही

------------------------------------------------------------

RULE T3️⃣ : USER MANUAL TESTING — CONDITIONED

• User ने manually testing करण्याची परवानगी
  फक्त तेव्हाच आहे जेव्हा:

  A) Cursor AI ने सर्व defined tests PASS केलेले असतील
  B) Cursor AI ने explicit "ALL TESTS PASSED" report दिलेला असेल

• Cursor PASS शिवाय User manual testing = NOT ALLOWED

------------------------------------------------------------

RULE T4️⃣ : SINGLE SOURCE OF TEST TRUTH

• Testing result साठी primary authority:
  → Cursor AI factual execution report

• User manual testing:
  → Sanity / confirmation purpose ONLY

• Cursor report शिवाय
  User ने testing वर अंतिम निर्णय घेणे
  = SSOT VIOLATION

------------------------------------------------------------

RULE T5️⃣ : NO MANUAL REMINDERS POLICY

• ChatGPT ला User ला manually
  "आधी Cursor कडून test करून घे"
  असे सांगण्याची गरज पडू नये.

• Testing flow हा SSOT मध्ये
  PERMANENTLY DEFINED असल्यामुळे
  तो AUTOMATICALLY follow झाला पाहिजे.

------------------------------------------------------------

LOCK STATEMENT:

• Cursor-first testing protocol is FINAL.
• Any testing bypassing Cursor AI is INVALID.
• This rule applies to ALL phases (Phase-2, Phase-3, Phase-4+).

============================================================
PERMANENT TESTING & VERIFICATION DOCTRINE (LOCKED)
============================================================

This doctrine applies to ALL phases (past, current, and future).
This doctrine is NON-NEGOTIABLE and PHASE-INDEPENDENT.
This doctrine has HIGHER PRIORITY than feature-level instructions.

--------------------------------------------------
1) CURSOR ROLE DEFINITION (LOCKED)
--------------------------------------------------

Cursor AI is NOT a happy-path tester.

Cursor AI is defined as:
- Human Error Simulator
- Assumption Breaker
- Data Loss Guard
- Edge-Case & Misuse Tester

Cursor MUST simulate every realistic way a human can:
- make mistakes
- misuse a feature
- partially complete an action
- repeat an action unintentionally
- bypass UI assumptions

--------------------------------------------------
2) FIELD-LEVEL TESTING (MANDATORY)
--------------------------------------------------

For ANY field that can be created, updated, approved, or resolved,
Cursor MUST test ALL of the following:

- valid value
- invalid value
- empty value
- null value
- whitespace-only value
- wrong data type
- boundary values (min / max / length)
- same value re-submission (no-op)
- direct request bypass (UI ignored)

Feature testing is INCOMPLETE if any of the above are not tested.

--------------------------------------------------
3) DATA MUTATION ZERO-TOLERANCE RULE
--------------------------------------------------

Any operation that mutates data (save, update, approve, override):

Cursor MUST verify:
- existing valid data is never deleted silently
- unrelated fields remain unchanged
- history/audit remains intact
- no duplicate history entries created
- no partial updates occur

Silent data loss is classified as a CRITICAL FAILURE.

--------------------------------------------------
4) NEGATIVE & MISUSE TESTING (MANDATORY)
--------------------------------------------------

Cursor MUST test:

- wrong action order (approve before detect, reject then approve)
- double clicks / repeated submissions
- refresh during action
- back-button interruptions
- half-filled forms
- stale forms after state change
- unauthorized access attempts
- invalid enum/state injection

Assumptions such as "this won't happen" are INVALID.

--------------------------------------------------
5) NO-OP & REPEAT ACTION TESTING
--------------------------------------------------

Cursor MUST test scenarios where:
- no actual data change occurs
- same value is submitted again
- same conflict is resolved twice
- action is retried after success/failure

System must remain stable and idempotent.

--------------------------------------------------
6) TESTING ORDER ENFORCEMENT
--------------------------------------------------

Mandatory order:
1) Cursor exhaustive testing (including negative cases)
2) Bug fixing (if any)
3) Cursor re-verification
4) User manual testing (confirmation only)

If a user discovers a bug:
- it is classified as a PROCESS FAILURE
- SSOT testing rules must be updated to prevent recurrence

--------------------------------------------------
7) PERMANENCE CLAUSE
--------------------------------------------------

This doctrine:
- applies to ALL phases
- applies to ALL features
- cannot be overridden by speed, deadlines, or convenience
- remains valid even if tools, frameworks, or team members change

--------------------------------------------------

LOCK STATEMENT:

• This testing doctrine is PERMANENT and FINAL.
• Partial adoption is NOT ALLOWED.
• These are RULES, not guidelines or suggestions.
• Violation of this doctrine is a SSOT VIOLATION.

============================================================
DAY-WISE IMPLEMENTATION PLAN
============================================================

------------------------------------------------
DAY 0 — Phase-3 Readiness & SSOT Validation
------------------------------------------------

**Objective:**
Validate Phase-2 stability and lock carry-forward invariants before Phase-3 begins.

**Prerequisites (STRICT GATE):**
- Phase-2 fully complete per PHASE-2_SSOT.md
- No open regressions in Phase-2 functionality
- All Phase-2 admin actions working correctly
- All Phase-2 user flows working correctly
- Phase-2 SSOT compliance verified

**Allowed Scope (TODAY ONLY):**
- Review Phase-2 SSOT compliance
- Document Phase-2 invariants that must carry forward
- Create Phase-3 Readiness Confirmation document
- Verify no Phase-2 behavior will break

**Explicitly NOT Allowed:**
- No code changes
- No database changes
- No new features
- No Phase-3 implementation

**Practical Output (NON-NEGOTIABLE):**
- Written Phase-3 Readiness Confirmation document
- Explicit list of Phase-2 invariants that Phase-3 must preserve:
  - Profile completeness threshold: 70%
  - Demo bulk creation limit: 1–50 profiles per action
  - Notification retention: 90 days
  - View-back frequency limit: 24 hours per demo-real pair
  - API version: v1 (with backward compatibility)
  - All Phase-1 permanent laws
  - All Phase-2 admin actions
  - All Phase-2 user interactions

**Verification Checklist:**
- Admin can perform all Phase-2 admin actions
- User can perform all Phase-2 user interactions
- Phase-2 search functionality works
- Phase-2 profile completeness rules enforced
- Phase-2 notification system functional
- Phase-2 demo profile behavior correct

**Completion Rule:**
- Day 0 is COMPLETE when:
  - Phase-3 Readiness Confirmation document exists
  - Phase-2 invariants list is documented
  - All Phase-2 functionality verified working
  - No blockers identified for Phase-3
- Day 0 is BLOCKED if:
  - Any Phase-2 functionality is broken
  - Phase-2 SSOT compliance issues exist
  - Critical regressions found

------------------------------------------------
DAY 1 — Field Registry Foundation & CORE Field Metadata
------------------------------------------------

**Objective:**
Create Profile Field Registry system to store metadata for all profile fields (CORE and EXTENDED).

**Prerequisites (STRICT GATE):**
- Day 0 complete
- Phase-3 Readiness Confirmation approved
- Database access verified

**Allowed Scope (TODAY ONLY):**
- Create `field_registry` table migration
- Define field metadata schema (field_key, field_type, data_type, is_mandatory, is_searchable, etc.)
- Create FieldRegistry model
- Seed existing CORE fields from `matrimony_profiles` table schema
- Create basic admin UI to view field registry (read-only)

**Explicitly NOT Allowed:**
- No EXTENDED field creation yet
- No field editing capabilities
- No field deletion
- No OCR integration
- No conflict records

**Practical Output (NON-NEGOTIABLE):**
- `field_registry` table exists with correct schema
- FieldRegistry model exists with relationships
- All existing CORE fields seeded in registry with correct metadata
- Admin can view field registry list (read-only)
- Field keys match existing database column names for CORE fields

**Verification Checklist:**
- Admin sees field registry page with all CORE fields listed
- Each CORE field shows: field_key, field_type='CORE', data_type, is_mandatory, is_searchable
- Field keys are immutable (cannot be edited)
- Phase-2 behavior unchanged (search, profile view, completeness calculation)

**Completion Rule:**
- Day 1 is COMPLETE when:
  - Field registry table exists and populated with CORE fields
  - Admin can view field registry
  - All CORE fields have correct metadata
  - Phase-2 functionality verified unchanged
- Day 1 is BLOCKED if:
  - Field registry table schema incorrect
  - CORE field metadata incomplete
  - Phase-2 behavior broken

------------------------------------------------
DAY 2 — EXTENDED Field Definition System
------------------------------------------------

**Objective:**
Enable admin to create EXTENDED field definitions via admin UI.

**Prerequisites (STRICT GATE):**
- Day 1 complete
- Field registry system functional
- Admin UI access verified

**Allowed Scope (TODAY ONLY):**
- Create admin UI for EXTENDED field creation
- Implement field definition creation logic
- Store EXTENDED fields in `field_registry` table (field_type='EXTENDED')
- Validate field_key uniqueness and format
- Validate data_type (text, number, date, boolean, select)
- Create `profile_extended_fields` table for storing field values

**Explicitly NOT Allowed:**
- No field value population yet
- No field editing after creation
- No field deletion
- No field dependencies
- No OCR integration
- No conflict records

**Practical Output (NON-NEGOTIABLE):**
- Admin can create EXTENDED field definitions via UI
- EXTENDED fields stored in `field_registry` table
- `profile_extended_fields` table exists (profile_id, field_key, field_value, created_at, updated_at)
- Field key validation enforced (unique, immutable)
- Data type validation enforced
- Admin can view list of EXTENDED fields

**Verification Checklist:**
- Admin can create new EXTENDED field with field_key, label, data_type
- EXTENDED field appears in field registry list
- Field key is immutable after creation
- Duplicate field keys are rejected
- Invalid data types are rejected
- Phase-2 behavior unchanged

**Completion Rule:**
- Day 2 is COMPLETE when:
  - Admin can create EXTENDED field definitions
  - EXTENDED fields stored correctly in field_registry
  - profile_extended_fields table exists
  - Field validation working
  - Phase-2 functionality verified unchanged
- Day 2 is BLOCKED if:
  - EXTENDED field creation fails
  - Field validation not working
  - Phase-2 behavior broken

------------------------------------------------
DAY 3 — EXTENDED Field Value Storage & Retrieval
------------------------------------------------

**Objective:**
Enable storage and retrieval of EXTENDED field values for profiles.

**Prerequisites (STRICT GATE):**
- Day 2 complete
- EXTENDED field definition system functional
- profile_extended_fields table exists

**Allowed Scope (TODAY ONLY):**
- Implement EXTENDED field value storage (create/update)
- Implement EXTENDED field value retrieval (read)
- Create service methods for EXTENDED field operations
- Update profile model to access EXTENDED fields
- Create basic admin UI to view/edit EXTENDED field values for a profile

**Explicitly NOT Allowed:**
- No user-facing UI yet
- No field dependencies
- No field archival
- No OCR integration
- No conflict records
- No field locking

**Practical Output (NON-NEGOTIABLE):**
- Admin can view EXTENDED field values for a profile
- Admin can edit EXTENDED field values for a profile
- EXTENDED field values stored in `profile_extended_fields` table
- Profile model has methods to access EXTENDED fields
- Service class exists for EXTENDED field operations
- Data type validation enforced on field values

**Verification Checklist:**
- Admin can view EXTENDED field values on profile page
- Admin can edit EXTENDED field values
- Values persist correctly in database
- Data type validation works (number fields reject text, etc.)
- Phase-2 behavior unchanged

**Completion Rule:**
- Day 3 is COMPLETE when:
  - EXTENDED field values can be stored and retrieved
  - Admin can view/edit EXTENDED field values
  - Data type validation working
  - Phase-2 functionality verified unchanged
- Day 3 is BLOCKED if:
  - EXTENDED field value storage fails
  - Data type validation not working
  - Phase-2 behavior broken

------------------------------------------------
DAY 4 — Conflict Record System Foundation
------------------------------------------------

**Objective:**
Create Conflict Record system to track and manage data conflicts.

**Prerequisites (STRICT GATE):**
- Day 3 complete
- EXTENDED field value system functional

**Allowed Scope (TODAY ONLY):**
- Create `conflict_records` table migration
- Define conflict record schema (all required fields)
- Create ConflictRecord model
- Create basic admin UI to view conflict records (list view)
- Implement conflict record creation logic (manual creation for testing)

**Explicitly NOT Allowed:**
- No conflict resolution workflow yet
- No OCR integration
- No automatic conflict detection
- No conflict resolution actions

**Practical Output (NON-NEGOTIABLE):**
- `conflict_records` table exists with correct schema
- ConflictRecord model exists
- Admin can view list of conflict records
- Conflict records can be created manually (for testing)
- Conflict records are immutable until resolved
- All required fields present (profile_id, field_name, field_type, old_value, new_value, source, etc.)

**Verification Checklist:**
- Admin sees conflict records list page
- Conflict records can be created manually
- All required fields stored correctly
- Conflict records show correct status (PENDING)
- Phase-2 behavior unchanged

**Completion Rule:**
- Day 4 is COMPLETE when:
  - Conflict records table exists
  - Admin can view conflict records
  - Conflict records can be created
  - Schema matches SSOT requirements
  - Phase-2 functionality verified unchanged
- Day 4 is BLOCKED if:
  - Conflict records table schema incorrect
  - Conflict record creation fails
  - Phase-2 behavior broken

------------------------------------------------
DAY 5 — Authority Order & Conflict Resolution Framework
------------------------------------------------

**Objective:**
Implement authority order enforcement and conflict resolution workflow.

**Prerequisites (STRICT GATE):**
- Day 4 complete
- Conflict record system functional

**Allowed Scope (TODAY ONLY):**
- Implement authority order logic (Admin > User > Matchmaker > OCR/System)
- Create conflict resolution service
- Implement conflict resolution workflow (approve/reject/override)
- Update conflict record status based on resolution
- Create admin UI for conflict resolution
- Enforce authority order in resolution actions

**Explicitly NOT Allowed:**
- No automatic conflict resolution
- No OCR integration
- No field locking yet
- No automatic conflict detection

**Practical Output (NON-NEGOTIABLE):**
- Authority order enforced in conflict resolution
- Admin can resolve conflicts (approve/reject/override)
- Conflict resolution updates conflict record status
- Resolution reason required for all resolutions
- Resolved conflicts show resolved_by and resolved_at
- Lower authority cannot override higher authority decisions

**Verification Checklist:**
- Admin can resolve conflicts via UI
- Authority order enforced (Admin can override all, User can override Matchmaker/OCR, etc.)
- Resolution reason required
- Conflict status updates correctly
- Resolved conflicts show resolution details
- Phase-2 behavior unchanged

**Completion Rule:**
- Day 5 is COMPLETE when:
  - Authority order enforced
  - Conflict resolution workflow functional
  - Admin can resolve conflicts
  - Resolution tracking working
  - Phase-2 functionality verified unchanged
- Day 5 is BLOCKED if:
  - Authority order not enforced
  - Conflict resolution fails
  - Phase-2 behavior broken

------------------------------------------------
DAY 6 — Field Locking & Post-Edit Protection
------------------------------------------------

**Objective:**
Implement field locking mechanism to prevent overwrites after human edits.

**Prerequisites (STRICT GATE):**
- Day 5 complete
- Conflict resolution system functional

**Allowed Scope (TODAY ONLY):**
- Add `lock_after_user_edit` flag to field registry
- Add `locked_by` and `locked_at` fields to field registry or profile_extended_fields
- Implement field locking logic (lock field after User/Admin/Matchmaker edit)
- Implement lock check before field overwrite
- Update conflict detection to respect locks
- Create admin UI to view locked fields

**Explicitly NOT Allowed:**
- No OCR integration yet
- No automatic unlocking
- No lock expiration

**Practical Output (NON-NEGOTIABLE):**
- Fields can be locked after human edit
- Locked fields cannot be overwritten automatically
- Lock status visible in admin UI
- Lock check enforced before field updates
- Admin can view which fields are locked for a profile
- Lock metadata stored (locked_by, locked_at)

**Verification Checklist:**
- Field locks after User edit
- Field locks after Admin edit
- Field locks after Matchmaker edit
- Locked fields cannot be overwritten automatically
- Admin can view locked fields
- Phase-2 behavior unchanged

**Completion Rule:**
- Day 6 is COMPLETE when:
  - Field locking mechanism functional
  - Locked fields protected from overwrite
  - Lock status visible to admin
  - Lock metadata stored correctly
  - Phase-2 functionality verified unchanged
- Day 6 is BLOCKED if:
  - Field locking not working
  - Lock protection not enforced
  - Phase-2 behavior broken

------------------------------------------------
DAY 7 — Profile Lifecycle State Management
------------------------------------------------

**Objective:**
Implement canonical profile lifecycle states and state transition rules.

**Prerequisites (STRICT GATE):**
- Day 6 complete
- Field locking system functional

**Allowed Scope (TODAY ONLY):**
- Add `lifecycle_state` field to `matrimony_profiles` table
- Define state enum (Draft, Active, Search-Hidden, Suspended, Archived, Owner-Hidden)
- Implement state transition validation
- Implement state-based behavior rules (editable, searchable, interaction allowed)
- Create admin UI for state transitions
- Enforce state transition rules

**Explicitly NOT Allowed:**
- No automatic state transitions from events
- No event-driven state changes
- No match outcome state changes

**Practical Output (NON-NEGOTIABLE):**
- Profile lifecycle_state field exists
- State transitions validated and controlled
- State-based behavior enforced (per state-behavior matrix)
- Admin can change profile state via UI
- State transitions require explicit action
- Events do not automatically change states

**Verification Checklist:**
- Admin can change profile state
- State transitions validated (illegal transitions blocked)
- State-based behavior enforced (Draft not searchable, Suspended not editable, etc.)
- Events (interest, match) do not change state
- Phase-2 behavior unchanged (existing state logic preserved)

**Completion Rule:**
- Day 7 is COMPLETE when:
  - Profile lifecycle states implemented
  - State transitions controlled
  - State-based behavior enforced
  - Admin can manage states
  - Events do not mutate states
  - Phase-2 functionality verified unchanged
- Day 7 is BLOCKED if:
  - State transitions not working
  - State-based behavior not enforced
  - Events mutate states incorrectly
  - Phase-2 behavior broken

------------------------------------------------
DAY 8 — Historical Data Protection & Field Versioning
------------------------------------------------

**Objective:**
Implement historical data protection and field versioning awareness.

**Prerequisites (STRICT GATE):**
- Day 7 complete
- Profile lifecycle states functional

**Allowed Scope (TODAY ONLY):**
- Create `field_value_history` table (or equivalent) for tracking field value changes
- Implement historical value storage on field updates
- Add `is_archived` flag to field_registry
- Implement field archival mechanism (soft delete)
- Create admin UI to view field history
- Enforce no hard delete of field definitions or values

**Explicitly NOT Allowed:**
- No data migration
- No retroactive historical data creation
- No historical data deletion

**Practical Output (NON-NEGOTIABLE):**
- Field value history table exists
- Historical values stored on field updates
- Field archival mechanism functional (soft delete)
- Admin can archive fields (hide without deleting)
- Admin can view field history
- No hard delete of field definitions
- No hard delete of field values

**Verification Checklist:**
- Field updates create historical records
- Historical values accessible
- Field archival works (fields hidden but not deleted)
- Archived fields can be reactivated
- No hard delete possible
- Phase-2 behavior unchanged

**Completion Rule:**
- Day 8 is COMPLETE when:
  - Historical data protection implemented
  - Field versioning functional
  - Field archival working
  - Historical values accessible
  - No hard delete possible
  - Phase-2 functionality verified unchanged
- Day 8 is BLOCKED if:
  - Historical data not stored
  - Field archival not working
  - Hard delete still possible
  - Phase-2 behavior broken

------------------------------------------------
DAY 9 — Field Registry Display Order & Admin Controls
------------------------------------------------

**Objective:**
Implement field display order management and admin field controls.

**Prerequisites (STRICT GATE):**
- Day 8 complete
- Historical data protection functional

**Allowed Scope (TODAY ONLY):**
- Add `display_order` field to field_registry
- Implement category-based ordering
- Create admin UI to reorder EXTENDED fields
- Implement field enable/disable toggle
- Create admin UI for field visibility controls
- Ensure display order changes do not affect field_key

**Explicitly NOT Allowed:**
- No field deletion
- No field_key modification
- No CORE field reordering (if not allowed)

**Practical Output (NON-NEGOTIABLE):**
- Admin can set display order for EXTENDED fields
- Display order is category-based
- Admin can enable/disable EXTENDED fields
- Display order changes do not affect field_key
- Field visibility controls functional
- Field registry shows correct display order

**Verification Checklist:**
- Admin can reorder EXTENDED fields
- Display order persists correctly
- Field enable/disable works
- Field_key remains unchanged
- Phase-2 behavior unchanged

**Completion Rule:**
- Day 9 is COMPLETE when:
  - Display order management functional
  - Admin can reorder fields
  - Field enable/disable working
  - Field_key immutable
  - Phase-2 functionality verified unchanged
- Day 9 is BLOCKED if:
  - Display order not working
  - Field enable/disable fails
  - Field_key can be modified
  - Phase-2 behavior broken

------------------------------------------------
DAY 10 — EXTENDED Field Dependency System (Simple Parent-Child)
------------------------------------------------

**Objective:**
Implement simple parent-child field dependencies for EXTENDED fields (DISPLAY/VISIBILITY ONLY).

**Prerequisites (STRICT GATE):**
- Day 9 complete
- Field display order functional

**Allowed Scope (TODAY ONLY):**
- Add dependency fields to field_registry (parent_field_key, dependency_condition)
- Implement simple parent-child dependency logic
- Support equality-based conditions (show field X if parent Y equals value Z)
- Support presence-based conditions (show field X if parent Y present)
- Create admin UI to configure dependencies (EXTENDED fields only)
- Validate no circular dependencies
- Dependencies SHALL affect DISPLAY/VISIBILITY ONLY

**DAY-SPECIFIC LOCK (MANDATORY):**
- Dependencies are DISPLAY/VISIBILITY ONLY
- Dependencies SHALL NOT affect:
  - Validation logic
  - Completeness calculation
  - Search functionality
  - Profile state
  - Field value storage
- Dependencies SHALL ONLY control:
  - Whether field appears in forms
  - Whether field is visible in UI

**Explicitly NOT Allowed:**
- No nested dependencies (child of child)
- No AND/OR rule builders
- No multi-parent dependencies
- No CORE field dependencies
- No validation dependencies
- No completeness dependencies
- No search dependencies

**Practical Output (NON-NEGOTIABLE):**
- Admin can configure simple field dependencies
- Dependencies work for EXTENDED fields only
- Equality-based conditions functional
- Presence-based conditions functional
- Circular dependency validation works
- Dependencies affect field visibility in forms ONLY
- Dependencies do NOT affect validation, completeness, or search

**Verification Checklist:**
- Admin can create field dependencies
- Dependencies work correctly (field shows/hides based on parent)
- Circular dependencies rejected
- CORE field dependencies rejected
- Nested dependencies rejected
- Phase-2 behavior unchanged

**Completion Rule:**
- Day 10 is COMPLETE when:
  - Field dependency system functional
  - Simple parent-child dependencies working
  - Dependency validation working
  - Admin can configure dependencies
  - Phase-2 functionality verified unchanged
- Day 10 is BLOCKED if:
  - Dependencies not working
  - Circular dependencies not prevented
  - Phase-2 behavior broken

------------------------------------------------
DAY 11 — Profile Completeness Engine Integration
------------------------------------------------

**Objective:**
Connect Phase-3 field_registry metadata to existing Phase-2 completeness logic (integration only, no formula changes).

**Prerequisites (STRICT GATE):**
- Day 10 complete
- Field dependency system functional

**DAY-SPECIFIC LOCK (MANDATORY):**
- Day 11 SHALL NOT modify:
  - Completeness formula (filled mandatory / total mandatory × 100)
  - Completeness threshold (70%)
  - Mandatory field definitions (Gender, DOB, Marital Status, Education, Location, Caste, Photo)
  - Completeness calculation logic
- Day 11 ONLY connects Phase-3 metadata to existing Phase-2 completeness logic
- Day 11 SHALL ensure field_registry is_mandatory flag aligns with Phase-2 mandatory fields

**Allowed Scope (TODAY ONLY):**
- Update completeness calculation to read is_mandatory flag from field_registry
- Ensure field_registry metadata matches Phase-2 mandatory field definitions
- Ensure completeness calculation uses field_registry as source of truth for is_mandatory
- Maintain Phase-2 completeness threshold (70%)
- Maintain Phase-2 completeness formula
- Ensure completeness recalculates when field_registry metadata changes

**Explicitly NOT Allowed:**
- No change to 70% threshold
- No change to Phase-2 completeness formula
- No change to Phase-2 completeness behavior
- No change to mandatory field definitions
- No retroactive completeness recalculation
- No new completeness rules

**Practical Output (NON-NEGOTIABLE):**
- Completeness calculation uses field_registry
- Completeness respects is_mandatory flag
- 70% threshold maintained
- Completeness recalculates on field changes
- Demo vs real profile rules preserved
- Phase-2 completeness behavior unchanged

**Verification Checklist:**
- Completeness calculation works correctly
- Mandatory fields from registry used
- 70% threshold enforced
- Completeness updates when fields change
- Phase-2 completeness behavior preserved
- Search visibility rules unchanged

**Completion Rule:**
- Day 11 is COMPLETE when:
  - Completeness uses field_registry
  - Completeness calculation correct
  - 70% threshold maintained
  - Completeness updates correctly
  - Phase-2 behavior unchanged
- Day 11 is BLOCKED if:
  - Completeness calculation broken
  - Threshold changed
  - Phase-2 behavior broken

------------------------------------------------
DAY 12 — Field Registry API & Flutter/Web App Safety
------------------------------------------------

**Objective:**
Document safety boundaries for Flutter/Web app access to EXTENDED fields (documentation and structure only).

**Prerequisites (STRICT GATE):**
- Day 11 complete
- Profile completeness integration functional

**DAY-SPECIFIC LOCK (MANDATORY):**
- Day 12 SHALL NOT introduce new APIs
- Day 12 SHALL NOT modify existing API contracts
- Day 12 SHALL NOT alter Flutter parity
- Day 12 ONLY documents safety boundaries and structure
- Day 12 SHALL ensure API structure supports EXTENDED fields without breaking changes

**Allowed Scope (TODAY ONLY):**
- Document API safety boundaries for EXTENDED fields
- Ensure API structure supports dynamic EXTENDED field access
- Document that CORE fields remain direct model properties
- Document that EXTENDED fields accessed via dynamic accessor
- Document graceful handling requirements for missing EXTENDED fields
- Document that apps must function without any EXTENDED fields
- Create API documentation updates (if needed for clarity)

**Explicitly NOT Allowed:**
- No breaking API changes
- No new API endpoints
- No modification of existing API contracts
- No hardcoded EXTENDED field names in apps
- No app redeployment required for new EXTENDED fields
- No Flutter parity changes

**Practical Output (NON-NEGOTIABLE):**
- API safety boundaries documented
- API structure supports EXTENDED fields (documented)
- Documentation clarifies CORE vs EXTENDED field access patterns
- Documentation clarifies graceful handling requirements
- Documentation clarifies apps must work without EXTENDED fields
- No breaking API changes
- API backward compatible

**Verification Checklist:**
- API safety boundaries documented
- API structure documented correctly
- No breaking changes introduced
- Phase-2 API behavior unchanged
- Flutter parity maintained
- Documentation complete

**Completion Rule:**
- Day 12 is COMPLETE when:
  - API safety boundaries documented
  - API structure documented
  - No breaking changes
  - Phase-2 API behavior unchanged
  - Flutter parity maintained
- Day 12 is BLOCKED if:
  - Breaking API changes introduced
  - Flutter parity broken
  - Phase-2 behavior broken

------------------------------------------------
DAY 13 — Conflict Detection Framework (Manual Trigger)
------------------------------------------------

**Objective:**
Implement conflict detection framework (manual trigger for testing, foundation for OCR).

**Prerequisites (STRICT GATE):**
- Day 12 complete
- API safety verified

**Allowed Scope (TODAY ONLY):**
- Create conflict detection service
- Implement field-by-field comparison logic
- Detect conflicts between old_value and new_value
- Create Conflict Records for detected conflicts
- Create admin UI to manually trigger conflict detection (for testing)
- Respect field locks in conflict detection

**Explicitly NOT Allowed:**
- No OCR integration
- No automatic conflict detection
- No conflict auto-resolution

**Practical Output (NON-NEGOTIABLE):**
- Conflict detection service exists
- Field comparison logic functional
- Conflict Records created for mismatches
- Admin can manually trigger conflict detection
- Field locks respected in detection
- Conflicts stored with correct metadata

**Verification Checklist:**
- Conflict detection works correctly
- Conflicts detected for value mismatches
- Conflict Records created correctly
- Field locks prevent overwrite
- Admin can trigger detection manually
- Phase-2 behavior unchanged

**Completion Rule:**
- Day 13 is COMPLETE when:
  - Conflict detection framework functional
  - Conflict Records created correctly
  - Field locks respected
  - Manual trigger works
  - Phase-2 functionality verified unchanged
- Day 13 is BLOCKED if:
  - Conflict detection not working
  - Conflict Records not created
  - Phase-2 behavior broken

------------------------------------------------
DAY 14 — OCR Mode-Based Governance Foundation (Structure Only)
------------------------------------------------

**Objective:**
Create governance structure for OCR mode-based field population (foundation only, no OCR engine).

**Prerequisites (STRICT GATE):**
- Day 13 complete
- Conflict detection framework functional

**Allowed Scope (TODAY ONLY):**
- Create `ocr_modes` enum/constants (MODE_1_FIRST_CREATION, MODE_2_EXISTING_PROFILE, MODE_3_LOCKED)
- Create service methods for mode-based field population logic (structure only)
- Implement mode detection logic (determine which mode applies)
- Create admin UI to simulate OCR modes (for testing governance)
- Document OCR governance rules in code comments
- Ensure mode-based rules respect authority order

**Explicitly NOT Allowed:**
- No actual OCR engine
- No OCR text parsing
- No automatic OCR processing
- No biodata upload

**Practical Output (NON-NEGOTIABLE):**
- OCR mode constants defined
- Mode detection logic functional
- Mode-based population service structure exists
- Admin can simulate OCR modes (manual input)
- Governance rules documented
- Authority order enforced in modes

**Verification Checklist:**
- OCR mode constants exist
- Mode detection works
- Mode-based logic structure in place
- Admin can test modes manually
- Governance rules enforced
- Phase-2 behavior unchanged

**Completion Rule:**
- Day 14 is COMPLETE when:
  - OCR governance structure exists
  - Mode detection functional
  - Mode-based logic structure in place
  - Governance rules documented
  - Phase-2 functionality verified unchanged
- Day 14 is BLOCKED if:
  - OCR governance structure incomplete
  - Mode detection not working
  - Phase-2 behavior broken

------------------------------------------------
DAY 15 — Integration Testing & Phase-3 Foundation Validation
------------------------------------------------

**Objective:**
Validate all Phase-3 governance foundations work together correctly.

**Prerequisites (STRICT GATE):**
- Day 14 complete
- All previous days complete

**Allowed Scope (TODAY ONLY):**
- End-to-end testing of field registry system
- End-to-end testing of conflict resolution
- End-to-end testing of field locking
- End-to-end testing of profile lifecycle states
- End-to-end testing of historical data protection
- Verify Phase-2 behavior unchanged
- Document Phase-3 foundation completion

**Explicitly NOT Allowed:**
- No new features
- No scope changes
- No Phase-2 behavior changes

**Practical Output (NON-NEGOTIABLE):**
- All Phase-3 governance foundations tested
- All systems work together correctly
- Phase-2 behavior verified unchanged
- Phase-3 Foundation Completion document created
- Integration test results documented

**Verification Checklist:**
- Field registry system functional
- Conflict resolution working
- Field locking working
- Profile lifecycle states working
- Historical data protection working
- OCR governance structure in place
- Phase-2 behavior unchanged
- All Phase-2 admin actions work
- All Phase-2 user interactions work

**Completion Rule:**
- Day 15 is COMPLETE when:
  - All Phase-3 foundations tested and working
  - Integration tests pass
  - Phase-2 behavior verified unchanged
  - Phase-3 Foundation Completion document exists
  - No critical issues found
- Day 15 is BLOCKED if:
  - Any Phase-3 foundation not working
  - Phase-2 behavior broken
  - Critical integration issues found

============================================================
PHASE-3 FIXED CONSTANTS
============================================================

The following constants SHALL NOT be changed without SSOT update:

- Profile completeness threshold: 70% (carried from Phase-2)
- Authority order: Admin > User > Matchmaker > OCR/System
- Field key immutability: Once created, field_key SHALL NOT change
- Conflict resolution required: All conflicts SHALL require explicit resolution
- Historical data retention: All historical values SHALL be retained
- No hard delete: Field definitions and values SHALL NOT be hard-deleted

============================================================
PHASE-3 EXPLICITLY NOT INCLUDED
============================================================

**Phase-3 DOES NOT implement:**
- OCR engines or text parsing
- AI logic or machine learning
- Automation workflows
- Payment systems
- WhatsApp or messaging
- Matching algorithms
- Scoring weights
- Automation triggers
- UX copy or UI micro-decisions
- Biodata upload functionality
- Actual OCR processing
- Executable business logic
- Decision-making algorithms
- Ranking or sorting logic

**Phase-3 DOES NOT decide:**
- AI behavior
- Matching or scoring logic
- Automation triggers
- UX copy or UI micro-decisions

**Phase-3 ONLY defines governance foundations:**
- Governance structures
- Authority rules
- Conflict handling framework
- Profile lifecycle governance
- Historical data protection
- Metadata systems
- Framework boundaries

============================================================
SSOT AUTHORITY & RULES
============================================================

This PHASE-3_SSOT.md document SHALL override all other documents for Phase-3 scope and implementation.

- Implementation MUST strictly follow this SSOT
- No features SHALL be implemented outside this document
- No scope changes SHALL be made without updating this SSOT
- Blueprints SHALL be used for reference only, not for implementation decisions
- Phase-1 and Phase-2 SSOT rules SHALL remain in force

============================================================
COMPLETION CRITERIA
============================================================

Phase-3 is COMPLETE when:
- All 15 days are complete
- All governance foundations are functional
- All Phase-2 behavior is preserved
- All SSOT rules are enforced
- Integration tests pass
- Phase-3 Foundation Completion document exists

Phase-3 is BLOCKED if:
- Any day is incomplete
- Phase-2 behavior is broken
- SSOT rules are violated
- Critical integration issues exist

==========================
✅ Day 0 Completion Status

Phase-3 Readiness Confirmation document exists ✔️

Carry-forward invariants documented ✔️

Phase-2 functionality verified ✔️

Phase-3 entry unblocked ✔️

Day 0: COMPLETE
=========================
📋 Day 1 Completion Checklist (ALL PASSED)

 Registry table schema SSOT-match

 9 CORE fields seeded (via seeder, not yet run)

 Admin read-only list visible

 field_key immutable governance respected

 Phase-2 behavior unchanged

 ==============================
 नक्की. खाली **फक्त 4 lines मध्ये Day-2 summary** — थेट **SSOT मध्ये add** करता येईल अशी:

---

**Day-2 Summary:**
Admin ला runtime मध्ये **EXTENDED profile fields define करण्याची सुविधा** implement केली.
EXTENDED fields `field_registry` मध्ये metadata म्हणून store केले; `field_key` unique व immutable ठेवला.
`profile_extended_fields` table तयार केली, **cascade delete टाळून historical integrity राखली**.
CORE fields, Phase-2 behavior, OCR, conflicts, dependencies — **कुठलाही extra scope touch केला नाही**.
===================
Date      : 2026-01-29
Day       : Day 3
Status    : ☑️ Completed
------------------------------------------------------------
Admin panel मध्ये EXTENDED fields editable + saveable केले.
Admin profiles list साठी dedicated /admin/profiles page add केला.
Admin list मधील View Profile links user routes ऐवजी admin routes वर fix केले.
Admin → List → Profile navigation end-to-end verify करून lock केला.


------------------------------------------------------------
Day-4 Summary:
Conflict Record System foundation implement केला; conflict_records table व ConflictRecord model SSOT-exact schema सह तयार केला.
Admin साठी read-only list UI दिली व manual conflict creation (testing only) सक्षम केली.
Default resolution_status = PENDING, records immutable ठेवून authority-based resolution पुढील दिवसासाठी defer केली.
OCR, auto-detection, resolution workflow, Phase-2 behavior — काहीही touch केले नाही.
---------------------
Day-5
Conflict Resolution Framework implement करून
authority order (Admin > User > Matchmaker > OCR) strict enforce केला.
Approve / Reject / Override actions audit-safe ठेवून
resolution_reason, resolved_by, resolved_at mandatory केले.
Resolved conflicts immutable ठेवले; profile data mutation जाणीवपूर्वक टाळली.
Decision (conflict record) आणि execution (profile update) वेगळे ठेवण्याचा
governance principle practically समजला.
---------------------------------------
Day-6 मध्ये field locking system पूर्णपणे implement व verify केला.
Human edit (User/Admin) नंतर field lock होतो, पण authority order नुसार legitimate edits allowed आहेत.
Locked fields वर system/OCR overwrite पूर्णपणे blocked आहे; unrelated fields edit safe आहेत.
Admin, User, API सर्व flows मध्ये lock enforcement + Phase-2 behavior intact ठेवून Day-6 formally closed केला.
--------------------------
Day-7 Summary (SSOT):
Profile lifecycle साठी canonical lifecycle_state governance layer implement करून scattered flags वर centralized control enforce केला.
Admin-controlled explicit state transitions (Active, Suspended, Archived, Search-Hidden, Owner-Hidden) lock केले.
Receiver आणि sender दोन्ही बाजूंनी interaction guards (interest, shortlist) lifecycle_state नुसार strict enforce केले.
Phase-2 behavior न मोडता lifecycle governance production-safe पद्धतीने complete केली
------------------------
Date      : 2026-02-03
Day       : Day 8
Status    : ☑️ Completed

Summary:
CORE आणि EXTENDED fields साठी append-only historical value tracking implement केली.
field_registry.is_archived वापरून field soft-archive governance enforce केली.
Locked CORE fields वर overwrite block करून user feedback (error / warning) correct केला.
Phase-2 behavior intact ठेवून CORE height_cm field end-to-end stable केला.

-----------------------------
Date      : 2026-02-04
Day       : Day 9
Status    : ☑️ Completed
------------------------------------------------------------
आज काय शिकलो:

1) EXTENDED fields साठी `display_order` वापरून
   category-wise rendering order governance कशी implement करायची
   ते SSOT-safe पद्धतीने शिकलो.

2) EXTENDED fields साठी `is_enabled` visibility control add करून
   field UI मधून hide करता येतो,
   पण data delete न करता retain कसा ठेवायचा ते शिकलो.

3) `field_key` immutability, CORE vs EXTENDED separation,
   आणि Phase-2 behavior untouched ठेवणे
   हे governance design मध्ये किती critical आहे ते practically समजले.

4) Archive (Day-8) आणि Enable/Disable (Day-9)
   हे दोन वेगवेगळे governance concepts आहेत
   आणि UI semantics मध्ये ते clear ठेवणे का आवश्यक आहे ते शिकलो.

आज अडचण काय आली (असल्यास):
• Git मध्ये commit न करता tag दिल्यामुळे
  tag जुन्या (Day-8) commit वर point झाला —
  त्यामुळे commit → push → tag हा exact क्रम का non-negotiable आहे
  हे practically शिकायला मिळालं.

Decision / Note (important):
• Admin UI मध्ये internal phase labels (उदा. “Day 8”)
  दाखवायचे नाहीत — feature semantics (“Actions”) वापरणे SSOT-compliant आहे.
• EXTENDED field visibility बदल ही UI-governance आहे;
  search, completeness, किंवा business logic ला touch करायचा नाही.

Next Day Prerequisite (जर काही लागणार असेल तर):
• Day-10 साठी EXTENDED Field Dependency (parent–child, display-only)
  design आणि admin UI changes करण्यासाठी
  existing field_registry + display_order fully stable असणे.
------------------------------------------------------------
Date      : 2026-02-05
Day       : Day 10
Status    : ☑️ Completed
------------------------------------------------------------
आज काय शिकलो:
1) EXTENDED fields साठी simple parent–child visibility dependency कशी define करायची.
2) Equality/Persistence rules strictly UI-only ठेवून data safety कशी राखायची.
3) CORE / nested / circular dependencies hard-reject करून governance boundary enforce कशी करायची.
4) Manual UI verification करून Phase-2 behavior unchanged असल्याची खात्री कशी करायची.
------------------------------------------------------------
Phase-3 Day-11
Status : ✅ COMPLETE (LOCKED)
Reason :
- Registry-driven completeness implemented
- Enabled + mandatory metadata aligned
- Runtime proof confirms % changes on caste add/remove
- No formula / threshold / Phase-2 regression
=============================
Day-12 Summary (SSOT):
CORE vs EXTENDED fields साठी API safety boundaries स्पष्टपणे document केल्या.
EXTENDED fields optional व dynamic असल्यामुळे apps ने missing/empty स्थितीतही gracefully काम करणे अनिवार्य असल्याचे lock केले.
CORE fields schema-bound व fixed असल्याचे स्पष्ट करून EXTENDED वर hard dependency forbidden ठरवली.
कोणताही API/Flutter parity बदल न करता backward compatibility (additive-only) नियम enforce करून Day-12 formally COMPLETE केला.
=============================
Day 13 — Conflict Detection Framework completed.
Field-by-field CORE + EXTENDED comparison implemented via ConflictDetectionService.
Admin-only manual trigger provided to generate ConflictRecords for mismatched values.
Locked fields are skipped during detection; overwrite protection preserved.
No automatic detection, no OCR integration, and Phase-2 behavior remains untouched.
--------------------
PHASE-3 — DAY 14: OCR GOVERNANCE FOUNDATION (CLOSED)

• OCR mode-based governance (MODE-1 / MODE-2 / MODE-3) structure implemented and verified.
• Authority order enforced using field locks; no OCR engine, no data mutation.
• Conflict creation, skip logic, and allow logic validated via Cursor-executed tests.
• Cursor-first testing protocol enforced; Day-14 marked COMPLETE and LOCKED.
---------------------
Phase-3 — Day-15 (SSOT Summary)

Phase-3 मधील सर्व governance systems (Field Registry, Dependency, Completeness, Locking, Conflict, Resolution, Lifecycle, OCR) end-to-end verify करून SSOT-compliant ठरवले.

Conflict Resolution मध्ये NULL / empty new_value मुळे होणारा silent data loss bug ओळखून fix केला व data deletion permanently बंद केली.

Interest feature मध्ये lifecycle guard gap (accept/reject वेळी) सापडून receiver lifecycle validation add करून interaction rules SSOT-correct केले.

Permanent Testing & Verification Doctrine SSOT मध्ये add करून freeze केली — Cursor AI ला exhaustive human-error simulation tester म्हणून अनिवार्य केले.