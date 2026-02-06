============================================================
PHASE-4 SINGLE SOURCE OF TRUTH (SSOT) v1.1
============================================================

SSOT Name   : Phase-4 Single Source of Truth
Version     : v1.1
Locked On   : 2026-02-04
Status      : FINAL AUTHORITATIVE

हा दस्तऐवज Phase-4 साठी निर्णायक अधिकार आहे.
Phase-4 ची कोणतीही अंमलबजावणी या दस्तऐवजाच्या बाहेर होणार नाही.
या SSOT नंतर कोणतीही corrections किंवा additions परवानगी नाही.

**FINAL DECLARATION:**
This file supersedes all previous Phase-4 SSOT drafts and blueprints.
All archived SSOT files (PHASE-1, PHASE-2, PHASE-3) and blueprints are reference-only.
This is the ONLY authoritative SSOT for Phase-4 going forward.

============================================================
1. PHASE-4 PURPOSE & SCOPE
============================================================

**What Phase-4 Is:**

Phase-4 is the final foundation layer for the matrimony system. It implements governance-first OCR/AI intake and conflict resolution within Phase-3 governance structures. Phase-4 enables biodata at scale with zero silent overwrite, provides admin control over overwrites and conflict resolution, ensures Shaadi.com-level user experience completeness, and maintains integration-ready v1 API and web behavior.

**What Phase-4 Is NOT:**

Phase-4 EXCLUDES:
- AI matching or scoring
- WhatsApp automation
- Payments or subscriptions
- Matchmaker network logic
- Automatic OCR apply (no blind apply, sandbox mandatory)
- Silent data overwrite
- SSOT-breaking changes
- Breaking v1 API changes
- Phase-1–3 behavior modifications
- User ≠ MatrimonyProfile violations
- Hard delete of profile/field data
- New CORE fields without migration
- Auto-migration of historical data

**Phase-5 Boundary:**

Phase-5 scope is STRICTLY LIMITED to:
a) AI usage on already-clean, governed data
b) WhatsApp communication layer
c) Payment execution

Phase-5 EXPLICITLY EXCLUDES:
- OCR parsing or logic changes
- Data normalization
- Conflict logic
- Field governance
- Lifecycle rules
- Admin authority changes

Phase-5 will NOT add, modify, replace, or extend any OCR logic or parsing engine. OCR is a Phase-4 FOUNDATION component and is production-complete.

Matchmaker is a separate, independent system and project.

============================================================
2. PHASE-4 GOVERNANCE LAWS (LOCKED)
============================================================

**Law 1: SSOT Supremacy**
- This SSOT document takes precedence over all blueprints, discussions, and proposals
- Blueprints are reference only and will not be used for implementation decisions
- Any conflict between blueprint and SSOT will be decided in favor of SSOT

**Law 2: MatrimonyProfile as Sole Biodata Authority**
- MatrimonyProfile is the single source of truth for all biodata
- User ≠ MatrimonyProfile (strict separation)
- User model used only for authentication and ownership
- All matchmaking interactions operate only on MatrimonyProfile entities
- Profile-centric business logic mandatory

**Law 3: Zero-Loss Biodata Principle**
- No line from uploaded biodata may be ignored, dropped, or lost
- Every extracted line must map to: CORE field, EXTENDED field, or preserved RAW BIODATA record
- If any biodata line is unmapped, profile creation or update is blocked until mapped or explicitly discarded with audit
- Manual typing for biodata-based profile creation should be zero
- "Additional Information (From Biodata)" is a permanent UI section
- No biodata line is ever dropped or silently purged

**Law 4: Authority Order (Non-Negotiable)**
- Global authority order: Admin > User > Matchmaker > OCR/System
- This order applies to: every approval flow, every conflict resolution, every overwrite decision
- Lower authority cannot silently overwrite higher authority
- Admin is supreme authority with audit trail

**Law 5: No Silent Overwrite**
- OCR never silently overwrites existing profile data
- Every overwrite of existing data goes through Conflict Record and resolution per Authority Order
- No destructive updates
- Historical values never erased or silently deleted

**Law 6: Conflict Immutability**
- Conflict records track differences between existing profile data and proposed changes
- Sources: OCR, USER, ADMIN, MATCHMAKER, SYSTEM
- Statuses: Pending, Approved, Rejected, Overridden
- Conflict records never hard-deleted
- Conflict records are immutable audit trail

**Law 7: Lock-Before-Write**
- Field locking prevents unauthorized overwrites
- Lock-before-write discipline enforced
- Lifecycle blocks edit when Suspended/Archived
- Post-human-edit lock (MODE 3) prevents OCR overwrite

**Law 8: Lifecycle Discipline**
- Lifecycle states: Draft, Active, Search-Hidden, Suspended, Archived, Owner-Hidden
- Lifecycle state change requires validation of allowed transitions
- Lifecycle edit block when Suspended/Archived
- Lifecycle transitions intentional and governed

**Law 9: Historical Integrity (Non-Destructive Updates)**
- Approved data changes take precedence over previous values
- Historical values never erased or silently deleted
- Historical values may be archived, versioned, or referenced
- No destructive updates

**Law 10: Field Identity & Data Integrity**
- Once a field exists and data is stored:
  - Its semantic meaning does not change
  - Its type is not reinterpreted
  - Historical values are not re-evaluated
- Field evolution possible only by adding new fields
- Existing fields cannot be redefined

**Law 11: Governance ≠ UX ≠ Logic Separation**
- Governance, UX, and Logic strictly separated
- Governance rules do not mix with UX
- UX logic does not mix with governance
- Business logic does not mix with governance
- Each layer is independent and handles separate concerns

**Law 12: Policy-First Rule**
- Any admin, system, OCR, monetization, or verification action requires explicit policy/configuration before implementation
- Implementing action without policy is SSOT violation
- Policy = documented rule + default behavior + boundary conditions
- Policy-less actions forbidden

**Law 13: Read-Only Operations Must Remain Read-Only**
- Read-only operations do not mutate domain data (view tracking and notifications excepted)
- Read-only operations remain read-only
- Read-only operations do not create side effects

**Law 14: No Implicit Side-Effect Creation**
- Interest, Shortlist, or Block records are not implicitly created
- No domain record created without explicit action
- Implicit side effects prohibited

**Law 15: Service Authority Rule**
- Business rules used in multiple controllers must be in a single Service class
- Business logic duplication prohibited
- Service classes are single authoritative implementation point for business rules

**Law 16: Blade Purity Law**
- Blade views contain only display logic
- No business logic in views

**Law 17: Admin Actions Do Not Silently Bypass Rules**
- Admin actions follow all core business rules
- Admin supremacy maintained with audit trail

**Law 18: Single Source per Core Concept**
- Each core concept has single authoritative implementation point

**Law 19: Datebook Alignment Law**
- Date and time consistency enforced everywhere
- Datebook alignment mandatory for all date/time operations
- Date/time discrepancies prohibited

**Law 20: Reference System Freeze Rule**
- Reference systems are frozen
- Reference data modifications prohibited without SSOT update
- Reference system changes require explicit SSOT approval
- Reference data integrity mandatory

**Law 21: Hygiene & Verification Day Rule**
- Every 3–4 execution days, one full day reserved for SSOT compliance verification, duplication removal, boundary validation, and regression checks
- No new features, enhancements, or scope expansion on hygiene day
- Skipping hygiene day is SSOT violation

**Law 22: Mandatory Exhaustive System Testing (Cursor-Only)**
- For EVERY development day, Cursor AI MUST perform exhaustive system testing before ANY human/manual testing is permitted
- Exhaustive testing includes:
  a) Database-level testing: table existence, column existence, null/empty/missing values, wrong data types, boundary values, duplicate values, foreign key violations, invalid enum/state injection
  b) Route-level testing: all referenced routes MUST exist, no Blade view may reference a non-existent route, direct URL access tests (valid + invalid), unauthorized access attempts
  c) Controller & Validation testing: missing required fields, extra/unexpected fields, invalid values beyond validation rules, empty strings/nulls/whitespace-only values, repeated submissions, bypass attempts
  d) UI & Blade rendering tests: all relevant Blade views MUST render without runtime error, no RouteNotFoundException/ViewException/500 error possible, no UI elements referencing future-scope or unimplemented features
  e) Negative & misuse testing: उलट सुलट inputs, शक्य/अशक्य combinations, चुकीच्या state मध्ये action, double-click/refresh/back-button misuse
  f) Phase regression testing: all previous phase functionality MUST remain intact, no side effects allowed
- Manual testing is STRICTLY FORBIDDEN unless Cursor explicitly reports: "EXHAUSTIVE SYSTEM TESTING PASSED — UI SAFE — NO RUNTIME ERRORS POSSIBLE"
- If ANY error is discovered during manual testing, it is considered a FAILURE of Cursor testing and a SSOT violation

**Law 23A: Day Resolution Canonical Rule**

For Phase-4 execution and testing:
- The authoritative Day definition is ONLY the one defined
  in Section 9 (Detailed Day-wise Execution & Verification Plan).
- Legacy day names, historical phase references, or developer memory
  MUST be ignored.
- If a Day number maps to a different feature in earlier phases,
  Cursor MUST resolve the Day strictly by Phase-4 Section 9.
- Any mismatch between assumed scope and Section 9 scope
  MUST cause immediate FAIL and STOP.

**Law 23C: Exhaustive Testing Output Canonical Rule**

For Phase-4 exhaustive testing:
- Only ONE canonical test report file MUST exist per Day.
- Canonical report file naming: DAY-{N}_FINAL_EXHAUSTIVE_TEST_REPORT.md
- Redundant or intermediate test report files MUST be deleted after final report is generated.
- Multiple test reports for the same Day cause confusion and MUST be avoided.
- If multiple reports exist, only the FINAL report is authoritative.

============================================================
3. PHASE-4 COMPLETE FEATURE SET (AUTHORITATIVE)
============================================================

**3.1 Conflict System**

- Conflict records track differences between existing profile data and proposed changes
- Sources: OCR, USER, ADMIN, MATCHMAKER, SYSTEM
- Statuses: Pending, Approved, Rejected, Overridden
- Conflict records immutable, never hard-deleted
- Conflict resolution per Authority Order
- Admin can override conflicts with audit trail

**3.2 Field Locking**

- Field locking prevents unauthorized overwrites
- Lock-before-write discipline enforced
- Post-human-edit lock (MODE 3) prevents OCR overwrite
- Admin can unlock fields with audit trail

**3.3 Lifecycle Management**

- Lifecycle states: Draft, Active, Search-Hidden, Suspended, Archived, Owner-Hidden
- Lifecycle state transitions validated
- Lifecycle blocks edit when Suspended/Archived
- Visibility dashboard explains why profile is hidden/restricted
- Explainability: clear reasons for blocked actions

**3.4 Field History & Audit**

- Profile version awareness: last approved version and current working version
- Read-only history for user
- Full audit visibility for admin
- Supports trust and error recovery
- Historical values preserved, never erased

**3.5 Biodata Intake System**

**3.5.1 RAW Storage:**
- Original biodata file stored
- Raw OCR-extracted text stored
- Upload timestamp recorded
- OCR mode recorded (MANUAL, OCR, etc.)
- Linked profile ID (nullable, reference only)
- Intake status: DRAFT, ATTACHED, ARCHIVED
- Never deleted (retention per policy)
- Used for re-verification and audit

**3.5.2 Sandbox (Read-Only):**
- Sandbox view shows full raw text or file reference
- Clear message: "This biodata has NOT modified the profile"
- No parsing in sandbox
- No profile update from sandbox
- No conflict creation from sandbox
- Admin-only access

**3.5.3 Manual Intake Create:**
- Admin can manually create biodata intake
- Paste raw text or upload file (TXT, PDF)
- Creates BiodataIntake record with:
  - raw_ocr_text (if pasted) or file_path (if uploaded)
  - uploaded_by (admin user ID)
  - ocr_mode = MANUAL
  - matrimony_profile_id = NULL
  - intake_status = DRAFT
- Read-only after creation
- NO OCR parsing
- NO profile update
- NO conflict creation

**3.5.4 Attach to Profile (Reference-Only):**
- Admin can manually attach intake to existing MatrimonyProfile
- Sets biodata_intakes.matrimony_profile_id
- Sets intake_status = ATTACHED
- This is ONLY a reference link
- NO data transfer
- NO field mapping
- NO overwrite
- NO profile data modification

**3.5.5 Intake Status:**
- DRAFT: Created but not linked to profile
- ATTACHED: Linked to profile (reference only)
- ARCHIVED: Admin-only archival
- Status clearly displayed in admin UI
- Status updated automatically: DRAFT on create, ATTACHED on attach

**3.6 OCR Governance**

- OCR parsing, intake handling, sandbox review, conflict detection, and zero-loss enforcement are FULLY IMPLEMENTED and COMPLETE
- OCR is Phase-4 FOUNDATION component and production-complete
- Phase-5 will NOT modify OCR logic or parsing engine

**Master Principles:**

**a) Zero-loss biodata:**
- If any biodata line is unmapped, profile creation or update blocked until mapped or explicitly discarded with audit
- Every extracted line maps to CORE field, EXTENDED field, or preserved RAW BIODATA record
- Manual typing for biodata-based profile creation should be zero

**b) Sandbox mandatory gate:**
- OCR sandbox is mandatory gate before any profile mutation
- Field-by-field preview before profile create or update
- Unmapped or low-confidence lines highlighted
- Profile creation/update requires explicit confirmation
- No blind OCR apply
- No silent overwrite

**c) Conflict-based apply only:**
- Three modes: MODE 1 (first profile creation), MODE 2 (existing profile + conflict records), MODE 3 (post-human-edit lock)
- Empty field auto-fill optional and gated by feature flag
- Existing value different creates Conflict Record
- OCR never silently overwrites

**Supported Input Formats:**
- Plain text (copy-paste)
- PDF (digital and scanned multi-page)
- Image (JPG/PNG with orientation correction where feasible)

**System Failure & Fail-Safe:**
- OCR failure never corrupts profile data
- Partial OCR success remains in sandbox
- System or intake errors cause NO data mutation
- Retry or discard explicit
- Failure never changes live profile state

**3.7 Admin Authority Model**

- Admin is supreme authority
- Admin can override data, resolve conflicts, fix profiles, restore previous values
- Every admin action audited
- Every override requires reason
- No admin action silent or irreversible without audit trail

**Role-Based Admin Access:**
- Super Admin: full authority
- Moderator: profile moderation, abuse handling
- Data Admin: OCR intake, conflicts, corrections
- Auditor: read-only access
- Authority Order intact
- Role separation does not dilute admin supremacy

**Fix-Profile Mode:**
- Admin-only mode to correct incomplete or legacy profiles
- Fill missing mandatory fields
- Override conflicts with audit trail
- Force-mark profile as complete
- Operate without developer intervention

**Operational Tools:**
- Admin search profiles by: completeness percentage, lifecycle state, OCR-created status, conflict pending status
- Dashboard counts: incomplete profiles, OCR conflicts pending resolution, suspended/hidden profiles, admin-overridden profiles

**3.8 Location & Identity Discipline**

- Location hierarchy NON-NEGOTIABLE: Country → State → District → Taluka → City/Town
- No free-text location entry
- Location selection hierarchical and enforced at UI level
- Lower-level selection disabled until parent selected
- Applies to User UI, Admin UI, and OCR mapping

**NRI Handling:**
- Country-first logic applies globally
- If Country ≠ India: State mandatory, District/Taluka optional, City/Town mandatory
- Search filters country-aware

**Alias & Admin Override:**
- Admin manages master lists: Country, State, District, Taluka, City
- Obsolete locations archived (not deleted)
- Alias mappings maintained
- Incorrect OCR/user mappings admin override with audit trail

**3.9 Women-First Safety Governance**

- Women control who can view profile: only verified users, only serious-intent profiles, only admin-approved profiles
- Woman's visibility preference respected by default
- Admin override of woman-level visibility controls allowed ONLY with audit and recorded reason
- Contact details visible only after interest acceptance or admin-defined unlock rules
- Contact visibility controlled by woman
- Report action may optionally auto-block reported profile
- Safety messaging explicit in UI

**3.10 Trust & Verification Signals (Informational Only)**

- Verification tags are trust signals, not matching logic
- Tags: Admin Reviewed, Matchmaker Verified, Parent Verified, Relative Verified, WhatsApp Verified, Aadhaar Verified (future-ready, optional), Phone Verified, Photo Verified, Document Verified (generic)
- Tags are informational labels only
- No ranking, scoring, or automatic trust weight
- Admin controls assignment, removal, and audit
- All verification actions audited

**Serious Intent Signaling:**
- Serious Intent is optional UI field
- Values: Immediately, Within 6 months, Within 1 year, Not decided
- User editable, visible on profile
- NOT used for search, ranking, scoring, matching, or filtering in Phase-4 or Phase-5
- Purely informational for human understanding

**3.11 Search & Discovery Fairness**

- No paid ranking or boosts
- No hidden priority flags
- Deterministic and neutral ordering
- Same filters produce predictable results
- Search fairness and neutrality rule applies
- Pre-AI fairness until Phase-5
- Phase-5 AI usage will not modify search fairness or neutrality rules

**3.12 Monetization Governance (Ads + Mini-Unlock)**

- Admin-controlled access matrix
- Admin ON/OFF controls: which fields always visible, visible after interest acceptance, unlockable via mini-payment, unlockable via ad-view
- Contact card preview in search results
- Partial profile visibility with locked fields
- No profile blur allowed
- Locked fields clearly show what is locked and why
- Mini-payment unlock: specific fields individually unlockable, time-bound access
- Ad-based unlock: ads as alternative to payment, explicit and time-bound
- Ads optional, never forced
- Same UX works with ads ON or OFF
- No subscription pressure
- "Pay only for what you need" principle
- Actual payments implemented in Phase-5

**3.13 Analytics, Privacy & Compliance**

- Aggregate analytics only
- No per-user behavioral profiling
- Ads/monetization systems cannot access biodata fields
- Personal biodata never used for ad targeting
- Admin-initiated profile export supported
- User self-service export not in Phase-4
- Data ownership: user owns profile data
- Profile deactivation: user can deactivate or hide profile
- Duplication prevention: heuristic-based, admin review required
- Data retention per admin-defined policy
- Conflict records and audit logs never deleted
- Raw biodata retained per admin-defined policy, never silently purged

============================================================
4. LINEAR BIODATA FLOW (CLEAR)
============================================================

**Simple End-to-End Flow:**

1. **Upload/Paste:** Admin or user uploads biodata file or pastes text
2. **Intake:** System creates BiodataIntake record (RAW storage)
   - Stores raw text or file path
   - Sets intake_status = DRAFT
   - NO parsing at this stage
   - NO profile update
   - NO conflict creation

3. **Sandbox:** Admin opens sandbox view (read-only)
   - Views full raw text or file reference
   - Sees clear message: "This biodata has NOT modified the profile"
   - NO parsing in sandbox
   - NO profile update from sandbox
   - NO conflict creation from sandbox

4. **Attach (Optional):** Admin can attach intake to existing profile
   - Sets biodata_intakes.matrimony_profile_id
   - Sets intake_status = ATTACHED
   - This is ONLY a reference link
   - NO data transfer
   - NO field mapping
   - NO overwrite
   - NO profile data modification

5. **NO APPLY:** Phase-4 does NOT include automatic or manual "apply" of intake to profile
   - No OCR parsing to profile fields
   - No field mapping
   - No conflict creation from intake
   - Intake remains RAW storage only

**Note:** OCR parsing, conflict detection, and profile mutation are Phase-4 foundation components but operate separately from the intake flow. The intake system is RAW storage and reference linking only.

============================================================
5. DAY-WISE EXECUTION MAP (HIGH-LEVEL)
============================================================

**Day-0: Foundation Locks**
- Establish SSOT authority
- Lock Phase-4 scope
- Define governance laws
- Set Phase-5 boundaries

**Day-1: Conflict & Lock Base**
- Implement conflict record system
- Implement field locking mechanism
- Implement lock-before-write discipline
- Establish conflict resolution per Authority Order

**Day-2: Biodata Intake Foundation**
- Create biodata_intakes table
- Implement RAW storage
- Implement manual intake create
- Implement sandbox view (read-only)
- Implement attach to profile (reference-only)
- Implement intake status (DRAFT, ATTACHED, ARCHIVED)

**Day-3+: Governance Completion**
- Complete lifecycle management
- Complete field history & audit
- Complete admin authority model
- Complete location & identity discipline
- Complete OCR governance (foundation complete)
- Complete women-first safety controls
- Complete trust & verification signals
- Complete search & discovery fairness
- Complete monetization governance (structure only, payments Phase-5)
- Complete analytics, privacy & compliance

**Note:** This is a logical execution order, not a calendar schedule. Days may overlap or be executed in parallel where dependencies allow.

============================================================
6. COMPLETION CHECKLIST (CONDENSED)
============================================================

Phase-4 is COMPLETE when all of the following conditions are met:

**Foundation:**
- [ ] SSOT v1.1 finalized and locked
- [ ] All governance laws implemented and enforced
- [ ] Authority Order (Admin > User > Matchmaker > OCR/System) enforced everywhere

**Conflict System:**
- [ ] Conflict records created for all overwrite attempts
- [ ] Conflict resolution per Authority Order working
- [ ] Conflict records immutable, never hard-deleted
- [ ] Admin can override conflicts with audit trail

**Field Locking:**
- [ ] Field locking prevents unauthorized overwrites
- [ ] Lock-before-write discipline enforced
- [ ] Post-human-edit lock (MODE 3) prevents OCR overwrite

**Lifecycle:**
- [ ] All lifecycle states implemented
- [ ] Lifecycle transitions validated
- [ ] Lifecycle blocks edit when Suspended/Archived
- [ ] Visibility dashboard explains blocked actions

**Field History & Audit:**
- [ ] Profile version awareness implemented
- [ ] Read-only history for user
- [ ] Full audit visibility for admin
- [ ] Historical values preserved

**Biodata Intake:**
- [ ] RAW storage implemented (biodata_intakes table)
- [ ] Manual intake create working (admin-only)
- [ ] Sandbox view working (read-only, clear message)
- [ ] Attach to profile working (reference-only, no data transfer)
- [ ] Intake status working (DRAFT, ATTACHED, ARCHIVED)
- [ ] NO OCR parsing from intake
- [ ] NO profile update from intake
- [ ] NO conflict creation from intake

**OCR Governance:**
- [ ] OCR parsing foundation complete (production-ready)
- [ ] Sandbox mandatory gate enforced
- [ ] Zero-loss biodata enforced
- [ ] Conflict-based apply only
- [ ] No silent overwrite

**Admin Authority:**
- [ ] Admin supremacy with audit trail
- [ ] Role-based admin access implemented
- [ ] Fix-profile mode working
- [ ] Operational tools available

**Location & Identity:**
- [ ] Location hierarchy enforced
- [ ] No free-text location entry
- [ ] NRI handling implemented
- [ ] Alias & admin override working

**Safety & Trust:**
- [ ] Women-first safety controls implemented
- [ ] Verification tags system working (informational only)
- [ ] Serious intent signaling implemented (informational only)

**Search & Discovery:**
- [ ] Neutral ordering implemented
- [ ] No paid ranking or boosts
- [ ] Deterministic results

**Monetization Structure:**
- [ ] Admin-controlled access matrix implemented
- [ ] Ads-ready UX architecture (ads optional)
- [ ] Mini-payment unlock structure (payments Phase-5)
- [ ] No profile blur
- [ ] User transparency implemented

**Privacy & Compliance:**
- [ ] Aggregate analytics only
- [ ] Ads privacy boundary enforced
- [ ] Data export supported (admin-initiated)
- [ ] Duplication prevention working
- [ ] Data retention policy enforced

**Testing & Verification:**
- [ ] Cursor-First testing completed
- [ ] SSOT compliance verified
- [ ] No silent overwrite verified
- [ ] No hard delete verified
- [ ] Zero-loss biodata verified

============================================================
7. DO / DON'T SUMMARY (DEVELOPER-FACING)
============================================================

**DO (Allowed in Phase-4):**

- Implement conflict records for all overwrite attempts
- Implement field locking and lock-before-write discipline
- Implement lifecycle management with validated transitions
- Implement field history and audit trail
- Implement RAW biodata storage (biodata_intakes)
- Implement manual intake create (admin-only)
- Implement sandbox view (read-only, no parsing)
- Implement attach intake to profile (reference-only, no data transfer)
- Implement intake status (DRAFT, ATTACHED, ARCHIVED)
- Enforce zero-loss biodata principle
- Enforce Authority Order (Admin > User > Matchmaker > OCR/System)
- Enforce no silent overwrite
- Enforce location hierarchy (Country → State → District → Taluka → City/Town)
- Implement admin authority with audit trail
- Implement women-first safety controls
- Implement verification tags (informational only)
- Implement search fairness (neutral ordering)
- Implement monetization structure (ads optional, payments Phase-5)
- Implement aggregate analytics only
- Preserve historical values
- Audit all admin actions
- Follow SSOT strictly

**DON'T (Strictly Forbidden in Phase-4):**

- NO automatic OCR apply from intake
- NO profile data modification from intake
- NO conflict creation from intake
- NO field mapping from intake
- NO data transfer when attaching intake to profile
- NO silent overwrite of existing profile data
- NO hard delete of profile/field data
- NO hard delete of conflict records
- NO hard delete of audit logs
- NO hard delete of raw biodata
- NO free-text location entry
- NO breaking v1 API changes
- NO Phase-1–3 behavior modifications
- NO User ≠ MatrimonyProfile violations
- NO new CORE fields without migration
- NO auto-migration of historical data
- NO AI matching or scoring
- NO WhatsApp automation
- NO payment execution (structure only)
- NO matchmaker network logic
- NO profile blur
- NO per-user behavioral profiling
- NO personal biodata for ad targeting
- NO paid ranking or boosts
- NO hidden priority flags
- NO non-deterministic ordering
- NO policy-less actions
- NO implicit side-effect creation
- NO business logic in Blade views
- NO deviation from SSOT without version increment

**CRITICAL RULE:**
If intake is attached to profile, it is ONLY a reference link. NO data transfer, NO field mapping, NO overwrite, NO profile data modification. The intake remains RAW storage only.

============================================================
8. FINAL DECLARATION
============================================================

**SSOT Supremacy:**
This file (PHASE-4_SSOT_v1.1.md) is the ONLY authoritative SSOT for Phase-4 going forward. All previous Phase-4 SSOT drafts, blueprints, and reference documents are superseded by this file.

**Reference Files:**
All archived SSOT files (PHASE-1, PHASE-2, PHASE-3) and blueprints are reference-only. They may inform understanding but must not override correctness. This SSOT takes precedence.

**Phase-4 Lock:**
Phase-4 discussion scope is fully locked. Phase-4 implementation must follow this SSOT strictly. No corrections or additions to Phase-4 scope are permitted after this SSOT is finalized.

**Phase-5 Boundary:**
Phase-5 scope is STRICTLY LIMITED to: a) AI usage on already-clean, governed data, b) WhatsApp communication layer, c) Payment execution. Phase-5 cannot bypass Phase-4 governance. Phase-5 will NOT modify OCR logic, conflict logic, field governance, lifecycle rules, or admin authority.

**Execution Discipline:**
This SSOT defines GOVERNANCE, not feature experiments. No silent overwrite, no hard delete, no free-text where structured data required. Any deviation requires SSOT version increment.

**Final Lock Statement:**
Phase-4 is the final foundation phase. This SSOT is the final authority. Phase-4 implementation must follow this SSOT exactly. Phase-5 must build on Phase-4 foundations without bypassing governance.

============================================================
9. DETAILED DAY-WISE EXECUTION & VERIFICATION PLAN
============================================================

This section provides the exact day-by-day VERIFICATION plan with mandatory testing and boundary attack gates. Phase-4 is a FOUNDATION + VERIFICATION phase, NOT a feature-building phase. Each day must achieve PASS status before proceeding to the next day.

**CRITICAL ASSUMPTION:**
All features described in SSOT Sections 1-8 are assumed to ALREADY EXIST. Days verify, break, and prove existing systems. If a feature does NOT exist, the day MUST FAIL and STOP.

------------------------------------------------
DAY 0 — SSOT AUTHORITY & SCOPE LOCK VERIFICATION
------------------------------------------------

Objective:
- Verify PHASE-4_SSOT_v1.1.md is the ONLY authoritative document
- Verify all archived SSOTs and blueprints are reference-only
- Verify Phase-4 scope boundaries are not violated
- Prove no Phase-5 features exist in codebase

Preconditions:
- PHASE-4_SSOT_v1.1.md exists in project root
- Archive folder exists: __DO_NOT_USE__REFERENCE_AND_ARCHIVE
- All production code directories exist (app/, routes/, database/, resources/)

Verification Scope (NO IMPLEMENTATION):
- Verify PHASE-4_SSOT_v1.1.md is the only Phase-4 SSOT in root
- Verify all archived SSOTs are in archive folder
- Verify all Phase-4 blueprints are in archive folder
- Verify no Phase-5 code exists (AI matching, WhatsApp, payment processing)
- Verify no automatic OCR apply exists
- Implements SSOT Section 1 (Purpose & Scope), Section 8 (Final Declaration)

Explicitly Forbidden Today:
- NO code logic changes
- NO feature implementation
- NO database migrations
- NO refactoring
- NO new files created

Human-like Test Scenarios (MANDATORY):
1. **SSOT Authority Verification:**
   - Route/Screen: Project root directory listing
   - Action: List all *.md files in root directory
   - Expected: Only PHASE-4_SSOT_v1.1.md present (no PHASE-4_SSOT.md, no PHASE-4_BLUEPRINT*.md)
   - DB Check: N/A (file system check)
   - Action: Check archive folder: __DO_NOT_USE__REFERENCE_AND_ARCHIVE
   - Expected: All older SSOTs and blueprints present in archive
   - DB Check: N/A (file system check)

2. **Scope Boundary Attack:**
   - Route/Screen: Codebase search (grep/IDE search)
   - Action: Search codebase for "Phase-5", "AI matching", "WhatsApp", "payment processing"
   - Expected: Zero Phase-5 implementation code found
   - DB Check: N/A (code search)
   - Action: Search for automatic OCR apply logic
   - Expected: No automatic apply found, only explicit admin actions
   - DB Check: N/A (code search)

3. **Documentation Consistency Verification:**
   - Route/Screen: Read PHASE-4_SSOT_v1.1.md Section 1
   - Action: Read "What Phase-4 Is NOT" section
   - Expected: Exclusion list matches codebase reality
   - DB Check: N/A (documentation check)
   - Action: Verify no conflicting documentation in root
   - Expected: Zero conflicts found
   - DB Check: N/A (file system check)

Boundary & SSOT Violation Tests:
1. **Violation Attack:** Search for AI matching code
   - System Response: Code review flags as Phase-5 scope if found
   - DB Tables Inspected: N/A (code search)
   - Fields Must Remain Unchanged: N/A
   - Audit Evidence: No AI matching code exists in Phase-4 codebase
   - Test Result: PASS if zero AI code found, FAIL if any exists → STOP

2. **Violation Attack:** Search for archived SSOT references in code
   - System Response: No code comments reference archived SSOTs
   - DB Tables Inspected: N/A (code search)
   - Fields Must Remain Unchanged: N/A
   - Audit Evidence: Only PHASE-4_SSOT_v1.1.md referenced in code comments
   - Test Result: PASS if only v1.1 referenced, FAIL if archived SSOTs referenced → STOP

Day Completion Criteria (HARD GATE):
- [ ] PHASE-4_SSOT_v1.1.md is the only Phase-4 SSOT in root directory (verified)
- [ ] All archived SSOTs and blueprints are in archive folder (verified)
- [ ] No Phase-5 features exist in Phase-4 codebase (verified)
- [ ] No conflicting documentation exists (verified)
- [ ] Zero Phase-5 code violations found

End-of-Day Output:
- PASS: SSOT authority verified, scope boundaries intact, zero Phase-5 violations
- FAIL: Phase-5 code found, scope violations exist, or documentation conflicts → STOP EXECUTION
- DB Tables Inspected: N/A (file system and code search only)
- Fields Must Remain Unchanged: N/A

============================================================
FOUNDATION VERIFICATION DAYS (DAY 1-6)
============================================================

These days verify that existing foundation systems (Conflict, Lock, Lifecycle, History, Intake) are implemented correctly and enforce SSOT governance laws. If any feature does NOT exist, the day MUST FAIL and STOP.

============================================================
BOUNDARY ATTACK DAYS (DAY 7-14)
============================================================

These days attack SSOT boundaries to prove governance rules are enforced. Days 8, 10, 11, 13, 14 are POLICY VERIFICATION ONLY - no table/service/UI creation, only prove rules are enforced/not violated. If any policy violation is possible, the day MUST FAIL and STOP.

**BOUNDARY DAYS CONSISTENCY RULE:**
For Location (Day 8), Safety (Day 10), Trust (Day 11), Search (Day 12), Monetization (Day 13), and Privacy (Day 14):
If the system does NOT exist, the verification goal is to prove that NO bypass, shortcut, or implicit behavior violates the SSOT rule.

------------------------------------------------
DAY 1 — CONFLICT SYSTEM VERIFICATION
------------------------------------------------

Objective:
- Verify conflict record system exists and tracks overwrite attempts
- Verify conflict resolution follows Authority Order
- Prove conflict records are immutable audit trail
- Attack silent overwrite attempts and prove they are blocked

Preconditions:
- MatrimonyProfile model exists
- Database connection working
- Admin authentication system exists
- **ASSUMPTION: Conflict system already exists (conflicts table, Conflict model, ConflictDetectionService, ConflictResolutionService)**

Verification Scope (NO IMPLEMENTATION):
- Verify conflicts table exists with required fields
- Verify Conflict model enforces immutability
- Verify ConflictDetectionService intercepts overwrite attempts
- Verify ConflictResolutionService enforces Authority Order
- Verify admin UI exists for viewing/resolving conflicts
- Attack silent overwrite and prove it fails
- Implements SSOT Section 2 (Law 5: No Silent Overwrite, Law 6: Conflict Immutability), Section 3.1 (Conflict System)

Explicitly Forbidden Today:
- NO table creation (assumes conflicts table exists)
- NO service creation (assumes services exist)
- NO UI creation (assumes UI exists)
- NO new features

Human-like Test Scenarios (MANDATORY):
1. **Conflict Creation Verification:**
   - Route/Screen: Admin profile edit form (e.g., /admin/profiles/{id}/edit)
   - Action: Admin attempts to update profile field that already has value
   - Expected: Conflict record created in conflicts table
   - DB Check: SELECT * FROM conflicts WHERE profile_id = {id} AND field_name = '{field}'
   - Fields Must Remain Unchanged: matrimony_profiles.{field} (original value unchanged)
   - Action: View conflict in admin UI (e.g., /admin/conflicts/{id})
   - Expected: Conflict shows field name, old value, new value, source, timestamp
   - DB Check: Verify conflicts table has record with status='Pending'

2. **Authority Order Attack:**
   - Route/Screen: Profile update endpoint (simulate OCR/user/admin update)
   - Action: Attempt OCR update to user-edited field
   - Expected: Conflict created, profile field unchanged
   - DB Check: conflicts table has new record, matrimony_profiles.{field} unchanged
   - Fields Must Remain Unchanged: matrimony_profiles.{field} (user value preserved)
   - Action: Attempt user update to admin-edited field
   - Expected: Conflict created, profile field unchanged
   - DB Check: conflicts table has new record, matrimony_profiles.{field} unchanged
   - Fields Must Remain Unchanged: matrimony_profiles.{field} (admin value preserved)
   - Action: Admin resolves conflict via /admin/conflicts/{id}/resolve with reason
   - Expected: Conflict status='Approved', resolution_reason filled, profile updated
   - DB Check: conflicts.status='Approved', conflicts.resolution_reason IS NOT NULL, audit_logs has record

3. **Conflict Immutability Attack:**
   - Route/Screen: Direct database/API attack
   - Action: Attempt DELETE FROM conflicts WHERE id = {id}
   - Expected: Deletion blocked (model guard or database constraint)
   - DB Check: conflicts record still exists
   - Fields Must Remain Unchanged: conflicts table (no records deleted)
   - Action: Attempt UPDATE conflicts SET status='Approved' WHERE id = {id}
   - Expected: Modification blocked (immutability guard)
   - DB Check: conflicts record unchanged
   - Fields Must Remain Unchanged: conflicts table (no records modified except via proper resolution flow)

Boundary & SSOT Violation Tests:
1. **Violation Attack:** Attempt silent overwrite without conflict record
   - System Response: ConflictDetectionService intercepts, creates conflict, blocks overwrite
   - DB Tables Inspected: conflicts, matrimony_profiles
   - Fields Must Remain Unchanged: matrimony_profiles.{field} (original value)
   - Audit Evidence: conflicts table has record, matrimony_profiles.{field} unchanged
   - Test Result: PASS if overwrite blocked, FAIL if silent overwrite succeeds → STOP

2. **Violation Attack:** Attempt to hard-delete conflict record
   - System Response: Model guard throws exception, deletion fails
   - DB Tables Inspected: conflicts
   - Fields Must Remain Unchanged: conflicts table (all records intact)
   - Audit Evidence: No DELETE queries succeed on conflicts table
   - Test Result: PASS if deletion blocked, FAIL if deletion succeeds → STOP

3. **Violation Attack:** Attempt conflict resolution without reason
   - System Response: Validation error, resolution rejected
   - DB Tables Inspected: conflicts
   - Fields Must Remain Unchanged: conflicts.resolution_reason (must be NULL if no reason provided)
   - Audit Evidence: No conflict resolved without resolution_reason field
   - Test Result: PASS if reason required, FAIL if resolution without reason succeeds → STOP

Day Completion Criteria (HARD GATE):
- [ ] Conflicts table exists with all required fields (verified)
- [ ] Conflict model enforces immutability (verified via attack)
- [ ] ConflictDetectionService detects overwrite attempts (verified)
- [ ] ConflictResolutionService enforces Authority Order (verified)
- [ ] Admin UI shows conflicts list (verified)
- [ ] Admin UI requires reason for resolution (verified)
- [ ] Silent overwrite attacks blocked (proven)
- [ ] Conflict deletion attacks blocked (proven)

End-of-Day Output:
- PASS: Conflict system verified, immutability proven, Authority Order enforced, attacks blocked
- FAIL: Conflict system missing, silent overwrite possible, conflicts deletable, or Authority Order violated → STOP EXECUTION
- DB Tables Inspected: conflicts, matrimony_profiles, audit_logs
- Fields Must Remain Unchanged: matrimony_profiles.{field} (during conflict creation), conflicts table (immutability)

------------------------------------------------
DAY 2 — FIELD LOCKING & LOCK-BEFORE-WRITE VERIFICATION
------------------------------------------------

Objective:
- Verify field locking mechanism exists and prevents unauthorized overwrites
- Verify lock-before-write discipline is enforced
- Verify post-human-edit lock (MODE 3) prevents OCR overwrite
- Attack lock bypass attempts and prove they fail

Preconditions:
- Conflict system verified (Day 1 PASSED)
- MatrimonyProfile model exists
- Field structure defined
- Admin authentication working
- **ASSUMPTION: Field locking system already exists (field_locks table, FieldLock model, FieldLockService)**

Verification Scope (NO IMPLEMENTATION):
- Verify field_locks table exists with required fields
- Verify FieldLockService enforces lock-before-write on all updates
- Verify MODE 3 lock automatically created on human edit
- Verify admin UI exists for viewing/unlocking fields
- Attack lock bypass and prove it fails
- Implements SSOT Section 2 (Law 7: Lock-Before-Write), Section 3.2 (Field Locking)

Explicitly Forbidden Today:
- NO table creation (assumes field_locks table exists)
- NO service creation (assumes FieldLockService exists)
- NO UI creation (assumes UI exists)
- NO new features

Human-like Test Scenarios (MANDATORY):
1. **Lock Creation Verification:**
   - Route/Screen: User profile edit form (e.g., /profile/edit)
   - Action: User edits profile field manually (e.g., name field)
   - Expected: Field automatically locked (MODE 3)
   - DB Check: SELECT * FROM field_locks WHERE profile_id = {id} AND field_name = 'name'
   - Fields Must Remain Unchanged: N/A (lock creation expected)
   - Expected DB State: field_locks has record with lock_mode='POST_HUMAN_EDIT', locked_by=user_id
   - Route/Screen: Admin field lock UI (e.g., /admin/profiles/{id}/lock)
   - Action: Admin manually locks field with reason
   - Expected: Field locked, lock_reason stored
   - DB Check: field_locks has record with lock_reason IS NOT NULL, locked_by=admin_id
   - Fields Must Remain Unchanged: matrimony_profiles.{field} (value unchanged, only lock added)

2. **Lock Enforcement Attack:**
   - Route/Screen: OCR update attempt (simulate OCR service call)
   - Action: OCR attempts to update locked field
   - Expected: Update blocked, conflict created (if different value), field unchanged
   - DB Check: conflicts table has record (if value different), matrimony_profiles.{field} unchanged
   - Fields Must Remain Unchanged: matrimony_profiles.{field} (locked value preserved)
   - Route/Screen: User profile edit form
   - Action: User attempts to update admin-locked field
   - Expected: Update blocked, error message shown
   - DB Check: matrimony_profiles.{field} unchanged
   - Fields Must Remain Unchanged: matrimony_profiles.{field} (admin-locked value preserved)
   - Route/Screen: Admin unlock UI (e.g., /admin/profiles/{id}/unlock)
   - Action: Admin unlocks field with reason
   - Expected: Field unlocked, unlock_reason stored, field can be updated
   - DB Check: field_locks record deleted or marked unlocked, audit_logs has unlock record

3. **Lock-Before-Write Attack:**
   - Route/Screen: Direct API/database attack
   - Action: Attempt profile update without checking locks (bypass FieldLockService)
   - Expected: Update fails, lock check enforced
   - DB Check: matrimony_profiles.{field} unchanged
   - Fields Must Remain Unchanged: matrimony_profiles.{field} (locked field unchanged)
   - Action: Update field that is not locked
   - Expected: Update succeeds, lock check passed
   - DB Check: matrimony_profiles.{field} updated, no field_locks record exists
   - Fields Must Remain Unchanged: N/A (update expected for unlocked field)

Boundary & SSOT Violation Tests:
1. **Violation Attack:** Attempt to update locked field without unlock
   - System Response: FieldLockService blocks update, returns error
   - DB Tables Inspected: field_locks, matrimony_profiles
   - Fields Must Remain Unchanged: matrimony_profiles.{field} (locked value)
   - Audit Evidence: No UPDATE queries succeed on locked fields
   - Test Result: PASS if update blocked, FAIL if locked field updated → STOP

2. **Violation Attack:** Attempt to unlock without admin authority
   - System Response: Unlock operation requires admin role, fails for non-admin
   - DB Tables Inspected: field_locks
   - Fields Must Remain Unchanged: field_locks table (no unlock without admin)
   - Audit Evidence: Only admin users can unlock fields
   - Test Result: PASS if non-admin unlock blocked, FAIL if non-admin can unlock → STOP

3. **Violation Attack:** Attempt to bypass lock check in update code
   - System Response: All update methods call FieldLockService, bypass impossible
   - DB Tables Inspected: matrimony_profiles, field_locks
   - Fields Must Remain Unchanged: matrimony_profiles.{field} (if locked)
   - Audit Evidence: No update code path skips lock check
   - Test Result: PASS if all paths check locks, FAIL if bypass exists → STOP

Day Completion Criteria (HARD GATE):
- [ ] Field locks table exists with all required fields (verified)
- [ ] FieldLockService enforces lock-before-write on all updates (verified)
- [ ] MODE 3 lock automatically created on human edit (verified)
- [ ] Admin UI shows locked fields (verified)
- [ ] Admin UI requires reason for unlock (verified)
- [ ] Lock bypass attacks blocked (proven)
- [ ] Updates without lock check blocked (proven)

End-of-Day Output:
- PASS: Field locking verified, lock-before-write proven, MODE 3 working, attacks blocked
- FAIL: Field locking missing, lock bypass possible, updates without lock check, or MODE 3 not working → STOP EXECUTION
- DB Tables Inspected: field_locks, matrimony_profiles, conflicts, audit_logs
- Fields Must Remain Unchanged: matrimony_profiles.{field} (when locked)

------------------------------------------------
DAY 3 — BIODATA INTAKE RAW STORAGE & MANUAL CREATE VERIFICATION
------------------------------------------------

Objective:
- Verify RAW biodata storage system exists
- Verify manual intake creation works (admin-only)
- Attack intake creation to prove it does NOT trigger parsing or profile updates
- Prove zero profile mutation from intake creation

Preconditions:
- Database connection working
- Admin authentication working
- File storage system configured
- MatrimonyProfile model exists
- **ASSUMPTION: Biodata intake system already exists (biodata_intakes table, BiodataIntake model, admin routes/UI)**

Verification Scope (NO IMPLEMENTATION):
- Verify biodata_intakes table exists with required fields
- Verify manual intake creation form exists and works
- Attack intake creation to prove no OCR parsing triggered
- Attack intake creation to prove no profile mutation
- Attack intake creation to prove no conflict creation
- Implements SSOT Section 3.5.1 (RAW Storage), Section 3.5.3 (Manual Intake Create), Section 4 (Linear Biodata Flow step 1-2)

Explicitly Forbidden Today:
- NO table creation (assumes biodata_intakes table exists)
- NO route creation (assumes routes exist)
- NO UI creation (assumes UI exists)
- NO new features

Human-like Test Scenarios (MANDATORY):
1. **Manual Intake Creation Verification:**
   - Route/Screen: GET /admin/biodata-intakes/create
   - Action: Admin navigates to intake creation form
   - Expected: Form shows text paste area and file upload option
   - DB Check: N/A (UI verification)
   - Route/Screen: POST /admin/biodata-intakes
   - Action: Admin pastes raw text "Name: John, Age: 30" and submits
   - Expected: BiodataIntake record created
   - DB Check: SELECT * FROM biodata_intakes WHERE id = LAST_INSERT_ID()
   - Fields Must Remain Unchanged: matrimony_profiles table (no new records, no updates)
   - Expected DB State: biodata_intakes.raw_ocr_text='Name: John, Age: 30', file_path=NULL, ocr_mode='MANUAL', intake_status='DRAFT', matrimony_profile_id=NULL
   - Action: Admin uploads PDF file and submits
   - Expected: File saved, BiodataIntake record created
   - DB Check: biodata_intakes.file_path IS NOT NULL, raw_ocr_text=NULL, ocr_mode='MANUAL', intake_status='DRAFT'
   - Fields Must Remain Unchanged: matrimony_profiles table (no mutation)

2. **No Profile Mutation Attack:**
   - Route/Screen: POST /admin/biodata-intakes (with biodata containing name, age, location)
   - Action: Create intake with biodata text "Name: Jane, Age: 25, Location: Pune"
   - Expected: No MatrimonyProfile created, no profile updated, no conflicts created
   - DB Check: SELECT COUNT(*) FROM matrimony_profiles WHERE created_at > '{intake_created_at}' (should be 0)
   - Fields Must Remain Unchanged: matrimony_profiles table (zero new records)
   - DB Check: SELECT COUNT(*) FROM conflicts WHERE created_at > '{intake_created_at}' (should be 0)
   - Fields Must Remain Unchanged: conflicts table (zero conflicts from intake)

3. **Intake Status Verification:**
   - Route/Screen: POST /admin/biodata-intakes
   - Action: Create new intake
   - Expected: intake_status = DRAFT
   - DB Check: SELECT intake_status FROM biodata_intakes WHERE id = {id} (should be 'DRAFT')
   - Fields Must Remain Unchanged: N/A (status verification)
   - Route/Screen: GET /admin/biodata-intakes (index)
   - Action: View intake in admin list
   - Expected: Status badge shows "DRAFT" with appropriate color
   - DB Check: Verify intake_status='DRAFT' in database

Boundary & SSOT Violation Tests:
1. **Violation Attack:** Attempt to trigger OCR parsing on intake creation
   - System Response: No OCR service called, no parsing occurs
   - DB Tables Inspected: biodata_intakes, matrimony_profiles, conflicts
   - Fields Must Remain Unchanged: matrimony_profiles table (no profile mutation), conflicts table (no conflicts)
   - Audit Evidence: No OCR parsing logs, no field extraction, intake remains RAW
   - Test Result: PASS if no parsing, FAIL if parsing triggered → STOP

2. **Violation Attack:** Attempt to create profile from intake automatically
   - System Response: No profile creation code executed
   - DB Tables Inspected: matrimony_profiles
   - Fields Must Remain Unchanged: matrimony_profiles table (zero new records)
   - Audit Evidence: No INSERT into matrimony_profiles table from intake creation
   - Test Result: PASS if no profile created, FAIL if profile auto-created → STOP

3. **Violation Attack:** Attempt to set intake_status to ATTACHED on create
   - System Response: intake_status forced to DRAFT regardless of input
   - DB Tables Inspected: biodata_intakes
   - Fields Must Remain Unchanged: biodata_intakes.intake_status (must be 'DRAFT')
   - Audit Evidence: All new intakes have status='DRAFT'
   - Test Result: PASS if status always DRAFT, FAIL if other status possible → STOP

Day Completion Criteria (HARD GATE):
- [ ] biodata_intakes table exists with all required fields (verified)
- [ ] Manual intake creation form working (verified)
- [ ] Intake creation sets intake_status=DRAFT, ocr_mode=MANUAL, matrimony_profile_id=NULL (verified)
- [ ] OCR parsing attacks blocked (proven)
- [ ] Profile mutation attacks blocked (proven)
- [ ] Conflict creation attacks blocked (proven)
- [ ] Admin-only access enforced (verified)

End-of-Day Output:
- PASS: RAW storage verified, manual create verified, zero profile mutation proven, attacks blocked
- FAIL: Intake system missing, parsing triggered, profile mutation occurs, or conflict creation → STOP EXECUTION
- DB Tables Inspected: biodata_intakes, matrimony_profiles, conflicts
- Fields Must Remain Unchanged: matrimony_profiles table (zero mutation), conflicts table (zero conflicts from intake)

------------------------------------------------
DAY 4 — BIODATA INTAKE SANDBOX & ATTACH VERIFICATION
------------------------------------------------

Objective:
- Verify read-only sandbox view exists for biodata intakes
- Verify attach intake to profile works (reference-only, no data transfer)
- Attack sandbox to prove it does NOT allow parsing or profile updates
- Attack attach to prove it does NOT transfer data or modify profile

Preconditions:
- Biodata intake RAW storage verified (Day 3 PASSED)
- MatrimonyProfile model exists
- Admin authentication working
- **ASSUMPTION: Sandbox and attach functionality already exists (routes, controller methods, UI)**

Verification Scope (NO IMPLEMENTATION):
- Verify sandbox view exists and displays raw text/file reference
- Verify sandbox shows "NOT modified profile" message
- Verify attach form exists for DRAFT intakes
- Attack sandbox to prove no parsing possible
- Attack attach to prove no profile mutation
- Attack attach to prove no data transfer
- Implements SSOT Section 3.5.2 (Sandbox), Section 3.5.4 (Attach to Profile), Section 3.5.5 (Intake Status), Section 4 (Linear Biodata Flow step 3-4)

Explicitly Forbidden Today:
- NO route creation (assumes routes exist)
- NO controller method creation (assumes methods exist)
- NO UI creation (assumes UI exists)
- NO new features

Human-like Test Scenarios (MANDATORY):
1. **Sandbox View Verification:**
   - Route/Screen: GET /admin/biodata-intakes/{intake}
   - Action: Admin opens sandbox for intake with raw text
   - Expected: Full raw text displayed, clear message "This biodata has NOT modified the profile" visible
   - DB Check: N/A (UI verification)
   - Route/Screen: GET /admin/biodata-intakes/{intake} (intake with file)
   - Action: Admin opens sandbox for intake with file
   - Expected: File reference/link displayed, clear message visible
   - DB Check: Verify biodata_intakes.file_path IS NOT NULL
   - Action: Attempt to edit or parse data in sandbox (UI attack)
   - Expected: No edit controls, no parse button, sandbox is read-only
   - DB Check: N/A (UI verification)

2. **Attach to Profile Attack:**
   - Route/Screen: GET /admin/biodata-intakes/{intake} (DRAFT intake)
   - Action: Admin selects profile from dropdown in sandbox
   - Expected: Dropdown shows list of existing profiles
   - DB Check: SELECT COUNT(*) FROM matrimony_profiles (verify profiles exist)
   - Route/Screen: PATCH /admin/biodata-intakes/{intake}/attach
   - Action: Admin submits attach form with matrimony_profile_id={profile_id}
   - Expected: Only intake.matrimony_profile_id and intake.intake_status updated
   - DB Check: SELECT matrimony_profile_id, intake_status FROM biodata_intakes WHERE id = {intake_id}
   - Fields Must Remain Unchanged: matrimony_profiles table (all fields unchanged)
   - Expected DB State: biodata_intakes.matrimony_profile_id={profile_id}, intake_status='ATTACHED'
   - Route/Screen: GET /admin/profiles/{profile_id}
   - Action: View profile after attach
   - Expected: Profile fields unchanged, no new data, no conflicts created
   - DB Check: SELECT * FROM matrimony_profiles WHERE id = {profile_id} (compare before/after attach)
   - Fields Must Remain Unchanged: matrimony_profiles.* (all fields unchanged)
   - DB Check: SELECT COUNT(*) FROM conflicts WHERE profile_id = {profile_id} AND created_at > '{attach_time}' (should be 0)

3. **Intake Status Display Verification:**
   - Route/Screen: GET /admin/biodata-intakes (index)
   - Action: View intake list in admin UI
   - Expected: Status column shows DRAFT/ATTACHED/ARCHIVED badges
   - DB Check: SELECT intake_status FROM biodata_intakes (verify status values)
   - Route/Screen: GET /admin/biodata-intakes/{intake}
   - Action: View intake detail
   - Expected: Status badge clearly visible with appropriate color
   - DB Check: Verify intake_status in database matches UI display
   - Action: Attach intake to profile
   - Expected: Status changes from DRAFT to ATTACHED in UI
   - DB Check: SELECT intake_status FROM biodata_intakes WHERE id = {intake_id} (should be 'ATTACHED')

Boundary & SSOT Violation Tests:
1. **Violation Attack:** Attempt to parse biodata in sandbox view
   - System Response: No parse button exists, no OCR service called
   - DB Tables Inspected: biodata_intakes, matrimony_profiles, conflicts
   - Fields Must Remain Unchanged: matrimony_profiles table (no mutation), conflicts table (no conflicts)
   - Audit Evidence: No parsing logs, no field extraction from sandbox
   - Test Result: PASS if no parsing possible, FAIL if parsing triggered → STOP

2. **Violation Attack:** Attempt to update profile when attaching intake
   - System Response: Only intake.matrimony_profile_id and intake.intake_status updated, profile table untouched
   - DB Tables Inspected: biodata_intakes, matrimony_profiles
   - Fields Must Remain Unchanged: matrimony_profiles.* (all fields unchanged)
   - Audit Evidence: No UPDATE queries on matrimony_profiles table from attach operation
   - Test Result: PASS if profile unchanged, FAIL if profile modified → STOP

3. **Violation Attack:** Attempt to attach intake and transfer data automatically
   - System Response: Attach only sets reference link, no data transfer code executed
   - DB Tables Inspected: biodata_intakes, matrimony_profiles
   - Fields Must Remain Unchanged: matrimony_profiles.* (no data transfer)
   - Audit Evidence: No field mapping, no data copying, intake remains RAW
   - Test Result: PASS if no data transfer, FAIL if data transferred → STOP

4. **Violation Attack:** Attempt to attach non-DRAFT intake
   - System Response: Attach form hidden for ATTACHED/ARCHIVED intakes, or validation error
   - DB Tables Inspected: biodata_intakes
   - Fields Must Remain Unchanged: biodata_intakes.matrimony_profile_id, intake_status (if non-DRAFT)
   - Audit Evidence: Only DRAFT intakes can be attached
   - Test Result: PASS if attach blocked for non-DRAFT, FAIL if attach allowed → STOP

Day Completion Criteria (HARD GATE):
- [ ] Sandbox view displays raw text or file reference (verified)
- [ ] Sandbox shows clear "NOT modified profile" message (verified)
- [ ] Sandbox is read-only (no parse, no edit) (verified)
- [ ] Attach form exists for DRAFT intakes (verified)
- [ ] Attach updates intake.matrimony_profile_id and intake.intake_status only (verified)
- [ ] Attach does NOT modify profile data (proven via attack)
- [ ] Parsing attacks blocked (proven)
- [ ] Data transfer attacks blocked (proven)

End-of-Day Output:
- PASS: Sandbox verified, attach verified, zero profile mutation proven, zero data transfer proven, attacks blocked
- FAIL: Sandbox missing, parsing possible in sandbox, profile modified on attach, or data transferred → STOP EXECUTION
- DB Tables Inspected: biodata_intakes, matrimony_profiles, conflicts
- Fields Must Remain Unchanged: matrimony_profiles.* (all fields unchanged during attach)

------------------------------------------------
DAY 5 — LIFECYCLE MANAGEMENT & VISIBILITY VERIFICATION
------------------------------------------------

Objective:
- Verify lifecycle state management is FULLY IMPLEMENTED with validated transitions
- Verify lifecycle edit blocks are FULLY IMPLEMENTED and work (Suspended/Archived)
- Verify visibility dashboard is FULLY IMPLEMENTED with explainability
- Attack invalid transitions and edit blocks to prove they fail

Preconditions:
- MatrimonyProfile model exists
- Lifecycle states defined (Draft, Active, Search-Hidden, Suspended, Archived, Owner-Hidden)
- Admin authentication working
- User authentication working
- **ASSUMPTION: Lifecycle system is FULLY IMPLEMENTED and exists (lifecycle_state column, LifecycleService, transition validation, edit blocks, visibility dashboard)**

Verification Scope (NO IMPLEMENTATION):
- Verify lifecycle_state column exists in matrimony_profiles table (FULLY IMPLEMENTED)
- Verify LifecycleService is FULLY IMPLEMENTED and enforces transition validation
- Verify edit block is FULLY IMPLEMENTED and enforced when Suspended/Archived
- Verify visibility dashboard is FULLY IMPLEMENTED and shows explainability messages
- Attack invalid transitions and prove they fail
- Attack edit attempts on Suspended/Archived and prove they fail
- Implements SSOT Section 2 (Law 8: Lifecycle Discipline), Section 3.3 (Lifecycle Management)

Explicitly Forbidden Today:
- NO table creation (assumes lifecycle_state column exists)
- NO service creation (assumes LifecycleService exists)
- NO UI creation (assumes UI exists)
- NO new features

Human-like Test Scenarios (MANDATORY):
1. **Lifecycle Transition Verification:**
   - Route/Screen: Admin lifecycle management UI (e.g., /admin/profiles/{id}/lifecycle)
   - Action: Admin changes profile from Active to Suspended
   - Expected: Transition succeeds, state updated
   - DB Check: SELECT lifecycle_state FROM matrimony_profiles WHERE id = {id} (should be 'Suspended')
   - Fields Must Remain Unchanged: matrimony_profiles.* (except lifecycle_state)
   - Route/Screen: Admin lifecycle management UI
   - Action: Attempt to change from Archived to Active directly (invalid transition attack)
   - Expected: Transition blocked, error message shown
   - DB Check: SELECT lifecycle_state FROM matrimony_profiles WHERE id = {id} (should remain 'Archived')
   - Fields Must Remain Unchanged: matrimony_profiles.lifecycle_state (invalid transition blocked)

2. **Edit Block Attack:**
   - Route/Screen: User profile edit form (e.g., /profile/edit)
   - Action: User attempts to edit profile in Suspended state
   - Expected: Edit form disabled or blocked, message "Profile is suspended, editing not allowed"
   - DB Check: SELECT lifecycle_state FROM matrimony_profiles WHERE id = {id} (should be 'Suspended')
   - Fields Must Remain Unchanged: matrimony_profiles.* (no updates from non-admin)
   - Action: User attempts to edit profile in Archived state
   - Expected: Edit form disabled or blocked, message "Profile is archived, editing not allowed"
   - DB Check: matrimony_profiles.* unchanged
   - Fields Must Remain Unchanged: matrimony_profiles.* (no updates from non-admin)

3. **Visibility Explainability Verification:**
   - Route/Screen: User dashboard (e.g., /dashboard)
   - Action: User views own profile in Search-Hidden state
   - Expected: Dashboard shows "Your profile is hidden from search results" with reason
   - DB Check: SELECT lifecycle_state FROM matrimony_profiles WHERE id = {id} (should be 'Search-Hidden')
   - Fields Must Remain Unchanged: N/A (read-only verification)

Boundary & SSOT Violation Tests:
1. **Violation Attack:** Attempt invalid lifecycle transition
   - System Response: LifecycleService validates transition, blocks invalid transitions
   - DB Tables Inspected: matrimony_profiles, audit_logs
   - Fields Must Remain Unchanged: matrimony_profiles.lifecycle_state (invalid transition blocked)
   - Audit Evidence: Invalid transition attempts logged, state unchanged
   - Test Result: PASS if invalid transitions blocked, FAIL if invalid transition succeeds → STOP

2. **Violation Attack:** Attempt to edit Suspended profile without admin authority
   - System Response: Edit operation checks lifecycle state, blocks non-admin edits
   - DB Tables Inspected: matrimony_profiles
   - Fields Must Remain Unchanged: matrimony_profiles.* (no updates from non-admin)
   - Audit Evidence: No UPDATE queries succeed on Suspended profiles from non-admin users
   - Test Result: PASS if edit blocked, FAIL if edit succeeds → STOP

3. **Violation Attack:** Attempt silent lifecycle change without audit
   - System Response: All lifecycle changes require reason and audit log
   - DB Tables Inspected: matrimony_profiles, audit_logs
   - Fields Must Remain Unchanged: N/A (audit verification)
   - Audit Evidence: Every lifecycle change has audit record
   - Test Result: PASS if all changes audited, FAIL if silent changes possible → STOP

Day Completion Criteria (HARD GATE):
- [ ] Lifecycle states exist (Draft, Active, Search-Hidden, Suspended, Archived, Owner-Hidden) (verified)
- [ ] Transition validation working (only allowed transitions) (verified)
- [ ] Edit block enforced when Suspended/Archived (verified via attack)
- [ ] Visibility dashboard shows lifecycle state (verified)
- [ ] Explainability messages clear and accurate (verified)
- [ ] Invalid transition attacks blocked (proven)
- [ ] Edit block attacks blocked (proven)

End-of-Day Output:
- PASS: Lifecycle management verified, transitions validated, edit blocks proven, explainability verified, attacks blocked
- FAIL: Lifecycle system missing, invalid transitions possible, edit blocks bypassed, or explainability missing → STOP EXECUTION
- DB Tables Inspected: matrimony_profiles, audit_logs
- Fields Must Remain Unchanged: matrimony_profiles.* (when Suspended/Archived and non-admin edit attempted)

------------------------------------------------
DAY 6 — FIELD HISTORY & AUDIT TRAIL VERIFICATION
------------------------------------------------

Objective:
- Verify profile version awareness exists (last approved vs current working)
- Verify read-only history exists for users
- Verify full audit visibility exists for admins
- Attack history deletion/modification to prove immutability
- Prove historical values are never erased

Preconditions:
- MatrimonyProfile model exists
- Conflict system verified (Day 1 PASSED)
- Admin authentication working
- **ASSUMPTION: Field history system already exists (field_history table, FieldHistory model, FieldHistoryService, version tracking, UI)**

Verification Scope (NO IMPLEMENTATION):
- Verify field_history table exists with required fields
- Verify FieldHistoryService records all field changes
- Verify version tracking works (approved vs working)
- Verify user/admin UI exists for viewing history
- Attack history deletion/modification and prove they fail
- Implements SSOT Section 2 (Law 9: Historical Integrity), Section 3.4 (Field History & Audit)

Explicitly Forbidden Today:
- NO table creation (assumes field_history table exists)
- NO service creation (assumes FieldHistoryService exists)
- NO UI creation (assumes UI exists)
- NO new features

Human-like Test Scenarios (MANDATORY):
1. **History Recording Verification:**
   - Route/Screen: User profile edit form (e.g., /profile/edit)
   - Action: User updates profile field (e.g., name field)
   - Expected: FieldHistory record created
   - DB Check: SELECT * FROM field_history WHERE profile_id = {id} AND field_name = 'name' ORDER BY changed_at DESC LIMIT 1
   - Fields Must Remain Unchanged: N/A (history creation expected)
   - Expected DB State: field_history has record with old_value, new_value, changed_by=user_id, changed_at timestamp
   - Route/Screen: Admin approval UI (e.g., /admin/profiles/{id}/approve)
   - Action: Admin approves change
   - Expected: FieldHistory record updated with approved_at, approved_by=admin_id
   - DB Check: SELECT approved_at, approved_by FROM field_history WHERE id = {history_id} (should be filled)
   - Route/Screen: User profile history view (e.g., /profile/history)
   - Action: View history for profile
   - Expected: All changes visible in chronological order
   - DB Check: SELECT COUNT(*) FROM field_history WHERE profile_id = {id} (verify all records visible)

2. **Version Awareness Verification:**
   - Route/Screen: User profile view (e.g., /profile)
   - Action: View profile with pending changes
   - Expected: UI shows "Last approved version" and "Current working version" clearly
   - DB Check: Compare field_history records with approved_at IS NULL vs approved_at IS NOT NULL
   - Fields Must Remain Unchanged: N/A (read-only verification)

3. **History Preservation Attack:**
   - Route/Screen: Direct database/API attack
   - Action: Attempt DELETE FROM field_history WHERE id = {id}
   - Expected: Deletion blocked (immutability guard)
   - DB Check: SELECT COUNT(*) FROM field_history WHERE id = {id} (should be 1, record still exists)
   - Fields Must Remain Unchanged: field_history table (no records deleted)
   - Action: Attempt UPDATE field_history SET old_value='modified' WHERE id = {id}
   - Expected: Modification blocked (immutability guard)
   - DB Check: SELECT old_value FROM field_history WHERE id = {id} (should be original value)
   - Fields Must Remain Unchanged: field_history.* (no modifications except approved_at/by)

Boundary & SSOT Violation Tests:
1. **Violation Attack:** Attempt to hard-delete history record
   - System Response: FieldHistory model blocks deletion, exception thrown
   - DB Tables Inspected: field_history
   - Fields Must Remain Unchanged: field_history table (all records intact)
   - Audit Evidence: No DELETE queries succeed on field_history table
   - Test Result: PASS if deletion blocked, FAIL if deletion succeeds → STOP

2. **Violation Attack:** Attempt to modify existing history record
   - System Response: FieldHistory model blocks update, exception thrown
   - DB Tables Inspected: field_history
   - Fields Must Remain Unchanged: field_history.* (except approved_at/by via proper approval flow)
   - Audit Evidence: No UPDATE queries succeed on field_history table (except approved_at/by)
   - Test Result: PASS if modification blocked, FAIL if modification succeeds → STOP

3. **Violation Attack:** Attempt to create profile change without history record
   - System Response: All field updates call FieldHistoryService, history always created
   - DB Tables Inspected: field_history, matrimony_profiles
   - Fields Must Remain Unchanged: N/A (history creation verification)
   - Audit Evidence: Every field change has corresponding history record
   - Test Result: PASS if all changes recorded, FAIL if changes without history → STOP

Day Completion Criteria (HARD GATE):
- [ ] Field history table exists with all required fields (verified)
- [ ] FieldHistoryService records all field changes (verified)
- [ ] Version tracking working (approved vs working) (verified)
- [ ] User UI shows read-only history (verified)
- [ ] Admin UI shows full audit trail (verified)
- [ ] History deletion attacks blocked (proven)
- [ ] History modification attacks blocked (proven)

End-of-Day Output:
- PASS: Field history verified, audit trail complete, historical integrity proven, attacks blocked
- FAIL: Field history missing, history deletable, changes without history, or historical values lost → STOP EXECUTION
- DB Tables Inspected: field_history, matrimony_profiles, audit_logs
- Fields Must Remain Unchanged: field_history table (immutability), historical values preserved

------------------------------------------------
DAY 7 — ADMIN AUTHORITY & OVERRIDE BOUNDARY ATTACK
------------------------------------------------

Objective:
- Verify admin supremacy exists with audit trail
- Verify role-based admin access works
- Attack admin actions to prove they never bypass core rules silently
- Prove admin override requires mandatory reason and audit

Preconditions:
- Admin authentication system exists
- Role system exists (Super Admin, Moderator, Data Admin, Auditor)
- Conflict system verified (Day 1 PASSED)
- Field locking verified (Day 2 PASSED)
- Lifecycle system verified (Day 5 PASSED)
- **ASSUMPTION: Admin authority system already exists (AdminService, override methods, role checks, fix-profile mode, operational tools)**

Verification Scope (NO IMPLEMENTATION):
- Verify AdminService exists and implements authority operations
- Verify admin override requires mandatory reason
- Verify role-based access enforced
- Attack admin actions to prove no silent bypass
- Attack admin override without reason and prove it fails
- Implements SSOT Section 2 (Law 4: Authority Order, Law 17: Admin Actions), Section 3.7 (Admin Authority Model)

Explicitly Forbidden Today:
- NO service creation (assumes AdminService exists)
- NO UI creation (assumes UI exists)
- NO new features
- NO table creation

Human-like Test Scenarios (MANDATORY):
1. **Admin Override Verification:**
   - Route/Screen: Admin conflict resolution UI (e.g., /admin/conflicts/{id}/resolve)
   - Action: Admin resolves conflict with reason "Data correction verified"
   - Expected: Conflict resolved, reason stored, audit log created
   - DB Check: SELECT resolution_reason FROM conflicts WHERE id = {id} (should be 'Data correction verified')
   - DB Check: SELECT COUNT(*) FROM audit_logs WHERE action = 'conflict_resolved' AND user_id = {admin_id} (should be > 0)
   - Fields Must Remain Unchanged: N/A (override expected)
   - Route/Screen: Admin field unlock UI (e.g., /admin/profiles/{id}/unlock)
   - Action: Admin unlocks field with reason "User requested unlock"
   - Expected: Field unlocked, reason stored, audit log created
   - DB Check: Verify field_locks record removed/updated, audit_logs has record

2. **Role-Based Access Attack:**
   - Route/Screen: Conflict resolution endpoint (as Moderator role)
   - Action: Moderator attempts to override conflict
   - Expected: Access granted if role allows, or denied with message
   - DB Check: Verify role permissions in database
   - Fields Must Remain Unchanged: conflicts table (if access denied)
   - Route/Screen: Field unlock endpoint (as Auditor role - read-only)
   - Action: Auditor attempts to override conflict
   - Expected: Access denied (read-only role)
   - DB Check: conflicts table unchanged
   - Fields Must Remain Unchanged: conflicts table, field_locks table (no changes from Auditor)

Boundary & SSOT Violation Tests:
1. **Violation Attack:** Attempt admin override without reason
   - System Response: Validation error, override rejected, reason required
   - DB Tables Inspected: conflicts, audit_logs
   - Fields Must Remain Unchanged: conflicts.resolution_reason (must be NULL if no reason provided)
   - Audit Evidence: No admin override succeeds without reason field
   - Test Result: PASS if reason required, FAIL if override without reason succeeds → STOP

2. **Violation Attack:** Attempt admin action without audit
   - System Response: All admin actions call audit service, audit always created
   - DB Tables Inspected: audit_logs
   - Fields Must Remain Unchanged: N/A (audit creation verification)
   - Audit Evidence: Every admin action has corresponding audit record
   - Test Result: PASS if all actions audited, FAIL if actions without audit → STOP

3. **Violation Attack:** Attempt admin action that silently bypasses conflict system
   - System Response: Admin override still creates conflict record (if applicable) or uses conflict resolution flow
   - DB Tables Inspected: conflicts, audit_logs
   - Fields Must Remain Unchanged: N/A (conflict tracking verification)
   - Audit Evidence: No admin action bypasses conflict system
   - Test Result: PASS if conflicts still tracked, FAIL if bypass exists → STOP

Day Completion Criteria (HARD GATE):
- [ ] AdminService exists and implements authority operations (verified)
- [ ] Admin override requires mandatory reason (verified via attack)
- [ ] Role-based access enforced (verified)
- [ ] Admin override without reason attacks blocked (proven)
- [ ] Admin actions without audit attacks blocked (proven)
- [ ] Silent bypass attacks blocked (proven)

End-of-Day Output:
- PASS: Admin authority verified, overrides audited, role access enforced, attacks blocked
- FAIL: Admin authority missing, admin actions without audit, overrides without reason, or silent bypass possible → STOP EXECUTION
- DB Tables Inspected: conflicts, field_locks, audit_logs, users (roles)
- Fields Must Remain Unchanged: conflicts.resolution_reason (if no reason provided), audit_logs (all actions must have records)

------------------------------------------------
DAY 8 — LOCATION HIERARCHY BOUNDARY ATTACK (POLICY VERIFICATION ONLY)
------------------------------------------------

Objective:
- Verify location hierarchy is enforced (Country → State → District → Taluka → City/Town)
- Attack free-text location entry and prove it fails
- Verify NRI handling works (Country-first logic)
- Prove location consistency across all entry points

Preconditions:
- Location reference tables exist (countries, states, districts, talukas, cities)
- MatrimonyProfile model exists
- Admin authentication working
- **ASSUMPTION: Location hierarchy system already exists (hierarchical UI, validation, NRI logic, admin management)**

Verification Scope (POLICY VERIFICATION ONLY - NO TABLE/SERVICE/UI CREATION):
- Verify location hierarchy enforced in database (parent-child relationships)
- Verify hierarchical location selection UI works (parent enables child)
- Verify location hierarchy enforced in profile creation/update
- Attack free-text location entry and prove it fails
- Verify NRI logic works (Country ≠ India: State mandatory, District/Taluka optional, City mandatory)
- **BOUNDARY RULE: If the system does NOT exist, the verification goal is to prove that NO bypass, shortcut, or implicit behavior violates the SSOT rule.**
- Implements SSOT Section 2 (Law 20: Reference System Freeze), Section 3.8 (Location & Identity Discipline)

Explicitly Forbidden Today:
- NO table creation (assumes location tables exist)
- NO service creation (assumes validation services exist)
- NO UI creation (assumes UI exists)
- NO new features

Human-like Test Scenarios (MANDATORY):
1. **Hierarchical Selection Verification:**
   - Route/Screen: User profile creation form (e.g., /profile/create)
   - Action: User selects Country=India
   - Expected: State dropdown enabled, District/Taluka/City disabled
   - DB Check: Verify countries table has India record
   - Fields Must Remain Unchanged: N/A (selection verification)
   - Action: User selects State=Maharashtra
   - Expected: District dropdown enabled, Taluka/City still disabled
   - DB Check: Verify states table has Maharashtra with country_id=India
   - Action: Complete hierarchy selection (District → Taluka → City)
   - Expected: All selections complete, profile can be saved
   - DB Check: Verify location hierarchy integrity in database

2. **Free-Text Prevention Attack:**
   - Route/Screen: Profile creation/update form (direct API attack)
   - Action: Attempt to POST location as free text "Pune, Maharashtra"
   - Expected: Validation error, free-text rejected
   - DB Check: SELECT * FROM matrimony_profiles WHERE location_text IS NOT NULL (should be 0 or NULL)
   - Fields Must Remain Unchanged: matrimony_profiles.* (no free-text location saved)
   - Action: Attempt to bypass dropdown validation via API
   - Expected: Validation error, only valid dropdown selections accepted
   - DB Check: Verify no invalid location_id values in matrimony_profiles

3. **NRI Handling Verification:**
   - Route/Screen: Profile creation form
   - Action: User selects Country=USA
   - Expected: State field mandatory, District/Taluka optional, City mandatory
   - DB Check: Verify NRI validation logic
   - Action: User selects State=California, City=San Francisco (no District/Taluka)
   - Expected: Profile creation succeeds (District/Taluka optional for NRI)
   - DB Check: Verify profile saved with NRI location structure

Boundary & SSOT Violation Tests:
1. **Violation Attack:** Attempt free-text location entry
   - System Response: No free-text field in UI, validation rejects free-text input
   - DB Tables Inspected: matrimony_profiles, countries, states, districts, talukas, cities
   - Fields Must Remain Unchanged: matrimony_profiles.* (no free-text location values)
   - Audit Evidence: No free-text location values in database
   - Test Result: PASS if free-text blocked, FAIL if free-text accepted → STOP

2. **Violation Attack:** Attempt to select child location without parent
   - System Response: Child dropdowns disabled until parent selected, validation blocks orphan selections
   - DB Tables Inspected: matrimony_profiles, location reference tables
   - Fields Must Remain Unchanged: matrimony_profiles.* (no orphan location selections)
   - Audit Evidence: No location records with missing parent relationships
   - Test Result: PASS if hierarchy enforced, FAIL if orphan selections possible → STOP

Day Completion Criteria (HARD GATE):
- [ ] Location hierarchy enforced in all entry points (verified)
- [ ] No free-text location fields exist (verified via attack)
- [ ] Hierarchical selection UI working (verified)
- [ ] NRI handling works (verified)
- [ ] Free-text attacks blocked (proven)
- [ ] Hierarchy violation attacks blocked (proven)

End-of-Day Output:
- PASS: Location hierarchy verified, free-text attacks blocked, NRI handling verified, attacks blocked
- FAIL: Location system missing, free-text possible, hierarchy broken, or NRI handling incorrect → STOP EXECUTION
- DB Tables Inspected: matrimony_profiles, countries, states, districts, talukas, cities
- Fields Must Remain Unchanged: matrimony_profiles.* (no free-text location values, no orphan selections)

------------------------------------------------
DAY 9 — OCR GOVERNANCE BOUNDARIES & ZERO-LOSS ENFORCEMENT
------------------------------------------------

Objective:
- Verify OCR parsing foundation is production-complete
- Verify sandbox mandatory gate is enforced
- Verify zero-loss biodata principle is enforced
- Verify conflict-based apply only (no silent overwrite)
- Ensure OCR never corrupts profile data

Preconditions:
- OCR parsing system exists (foundation complete per SSOT)
- Conflict system exists (Day 1)
- Field locking exists (Day 2)
- Sandbox system exists (Day 4)
- Biodata intake system exists (Day 3-4)

Exact Implementation Scope:
- Verify OCR parsing logic exists and is production-ready
- Verify sandbox is mandatory gate before profile mutation
- Verify zero-loss enforcement (unmapped lines block profile update)
- Verify conflict creation for existing value differences
- Verify MODE 1 (first profile creation) logic
- Verify MODE 2 (existing profile + conflict records) logic
- Verify MODE 3 (post-human-edit lock) logic
- Verify empty field auto-fill is gated by feature flag
- Verify OCR failure never corrupts profile
- Implements SSOT Section 3.6 (OCR Governance), Section 2 (Law 3: Zero-Loss Biodata, Law 5: No Silent Overwrite)

Explicitly Forbidden Today:
- NO modifications to OCR parsing logic (foundation complete)
- NO removal of sandbox gate
- NO silent overwrite from OCR
- NO profile mutation without conflict record (for existing values)

Human-like Test Scenarios (MANDATORY):
1. **Sandbox Mandatory Gate Attack:**
   - Route/Screen: OCR processing endpoint (attempt bypass attack)
   - Action: Attempt to bypass sandbox and apply OCR directly
   - Expected: Apply blocked, sandbox must be reviewed first
   - DB Check: SELECT COUNT(*) FROM matrimony_profiles WHERE created_at > '{ocr_time}' (should be 0 if bypass attempted)
   - Fields Must Remain Unchanged: matrimony_profiles table (no mutation without sandbox)
   - Action: Review sandbox, confirm apply
   - Expected: Profile mutation proceeds, conflicts created if needed
   - DB Check: Verify conflicts table has records if value differences exist

2. **Zero-Loss Biodata Attack:**
   - Route/Screen: OCR sandbox with unmapped lines
   - Action: OCR extracts biodata with unmapped lines, attempt to apply
   - Expected: Profile update blocked, unmapped lines highlighted in sandbox
   - DB Check: Verify biodata_intakes.raw_ocr_text contains all lines (no loss)
   - Fields Must Remain Unchanged: matrimony_profiles table (update blocked)
   - Action: Map unmapped lines or mark as discard
   - Expected: Profile update can proceed after mapping/discard
   - DB Check: Verify all lines accounted for (mapped or preserved in RAW)

3. **Conflict-Based Apply Attack:**
   - Route/Screen: OCR apply attempt
   - Action: OCR proposes value for field that already has value
   - Expected: Conflict record created, profile value unchanged
   - DB Check: SELECT * FROM conflicts WHERE profile_id = {id} AND field_name = '{field}' (conflict exists)
   - DB Check: SELECT {field} FROM matrimony_profiles WHERE id = {id} (original value unchanged)
   - Fields Must Remain Unchanged: matrimony_profiles.{field} (original value preserved)

Boundary & SSOT Violation Tests:
1. **Violation Attack:** Attempt silent overwrite from OCR
   - System Response: ConflictDetectionService intercepts, creates conflict, blocks overwrite
   - DB Tables Inspected: conflicts, matrimony_profiles
   - Fields Must Remain Unchanged: matrimony_profiles.{field} (original value)
   - Audit Evidence: Conflict record exists, profile value unchanged, no silent overwrite
   - Test Result: PASS if overwrite blocked, FAIL if silent overwrite succeeds → STOP

2. **Violation Attack:** Attempt to bypass sandbox gate
   - System Response: All OCR apply operations require sandbox review, bypass blocked
   - DB Tables Inspected: matrimony_profiles, biodata_intakes
   - Fields Must Remain Unchanged: matrimony_profiles table (no mutation without sandbox)
   - Audit Evidence: No profile mutations without sandbox review
   - Test Result: PASS if sandbox mandatory, FAIL if bypass possible → STOP

3. **Violation Attack:** Attempt to lose unmapped biodata lines
   - System Response: Zero-loss enforcement blocks profile update, unmapped lines preserved
   - DB Tables Inspected: biodata_intakes, matrimony_profiles
   - Fields Must Remain Unchanged: biodata_intakes.raw_ocr_text (all lines preserved)
   - Audit Evidence: All extracted lines accounted for (mapped or preserved in RAW)
   - Test Result: PASS if zero-loss enforced, FAIL if lines lost → STOP

Day Completion Criteria (HARD GATE):
- [ ] OCR parsing foundation verified as production-complete (verified)
- [ ] Sandbox mandatory gate enforced (verified via attack)
- [ ] Zero-loss biodata enforced (verified via attack)
- [ ] Conflict-based apply working (verified via attack)
- [ ] Silent overwrite attacks blocked (proven)
- [ ] Sandbox bypass attacks blocked (proven)
- [ ] Zero-loss attacks blocked (proven)

End-of-Day Output:
- PASS: OCR governance verified, zero-loss proven, sandbox gate proven, attacks blocked
- FAIL: OCR system missing, silent overwrite possible, sandbox bypass possible, or zero-loss not enforced → STOP EXECUTION
- DB Tables Inspected: conflicts, matrimony_profiles, biodata_intakes, field_locks
- Fields Must Remain Unchanged: matrimony_profiles.{field} (when conflict exists), biodata_intakes.raw_ocr_text (zero-loss)

------------------------------------------------
DAY 10 — WOMEN-FIRST SAFETY BOUNDARY ATTACK (POLICY VERIFICATION ONLY)
------------------------------------------------

Objective:
- Verify women's visibility control policy is enforced (who can view profile)
- Verify contact details visibility control policy is enforced (woman controls)
- Attack visibility controls to prove unauthorized access is blocked
- Prove admin override requires audit and reason

Preconditions:
- MatrimonyProfile model exists (with gender field)
- User authentication working
- Interest/Shortlist system exists
- Admin authentication working
- **ASSUMPTION: Safety governance policies already exist (visibility settings, contact control, report system)**

Verification Scope (POLICY VERIFICATION ONLY - NO TABLE/SERVICE/UI CREATION):
- Verify visibility preference settings work (verified users only, serious-intent only, admin-approved only)
- Verify contact visibility control works (visible after interest acceptance)
- Attack visibility controls to prove unauthorized access blocked
- Verify admin override requires mandatory reason and audit
- **BOUNDARY RULE: If the system does NOT exist, the verification goal is to prove that NO bypass, shortcut, or implicit behavior violates the SSOT rule.**
- Implements SSOT Section 3.9 (Women-First Safety Governance)

Explicitly Forbidden Today:
- NO table creation (assumes visibility/contact control tables exist)
- NO service creation (assumes services exist)
- NO UI creation (assumes UI exists)
- NO new features

Human-like Test Scenarios (MANDATORY):
1. **Visibility Control Policy Attack:**
   - Route/Screen: Profile view (as unverified user)
   - Action: Unverified user attempts to view woman's profile with "Verified users only" setting
   - Expected: Access blocked, limited info shown
   - DB Check: Verify visibility preference in database
   - Fields Must Remain Unchanged: N/A (access control verification)
   - Route/Screen: Profile view (as non-serious-intent user)
   - Action: User without serious intent attempts to view profile with "Serious intent only" setting
   - Expected: Access blocked, limited info shown
   - DB Check: Verify serious intent requirement enforced

2. **Contact Visibility Policy Attack:**
   - Route/Screen: Profile view (before interest acceptance)
   - Action: User views woman's profile before sending interest
   - Expected: Contact details hidden
   - DB Check: Verify contact visibility policy in database
   - Fields Must Remain Unchanged: N/A (visibility verification)
   - Route/Screen: Profile view (after interest rejection)
   - Action: User views profile after interest rejected
   - Expected: Contact details remain hidden
   - DB Check: Verify contact visibility unchanged

3. **Admin Override Policy Attack:**
   - Route/Screen: Admin override UI (attempt without reason)
   - Action: Admin attempts to override woman's visibility preference without reason
   - Expected: Override rejected, reason required
   - DB Check: SELECT * FROM audit_logs WHERE action = 'visibility_override' AND reason IS NULL (should be 0)
   - Fields Must Remain Unchanged: matrimony_profiles.visibility_preference (if no reason provided)

Boundary & SSOT Violation Tests:
1. **Violation Attack:** Attempt to view woman's profile without meeting visibility criteria
   - System Response: Visibility check blocks access, limited info shown
   - DB Tables Inspected: matrimony_profiles, users (verification status)
   - Fields Must Remain Unchanged: N/A (access control verification)
   - Audit Evidence: Unauthorized view attempts logged, full profile not shown
   - Test Result: PASS if visibility enforced, FAIL if bypass possible → STOP

2. **Violation Attack:** Attempt to view contact details without woman's permission
   - System Response: Contact visibility check blocks access, contact details hidden
   - DB Tables Inspected: matrimony_profiles, interests
   - Fields Must Remain Unchanged: N/A (visibility verification)
   - Audit Evidence: Unauthorized contact view attempts logged, contact not shown
   - Test Result: PASS if contact control enforced, FAIL if contact visible without permission → STOP

3. **Violation Attack:** Attempt admin override without reason
   - System Response: Validation error, override rejected, reason required
   - DB Tables Inspected: audit_logs, matrimony_profiles
   - Fields Must Remain Unchanged: matrimony_profiles.visibility_preference (if no reason)
   - Audit Evidence: No admin override succeeds without reason field
   - Test Result: PASS if reason required, FAIL if override without reason succeeds → STOP

Day Completion Criteria (HARD GATE):
- [ ] Visibility preference settings work (verified)
- [ ] Woman's visibility preference enforced (verified via attack)
- [ ] Contact visibility controlled by woman (verified)
- [ ] Visibility bypass attacks blocked (proven)
- [ ] Contact visibility attacks blocked (proven)
- [ ] Admin override without reason attacks blocked (proven)

End-of-Day Output:
- PASS: Safety governance policies verified, visibility enforced, contact control verified, attacks blocked
- FAIL: Safety policies missing, visibility bypass possible, contact visible without permission, or admin override without reason → STOP EXECUTION
- DB Tables Inspected: matrimony_profiles, interests, audit_logs, users
- Fields Must Remain Unchanged: matrimony_profiles.visibility_preference (if no reason provided)

------------------------------------------------
DAY 11 — TRUST & VERIFICATION POLICY ATTACK (POLICY VERIFICATION ONLY)
------------------------------------------------

Objective:
- Verify verification tags policy is enforced (informational only, no ranking/scoring)
- Verify serious intent policy is enforced (informational only)
- Attack search/ranking to prove tags are NOT used
- Prove tags are trust signals, not matching logic

Preconditions:
- MatrimonyProfile model exists
- Admin authentication working
- **ASSUMPTION: Verification tags system already exists (tags table/model, admin UI, serious intent field)**

Verification Scope (POLICY VERIFICATION ONLY - NO TABLE/SERVICE/UI CREATION):
- Verify tags exist and are displayed (informational badges)
- Verify serious intent field exists and is displayed
- Attack search/ranking to prove tags NOT used
- Attack filtering to prove tags NOT used
- Verify admin controls tag assignment/removal
- **BOUNDARY RULE: If the system does NOT exist, the verification goal is to prove that NO bypass, shortcut, or implicit behavior violates the SSOT rule.**
- Implements SSOT Section 3.10 (Trust & Verification Signals)

Explicitly Forbidden Today:
- NO table creation (assumes verification_tags table exists)
- NO service creation (assumes services exist)
- NO UI creation (assumes UI exists)
- NO new features

Human-like Test Scenarios (MANDATORY):
1. **Tag Policy Verification:**
   - Route/Screen: Profile view
   - Action: View profile with multiple verification tags
   - Expected: All tags displayed as informational badges, no ranking implied
   - DB Check: SELECT * FROM verification_tags WHERE profile_id = {id} (verify tags exist)
   - Fields Must Remain Unchanged: N/A (display verification)

2. **Search Ranking Policy Attack:**
   - Route/Screen: Search results (with profiles having different tag counts)
   - Action: Search profiles, compare order of profiles with different tag counts
   - Expected: No automatic sorting by tag count, tags do not affect ranking
   - DB Check: Verify search query does not ORDER BY tag count or tag type
   - Fields Must Remain Unchanged: N/A (ranking verification)
   - Route/Screen: Search results
   - Action: Search with filters, verify serious intent NOT in filter options
   - Expected: Serious intent NOT used in search filters
   - DB Check: Verify search query does not filter by serious_intent field

Boundary & SSOT Violation Tests:
1. **Violation Attack:** Attempt to use verification tags for ranking
   - System Response: Search/ranking logic does not reference tags, ranking unaffected by tags
   - DB Tables Inspected: verification_tags, search queries
   - Fields Must Remain Unchanged: N/A (policy verification)
   - Audit Evidence: No ranking code uses tag data, tags purely informational
   - Test Result: PASS if tags not used for ranking, FAIL if tags affect ranking → STOP

2. **Violation Attack:** Attempt to use serious intent for search filtering
   - System Response: Search filters do not include serious intent, filtering unaffected
   - DB Tables Inspected: matrimony_profiles, search queries
   - Fields Must Remain Unchanged: N/A (policy verification)
   - Audit Evidence: No search code uses serious intent field
   - Test Result: PASS if serious intent not used, FAIL if used in search → STOP

Day Completion Criteria (HARD GATE):
- [ ] Verification tags exist and are displayed (verified)
- [ ] Serious intent field exists and is displayed (verified)
- [ ] Tags NOT used in search/ranking (verified via attack)
- [ ] Serious intent NOT used in search/ranking (verified via attack)
- [ ] Tag ranking attacks blocked (proven)
- [ ] Serious intent filtering attacks blocked (proven)

End-of-Day Output:
- PASS: Trust & verification policies verified, tags informational only, attacks blocked
- FAIL: Tags used for ranking, serious intent used in search, or policy violations → STOP EXECUTION
- DB Tables Inspected: verification_tags, matrimony_profiles, search queries
- Fields Must Remain Unchanged: N/A (policy verification only)

------------------------------------------------
DAY 12 — SEARCH FAIRNESS POLICY ATTACK (POLICY VERIFICATION ONLY)
------------------------------------------------

Objective:
- Verify search fairness policy is enforced (neutral, deterministic ordering)
- Attack search to prove no paid ranking or boosts exist
- Prove same filters produce predictable results
- Verify pre-AI fairness maintained

Preconditions:
- Search functionality exists
- MatrimonyProfile model exists
- Filter system exists
- Admin authentication working
- **ASSUMPTION: Search system already exists with fairness policies**

Verification Scope (POLICY VERIFICATION ONLY - NO TABLE/SERVICE/UI CREATION):
- Verify search ordering is deterministic (no randomness)
- Attack search to prove no paid ranking logic exists
- Attack search to prove no boost/priority flags exist
- Verify same filters produce same results (predictable)
- **BOUNDARY RULE: If the system does NOT exist, the verification goal is to prove that NO bypass, shortcut, or implicit behavior violates the SSOT rule.**
- Implements SSOT Section 3.11 (Search & Discovery Fairness)

Explicitly Forbidden Today:
- NO table creation (assumes search tables exist)
- NO service creation (assumes search services exist)
- NO new features

Human-like Test Scenarios (MANDATORY):
1. **Deterministic Ordering Policy Attack:**
   - Route/Screen: Search results (e.g., /search?age_min=25&age_max=30&location=Pune&gender=Male)
   - Action: Search profiles with same filters multiple times
   - Expected: Results in same order every time (deterministic)
   - DB Check: Verify search query uses deterministic ORDER BY (e.g., ORDER BY id, not RAND())
   - Fields Must Remain Unchanged: N/A (ordering verification)
   - Action: Search profiles, note order, search again
   - Expected: Exact same order, no randomness
   - DB Check: Verify no RAND() or random functions in search queries

2. **Paid Ranking Policy Attack:**
   - Route/Screen: Search results
   - Action: Search profiles (no payment involved)
   - Expected: Results ordered neutrally, no premium profiles at top
   - DB Check: Verify search query does not ORDER BY payment_status or premium_flag
   - Fields Must Remain Unchanged: N/A (ranking verification)
   - Action: Check for paid ranking code (code search)
   - Expected: No paid ranking logic exists in codebase
   - DB Check: Verify no payment-based ranking fields in database

3. **Boost Policy Attack:**
   - Route/Screen: Search results
   - Action: Search profiles, check for boosted results
   - Expected: No profiles artificially boosted, all treated equally
   - DB Check: Verify no boost/priority fields in matrimony_profiles table
   - Fields Must Remain Unchanged: N/A (boost verification)

Boundary & SSOT Violation Tests:
1. **Violation Attack:** Search for paid ranking code/logic
   - System Response: Code review flags as SSOT violation if found, no paid ranking code exists
   - DB Tables Inspected: matrimony_profiles, search queries
   - Fields Must Remain Unchanged: N/A (policy verification)
   - Audit Evidence: No payment-based ranking logic in codebase
   - Test Result: PASS if no paid ranking, FAIL if paid ranking exists → STOP

2. **Violation Attack:** Attempt to boost profile in search results
   - System Response: No boost mechanism exists, boost attempt fails
   - DB Tables Inspected: matrimony_profiles
   - Fields Must Remain Unchanged: N/A (boost verification)
   - Audit Evidence: No boost/priority fields or logic in codebase
   - Test Result: PASS if boost impossible, FAIL if boost possible → STOP

3. **Violation Attack:** Attempt non-deterministic ordering
   - System Response: Search always uses deterministic ordering (e.g., ORDER BY id), no randomness
   - DB Tables Inspected: Search queries
   - Fields Must Remain Unchanged: N/A (ordering verification)
   - Audit Evidence: No RAND() or random ordering in search queries
   - Test Result: PASS if ordering deterministic, FAIL if ordering random → STOP

Day Completion Criteria (HARD GATE):
- [ ] Search ordering is deterministic (verified via attack)
- [ ] No paid ranking logic exists (verified via attack)
- [ ] No boost/priority flags exist (verified via attack)
- [ ] Same filters produce same results (verified)
- [ ] Paid ranking attacks blocked (proven)
- [ ] Boost attacks blocked (proven)
- [ ] Non-deterministic ordering attacks blocked (proven)

End-of-Day Output:
- PASS: Search fairness policies verified, neutral ordering proven, attacks blocked
- FAIL: Paid ranking exists, boost possible, or non-deterministic ordering → STOP EXECUTION
- DB Tables Inspected: matrimony_profiles, search queries
- Fields Must Remain Unchanged: N/A (policy verification only)

------------------------------------------------
DAY 13 — MONETIZATION POLICY ATTACK (POLICY VERIFICATION ONLY)
------------------------------------------------

Objective:
- Verify monetization policies are enforced (no profile blur, ads optional, no forced ads)
- Attack monetization to prove no actual payments exist (structure only)
- Prove user transparency is maintained
- Verify Phase-5 boundary (payments Phase-5, ads Phase-5)

Preconditions:
- MatrimonyProfile model exists
- Field structure defined
- Admin authentication working
- Interest/Shortlist system exists
- **ASSUMPTION: Monetization structure already exists (access matrix, unlock structure, ads architecture)**

Verification Scope (POLICY VERIFICATION ONLY - NO TABLE/SERVICE/UI CREATION):
- Verify access matrix policies work (field visibility rules)
- Attack monetization to prove no profile blur exists
- Attack monetization to prove ads are optional, never forced
- Attack monetization to prove no actual payment processing exists (structure only)
- **BOUNDARY RULE: If the system does NOT exist, the verification goal is to prove that NO bypass, shortcut, or implicit behavior violates the SSOT rule.**
- Implements SSOT Section 3.12 (Monetization Governance)

Explicitly Forbidden Today:
- NO table creation (assumes access_matrix table exists)
- NO service creation (assumes services exist)
- NO UI creation (assumes UI exists)
- NO new features

Human-like Test Scenarios (MANDATORY):
1. **Profile Blur Policy Attack:**
   - Route/Screen: Profile view with locked fields
   - Action: User views profile with locked fields
   - Expected: Locked fields clearly marked "Locked", reason shown, NO blur
   - DB Check: Verify no blur/obfuscation logic in codebase
   - Fields Must Remain Unchanged: N/A (display verification)
   - Action: Search codebase for "blur", "obfuscate", "hide" functions
   - Expected: No blur logic found, only clear unlock messages

2. **Ads Optional Policy Attack:**
   - Route/Screen: Profile unlock flow
   - Action: User attempts to unlock field, ads disabled in config
   - Expected: UX works normally, no ad placeholders, no forced ad views
   - DB Check: Verify ads configuration (ads_enabled flag)
   - Fields Must Remain Unchanged: N/A (policy verification)
   - Action: User attempts to unlock field, ads enabled in config
   - Expected: Ad placeholders exist, but ads optional (user can choose payment instead)
   - DB Check: Verify ads are optional, not forced

3. **Payment Processing Policy Attack:**
   - Route/Screen: Payment unlock attempt (structure only)
   - Action: User attempts to unlock field via payment
   - Expected: Unlock structure works, but NO actual payment processing
   - DB Check: Verify no payment gateway integration, no payment transactions table
   - Fields Must Remain Unchanged: N/A (structure verification)

Boundary & SSOT Violation Tests:
1. **Violation Attack:** Search for profile blur code/logic
   - System Response: No blur logic exists, locked fields show clear unlock message instead
   - DB Tables Inspected: Codebase search (no DB tables for blur)
   - Fields Must Remain Unchanged: N/A (policy verification)
   - Audit Evidence: No blur/obfuscation code in codebase
   - Test Result: PASS if no blur, FAIL if blur exists → STOP

2. **Violation Attack:** Attempt to force ads
   - System Response: Ads are optional, user can choose payment alternative, no forced ad views
   - DB Tables Inspected: Configuration tables
   - Fields Must Remain Unchanged: N/A (policy verification)
   - Audit Evidence: No forced ad logic, ads always optional
   - Test Result: PASS if ads optional, FAIL if ads forced → STOP

3. **Violation Attack:** Search for actual payment processing code
   - System Response: Payment structure exists but no actual processing (Phase-5 scope)
   - DB Tables Inspected: Codebase search, payment gateway integrations
   - Fields Must Remain Unchanged: N/A (policy verification)
   - Audit Evidence: No payment gateway integration, payment structure ready for Phase-5
   - Test Result: PASS if structure only, FAIL if actual payments implemented → STOP

Day Completion Criteria (HARD GATE):
- [ ] Access matrix policies work (verified)
- [ ] No profile blur exists (verified via attack)
- [ ] Ads optional, never forced (verified via attack)
- [ ] No actual payment processing exists (verified via attack)
- [ ] Profile blur attacks blocked (proven)
- [ ] Forced ads attacks blocked (proven)
- [ ] Payment processing attacks blocked (proven)

End-of-Day Output:
- PASS: Monetization policies verified, no blur proven, ads optional proven, no payments proven, attacks blocked
- FAIL: Profile blur exists, ads forced, or actual payments implemented → STOP EXECUTION
- DB Tables Inspected: access_matrix, configuration tables, codebase search
- Fields Must Remain Unchanged: N/A (policy verification only)

------------------------------------------------
DAY 14 — PRIVACY & COMPLIANCE POLICY ATTACK (POLICY VERIFICATION ONLY)
------------------------------------------------

Objective:
- Verify privacy policies are enforced (aggregate analytics only, no per-user profiling)
- Verify ads/monetization privacy boundary is enforced (no biodata for ad targeting)
- Attack privacy boundaries to prove they are not violated
- Verify data retention policies are enforced (conflicts/audits never deleted)

Preconditions:
- Analytics system exists
- Export functionality exists
- Duplication detection exists
- Admin authentication working
- Audit log system exists
- **ASSUMPTION: Privacy/compliance systems already exist (analytics, export, duplication prevention, retention policies)**

Verification Scope (POLICY VERIFICATION ONLY - NO TABLE/SERVICE/UI CREATION):
- Verify analytics are aggregate only (no per-user behavioral profiling)
- Attack privacy to prove ads/monetization cannot access biodata
- Verify conflict records never deleted (immutability)
- Verify audit logs never deleted (immutability)
- Verify raw biodata retained per policy
- **BOUNDARY RULE: If the system does NOT exist, the verification goal is to prove that NO bypass, shortcut, or implicit behavior violates the SSOT rule.**
- Implements SSOT Section 3.13 (Analytics, Privacy & Compliance)

Explicitly Forbidden Today:
- NO table creation (assumes analytics/export/duplication tables exist)
- NO service creation (assumes services exist)
- NO UI creation (assumes UI exists)
- NO new features

Human-like Test Scenarios (MANDATORY):
1. **Aggregate Analytics Policy Attack:**
   - Route/Screen: Analytics dashboard (e.g., /admin/analytics)
   - Action: View analytics dashboard
   - Expected: Aggregate statistics only (total profiles, total searches, etc.), no per-user data
   - DB Check: Verify analytics queries use aggregate functions (COUNT, SUM), no individual user tracking
   - Fields Must Remain Unchanged: N/A (policy verification)
   - Action: Attempt to view individual user behavior
   - Expected: No per-user profiling data available, aggregate only
   - DB Check: Verify no per-user behavior tracking tables exist

2. **Privacy Boundary Policy Attack:**
   - Route/Screen: Ad targeting system (attempt to access biodata)
   - Action: Attempt to use biodata for ad targeting
   - Expected: Targeting blocked, biodata not accessible to ad system
   - DB Check: Verify ad system queries do not access biodata fields
   - Fields Must Remain Unchanged: N/A (privacy verification)
   - Action: Check ads/monetization system access to biodata (code search)
   - Expected: Ads system cannot access biodata fields, privacy boundary enforced

3. **Data Retention Policy Attack:**
   - Route/Screen: Direct database attack
   - Action: Attempt DELETE FROM conflicts WHERE id = {id}
   - Expected: Deletion blocked (immutability guard)
   - DB Check: SELECT COUNT(*) FROM conflicts WHERE id = {id} (should be 1, record still exists)
   - Fields Must Remain Unchanged: conflicts table (no records deleted)
   - Action: Attempt DELETE FROM audit_logs WHERE id = {id}
   - Expected: Deletion blocked (immutability guard)
   - DB Check: SELECT COUNT(*) FROM audit_logs WHERE id = {id} (should be 1, record still exists)
   - Fields Must Remain Unchanged: audit_logs table (no records deleted)

Boundary & SSOT Violation Tests:
1. **Violation Attack:** Search for per-user behavioral profiling code
   - System Response: Analytics system aggregates data only, no individual tracking
   - DB Tables Inspected: Analytics tables, codebase search
   - Fields Must Remain Unchanged: N/A (policy verification)
   - Audit Evidence: No per-user behavior data stored or analyzed
   - Test Result: PASS if aggregate only, FAIL if per-user profiling exists → STOP

2. **Violation Attack:** Attempt to use biodata for ad targeting
   - System Response: Privacy boundary blocks access, ad system cannot read biodata fields
   - DB Tables Inspected: Ad targeting queries, biodata tables
   - Fields Must Remain Unchanged: N/A (privacy verification)
   - Audit Evidence: No ad targeting code accesses biodata, privacy boundary enforced
   - Test Result: PASS if targeting blocked, FAIL if biodata accessible to ads → STOP

3. **Violation Attack:** Attempt to delete conflict records
   - System Response: Deletion blocked (immutability guard), conflicts never deleted
   - DB Tables Inspected: conflicts
   - Fields Must Remain Unchanged: conflicts table (all records intact)
   - Audit Evidence: No DELETE queries succeed on conflicts table
   - Test Result: PASS if deletion blocked, FAIL if deletion succeeds → STOP

4. **Violation Attack:** Attempt to delete audit logs
   - System Response: Deletion blocked (immutability guard), audit logs never deleted
   - DB Tables Inspected: audit_logs
   - Fields Must Remain Unchanged: audit_logs table (all records intact)
   - Audit Evidence: No DELETE queries succeed on audit_logs table
   - Test Result: PASS if deletion blocked, FAIL if deletion succeeds → STOP

Day Completion Criteria (HARD GATE):
- [ ] Analytics are aggregate only (verified via attack)
- [ ] Ads/monetization privacy boundary enforced (verified via attack)
- [ ] Conflict records never deleted (verified via attack)
- [ ] Audit logs never deleted (verified via attack)
- [ ] Raw biodata retained per policy (verified)
- [ ] Per-user profiling attacks blocked (proven)
- [ ] Privacy boundary attacks blocked (proven)
- [ ] Deletion attacks blocked (proven)

End-of-Day Output:
- PASS: Privacy policies verified, analytics aggregate only, privacy boundaries proven, retention enforced, attacks blocked
- FAIL: Per-user profiling exists, biodata accessible to ads, or records deletable → STOP EXECUTION
- DB Tables Inspected: conflicts, audit_logs, biodata_intakes, analytics tables
- Fields Must Remain Unchanged: conflicts table (immutability), audit_logs table (immutability), biodata_intakes.raw_ocr_text (retention)

------------------------------------------------
DAY 15 — HYGIENE & REGRESSION VERIFICATION (FINAL HARD GATE)
------------------------------------------------

Objective:
- Verify SSOT compliance across all verified features
- Remove any code duplication (if found)
- Validate all boundary conditions via attacks
- Run regression tests on all governance rules
- Ensure zero missing scope from SSOT
- Prepare final Phase-4 completion report

Preconditions:
- All previous days (Day 0-14) completed and PASSED
- All features verified (not implemented, verified)
- All governance rules proven via attacks
- Test data available

Verification Scope (NO NEW FEATURES):
- Review all SSOT sections (1-8) against verified implementation
- Verify all governance laws (1-23A) are enforced (via attacks)
- Verify all features (3.1-3.13) are verified (not missing)
- Check for code duplication (remove if found)
- Validate all boundary conditions via attacks (no violations possible)
- Run end-to-end regression tests
- Verify no Phase-5 features exist (via code search)
- Verify no SSOT violations exist
- Create completion checklist verification report
- Implements SSOT Section 2 (Law 21: Hygiene & Verification Day Rule), Section 6 (Completion Checklist)

Explicitly Forbidden Today:
- NO new features
- NO enhancements
- NO scope expansion
- NO code changes except duplication removal and bug fixes

Human-like Test Scenarios (MANDATORY):
1. **SSOT Compliance Verification:**
   - Route/Screen: Codebase review (all SSOT sections)
   - Action: Review each SSOT section (1-8) against verified implementation
   - Expected: All SSOT requirements verified, no missing scope
   - DB Check: Verify all required tables/models exist
   - Action: Check for SSOT violations (code search)
   - Expected: Zero violations found, all rules enforced
   - DB Check: N/A (code review)

2. **Governance Laws Attack:**
   - Route/Screen: Boundary attack tests (all laws 1-22)
   - Action: Test each governance law (1-22) with boundary violation attacks
   - Expected: All laws enforced, violations blocked, audit evidence exists
   - DB Check: Verify audit_logs has records for all law enforcement
   - Action: Verify Authority Order (Admin > User > Matchmaker > OCR/System)
   - Expected: Order enforced everywhere, no bypass possible
   - DB Check: Verify role-based access enforced in database

3. **Feature Completeness Verification:**
   - Route/Screen: Feature verification (all features 3.1-3.13)
   - Action: Verify each feature (Conflict, Lock, Lifecycle, History, Intake, OCR, Admin, Location, Safety, Trust, Search, Monetization, Privacy)
   - Expected: All features verified (not missing), all requirements met
   - DB Check: Verify all required tables/models exist for each feature
   - Action: Verify no partial implementations
   - Expected: All features complete, no TODOs or placeholders

4. **Code Duplication Attack:**
   - Route/Screen: Codebase search
   - Action: Search for duplicate business logic across controllers
   - Expected: Business logic in Services only, no duplication
   - DB Check: N/A (code review)
   - Action: Check for duplicate validation rules
   - Expected: Validation centralized, no duplication
   - DB Check: N/A (code review)

5. **Boundary Validation Attack:**
   - Route/Screen: All boundary attack endpoints
   - Action: Attempt all forbidden actions (silent overwrite, hard delete, free-text location, etc.)
   - Expected: All violations blocked, system enforces boundaries
   - DB Check: Verify no violations succeeded (all attacks blocked)
   - Action: Verify no Phase-5 features exist (code search)
   - Expected: Zero Phase-5 code found
   - DB Check: N/A (code search)

6. **Regression Test:**
   - Route/Screen: End-to-end flows
   - Action: Run end-to-end test: Create intake → Sandbox → Attach → Verify no profile mutation
   - Expected: All steps work, no regressions
   - DB Check: Verify matrimony_profiles table unchanged during intake flow
   - Action: Run conflict resolution flow: Create conflict → Resolve → Verify audit
   - Expected: Flow works, audit complete, no regressions
   - DB Check: Verify conflicts and audit_logs tables updated correctly

7. **Random Re-execution Test (HARDENING):**
   - Route/Screen: Random selection of previous days
   - Action: Re-execute ANY 3 random FOUNDATION VERIFICATION DAYS (from Days 1-6)
   - Expected: Identical PASS result as original execution
   - DB Check: Verify same tables inspected, same fields unchanged
   - Action: Re-execute ANY 2 random BOUNDARY ATTACK DAYS (from Days 7-14)
   - Expected: Identical PASS result as original execution
   - DB Check: Verify same attacks blocked, same policies verified
   - **Purpose:** Prove verification is repeatable and safe against partial understanding or scope drift

Boundary & SSOT Violation Tests:
1. **Violation Attack:** Check for any SSOT violation in codebase
   - System Response: Code review identifies violations, violations fixed
   - DB Tables Inspected: All tables (verify SSOT compliance)
   - Fields Must Remain Unchanged: N/A (compliance verification)
   - Audit Evidence: Zero SSOT violations in final codebase
   - Test Result: PASS if zero violations, FAIL if violations exist → STOP

2. **Violation Attack:** Check for code duplication
   - System Response: Duplication identified and removed, logic centralized in Services
   - DB Tables Inspected: N/A (code review)
   - Fields Must Remain Unchanged: N/A (code cleanup)
   - Audit Evidence: No duplicate business logic, Services are single source
   - Test Result: PASS if no duplication, FAIL if duplication exists → STOP

3. **Violation Attack:** Check for missing SSOT scope
   - System Response: All SSOT requirements verified, no missing scope
   - DB Tables Inspected: All tables (verify all features exist)
   - Fields Must Remain Unchanged: N/A (scope verification)
   - Audit Evidence: Completion checklist 100% verified
   - Test Result: PASS if all scope covered, FAIL if scope missing → STOP

Day Completion Criteria (HARD GATE):
- [ ] All SSOT sections (1-8) verified against verified implementation (verified)
- [ ] All governance laws (1-23A) enforced and tested via attacks (verified)
- [ ] All features (3.1-3.13) verified (not missing) (verified)
- [ ] Code duplication removed (if any) (verified)
- [ ] All boundary conditions validated via attacks (verified)
- [ ] Regression tests passed (verified)
- [ ] Random re-execution of 3 FOUNDATION VERIFICATION DAYS passed (verified)
- [ ] Random re-execution of 2 BOUNDARY ATTACK DAYS passed (verified)
- [ ] No Phase-5 features exist (verified via code search)
- [ ] No SSOT violations exist (verified)
- [ ] Completion checklist 100% verified (verified)

End-of-Day Output:
- PASS: SSOT compliance verified, all features verified, zero violations, zero missing scope, Phase-4 COMPLETE
- FAIL: SSOT violations found, missing scope identified, or features incomplete → STOP EXECUTION
- DB Tables Inspected: All tables (comprehensive verification)
- Fields Must Remain Unchanged: All immutable tables (conflicts, audit_logs, field_history, biodata_intakes)

============================================================
PHASE-4 EXECUTION LOCK
============================================================

This day-wise execution plan assumes PHASE-4_SSOT_v1.1.md is final and authoritative.

**Execution Rules:**
- Each day must achieve PASS status before proceeding to next day
- No day may skip testing or verification
- No day may introduce Phase-5 features
- No day may violate SSOT rules
- Hygiene day (Day 15) is mandatory and cannot be skipped
- All days assume features ALREADY EXIST - days verify, break, and prove existing systems
- If any feature does NOT exist, the day MUST FAIL and STOP

**Completion Declaration:**
Phase-4 is COMPLETE only when Day 15 achieves PASS status with zero violations and zero missing scope.

**Scope Lock:**
No new scope may be added without SSOT version increment. This execution plan covers all SSOT-mandated features and governance rules.

**VERIFICATION DISCIPLINE DECLARATION:**
Phase-4 is executable only as a verification discipline, not as a feature-building phase. All features described in SSOT Sections 1-8 are assumed to ALREADY EXIST. Days verify existing implementations, attack boundaries to prove governance rules are enforced, and demonstrate SSOT compliance. If a feature does NOT exist, the day MUST FAIL and STOP. Days 8, 10, 11, 13, 14 are POLICY VERIFICATION ONLY - no table/service/UI creation, only prove rules are enforced/not violated.

**FINAL STATEMENT:**
Phase-4 verification is now human-executable, repeatable, and safe against partial understanding or scope drift.

============================================================
END OF PHASE-4 SSOT v1.1
============================================================
खाली **SSOT मध्ये थेट add करता येईल अशी**,
**heading सहित, नेमकी 4 lines summary** देतो — **format-clean, audit-safe**.

---

### **Day 1 — Field Registry Governance Hardening (SSOT Addendum)**

FieldRegistry model वर **model-level immutability enforce** करून `field_key` व `field_type` creation नंतर बदलता येणार नाहीत असे hard-lock केले.
Mass assignment, direct property assignment व Eloquent update paths द्वारे होणारे सर्व illegal mutations **exception द्वारे block** केले.
Seeder, Admin UI (read-only), व Phase-2 flows वर **कुठलाही regression न होता** governance सुरक्षा मजबूत केली.
Cursor exhaustive misuse + re-verification tests नंतर **Day-1 formally CLOSED** व Phase-4 Day-2 साठी system **SAFE घोषित** केले.

---
Day-2 मध्ये Admin साठी EXTENDED profile fields define करण्याची system implement केली.
EXTENDED fields `field_registry` मध्ये metadata म्हणून store केले; `field_key` unique व immutable lock केला.
`profile_extended_fields` table तयार केली पण value population intentionally scope-बाहेर ठेवली.
OCR, conflict records, Phase-2 completeness/search logic — काहीही touch न करता Day-2 formally complete केला.
----------------------------
Day-3 Summary (SSOT):
Admin-only Manual Biodata Intake (Sandbox) system पूर्णपणे implement व verify केला.
RAW biodata text / file store होते, पण OCR parsing, profile update, conflict creation शून्य असल्याचं exhaustive testing ने सिद्ध केलं.
biodata_intakes table, routes, validations, guards आणि UI SSOT-exact boundaries मध्ये confirm झाले.
Law-22 exhaustive testing PASS झाल्यामुळे Day-3 formally COMPLETE & LOCKED केला.
-------------------
Day-4 UI Verification Summary (SSOT):
Day-4 मध्ये Admin Biodata Intake Sandbox व Attach backend-level पूर्णपणे verified व LOCK केला.
Post-verification मध्ये Admin Panel UI मधून Biodata Intake link visible नाही हे आढळले.
Route आणि functionality अस्तित्वात असूनही UI exposure missing असल्यामुळे हे UI visibility gap म्हणून classify केले.
UI मधून explicit, non-hidden link देणे Admin usability साठी mandatory म्हणून next action mark केले.
-----------------------
