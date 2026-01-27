============================================================
📋 PHASE-3 BLUEPRINT — Dynamic Biodata & Field Governance System
============================================================

⚠️ IMPORTANT: THIS IS A ROUGH PROPOSAL-LEVEL BLUEPRINT
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

**Phase-3 Goals:**
- Handle large-scale biodata ingestion via OCR
- Expected profile fields ≈ 130+
- Biodata content is heterogeneous (property, children, conditions, notes, etc.)
- Flutter + Web apps already exist and MUST NOT break due to runtime field changes

**Core Architectural Decision (LOCKED FOR PHASE-3 BLUEPRINT):**
Adopt a **2-Layer Profile Field Architecture**:
1) CORE fields (locked, app-dependent)
2) EXTENDED fields (admin-creatable, runtime-safe)

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
- Map extracted data into EXTENDED fields
- Include confidence score for each extraction
- Provide admin review workflow for low-confidence extractions

**Components:**
1. **OCR Ingestion:**
   - Accept biodata images/documents
   - Extract raw text via OCR service
   - Store raw text with metadata (source, timestamp, confidence)

2. **Field Extraction:**
   - Parse raw text using pattern matching / NLP
   - Identify potential field values
   - Map to existing EXTENDED field definitions
   - Generate confidence scores per extraction

3. **Admin Review:**
   - Low-confidence extractions flagged for review
   - Admin can approve, reject, or correct extractions
   - Approved extractions populate EXTENDED fields
   - Rejected extractions stored for pattern improvement

4. **Data Storage:**
   - Raw OCR text: `ocr_raw_data` table
   - Extracted candidates: `ocr_extractions` table
   - Mapped values: `profile_extended_fields` table

**Safety:**
- No automatic population of CORE fields from OCR
- All OCR data mapped to EXTENDED fields only
- Admin approval required for low-confidence extractions

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
✅ OCR → EXTENDED field mapping
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
