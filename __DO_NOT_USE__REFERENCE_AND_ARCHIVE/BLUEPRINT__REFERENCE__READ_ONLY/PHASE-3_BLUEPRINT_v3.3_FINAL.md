============================================================
📋 PHASE-3 BLUEPRINT v3.3 FINAL — Dynamic Biodata & Field Governance System
============================================================

⚠️ **VERSION NOTE:** This document is the FINAL consolidated Phase-3 blueprint (v3.3).
⚠️ This document consolidates v3.1 FINAL (technical governance) and v3.2 ADDENDUM (philosophical governance).
⚠️ This document is still **NOT SSOT**.
⚠️ It exists as the SOLE REFERENCE DOCUMENT for Phase-3 SSOT creation.
⚠️ SSOT will be derived ONLY from this file.

⚠️ IMPORTANT: THIS IS A PROPOSAL-LEVEL BLUEPRINT
⚠️ THIS DOCUMENT IS NOT SSOT
⚠️ Phase-2 is complete and locked via PHASE-2_SSOT.md
⚠️ This blueprint represents architectural proposals for Phase-3

============================================================
📋 CONTEXT (MANDATORY READ FIRST)
============================================================

**Phase-2 Status:**
- Phase-2 is complete and locked via PHASE-2_SSOT.md
- Profile field configuration system exists (Day-16, Day-17, Day-18)
- Admin can manage field flags (enabled, visible, searchable, mandatory)
- Field enforcement is implemented at controller and view levels
- Core System Laws (User ≠ Profile, Profile-Centric Logic, No Implicit Side-Effects) remain in force

**Phase-3 Goals:**
- Handle large-scale biodata ingestion via OCR
- Expected profile fields ≈ 130+
- Biodata content is heterogeneous (property, children, conditions, notes, etc.)
- Flutter + Web apps already exist and MUST NOT break due to runtime field changes
- Enable full automation (manual work = 0) for biodata-based registration

**Core Architectural Decision (LOCKED FOR PHASE-3 BLUEPRINT):**
Adopt a **2-Layer Profile Field Architecture**:
1) CORE fields (locked, app-dependent)
2) EXTENDED fields (admin-creatable, runtime-safe)

============================================================
⚠️ PRE-PHASE-3 MANDATORY PHASE-2 FOUNDATION TASKS
============================================================

**Status:** These items are **Phase-2 completion prerequisites** and **MUST be completed** before Phase-3 (AI / automation) execution begins.

**Purpose:** Ensure clean foundation for AI-based matching, scoring, and automation features. These tasks address data quality, realism, and correctness issues that would negatively impact Phase-3 AI systems.

**Classification:** These are **NOT Phase-3 features**. They are **Phase-2 foundation tasks** required to ensure Phase-3 success.

------------------------------------------------
1) Demo Profile Age / DOB Realism
------------------------------------------------

**Requirement:**
- Demo profiles must have realistic date of birth (DOB) values
- Age ranges should be natural and human-like
- DOB assignment happens at creation time only (no retroactive changes)

**Rationale:**
- AI-based matching and scoring systems require realistic age data
- Unrealistic age distributions create noise in matching algorithms
- Age-based filters and compatibility calculations depend on accurate DOB values
- Required before any AI-based matching or scoring features are implemented

**Scope:**
- Apply to new demo profiles created after implementation
- Existing demo profiles may remain unchanged (no backfill required)
- DOB generation logic must produce natural age distributions

------------------------------------------------
2) Demo Profile Location Realism
------------------------------------------------

**Requirement:**
- Demo profiles should use limited, realistic location sets
- Prevent unnatural geographic scatter across too many locations
- Location selection should be constrained to realistic options

**Rationale:**
- Improves search realism and user experience
- Reduces noise in location-based matching algorithms
- Better AI signal quality for location-based compatibility scoring
- Prevents demo profiles from appearing in unrealistic geographic distributions

**Scope:**
- Apply to new demo profiles created after implementation
- Location pool should be limited and realistic
- Geographic clustering should be natural

------------------------------------------------
3) Admin Demo Create Preview Bug
------------------------------------------------

**Requirement:**
- Demo profile creation preview currently references a missing image file
- Requires cleanup before Phase-3 to avoid admin confusion
- Classified as a UI polish / correctness fix

**Rationale:**
- Admin confusion during demo profile creation disrupts workflow
- Missing image references create broken UI elements
- Clean admin experience required before Phase-3 automation features
- Prevents admin errors and support requests

**Scope:**
- Fix preview image reference in admin demo profile creation view
- Ensure preview displays correctly or uses appropriate fallback
- No functional impact, purely UI correctness

------------------------------------------------
4) Legacy Notification Copy (Optional)
------------------------------------------------

**Status:** **OPTIONAL** — Acknowledged as acceptable technical debt if skipped.

**Requirement:**
- Older notifications may still contain internal wording (e.g., "demo profile" references)
- No backfill of existing notifications required
- New notifications use corrected wording

**Rationale:**
- Historical notifications may contain internal terminology
- Backfilling is not required for functionality
- New notifications already use user-friendly wording
- Can be left as-is if resources are limited

**Scope:**
- Acknowledged as acceptable technical debt
- No action required if skipped
- Does not block Phase-3 implementation

============================================================
PHASE-3 REQUIRED CORE FUNCTIONS (FOUNDATION LAYER)
============================================================

**Scope:** This section documents **functional requirements** for Phase-3 core capabilities. These are **foundation-layer** functions that support profile fields, search, AI readiness, and future biodata automation.

**Important:**
- These are Phase-3 **FOUNDATION** functions.
- They are **prerequisites** for AI and automation features.
- They are **NOT** UI features.
- They are **NOT** Phase-4 features.

--------------------------------------------------
A) PROFILE DATA MANAGEMENT FUNCTIONS
--------------------------------------------------

- **Profile Field Registry**
  - Central metadata for every profile field
  - Data type, mandatory/optional, searchable flag
  - Editable by (user/admin/system)
  - Applicable to (real/demo/biodata)

- **Profile Completeness Engine**
  - Automatic completeness percentage
  - Recalculated when fields change
  - Separate rules for demo vs real profiles

- **Field Version Awareness**
  - Awareness that profile fields may evolve
  - Old data may become partially outdated
  - Required for AI and migration safety

--------------------------------------------------
B) SEARCH & FILTER SUPPORT FUNCTIONS
--------------------------------------------------

- **Searchable Field Controller**
  - Explicit control over which fields appear in search
  - Admin-level configurability

- **Location Logic (City/State based)**
  - City-level primary matching
  - State-level fallback
  - Prepared for future radius-based logic

--------------------------------------------------
C) USER INTERACTION & SIGNAL FUNCTIONS
--------------------------------------------------

- **Profile Interaction Signals**
  - Profile views
  - View-back
  - Interests sent / accepted / rejected
  - Shortlist add/remove

- **Interest Lifecycle Tracking**
  - Clear state transitions
  - Stored as behavioral signals
  - No AI logic yet, data-only foundation

--------------------------------------------------
D) ADMIN & SYSTEM GUARD FUNCTIONS
--------------------------------------------------

- **Admin Field Control**
  - Mandatory / optional toggle
  - Visibility control
  - Rule changes without code deploy

- **Demo vs Real Profile Guard**
  - Prevent demo profiles from behaving like real users
  - Centralized enforcement of demo limitations

--------------------------------------------------
E) FUTURE-READY SUPPORT FUNCTIONS
--------------------------------------------------

- **Biodata Mapping Readiness**
  - Field alias awareness
  - Prepared for biodata parsing in later phase

- **Missing Data Semantics**
  - Clear distinction between:
    NULL, Unknown, Not Provided
  - Required for AI accuracy

--------------------------------------------------
F) OFTEN MISSED BUT CRITICAL FUNCTIONS
--------------------------------------------------

- Soft delete vs hard delete awareness
- Profile data locking after verification
- Field-level audit awareness for admin changes

============================================================
CORE GOVERNANCE PRINCIPLES
============================================================

**Scope:** This section establishes foundational governance principles that guide all Phase-3 decisions and future phases. These principles bridge technical governance and philosophical foundations.

--------------------------------------------------
1) PROFILE OUTCOME INDEPENDENCE PRINCIPLE
--------------------------------------------------

A single MatrimonyProfile may participate in multiple match attempts, meetings, or outcomes over time. Match outcomes (success/failure) do NOT directly change profile lifecycle state. Profile lifecycle states (Active, Archived, Suspended, etc.) are independent of match results.

This principle ensures that profile governance remains stable regardless of individual match outcomes. A profile that has participated in multiple meetings or match attempts retains its lifecycle state based on explicit administrative or user actions, not on the success or failure of any particular match. This separation prevents unintended state mutations and maintains clear governance boundaries.

This is a governance principle, not execution logic. Implementation must respect this independence while enabling match tracking and outcome recording as separate event streams.

--------------------------------------------------
2) EVENT vs STATE SEPARATION PRINCIPLE
--------------------------------------------------

Meetings, match attempts, engagements, marriages, payments, and similar actions are EVENTS. Draft, Active, Suspended, Archived, and similar classifications are STATES. Events do not directly mutate states unless explicitly governed by defined rules.

This separation prevents state-machine confusion and ensures that event tracking remains distinct from profile lifecycle management. Events may trigger state transitions only when explicitly defined governance rules authorize such transitions. For example, a payment event may trigger visibility expansion, but only if governance rules explicitly define this relationship.

This principle exists to prevent future state-machine confusion and maintain clear boundaries between event tracking and state management. All state transitions must be intentional and governed, not automatic consequences of events.

--------------------------------------------------
3) HISTORICAL INTEGRITY & NON-DESTRUCTIVE CHANGE PRINCIPLE
--------------------------------------------------

Approved data changes supersede previous values but do NOT erase historical information. Historical values may be archived, versioned, or referenced, but not silently deleted. This principle supports auditability, trust, and future learning systems.

When profile fields are updated, corrected, or modified, the previous values remain accessible through appropriate historical mechanisms. This ensures that the system maintains a complete audit trail and enables future analysis, learning, and dispute resolution. Historical data integrity is essential for building trust and supporting advanced features that may require understanding how profiles evolved over time.

No storage or schema details are specified here. This is a governance principle that guides how data changes must be handled, regardless of technical implementation approach.

--------------------------------------------------
4) HUMAN TRUST OVERRIDES AI CONFIDENCE PRINCIPLE
--------------------------------------------------

AI confidence, probability, or prediction must never override explicit human confirmation. In conflicts, human decisions (User/Admin/Matchmaker) always take precedence over AI-generated insights. This rule applies across all future phases.

This is a trust and cultural safeguard that ensures human agency and judgment remain paramount in the system. Even when AI systems provide high-confidence predictions or recommendations, explicit human decisions take precedence. This principle prevents AI systems from making autonomous decisions that override human intent, maintaining user trust and ensuring that the platform serves human needs rather than algorithmic optimization.

This philosophical governance rule guides all future AI integration, ensuring that AI serves as a tool to support human decision-making rather than replace it. The authority order (Admin > User > Matchmaker > OCR/System) reflects this principle, with AI/System always at the lowest authority level.

--------------------------------------------------
5) PROFILE AS SOCIAL & LEGAL REPRESENTATION PRINCIPLE
--------------------------------------------------

A MatrimonyProfile represents real human intent, family context, and social consequences. Governance decisions must prioritize trust, reversibility, and human dignity. This framing guides future monetization, meetings, and legal-sensitive workflows.

Profiles are not merely data structures but representations of real people making life-altering decisions within complex social and cultural contexts. Every governance decision must consider the human impact, the potential for harm, and the need for reversibility. Features that affect profile visibility, monetization, or match outcomes must be designed with human dignity and trust as primary considerations.

This principle ensures that future monetization strategies, meeting coordination features, and legal-sensitive workflows are designed with appropriate safeguards and respect for the gravity of matrimonial decisions. Governance must always prioritize human welfare over system efficiency or revenue optimization.

============================================================
PHASE-3 DESIGN GOVERNANCE CLARIFICATIONS
============================================================

**Scope:** This section documents **design governance** rules and clarifications for Phase-3. These are blueprint-level decisions that ensure future-proofing for AI, OCR, admin governance, and Flutter/Web clients.

**Important:**
- These are Phase-3 **governance clarifications**.
- They are **prerequisites** for safe Phase-4 AI work.
- They do **NOT** introduce new features.
- They do **NOT** change Phase-3 scope.

--------------------------------------------------
A) PROFILE FIELD DISPLAY ORDER GOVERNANCE
--------------------------------------------------

- Profile fields have a stable default display order
- Ordering is category-based
- Admin may override display order
- Order changes do NOT affect internal field keys

--------------------------------------------------
B) FIELD KEY vs DISPLAY LABEL IMMUTABILITY
--------------------------------------------------

- Internal field_key is immutable once introduced
- Display labels are UI-only and may change
- Labels may be localized (language-ready)
- AI, OCR, APIs depend ONLY on field_key, never on labels

--------------------------------------------------
C) TRUTH AUTHORITY & CONFLICT RESOLUTION ORDER
--------------------------------------------------

- Define precedence when the same field is edited by:
  - User
  - Admin
  - Matchmaker
  - System (OCR / automation)

**Explicit Authority Order:**
Admin > User > Matchmaker > OCR/System

**Governance Rules:**
- Lower authority cannot silently overwrite higher authority
- Conflicts must be explicit, not silent
- This is a governance rule, not UI logic
- Prevent silent overwrites
- Human decisions always take precedence over AI/System (per Human Trust Overrides AI Confidence Principle)

--------------------------------------------------
C.1) FIELD MUTABILITY & OCR OVERWRITE GOVERNANCE
--------------------------------------------------

**Conceptual Metadata Flags (governance-level only):**
- is_user_editable
- is_system_overwritable
- lock_after_user_edit

--------------------------------------------------
C.1.1) OCR GOVERNANCE: CORE & EXTENDED FIELD POPULATION MODES
--------------------------------------------------

**Governance Model:**
OCR behavior is governed by three distinct modes based on profile state and field history. This mode-based approach enables full automation during biodata-based registration while preserving data safety, authority, and auditability.

**MODE 1: FIRST PROFILE CREATION (Biodata-based Registration)**

- OCR is allowed to AUTO-FILL:
  - CORE fields
  - EXTENDED fields
- No manual approval required at this stage
- Profile is marked conceptually as "OCR-created"
- Goal: Manual data entry = 0
- No data should be missed from biodata

**MODE 2: EXISTING PROFILE + NEW BIODATA UPLOAD**

OCR parses new biodata and evaluates field-by-field:

For CORE fields:
- If CORE field is EMPTY → OCR auto-fill is ALLOWED
- If CORE field EXISTS and value is SAME → Ignore
- If CORE field EXISTS and value is DIFFERENT → 
  ❌ No auto-overwrite
  ✅ Human approval required (Admin/User/Matchmaker)

For EXTENDED fields:
- If field does NOT exist → Auto-create & auto-fill allowed
- If field exists with different value → Approval required

**MODE 3: POST-HUMAN-EDIT LOCK STATE**

- If a CORE or EXTENDED field has been edited by:
  - User
  - Admin
  - Matchmaker
- That field is considered LOCKED
- OCR may suggest changes but:
  ❌ Cannot overwrite automatically
- Admin retains override authority (per authority order)

**Governance Clarification:**
- OCR auto-fills CORE fields during first profile creation and for missing fields only
- Overwriting existing CORE fields always requires human approval
- This change applies ONLY to governance understanding
- No implementation details, APIs, schemas, or workflows are specified here

--------------------------------------------------
C.1.2) FIELD LOCK & OVERWRITE RULES
--------------------------------------------------

**When user edits permanently lock a field:**
- User edit sets lock_after_user_edit = true
- Locked fields cannot be overwritten by OCR/System
- Admin may override locks if needed (per authority order)

**When OCR is allowed to overwrite a field:**
- Only if field has not been user-edited (lock_after_user_edit = true)
- Only if is_system_overwritable = true
- Subject to mode-based governance rules above
- Never silently overwrite fields edited by higher authority

--------------------------------------------------
D) PROFILE SNAPSHOT AWARENESS
--------------------------------------------------

- Introduce the concept of profile snapshots
- Snapshot = read-only view of profile at a critical moment
  (interest sent, match generated, verification)
- Required for audit, disputes, and AI training consistency
- Supports Historical Integrity & Non-Destructive Change Principle
- No implementation details, concept only

--------------------------------------------------
E) SEARCH RESULT FAIRNESS & ROTATION RULES (NON-AI)
--------------------------------------------------

- Search results must avoid permanent top profiles
- Rotation and freshness awareness required
- Demo vs real profile balance awareness
- Diversity and fairness as non-AI baseline rules

**Non-AI Rotation Clarity:**
- Rotation happens before pagination
- Same profiles should not permanently appear in top results
- Demo vs Real profile balance awareness per page
- Deterministic, non-AI behavior

--------------------------------------------------
F) CANONICAL PROFILE VISIBILITY STATES
--------------------------------------------------

- Document a finite set of profile visibility states
  (e.g. Draft, Active, Search-Hidden, Suspended, Archived, Demo-Hidden)
- Clarify that state transitions are controlled
- Visibility state affects search, interactions, and AI signals
- States are independent of match outcomes (per Profile Outcome Independence Principle)
- State transitions are intentional and governed (per Event vs State Separation Principle)

--------------------------------------------------
F.1) PROFILE LIFECYCLE GOVERNANCE MATRIX
--------------------------------------------------

**Conceptual State-Behavior Matrix (governance clarity only):**

| State | Editable | Searchable | Interest/Interaction Allowed |
|-------|----------|------------|----------------------------|
| Draft | Yes | No | No |
| Active | Yes | Yes | Yes |
| Suspended | No | No | No |
| Archived | No | No | No |
| Demo-Hidden | Yes | No | No |

**Important:**
- This is NOT UI logic
- This is NOT enforcement logic
- This is a governance clarity matrix only
- Actual state names and behaviors subject to SSOT finalization

--------------------------------------------------
G) MULTI-LANGUAGE READINESS (OPTIONAL BUT NOTED)
--------------------------------------------------

- Blueprint should acknowledge future localization
- Field labels, enums, and UI text may be localized
- Internal logic remains language-agnostic

--------------------------------------------------
H) DATA EXPORT & PORTABILITY GOVERNANCE NOTE
--------------------------------------------------

**Policy-Level Statement:**
- CORE and EXTENDED fields remain export-capable
- Archived fields are still part of data ownership
- This is for legal/user portability readiness
- No implementation required in Phase-3

**Governance Intent:**
- Users own their profile data (CORE and EXTENDED)
- Data export functionality should include all fields
- Archived or disabled fields remain part of user's data
- Export format should be machine-readable and complete
- Supports Profile as Social & Legal Representation Principle

============================================================
📐 PHASE-3 PROFILE FIELD STRATEGY & DATA MODEL BOUNDARIES
============================================================

⚠️ **STATUS:** This section provides **planning guidance** for Phase-3 profile field design. It is **NOT executable** and is **subject to SSOT lock** before implementation.

⚠️ **PURPOSE:** Document field strategy, scope boundaries, and future-ready decisions that inform AI matching, search quality, and automation readiness.

------------------------------------------------
A) CORE PRINCIPLE (MANDATORY)
------------------------------------------------

**Design Philosophy:**
- Profile fields in Phase-3 are designed for:
  - **AI matching** — fields must support compatibility scoring and algorithmic matching
  - **Search quality** — fields must enable effective filtering and search precision
  - **Automation readiness** — fields must be structured for automated processing and parsing

**Explicitly NOT designed for:**
- Maximum data collection (avoid over-collection)
- Over-complex hierarchies (keep structure simple)
- Deep nested relationships (maintain flat or shallow structure)

**Rationale:**
- AI systems require clean, structured data signals
- Complex hierarchies create noise and reduce matching quality
- Simple structures improve user experience and system performance
- Automation systems benefit from predictable, consistent field structures

------------------------------------------------
B) LOCATION MODEL (LOCKED FOR PHASE-3)
------------------------------------------------

**Phase-3 Location Structure:**
- **Country:** Optional (default = India)
- **State:** REQUIRED
- **City:** REQUIRED

**Explicitly Excluded from Phase-3:**
- District / Taluka / Village fields are **NOT included** in Phase-3
- Deep geographic hierarchy is **deferred to later phases**
- Sub-city or locality-level fields are **NOT part of Phase-3 scope**

**Rationale:**
- **Data quality:** Deeper hierarchies increase data entry errors and inconsistency
- **AI noise reduction:** Too many location levels create matching noise and reduce signal quality
- **UX simplicity:** Users benefit from simple, clear location selection
- **Search performance:** Shallow hierarchy improves search query performance
- **Automation readiness:** Simple structure enables cleaner OCR parsing and mapping

**Future Considerations:**
- District/Taluka/Village may be added in later phases if business requirements demand
- Phase-3 location model must be extensible but not implemented

------------------------------------------------
C) PROFILE PHOTOS POLICY
------------------------------------------------

**Phase-3 Photo Support:**
- Phase-3 supports **ONLY** one primary profile photo
- Single photo per profile is the Phase-3 standard

**Explicitly Deferred:**
- Multiple photos / photo gallery is **explicitly deferred** to later phases
- Photo albums or media collections are **NOT part of Phase-3 scope**

**Rationale:**
- **Moderation complexity:** Multiple photos increase moderation workload and abuse risk
- **Storage & abuse risk:** Photo galleries require more storage and create higher abuse potential
- **Not required for initial AI matching:** Single photo sufficient for profile identification and initial matching
- **User experience focus:** Single photo keeps profile creation simple and focused

**Future Considerations:**
- Photo gallery features may be added in later phases based on user demand
- Phase-3 photo infrastructure must not prevent future gallery implementation

------------------------------------------------
D) BIODATA → PROFILE PIPELINE (FUTURE-READY NOTE)
------------------------------------------------

**Phase-3 Scope:**
- Phase-3 does **NOT implement** biodata upload functionality
- Phase-3 does **NOT implement** automated biodata parsing
- Phase-3 does **NOT implement** auto-profile creation from biodata

**Phase-3 Preparation Requirement:**
- Phase-3 **MUST prepare** profile fields so that:
  - Biodata parsing can map cleanly in future phases
  - Field structure supports future OCR extraction workflows
  - Field definitions enable future biodata-to-profile mapping

**Deferred Functionality:**
- Biodata upload & auto-profile creation deferred to later phase
- OCR-based profile population deferred to later phase
- Automated biodata ingestion deferred to later phase

**Rationale:**
- Phase-3 focuses on field governance and structure
- Biodata parsing requires separate implementation phase
- Field structure must be stable before biodata mapping is implemented
- Future-ready design enables clean biodata integration later

------------------------------------------------
E) PHASE-3 RECOMMENDED PROFILE FIELD CATEGORIES
------------------------------------------------

**Note:** These are **category groupings** for planning purposes. **No field-level implementation details** are specified here.

**1. Basic Identity**
- Core identification fields (name, gender, age/DOB)
- Profile metadata (creation date, last update, status)

**2. Marital Information**
- Marital status
- Divorce/widowhood details (if applicable)
- Marriage timeline information

**3. Community**
- **Religion** (explicitly included in Phase-3)
- Caste / sub-caste
- Community preferences

**4. Education & Occupation**
- Education level and details
- Occupation / profession
- Employment status
- Income range (if applicable)

**5. Location**
- Country (optional, default India)
- State (required)
- City (required)
- Current location vs native location distinction

**6. Family Background**
- Family structure information
- Sibling details
- Parent information
- Family values and background

**7. Preferences**
- Age preference range
- Location preference (state/city)
- Community preference (religion, caste)
- Education preference
- Other compatibility preferences

**8. Media**
- Single profile photo (primary)
- Photo approval status
- Photo moderation metadata

**Category Notes:**
- Categories are organizational groupings, not database structures
- Fields within categories may be CORE or EXTENDED based on app dependencies
- Category boundaries are flexible and subject to SSOT finalization

------------------------------------------------
F) CRITICAL DESIGN CONSIDERATIONS (OFTEN MISSED)
------------------------------------------------

**1. Field Versioning Awareness**
- Fields may evolve over time (values, structure, meaning)
- System must handle field definition changes gracefully
- Historical data must remain accessible and interpretable
- Field versioning strategy must be planned before implementation
- Supports Historical Integrity & Non-Destructive Change Principle

**2. "Unknown / Prefer not to say" Values**
- Fields must support explicit "unknown" or "prefer not to say" options
- These values are distinct from NULL/empty
- Search and matching logic must handle these values appropriately
- User privacy and comfort require these options

**3. Searchable vs Non-Searchable Distinction**
- Not all fields should be searchable
- Searchable fields affect query performance and user experience
- Non-searchable fields may be display-only or internal-use
- Searchability decision affects field categorization (CORE vs EXTENDED)

**4. Mandatory vs Optional Field Rules**
- Field mandatory status affects profile completeness calculation
- Mandatory fields may vary by user type or profile state
- Optional fields should not block profile creation or activation
- Mandatory rules must be clearly defined and consistently enforced

**5. Profile Completeness Recalculation Impact**
- Adding or removing fields affects completeness percentages
- Changing mandatory status affects completeness scores
- Completeness calculation must be version-aware
- Users should understand how completeness is calculated

**6. Field Dependency Impact**
- Field dependencies affect form rendering and data validation
- Dependencies create cascading effects when fields change
- Dependency chains must be validated to prevent circular references
- Field archival must consider dependent field relationships

**7. Data Migration Considerations**
- Field changes may require data migration
- Historical data preservation must be planned
- Field renaming or restructuring requires migration strategy
- User-facing changes require communication and transition planning

**8. API Compatibility**
- Field changes affect API responses
- API versioning may be required for field changes
- Mobile app compatibility must be maintained
- Field additions should not break existing API consumers

**9. Search Index Impact**
- Searchable fields require indexing strategy
- Field changes affect search index structure
- Index rebuilds may be required for field modifications
- Search performance depends on indexed field selection

**10. Matching Algorithm Considerations**
- Field selection affects matching quality
- Some fields are more important for compatibility scoring
- Field weights may need adjustment over time
- Matching algorithms must handle missing or unknown field values

============================================================
🏗️ Phase-3: Dynamic Biodata & Field Governance System
============================================================

------------------------------------------------
1) CORE vs EXTENDED FIELD MODEL
------------------------------------------------

**CORE Fields:**
- **Definition:** Fixed schema fields that are fundamental to the matrimony platform
- **Characteristics:**
  - Fixed database schema (columns exist in `matrimony_profiles` table)
  - Used by search algorithms, filters, matching logic
  - Required by Flutter/Web app UI components
  - Cannot be created or deleted by admin at runtime
  - Changes require code modification + app release
- **Examples:** `full_name`, `gender`, `date_of_birth`, `marital_status`, `education`, `location`, `caste`, `height_cm`, `profile_photo`
- **Governance:** Managed via code migrations and app updates only

**EXTENDED Fields:**
- **Definition:** Contextual, OCR-derived, or optional fields that extend beyond core biodata
- **Characteristics:**
  - Admin-creatable at runtime (no code changes required)
  - Stored as key-value pairs (JSON or separate `profile_extended_fields` table)
  - No hard dependency in app logic (search, matching, filters)
  - Rendered as passive "Additional Information" sections
  - Can be enabled/disabled without breaking app functionality
- **Examples:** `property_details`, `children_info`, `medical_conditions`, `family_notes`, `preferences`, `hobbies`
- **Governance:** Managed via admin UI, stored separately from CORE fields

**Separation Principle:**
- CORE fields = App-critical, schema-bound, migration-controlled
- EXTENDED fields = Contextual, runtime-managed, app-agnostic

------------------------------------------------
2) ADMIN FIELD CREATION (EXTENDED ONLY)
------------------------------------------------

**Admin MAY:**
- Create EXTENDED fields via admin UI
- Define field properties:
  - Label (display name)
  - Description (help text)
  - Data type (text, number, date, boolean, select)
  - Field key (unique identifier)
- Enable or disable EXTENDED fields
- Soft-archive EXTENDED fields (hide without deleting data)
- Reorder EXTENDED fields for display purposes

**Admin SHALL NOT:**
- Create database columns dynamically
- Modify CORE field definitions
- Hard delete EXTENDED fields or associated data
- Change CORE field data types or constraints
- Promote EXTENDED fields to CORE without code review

**Field Creation Workflow:**
1. Admin creates EXTENDED field via UI
2. Field stored in `extended_field_definitions` table (or similar)
3. Field values stored in `profile_extended_fields` table (key-value pairs)
4. Field appears in "Additional Information" section of profile views
5. No app redeployment required

------------------------------------------------
2.1) EXTENDED FIELD EXPLOSION SAFETY GUARD
------------------------------------------------

**Conceptual Safeguard (governance-level only):**

- Soft limits per category (example: ~50 fields)
- Admin warnings when thresholds are crossed
- No hard enforcement, no blocking
- Purpose: UX, performance, and admin sanity

**Governance Intent:**
- Prevent unbounded field growth that degrades UX
- Maintain reasonable profile display performance
- Provide admin guidance without blocking functionality
- Thresholds are advisory, not enforced

------------------------------------------------
3) DEPENDENCY & VISIBILITY RULES (SAFE MODE)
------------------------------------------------

**Dependencies Allowed:**
- Dependencies allowed ONLY for EXTENDED fields
- CORE fields cannot have dependencies (app logic handles this)

**Simple Parent-Child Logic:**
- Single parent → single child relationships only
- Equality-based conditions:
  - Show field X if parent field Y equals value Z
  - Show field X if parent field Y is present/not null
- Presence-based conditions:
  - Show field X if parent field Y has any value
  - Hide field X if parent field Y is empty

**Explicitly Excluded:**
- AND / OR rule builders (complex conditional logic)
- Nested dependencies (child of a child)
- Rule engines or expression evaluators
- Multi-parent dependencies
- Cross-field calculations or validations

**Example (Allowed):**
- Show `divorce_year` EXTENDED field only if `marital_status` CORE field equals "divorced"

**Example (Forbidden):**
- Show field X if (field A = value1 AND field B = value2) OR field C exists

------------------------------------------------
4) OCR → FIELD MAPPING LAYER
------------------------------------------------

**Architecture:**
- Store raw OCR text in `ocr_raw_data` table
- Parse OCR text into structured field candidates
- Map extracted data into CORE and EXTENDED fields (per mode-based governance)
- Include confidence score for each extraction
- Provide admin review workflow for low-confidence extractions and conflicts

**Components:**
1. **OCR Ingestion:**
   - Accept biodata images/documents
   - Extract raw text via OCR service
   - Store raw text with metadata (source, timestamp, confidence)

2. **Field Extraction:**
   - Parse raw text using pattern matching / NLP
   - Identify potential field values
   - Map to existing CORE and EXTENDED field definitions
   - Generate confidence scores per extraction

3. **Mode-Based Population:**
   - First profile creation: Auto-populate CORE and EXTENDED fields
   - Existing profile: Apply mode-based rules (empty fields auto-fill, conflicts require approval)
   - Locked fields: OCR suggests but cannot auto-overwrite

4. **Admin Review:**
   - Low-confidence extractions flagged for review
   - Field conflicts (existing vs OCR value) require human approval
   - Admin can approve, reject, or correct extractions
   - Approved extractions populate fields per mode rules
   - Rejected extractions stored for pattern improvement

5. **Data Storage:**
   - Raw OCR text: `ocr_raw_data` table
   - Extracted candidates: `ocr_extractions` table
   - Mapped values: CORE fields (direct) and `profile_extended_fields` table

**Safety:**
- OCR auto-fills CORE fields during first profile creation and for missing fields only
- Overwriting existing CORE fields always requires human approval
- All OCR operations subject to mode-based governance rules
- Admin approval required for low-confidence extractions and field conflicts
- Supports Historical Integrity & Non-Destructive Change Principle

------------------------------------------------
5) APPLICATION SAFETY GUARANTEE
------------------------------------------------

**Flutter/Web App Requirements:**
- Apps MUST depend ONLY on CORE fields for:
  - Search functionality
  - Filter logic
  - Matching algorithms
  - Critical UI components
  - Data validation

- Apps MUST render EXTENDED fields dynamically:
  - As key-value sections in profile views
  - No hardcoded field names
  - No assumptions about field existence
  - Graceful handling of missing fields

**Runtime Safety:**
- Adding EXTENDED fields must NOT require app redeploy
- Removing EXTENDED fields must NOT cause app crashes
- Disabling EXTENDED fields must NOT break app functionality
- Field type changes must NOT cause data corruption

**Implementation Pattern:**
- CORE fields: Direct model properties (`$profile->full_name`)
- EXTENDED fields: Dynamic accessor (`$profile->getExtendedField('property_details')`)
- Views: Iterate over available EXTENDED fields, render as generic key-value pairs

**Error Handling:**
- Missing EXTENDED fields: Render as empty or "Not provided"
- Invalid field types: Log warning, skip rendering
- Deleted field definitions: Preserve historical data, hide from UI

------------------------------------------------
6) FIELD VERSIONING & ARCHIVAL
------------------------------------------------

**Versioning Support:**
- Track field definition changes over time
- Maintain historical field configurations
- Support field replacement workflows
- Supports Historical Integrity & Non-Destructive Change Principle

**Archival Mechanism:**
- `is_archived` flag on field definitions
- Archived fields:
  - Hidden from admin UI (creation/editing)
  - Hidden from user-facing forms
  - Historical data preserved in database
  - Can be reactivated if needed

**Field Replacement:**
- `replaced_by_field` reference for deprecated fields
  - When field A is replaced by field B:
    - Field A marked as archived
    - Field B linked as replacement
    - Migration path for data (optional, manual)
    - Historical data remains accessible

**Data Preservation:**
- No hard delete of field definitions
- No hard delete of field values
- All historical biodata retained
- Archive instead of delete policy
- Supports Historical Integrity & Non-Destructive Change Principle

**Use Cases:**
- Field renamed: Archive old, create new, link replacement
- Field deprecated: Archive field, preserve data
- Field merged: Archive both, create merged field

------------------------------------------------
7) SEARCH & MATCHING PROTECTION
------------------------------------------------

**EXTENDED Fields Default Behavior:**
- **Not searchable by default:**
  - EXTENDED fields excluded from search queries
  - No filter options for EXTENDED fields in search UI
  - Search algorithms ignore EXTENDED field values

- **Not part of matching logic:**
  - Compatibility scoring uses CORE fields only
  - Matching algorithms operate on CORE field data
  - EXTENDED fields do not affect match percentages

**Search Safety:**
- Search performance depends on CORE fields only
- Adding EXTENDED fields does not impact search speed
- Removing EXTENDED fields does not break search functionality

**Future Promotion to CORE:**
- Promotion workflow (proposal only, not Phase-3):
  1. Admin proposes EXTENDED → CORE promotion
  2. Impact review required:
     - App code changes needed
     - Database migration required
     - Flutter/Web app updates required
  3. Code review and approval process
  4. Migration plan and testing
  5. App release with new CORE field

- **Not automatic:**
  - No automatic promotion based on usage
  - No automatic schema changes
  - Manual, reviewed process only

**Exception Handling:**
- If EXTENDED field needs searchability:
  - Create separate searchable CORE field
  - Migrate data manually
  - Update app code
  - Not a runtime operation

------------------------------------------------
8) EXPLICIT NON-GOALS (PHASE-3)
------------------------------------------------

**Explicitly Excluded from Phase-3:**

- **No Dynamic DB Schema Mutation:**
  - Cannot create database columns at runtime
  - Cannot alter table structure dynamically
  - All schema changes via migrations only

- **No Free-Form Rule Builders:**
  - No visual rule builder UI
  - No expression evaluators
  - No complex conditional logic engines
  - Simple parent-child dependencies only

- **No App Hard-Dependency on EXTENDED Fields:**
  - Apps cannot require specific EXTENDED fields
  - Apps cannot assume EXTENDED field existence
  - Apps must function without any EXTENDED fields

- **No Auto-Promotion to CORE:**
  - EXTENDED fields never automatically become CORE
  - No usage-based promotion
  - No automatic schema changes
  - Manual, reviewed process only

- **No Complex Field Types:**
  - No nested objects
  - No arrays/lists (single values only)
  - No file uploads in EXTENDED fields
  - Simple scalar types only (text, number, date, boolean, select)

- **No Field Validation Rules:**
  - No custom validation logic for EXTENDED fields
  - No cross-field validation
  - Basic type checking only

- **No Field Relationships:**
  - No foreign key relationships for EXTENDED fields
  - No references to other profiles
  - Self-contained key-value pairs only

============================================================
📐 ARCHITECTURAL CONSTRAINTS SUMMARY
============================================================

**Allowed:**
✅ Admin creates EXTENDED fields at runtime
✅ Simple parent-child field dependencies
✅ OCR → CORE and EXTENDED field mapping (per mode-based governance)
✅ Field archival (soft delete)
✅ Dynamic rendering of EXTENDED fields in apps

**Forbidden:**
❌ Dynamic database schema changes
❌ CORE field modification via admin UI
❌ Complex rule builders or expression evaluators
❌ App hard-dependency on EXTENDED fields
❌ Automatic promotion to CORE
❌ Hard delete of field definitions or data

============================================================
📝 NOTES
============================================================

- This blueprint is a **proposal-level document**
- Implementation details will be defined in Phase-3 SSOT (if approved)
- Phase-2 SSOT remains locked and unchanged
- This architecture ensures app stability while enabling flexible biodata management
- All governance principles guide Phase-3 and future phases

============================================================
🔍 PHASE-3: OPEN GOVERNANCE DECISIONS (UNDER DISCUSSION)
============================================================

⚠️ **CRITICAL:** This section documents IMPORTANT DESIGN DECISIONS that are **NOT finalized** yet but **MUST be consciously decided** before Phase-3 implementation.

⚠️ **STATUS:** These topics are **intentionally deferred** and marked as governance-level decisions.

⚠️ **REQUIREMENT:** Implementation **MUST NOT begin** until these topics are finalized and promoted into Phase-3 SSOT.

**Decision Status Markers:**
- **AGREED IN PRINCIPLE:** Core concept accepted, details to be finalized
- **PROPOSED:** Concept under consideration, requires discussion
- **OPEN:** Active discussion needed, no consensus yet

------------------------------------------------
1) OCR DATA OWNERSHIP & VERSIONING
------------------------------------------------

**AGREED IN PRINCIPLE:**
- OCR-extracted data can be edited by the USER
- User-edited data is considered final and authoritative
- Admin review is NOT required by default

**OPEN QUESTIONS:**

- **Versioning Strategy:**
  - Should OCR edits be versioned?
  - How many historical versions to retain?
  - What is the retention period for version history?
  - Should version history be visible to users?

- **Data Preservation:**
  - Should OCR original value be preserved alongside user-corrected value?
  - Should we store both `ocr_value` and `user_corrected_value`?
  - How should conflicts be resolved if OCR re-processes a user-edited field?

- **Re-processing Behavior:**
  - Can OCR re-parse overwrite user-edited values?
  - Should OCR re-processing skip fields that have been user-edited?
  - Should there be a "lock" flag to prevent OCR overwriting user edits?
  - What happens if user edits a field, then OCR provides a higher-confidence value?

- **Audit Trail:**
  - Should all OCR → user edit transitions be logged?
  - Should we track who made the edit (user vs admin vs OCR)?
  - How detailed should the edit history be?

**Governance Impact:**
- These decisions affect data integrity, user trust, and system behavior
- Must be finalized before OCR ingestion system is implemented
- Must align with Historical Integrity & Non-Destructive Change Principle

------------------------------------------------
2) FIELD TRUST LEVEL & SOURCE TAGGING
------------------------------------------------

**PROPOSED CONCEPT:**
- Fields may have `trust_level` attribute (e.g., OCR, User-Declared, Admin-Verified)
- Fields may have `source` attribute tracking data origin
- Trust and source metadata stored alongside field values

**OPEN QUESTIONS:**

- **Trust Level Impact:**
  - Should `trust_level` affect search ranking or matching?
  - Should untrusted OCR data be excluded from critical matches?
  - Should low-trust fields have reduced weight in compatibility scoring?
  - How should trust levels be assigned and updated?

- **User Visibility:**
  - Should trust/source be visible to the user in UI?
  - If visible, how subtle or explicit should it be?
  - Should users see "OCR-extracted" badges on fields?
  - Would showing trust levels reduce user confidence?

- **Admin Workflow:**
  - Can admins manually adjust trust levels?
  - Should admin verification automatically upgrade trust level?
  - How should trust levels be displayed in admin UI?

- **Matching Algorithm:**
  - Should matching algorithms consider trust levels?
  - Should high-trust fields have higher weight in compatibility calculations?
  - Should untrusted fields be excluded from critical match decisions?

**Governance Impact:**
- Affects matching quality, user experience, and data reliability perception
- Must be finalized before matching algorithms are updated
- Must align with Human Trust Overrides AI Confidence Principle

------------------------------------------------
3) FIELD-LEVEL PRIVACY CONTROL
------------------------------------------------

**AGREED IN PRINCIPLE:**
- Not all fields should be publicly visible
- User should control visibility of sensitive fields
- Privacy controls are essential for sensitive biodata

**OPEN QUESTIONS:**

- **Privacy Scopes:**
  - What privacy scopes should be supported?
  - Proposed options:
    - Public (visible to all)
    - Logged-in (visible to authenticated users only)
    - Matched-only (visible after mutual interest/match)
    - Admin-only (visible to admins only)
  - Are additional scopes needed (e.g., "Premium users only")?

- **Default Privacy:**
  - Should some fields be permanently private (user cannot make public)?
  - Should OCR-derived sensitive fields default to private?
  - Which fields should have privacy controls enabled?
  - Should privacy be field-level or profile-level?

- **Privacy Enforcement:**
  - How should privacy be enforced in API responses?
  - Should privacy checks happen at database level or application level?
  - How should privacy affect search visibility?
  - Should private fields be excluded from search entirely?

- **User Experience:**
  - How should users configure field privacy?
  - Should privacy settings be per-field or bulk?
  - Should users see privacy indicators in their profile?
  - How should privacy be communicated to viewers?

**Governance Impact:**
- Critical for user trust and data protection
- Affects API design, database queries, and UI/UX
- Must be finalized before privacy controls are implemented
- Must align with Profile as Social & Legal Representation Principle

------------------------------------------------
4) PARTIAL PROFILE VISIBILITY & MONETIZATION
------------------------------------------------

**AGREED IN PRINCIPLE:**
- Partial profile visibility can encourage free → paid conversion
- Monetization strategy may require field-level access control

**OPEN QUESTIONS:**

- **Free User Visibility:**
  - Which fields are visible to free users?
  - Should free users see basic CORE fields only?
  - Should EXTENDED fields be premium-only?
  - How many fields should be visible in free tier?

- **Visibility Expansion Triggers:**
  - At what interaction stage does visibility expand?
  - Options to consider:
    - Interest sent (one-way visibility)
    - Interest accepted (mutual visibility)
    - Payment/subscription (full visibility)
    - Time-based (visibility after X days)
  - Should visibility expansion be automatic or user-initiated?

- **Monetization Model:**
  - Is visibility tied to interest acceptance, payment, or both?
  - Should there be multiple subscription tiers with different visibility levels?
  - Should premium users see all fields immediately?
  - How should monetization affect search and matching?

- **User Experience:**
  - How should partial visibility be communicated to users?
  - Should users see "Upgrade to see more" prompts?
  - Should field placeholders indicate hidden content?
  - How should this affect profile completeness calculation?

**Governance Impact:**
- Affects business model, user experience, and revenue strategy
- Must align with business requirements and user expectations
- Must be finalized before monetization features are implemented
- Must align with Profile as Social & Legal Representation Principle
- Visibility changes are EVENTS, not automatic state mutations (per Event vs State Separation Principle)

------------------------------------------------
5) DATA NORMALIZATION vs FREE-TEXT STRATEGY
------------------------------------------------

**OPEN DISCUSSION:**
- OCR produces heterogeneous free text
- Some fields benefit from normalization, others should remain free-text
- Strategy affects search, filtering, and performance

**OPEN QUESTIONS:**

- **Normalization Strategy:**
  - Which fields should be normalized (numeric, boolean, structured)?
  - Which fields should remain free-text forever?
  - Can both normalized + raw text coexist?
  - Should normalization happen during OCR ingestion or post-processing?

- **Normalization Examples:**
  - Should "property_value" be normalized to numeric (for range queries)?
  - Should "has_children" be normalized to boolean (for filtering)?
  - Should "education_details" remain free-text (for flexibility)?
  - Should dates be normalized even if OCR provides text?

- **Search & Performance Impact:**
  - How does normalization affect search performance?
  - Can normalized fields be indexed more efficiently?
  - Should free-text fields use full-text search?
  - How should mixed normalized/free-text fields be queried?

- **Data Quality:**
  - How should normalization errors be handled?
  - Should failed normalization preserve original text?
  - Should normalization be reversible (store both formats)?
  - How should normalization affect data validation?

- **User Experience:**
  - Should users see normalized values or original text?
  - How should normalization be communicated in UI?
  - Should users be able to edit normalized values?

**Governance Impact:**
- Affects database schema, search algorithms, and performance
- Must balance flexibility vs. searchability vs. performance
- Must be finalized before OCR parsing logic is implemented
- Must align with Historical Integrity & Non-Destructive Change Principle

------------------------------------------------
6) FIELD ARCHIVAL & LIFECYCLE POLICY
------------------------------------------------

**AGREED IN PRINCIPLE:**
- Fields should NOT be hard-deleted
- Archived fields may be hidden from user UI
- OCR ingestion may still populate archived fields

**OPEN QUESTIONS:**

- **Archival Duration:**
  - How long should archived fields remain queryable?
  - Should archived fields be queryable indefinitely?
  - Should there be a retention period before permanent archival?
  - Should archived fields be excluded from exports after X years?

- **Export Behavior:**
  - Should archived fields appear in data exports?
  - Should exports include historical field definitions?
  - How should archived fields be marked in exports?
  - Should users be able to export their archived field data?

- **OCR Re-processing:**
  - How should archived fields behave during OCR re-processing?
  - Should OCR populate archived fields if they match?
  - Should OCR skip archived fields entirely?
  - Should archived fields be reactivated if OCR finds new data?

- **User Visibility:**
  - Should users see archived fields in their profile history?
  - Should archived fields be visible in profile edit forms?
  - How should archived fields be displayed (if at all)?

- **Admin Workflow:**
  - Can admins reactivate archived fields?
  - Should reactivation require approval?
  - How should archived fields be managed in admin UI?
  - Should there be bulk archival operations?

**Governance Impact:**
- Affects data retention policy, user experience, and system maintenance
- Must align with data protection and privacy requirements
- Must be finalized before archival system is implemented
- Must align with Historical Integrity & Non-Destructive Change Principle

------------------------------------------------
7) MATCHMAKER ROLE & PERMISSIONS
------------------------------------------------

**OPEN DISCUSSION:**
- Matchmaker is distinct from User and Admin
- Matchmakers may need special permissions for profile management
- Role definition affects access control and audit requirements

**OPEN QUESTIONS:**

- **Profile Editing Permissions:**
  - Can matchmakers edit profiles?
  - Can they edit CORE fields or EXTENDED fields only?
  - Should matchmaker edits require user approval?
  - Should matchmaker edits be visible to users?

- **Field Management:**
  - Can matchmakers suggest new EXTENDED fields?
  - Can they create EXTENDED fields directly?
  - Should matchmaker field suggestions require admin approval?
  - How should matchmaker field suggestions be tracked?

- **OCR Data Correction:**
  - Can matchmakers correct OCR data on behalf of users?
  - Should matchmaker corrections be marked differently than user corrections?
  - Should matchmaker corrections require user notification?
  - How should matchmaker corrections affect trust levels?

- **Audit Trail Requirements:**
  - What audit trail is required for matchmaker actions?
  - Should all matchmaker edits be logged with reason?
  - Should matchmaker actions be visible to users?
  - Should matchmaker actions be visible to admins?

- **Access Control:**
  - Should matchmakers have access to all profiles or assigned profiles only?
  - Should matchmakers see private fields?
  - Should matchmakers have search/filter capabilities?
  - How should matchmaker permissions be managed?

- **Role Definition:**
  - Is matchmaker a separate user role or a permission flag?
  - Can a user be both matchmaker and regular user?
  - How should matchmaker accounts be created and managed?
  - Should matchmakers have their own profiles?

**Governance Impact:**
- Affects access control, audit logging, and user trust
- Must align with business model and regulatory requirements
- Must be finalized before matchmaker features are implemented
- Must align with authority order: Admin > User > Matchmaker > OCR/System
- Must align with Profile as Social & Legal Representation Principle

============================================================
📋 GOVERNANCE DECISION WORKFLOW
============================================================

**Current Status:**
- All topics above are **OPEN FOR DISCUSSION**
- No final decisions have been made
- These topics are **intentionally deferred** from Phase-3 architecture

**Required Process:**
1. **Discussion Phase:** Stakeholders review and discuss each topic
2. **Decision Phase:** Final decisions documented with rationale
3. **SSOT Promotion:** Decisions promoted to Phase-3 SSOT
4. **Implementation:** Development begins only after SSOT finalization

**Blockers:**
- Phase-3 implementation **MUST NOT begin** until all governance decisions are finalized
- Critical features (OCR ingestion, privacy controls, monetization) depend on these decisions
- Implementation without finalized governance risks architectural debt and rework

**Documentation Requirements:**
- All final decisions must be documented in Phase-3 SSOT
- Rationale for each decision must be recorded
- Trade-offs and alternatives considered must be noted
- Implementation constraints derived from decisions must be explicit
- All decisions must align with Core Governance Principles

============================================================
📝 ADDITIONAL NOTES
============================================================

- This governance section is **living document** — topics may be added or refined
- Decisions marked as "AGREED IN PRINCIPLE" still require detail finalization
- "OPEN" topics require active discussion and stakeholder input
- "PROPOSED" concepts are under consideration and may be accepted or rejected

- **Timeline:** Governance decisions should be finalized before Phase-3 development begins
- **Impact:** These decisions affect system architecture, user experience, and business model
- **Risk:** Implementing without finalized governance may require significant rework

============================================================
💎 FUTURE VALUE MULTIPLIERS (OPTIONAL / NON-BINDING)
"SONE PE SUHAGA" FEATURES
============================================================

⚠️ **IMPORTANT:** This section lists high-impact concepts that are **OPTIONAL**, **FUTURE-FACING**, and **NON-BINDING**.

⚠️ **STATUS:** These ideas are **NOT required** for Phase-3 success. They are **idea pool** concepts that can be selectively picked in future phases.

⚠️ **PHILOSOPHY:** These features emphasize empathy, trust, clarity, and real-world usability. They are designed to improve user experience without introducing heavy AI dependencies or fragile systems.

**Selection Criteria:**
- Improve trust, clarity, and real-world usability
- Do NOT introduce heavy AI or fragile dependencies
- Can be implemented selectively in future phases
- Align with governance-first, empathy-driven product philosophy

------------------------------------------------
1) Profile Confidence Score
------------------------------------------------

**Concept:**
- Internal quality indicator for profile data reliability
- Score calculated based on:
  - Data stability over time
  - OCR vs user edits ratio
  - Field completeness consistency
  - Contradiction detection results
- **NOT shown as raw score** to users (avoids shaming)

**Value:**
- Used internally for ranking or matchmaker guidance
- Helps identify profiles needing attention
- Improves overall platform data quality perception

**Implementation Notes:**
- Rule-based calculation (no ML required)
- Can be displayed as qualitative indicators (High/Medium/Low confidence)
- Optional feature, does not block core functionality

------------------------------------------------
2) Profile Story / Narrative Mode
------------------------------------------------

**Concept:**
- Rule-based summary of key biodata highlights
- Converts many fields into a short, readable narrative paragraph
- Automatically generated from CORE and EXTENDED fields

**Value:**
- Useful for parents and matchmakers who prefer narrative over forms
- Makes profiles more human-readable and relatable
- Reduces cognitive load when reviewing many profiles

**Implementation Notes:**
- Template-based generation (no NLP/AI required)
- Uses field values to construct natural-language summary
- Can be regenerated when profile data changes
- Optional display mode, does not replace structured data

------------------------------------------------
3) Contradiction Detection
------------------------------------------------

**Concept:**
- Detects conflicts between related fields
- Examples:
  - Children count field vs children details notes
  - Age vs education timeline inconsistencies
  - Location vs work location conflicts
- Shows **soft warnings**, not blocking errors

**Value:**
- Improves trust and data hygiene
- Helps users identify and correct mistakes
- Reduces confusion for matchmakers and viewers
- Prevents embarrassing profile inconsistencies

**Implementation Notes:**
- Rule-based detection (simple if-then logic)
- Non-blocking warnings (users can proceed)
- Suggests corrections but does not enforce
- Optional feature, enhances data quality

------------------------------------------------
4) Family Influence Tagging
------------------------------------------------

**Concept:**
- Field-level tag indicating decision source:
  - **Self:** User's own decision
  - **Family:** Family-influenced decision
  - **Joint:** Collaborative decision
- Applied to preference fields and key biodata

**Value:**
- Helps align expectations and reduce friction
- Provides context for matchmakers
- Acknowledges cultural reality of family involvement
- Reduces misunderstandings about decision-making

**Implementation Notes:**
- Simple metadata tag per field
- User-selectable during profile creation/edit
- Optional feature, does not affect matching logic
- Can be displayed subtly in profile views

------------------------------------------------
5) "Why This Profile?" Explainability
------------------------------------------------

**Concept:**
- Simple, human-readable reasons for match suggestions
- Explains why a profile was recommended:
  - "Similar education background"
  - "Compatible location preferences"
  - "Matching family values"
- No complex algorithms, just clear explanations

**Value:**
- Improves transparency for users and matchmakers
- Builds trust in matching system
- Helps users understand compatibility factors
- Reduces "random match" perception

**Implementation Notes:**
- Rule-based explanation generation
- Uses CORE field comparisons
- Simple, factual statements (no AI/ML)
- Optional feature, enhances user understanding

------------------------------------------------
6) Soft Timeline Indicators
------------------------------------------------

**Concept:**
- Profile freshness and recent activity signals
- Indicators like:
  - "Recently updated"
  - "Active profile"
  - "New member"
- **No shaming labels**, no pressure mechanics

**Value:**
- Helps users gauge profile relevance
- Provides context without creating urgency
- Respects user privacy and pace
- Improves matchmaker workflow efficiency

**Implementation Notes:**
- Time-based indicators (last update, creation date)
- Soft, positive language only
- No "inactive" or "stale" labels
- Optional feature, enhances user experience

------------------------------------------------
7) Negotiable vs Non-Negotiable Preferences
------------------------------------------------

**Concept:**
- User marks preferences as:
  - **Must:** Non-negotiable requirement
  - **Flexible:** Preferred but open to discussion
  - **Optional:** Nice to have, not critical
- Applied to preference fields (education, location, etc.)

**Value:**
- Reduces unnecessary mismatch frustration
- Helps matchmakers understand priorities
- Encourages realistic expectations
- Improves match quality and user satisfaction

**Implementation Notes:**
- Simple preference strength metadata
- User-selectable during preference setting
- Can affect match filtering (optional)
- Optional feature, improves matching precision

------------------------------------------------
8) Matchmaker Internal Notes
------------------------------------------------

**Concept:**
- Private notes visible only to matchmakers and admins
- Matchmakers can add context, observations, or reminders
- **Never exposed to users** or profile owners
- Audit-logged for accountability

**Value:**
- Helps matchmakers track important details
- Preserves institutional knowledge
- Improves matchmaker efficiency
- Maintains professional boundaries

**Implementation Notes:**
- Separate notes table linked to profiles
- Access-controlled (matchmaker/admin only)
- Full audit trail required
- Optional feature, supports matchmaker workflow

------------------------------------------------
9) Profile Maturity Indicator
------------------------------------------------

**Concept:**
- Qualitative state indicator:
  - **New:** Recently created, still being filled
  - **Settled:** Stable, complete, actively used
  - **Mature:** Long-term, well-maintained profile
- Based on stability, completeness, and activity patterns

**Value:**
- Helps matchmakers prioritize profiles
- Provides context for profile quality
- Acknowledges that profiles evolve over time
- Improves matching efficiency

**Implementation Notes:**
- Rule-based calculation (time + completeness + activity)
- Qualitative labels, not numeric scores
- Optional feature, does not affect core matching
- Can be displayed subtly in admin/matchmaker views

------------------------------------------------
10) Exit With Dignity
------------------------------------------------

**Concept:**
- User can gracefully archive profile after finding a match
- Preserves goodwill and brand trust
- Profile archived (not deleted) with optional "Found a match" status
- No pressure to stay active, no shaming for leaving

**Value:**
- Preserves user relationships and brand trust
- Creates positive exit experience
- Optional success story collection
- Reduces negative platform perception

**Implementation Notes:**
- Soft archive with optional success status
- User-initiated, no automatic archiving
- Preserves all data for potential reactivation
- Optional feature, improves user experience

============================================================
💎 VALUE MULTIPLIER SUMMARY
============================================================

**Common Themes:**
- **Empathy:** Features respect user pace, privacy, and dignity
- **Trust:** Transparency and explainability build confidence
- **Clarity:** Simple indicators reduce confusion
- **Human Realism:** Acknowledges cultural and practical realities

**Implementation Approach:**
- All features are **optional** and can be implemented selectively
- **No AI/ML dependencies** — rule-based, deterministic logic
- **Non-blocking** — core functionality works without these features
- **Future-facing** — can be added incrementally in later phases

**Selection Criteria:**
- Features should be evaluated based on:
  - User value and impact
  - Implementation complexity
  - Maintenance burden
  - Alignment with product philosophy
  - Business priorities

**No Commitments:**
- These ideas are **idea pool** concepts only
- **No prioritization** implied by order
- **No execution commitment** required
- **No timeline** assigned
- Features can be accepted, rejected, or modified based on future needs

============================================================
📝 NOTES
============================================================

- These value multipliers are **optional enhancements**
- They represent **"sone pe suhaga"** (icing on the cake) features
- Selection and implementation should be based on:
  - User feedback and needs
  - Business priorities
  - Resource availability
  - Technical feasibility
- **No Phase-3 dependency** — core Phase-3 functionality does not require these features

============================================================
📋 FINALIZATION NOTE
============================================================

**Version Status:**
- This document (v3.3 FINAL) represents the consolidated, governance-complete Phase-3 blueprint.
- All content from v3.1 FINAL (technical governance) and v3.2 ADDENDUM (philosophical governance) has been integrated.
- All governance clarifications have been consolidated.
- All remaining OPEN governance decisions are explicitly marked and documented.
- Structural consistency has been verified across all sections.
- No contradictions exist between technical and philosophical governance principles.

**SSOT Readiness:**
- This version is suitable as the SOLE REFERENCE DOCUMENT for Phase-3 SSOT creation.
- All governance concepts are documented at the appropriate level (governance, not implementation).
- No hidden assumptions remain — all open items are explicitly marked.
- The document maintains its proposal-level, future-ready, governance-heavy character.
- All principles align with Phase-2 SSOT constraints and Core System Laws.

**Next Steps:**
- Review and finalize OPEN GOVERNANCE DECISIONS section.
- Promote finalized decisions to Phase-3 SSOT.
- Extract implementation requirements from finalized governance.
- Begin Phase-3 development only after SSOT finalization.

**Document Integrity:**
- All content from v3.1 and v3.2 preserved without conceptual drift.
- All sections maintain governance-level focus (no implementation details).
- Structural improvements applied for clarity and consistency.
- Single source of truth for Phase-3 blueprint consolidation complete.
- Ready for SSOT extraction and promotion.

=====================
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