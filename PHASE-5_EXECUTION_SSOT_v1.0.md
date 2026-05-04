============================================================
PHASE-5 EXECUTION SSOT v1.0
============================================================

Status: ACTIVE EXECUTION MODE  
Authority Level: HIGHER THAN BLUEPRINT  
Blueprint Reference: PHASE-5_FINAL_BLUEPRINT_v5.1.md  

This document governs HOW Phase-5 will be executed.

Blueprint defines WHAT to build.
Execution SSOT defines HOW to build.

============================================================
0. SOURCE OF TRUTH HIERARCHY
============================================================

1. Phase-4 SSOT (Foundation Authority)
2. Phase-5 Blueprint v5.1 (Architecture Authority)
3. Phase-5 Execution SSOT v1.0 (Execution Authority)

No other file is authoritative.

All other documents are:

REFERENCE ONLY  
NOT EXECUTION AUTHORITY  

If conflict arises:
Higher authority prevails.

============================================================
1. ASSUMPTION-FREE DEVELOPMENT RULE
============================================================

No अंदाज.
No guessing.
No theoretical coding.

At the beginning of EVERY day:

1. Developer opens PowerShell.
2. Actual project directory is verified.
3. Relevant files are opened and inspected.
4. ChatGPT receives ACTUAL file contents.
5. ChatGPT makes decision ONLY after real inspection.

ChatGPT must NEVER:
- Assume file structure
- Assume code presence
- Assume route definitions
- Assume service layer existence

Reality-based development ONLY.

============================================================
2. ROLE DISCIPLINE (STRICT ENFORCEMENT)
============================================================

🧠 ChatGPT:
- Architect
- Decision authority
- SSOT enforcer
- Planning authority
- Verification logic designer
- Git push guide

❌ ChatGPT must NOT directly write final production code blindly.

🛠 Cursor:
- Writes code
- Refactors
- Performs bulk operations
- Syntax correction
- File scanning

❌ Cursor must NOT define architecture.

💻 PowerShell:
- File listing
- Grep / Select-String verification
- Route scan
- Migration scan
- Config scan
- Test execution
- Git status check

PowerShell is verification layer.

============================================================
3. DAY EXECUTION DISCIPLINE
============================================================

Each day must follow this exact order:

STEP 1 — Status Verification
STEP 2 — Scope Lock
STEP 3 — Implementation
STEP 4 — Automated Verification
STEP 5 — Manual Deep Testing
STEP 6 — Git Commit & Push
STEP 7 — Day Closure Declaration

No step may be skipped.

============================================================
4. DAY START PROTOCOL (MANDATORY)
============================================================

At start of every day:

Developer runs PowerShell commands:

- git status
- git branch
- php artisan route:list
- Get-ChildItem for relevant service folder
- Select-String for feature keywords

All outputs pasted to ChatGPT.

ChatGPT analyzes ACTUAL state.

Only then scope is defined.

If prerequisites missing:
That day becomes "Preparation Day".

Feature implementation starts ONLY after foundation verified.

============================================================
5. INCOMPLETE DAY PROHIBITION
============================================================

A day is considered COMPLETE only if:

- Feature implemented
- All guards applied
- All failure scenarios tested
- No runtime errors
- No route missing
- No blade crash
- No logic bypass
- Git pushed successfully

“नंतर करू”
“चल पुढे जाऊ”
“तसं चालेल”

STRICTLY FORBIDDEN.

Same day = full resolution.

============================================================
6. TESTING REQUIREMENT (MANDATORY)
============================================================

Before manual testing:

Cursor OR PowerShell must:

- Scan for duplicate routes
- Scan for forbidden variables
- Scan for direct MatrimonyProfile::update()
- Scan for banned ranking injection
- Scan for unauthorized DB writes
- Scan for exception handling gaps

Only after automated checks pass:

Manual testing allowed.

Manual testing includes:

- Guest access
- Logged-in user
- Premium user
- Suspended user
- Expired subscription user
- Boost expired case

All scenarios must pass.

============================================================
7. UI PRIORITY RULE (PHASE-5 SPECIFIC)
============================================================

Phase-5 is monetization + visibility phase.

Therefore:

UI clarity = mandatory.

Each paid feature must visibly show:

- Badge
- Expiry
- Tier
- Boost status

No hidden paid effect allowed.

UI must reflect logic state.

Backend-only feature = incomplete feature.

============================================================
8. FLUTTER COMPATIBILITY RULE
============================================================

While writing any API logic:

- No breaking API contract
- No renaming existing endpoints
- No changing response structure silently
- No adding required fields without versioning

Web + Flutter must coexist.

Any change that breaks mobile app = SSOT violation.

============================================================
9. PRE-GIT PUSH VERIFICATION
============================================================

Before git push:

PowerShell must confirm:

- git status clean
- No unintended file modifications
- No debug statements left
- No commented-out critical code
- .env untouched
- No vendor modifications

Only then:

ChatGPT guides commit message format.

Day closes only after remote push success.

============================================================
PART 2 — DEEP TESTING PROTOCOL & VERIFICATION CONSTITUTION
============================================================

This section defines mandatory verification layers.
No feature may move forward without passing all layers.

============================================================
10. AUTOMATED VERIFICATION LAYER (POWERSHELL-FIRST)
============================================================

Before any manual testing:

PowerShell must verify:

A. Route Integrity
- php artisan route:list
- No duplicate route names
- No conflicting URIs
- No unexpected API route changes

B. Forbidden Pattern Scan
- Search for direct MatrimonyProfile::update(
- Search for raw DB::table writes to matrimony_profiles
- Search for ranking weight override
- Search for debug dump (dd(), dump())

C. Service Layer Validation
- All new logic placed in Service class (if required)
- Controller slim (no heavy logic dump)
- No lifecycle bypass

D. Feature Flag Check
- New feature behind flag
- Flag default OFF (unless explicitly decided)

If any scan fails:
STOP.
Fix immediately.

============================================================
11. LOGIC SAFETY TEST MATRIX
============================================================

Every new feature must pass these scenarios:

1. Guest User
   - Cannot access paid feature
   - Cannot see hidden badge logic
   - Cannot trigger service directly

2. Logged-in Free User
   - Can see upgrade prompts
   - Cannot bypass payment
   - Cannot access sponsored slot without boost

3. Premium Basic
   - Receives correct tier benefits
   - Does not exceed weight cap
   - Expiry respected

4. Premium Plus / Elite
   - Weight applied correctly
   - No permanent top lock
   - Cooldown enforced

5. Suspended User
   - No exposure
   - No boost effect
   - No unlock

6. Expired Subscription
   - Badge removed
   - Weight reset
   - No ghost benefit

============================================================
12. EXPOSURE ENGINE VALIDATION TESTS
============================================================

Mandatory tests:

- Same search repeated 5 times → rotation visible
- Sponsored pool overflow → rotation visible
- Boost expiry → profile drops from Block A
- Subscription expiry → moves from Block B to Block C
- Exposure counter increments correctly
- Cooldown penalty activates
- No profile monopolizes slot 1

Log verification required for:

calculated_score
position_index
layer

============================================================
13. PAYMENT VALIDATION TESTS
============================================================

For each payment type:

- Intent created
- Verification succeeds
- Unlock applied
- Expiry stored
- Audit log created

Refund test:

- Unlock revoked
- Audit entry exists
- No ranking corruption

Failed payment test:

- No unlock
- No badge
- No exposure injection

============================================================
14. WHATSAPP VALIDATION TESTS
============================================================

- Template used correctly
- No hidden fields sent
- Contact sent only after eligibility
- Rate limit triggered after threshold
- Disabled by woman preference
- Payment dependency respected

============================================================
15. ERROR SIMULATION TESTING
============================================================

Simulate:

- Payment gateway failure
- WhatsApp API failure
- Service class exception
- Expired boost mid-session
- Manual DB tampering attempt

System must:

- Fail safely
- Not corrupt ranking
- Not corrupt lifecycle
- Log exception

============================================================
16. ZERO-SILENT-FAILURE RULE
============================================================

No silent behavior allowed.

If:

- Boost expires
- Subscription expires
- Unlock revoked

UI must reflect immediately.

Hidden state = violation.

============================================================
17. MANUAL TESTING ENTRY CRITERIA
============================================================

Manual testing allowed ONLY if:

- All PowerShell scans pass
- All service guards verified
- No runtime exception
- No route crash
- No blade render failure

Manual testing is validation layer,
not bug discovery layer.

============================================================
18. TEST FAILURE PROTOCOL
============================================================

If any test fails:

- Day status = INCOMPLETE
- Fix immediately
- Re-run full verification
- Re-run scenario matrix

No partial fix allowed.
No “minor issue ignore” allowed.

============================================================
19. PERFORMANCE SAFETY CHECK
============================================================

Before closing day:

- No N+1 query introduced
- No heavy loop in Blade
- No unbounded exposure query
- Sponsored selection efficient
- Exposure logging optimized

Performance regression = failure.

============================================================
20. DAY PASS CRITERIA
============================================================

Day considered PASS only if:

- Automated scan clean
- Logic test matrix passed
- Exposure engine validated
- Payment validated
- WhatsApp validated
- Manual test passed
- Performance safe
- Git push successful

============================================================
PART 3 — BUG ESCALATION & ROLLBACK CONSTITUTION
============================================================

This section defines what happens when something goes wrong.
No panic coding.
No random fixes.
Structured resolution only.

============================================================
21. BUG CLASSIFICATION SYSTEM
============================================================

Every issue must be classified before fixing.

CLASS A — Critical Governance Breach
Examples:
- Hidden ranking bias detected
- Contact leak
- Lifecycle bypass
- Conflict bypass
- Unauthorized DB mutation
- Payment unlock without verification

Action:
Immediate STOP.
No new feature work allowed.
Fix same day.
Re-run full verification protocol.

------------------------------------------------------------

CLASS B — Logic Integrity Failure
Examples:
- Boost not rotating properly
- Subscription weight misapplied
- Exposure counter not incrementing
- Cooldown not activating

Action:
Fix before continuing day.
Re-run exposure validation tests.

------------------------------------------------------------

CLASS C — UI State Mismatch
Examples:
- Badge visible but benefit inactive
- Expiry not reflected
- Button state wrong
- Sponsored label missing

Action:
Fix immediately.
UI must reflect backend truth.

------------------------------------------------------------

CLASS D — Minor Cosmetic Issue
Examples:
- Alignment issue
- Spacing
- Non-breaking typo

Action:
Fix same day.
Do not carry forward.

============================================================
22. SAME-DAY RESOLUTION LAW
============================================================

If any bug is discovered:

- It must be resolved the same day.
- No postponement.
- No backlog carryover.
- No "we will improve later".

Incomplete day is forbidden.

Day cannot close until:

- All bug classes resolved.
- Verification re-run.
- Git pushed.

============================================================
23. ROLLBACK RULE (STRICT)
============================================================

Rollback allowed only if:

- Governance breach occurred.
- Data corruption risk exists.
- Ranking corruption detected.
- Payment corruption detected.

Rollback process:

1. Identify last stable commit.
2. Verify commit integrity.
3. Revert locally.
4. Re-test.
5. Push corrected state.

Blind rollback forbidden.
Rollback must be verified.

============================================================
24. INCIDENT RESPONSE SEQUENCE
============================================================

When critical issue detected:

STEP 1 — Freeze feature development.
STEP 2 — Reproduce bug.
STEP 3 — Identify root cause.
STEP 4 — Patch minimal surface.
STEP 5 — Re-run automated tests.
STEP 6 — Re-run manual tests.
STEP 7 — Document incident summary.
STEP 8 — Git commit with incident tag.

No rushed fixes.
No speculative edits.

============================================================
25. HOTFIX DISCIPLINE
============================================================

Hotfix allowed only for:

- Payment failure in production
- Contact exposure bug
- Ranking bias bug
- API break affecting Flutter

Hotfix rules:

- Minimal change only.
- No refactor during hotfix.
- Post-hotfix full audit mandatory.

============================================================
26. PRODUCTION SAFETY LOCK
============================================================

If Phase-5 deployed in soft-launch:

Any of the following triggers immediate disable:

- Unexpected ranking surge
- Sponsored slot corruption
- Payment mismatch
- Boost not expiring
- API returning inconsistent JSON

Global kill switch must work instantly.

============================================================
27. DATA INTEGRITY GUARANTEE
============================================================

Under no circumstances may:

- Exposure counter reset incorrectly
- Subscription expiry be ignored
- Boost remain active beyond expiry
- Refund fail to revoke unlock
- Contact remain visible after revocation

Data corruption = full STOP.

============================================================
28. POST-INCIDENT DOCUMENTATION
============================================================

After bug resolution:

Document:

- What happened
- Root cause
- Fix applied
- Test re-run result
- Preventive measure

No undocumented fix allowed.

============================================================
29. ESCALATION AUTHORITY
============================================================

If developer uncertain:

- Pause.
- Provide actual file state.
- Provide actual error.
- ChatGPT decides resolution path.

No guessing allowed.

============================================================
30. DAY CLOSURE BLOCKER
============================================================

If any of the following exists:

- Unverified route
- Untested payment path
- Untested boost expiry
- Untested subscription downgrade
- Untested suspension case

Day cannot close.

============================================================
PART 4 — UI ENFORCEMENT & MONETIZATION TRANSPARENCY LAW
============================================================

Phase-5 introduces monetization.
Therefore UI clarity is legally and architecturally mandatory.

Hidden monetization = governance violation.

============================================================
31. TRANSPARENCY PRINCIPLE (NON-NEGOTIABLE)
============================================================

If a profile receives any paid advantage:

- It MUST display a visible badge.
- It MUST clearly indicate the reason.
- It MUST not simulate organic ranking.

User must never be misled.

============================================================
32. BADGE RULES (MANDATORY)
============================================================

Sponsored Boost:
- Badge: "Sponsored"
- Visible on card and profile view
- Optional expiry tooltip

Premium Subscription:
- Badge: "Premium Basic" / "Premium Plus" / "Elite"
- Visible on card and profile view
- Expiry date visible in user dashboard

No silent visual differences allowed.

============================================================
33. EXPIRY VISIBILITY RULE
============================================================

If boost or subscription expires:

- Badge must disappear immediately.
- UI must reflect downgrade.
- No ghost badge.
- No hidden benefit.

UI state must always match backend state.

============================================================
34. SEARCH BLOCK VISUAL SEPARATION
============================================================

Search result page must:

Clearly separate:

BLOCK A — Sponsored
BLOCK B — Premium
BLOCK C — Standard

Visual divider required.

User must understand ranking logic.

============================================================
35. UPGRADE PROMPT ETHICS
============================================================

Upgrade prompts must:

- Clearly state benefit.
- Clearly state duration.
- Clearly state expiry.
- Not mislead about guaranteed match.

No exaggerated claims allowed.

============================================================
36. PAYMENT UI SAFETY
============================================================

Payment page must show:

- Feature name
- Duration
- Price
- Expiry policy
- Refund policy (if applicable)

User must confirm before payment.

No one-click hidden charge.

============================================================
37. REFUND UI BEHAVIOR
============================================================

If refund processed:

- Badge removed
- Unlock revoked
- Clear status shown
- No hidden exposure

Refund must visibly downgrade feature.

============================================================
38. ERROR VISIBILITY RULE
============================================================

If payment fails:

- Clear failure message
- No badge granted
- No exposure benefit
- Retry option visible

Silent failure forbidden.

============================================================
39. FLUTTER UI CONSISTENCY RULE
============================================================

All API responses must include:

- is_sponsored (boolean)
- subscription_tier (nullable string)
- boost_expiry (timestamp or null)
- subscription_expiry (timestamp or null)

Flutter UI must use these flags.
Web UI must use same logic.

No divergence allowed.

============================================================
40. ACCESS MATRIX UI RULE
============================================================

Contact unlock:

- Clearly indicate locked vs unlocked state.
- Show unlock reason.
- Show expiry if time-bound.
- Do not expose partially.

No ambiguous UI state allowed.

============================================================
41. DASHBOARD VISIBILITY RULE
============================================================

User dashboard must show:

- Current subscription tier
- Remaining duration
- Active boost status
- Exposure count (optional)
- Expiry countdown

User must understand paid state.

============================================================
42. ADMIN UI TRANSPARENCY
============================================================

Admin panel must display:

- Exposure counter
- Boost status
- Subscription tier
- Expiry
- Payment status

Admin must audit monetization.

============================================================
43. UI-LOGIC CONSISTENCY CHECK
============================================================

Before day closure:

Verify:

- Badge appears only when logic true.
- Badge disappears when expired.
- Sponsored block populated correctly.
- Premium block populated correctly.
- No profile appears in wrong block.

UI mismatch = failure.

============================================================
44. DARK PATTERN PROHIBITION
============================================================

Strictly forbidden:

- Fake urgency timer
- Misleading "limited slot" claim
- Hidden auto-renew
- Forced subscription

Ethical monetization only.

============================================================
45. UI FAILURE BLOCKER
============================================================

If any monetization feature works in backend
but is not clearly visible in UI:

Day cannot close.

Backend-only monetization = incomplete implementation.

============================================================
PART 5 — ZERO-BUG DAY CLOSURE FRAMEWORK
============================================================

This section defines final day completion authority.
No day may close without passing this framework.

============================================================
46. DAY COMPLETION DEFINITION
============================================================

A day is COMPLETE only if:

- Blueprint scope implemented
- All guards applied
- All automated scans passed
- All test matrix scenarios passed
- All UI consistency checks passed
- All monetization transparency rules satisfied
- Flutter API compatibility verified
- No console errors
- No route errors
- No blade errors
- Git pushed successfully

Anything missing = INCOMPLETE DAY.

============================================================
47. POWERSHELL FINAL VERIFICATION SCRIPT (MANDATORY)
============================================================

Before closing day, run:

- git status
- git diff
- php artisan route:list
- php artisan config:clear
- php artisan cache:clear
- php artisan view:clear

Scan for:

- dd(
- dump(
- TODO
- debug comments
- accidental echo

All must be clean.

============================================================
48. CURSOR STRESS TEST
============================================================

Cursor must perform:

- Route collision scan
- Ranking injection scan
- Direct DB write scan
- Service guard verification
- Duplicate badge logic scan

Cursor returns verification summary.

If any red flag:
Day cannot close.

============================================================
49. MANUAL TEST PASS CERTIFICATION
============================================================

Manual test checklist:

- Guest search
- Guest restricted access
- Free user upgrade flow
- Premium user benefit
- Boost purchase flow
- Boost expiry flow
- Subscription expiry flow
- Refund flow
- Suspension scenario
- API response validation for Flutter

All must pass.

============================================================
50. COMMIT DISCIPLINE
============================================================

Commit must:

- Represent one logical feature
- Have clear descriptive message
- Reference Phase-5 Day number
- Mention verification passed

Example:

"Phase-5 Day-3: Sponsored rotation logic implemented — all verification layers passed."

No vague commit messages allowed.

============================================================
51. PUSH CONFIRMATION
============================================================

After push:

- Confirm remote branch upda
============================================================
  ase============================================================
PHASE-5 DAYWISE MASTER IMPLEMENTATION ROADMAP (v2)
============================================================

This roadmap strictly follows:
- Phase-5 Blueprint v5.1
- Exposure Engine Mathematical Definition
- Monetization Transparency Law
- Cross-System Governance Rules

No feature stacking allowed.
No incomplete dependency allowed.
Each day is atomic and self-contained.

============================================================
DAY 0 — REALITY AUDIT & STRUCTURAL PREPARATION
============================================================

Objective:
Ensure Laravel project is structurally ready for Phase-5.

Tasks:
- Inspect MatrimonyProfile model
- Inspect search query builder
- Inspect existing ranking logic
- Inspect payment-related tables
- Inspect service folder structure
- Verify feature flag storage mechanism
- Verify logging infrastructure

If missing:
- Create preparation migration
- Create base services folder
- Create feature_flags table (if missing)
- Create exposure tracking fields

Day cannot close until:
Project is structurally ready for monetization & exposure tracking.

No UI changes.

------------------------------------------------------------

DAY 1 — EXPOSURE TRACKING INFRASTRUCTURE
------------------------------------------------------------

Objective:
Implement exposure data structure WITHOUT affecting ranking yet.

Tasks:
- Add fields:
    global_exposure_count
    daily_exposure_count
    last_exposure_timestamp
- Add exposure logging mechanism
- Add immutable exposure log table
- Implement exposure increment logic

Must verify:
- Exposure increments only when profile visible
- No direct DB mutation bypass
- No performance regression

UI unchanged.

------------------------------------------------------------

DAY 2 — STANDARD ROTATIONAL ENGINE (BLOCK C)
------------------------------------------------------------

Objective:
Implement deterministic rotational search logic for Standard block.

Tasks:
- Implement session_seed logic
- Implement exposure-weight decay formula
- Add cooldown penalty logic
- Ensure no ranking bias

Must verify:
- Same search repeated → rotation observed
- No profile monopolizes slot 1
- Cooldown effective
- Logs generated correctly

UI:
- No monetization yet
- Search behavior updated

------------------------------------------------------------

DAY 3 — SPONSORED BOOST FOUNDATION (BLOCK A CORE)
------------------------------------------------------------

Objective:
Implement boost model WITHOUT payment integration yet.

Tasks:
- Create boost table
- Implement boost activation logic (admin test mode)
- Implement sponsored block separation
- Implement sponsored rotation formula

Must verify:
- Max 5 slot limit enforced
- Overflow rotation works
- Expiry auto-removes boost
- No lifecycle override

UI:
- Sponsored badge visible
- Block A separated

------------------------------------------------------------

DAY 4 — PREMIUM SUBSCRIPTION CORE (BLOCK B)
------------------------------------------------------------

Objective:
Implement subscription tier logic WITHOUT payment integration yet.

Tasks:
- Create subscription table
- Implement tier weight caps
- Implement Premium block separation
- Ensure weight cap = 1.4 max

Must verify:
- Tier weight applied
- No permanent dominance
- Expiry downgrade works
- No override of suspension

UI:
- Premium badge visible
- Dashboard tier section visible

------------------------------------------------------------
------------------------------------------------------------
DAY 5 — PAYMENT ENGINE (SANDBOX TO VERIFIED MODE)
------------------------------------------------------------

Objective:
Implement full payment execution layer with verification discipline.

Tasks:
- Create PaymentTransaction table
- Implement Payment Intent creation
- Implement Gateway verification handler
- Implement Signature verification
- Connect boost activation to verified payment
- Connect subscription activation to verified payment

Must verify:
- Unlock not granted before verification
- Duplicate callback ignored
- Expiry timestamp stored
- Audit log created
- No hidden ranking change

UI:
- Payment confirmation page
- Failure page
- Retry mechanism

Day closes only after:
Refund simulation test passed.
Failure simulation test passed.

------------------------------------------------------------
DAY 6 — REFUND REVERSAL & ACCESS REVOKE LOGIC
------------------------------------------------------------

Objective:
Ensure full rollback safety for monetization.

Tasks:
- Implement refund status update
- Revoke boost on refund
- Revoke subscription on refund
- Remove badges instantly
- Log refund event immutably

Must verify:
- Exposure recalculates correctly after revoke
- No ghost premium weight
- No stale sponsored slot
- Audit trail intact

UI:
- Refund status visible
- Downgrade reflected immediately

------------------------------------------------------------
DAY 7 — REVENUE ANALYTICS ENGINE
------------------------------------------------------------

Objective:
Implement monetization analytics WITHOUT affecting ranking logic.

Tasks:
- Revenue per feature tracking
- Boost purchase count
- Subscription churn tracking
- Refund ratio tracking
- Exposure vs payment correlation metrics
- Conversion funnel logging

Must verify:
- Analytics read-only
- No exposure manipulation
- No personal biodata misuse
- Performance impact minimal

Admin UI:
- Analytics dashboard (basic view)
- No over-complex visualization yet

------------------------------------------------------------
DAY 8 — DISASTER RECOVERY & RECONCILIATION LAYER
------------------------------------------------------------

Objective:
Protect system against payment and ranking corruption.

Tasks:
- Daily automated DB backup configuration
- Payment ledger reconciliation script
- Duplicate transaction detection
- Expiry validation cron
- Sponsored slot integrity check
- Ranking corruption detection script

Must verify:
- Manual rollback test works
- No lost transaction
- No duplicate unlock
- Expired boost auto-clears
- Subscription expiry enforced

No UI heavy change.

Day closes only after:
Reconciliation script manually executed and verified.
------------------------------------------------------------
DAY 9 — WHATSAPP CONTROLLED INTEGRATION (SAFE MODE)
------------------------------------------------------------

Objective:
Integrate WhatsApp layer strictly governed by Phase-4 rules.

Tasks:
- Implement WhatsAppService
- Implement template-based messaging only
- Implement interest notification
- Implement contact unlock notification (payment dependent)
- Enforce woman-first visibility rules
- Add rate limit logic
- Add duplicate prevention guard

Must verify:
- No hidden data exposure
- Contact sent only after mutual acceptance
- Contact not sent if woman disabled sharing
- API failure does not change profile state
- Audit log created

UI:
- Notification toggle for woman
- Contact unlock clearly visible

------------------------------------------------------------
DAY 10 — FEATURE FLAG SYSTEM & GLOBAL KILL SWITCH
------------------------------------------------------------

Objective:
Create master control over Phase-5 components.

Tasks:
- Implement ai_enabled flag
- Implement whatsapp_enabled flag
- Implement payments_enabled flag
- Add admin toggle UI
- Enforce full subsystem inert state when disabled

Must verify:
- Disabling payments disables boost & subscription
- Disabling AI hides all AI endpoints
- Disabling WhatsApp stops all notifications
- No partial execution allowed

------------------------------------------------------------
DAY 11 — ABUSE DETECTION & REVIEW_MODE SYSTEM
------------------------------------------------------------

Objective:
Protect monetization and exposure from abuse.

Tasks:
- Detect repeated unlock attempts
- Detect rapid boost purchase attempts
- Detect spam-like WhatsApp usage
- Detect abnormal exposure spike
- Implement REVIEW_MODE state
- Implement RESTRICTED state

System behavior:
- AI disabled in REVIEW_MODE
- WhatsApp disabled in REVIEW_MODE
- Payments disabled in REVIEW_MODE
- Admin notified

Must verify:
- Risk state transition works
- Restricted user cannot gain exposure
- Manual admin clear works

Admin UI:
- Risk state indicator
- Manual override control
------------------------------------------------------------
DAY 12 — CROSS-SYSTEM INTEGRATION AUDIT
------------------------------------------------------------

Objective:
Ensure AI, Payment, WhatsApp, Exposure Engine operate without conflict.

Tasks:
- Verify no direct MatrimonyProfile::update() used
- Verify FieldLockService enforced everywhere
- Verify ConflictDetectionService respected
- Verify lifecycle checks applied in all services
- Verify no hidden ranking injection

Must verify:
- Cross-system logs consistent
- No circular dependency
- No ranking corruption under boost + subscription mix

------------------------------------------------------------
DAY 13 — HARD GLOBAL STOP & FAILURE SIMULATION
------------------------------------------------------------

Objective:
Test all global stop conditions defined in Blueprint.

Simulate:
- Hidden ranking bias injection
- Contact leak attempt
- Payment unlock without verification
- Lifecycle override attempt
- Conflict bypass attempt

System must:
- Throw proper exception
- Log incident
- Disable subsystem if required

No silent failure allowed.

------------------------------------------------------------
DAY 14 — FLUTTER API COMPATIBILITY AUDIT
------------------------------------------------------------

Objective:
Ensure no mobile app breakage.

Tasks:
- Compare API JSON before Phase-5 and after
- Verify no removed keys
- Verify no type changes
- Verify monetization flags added safely
- Test sponsored & premium visibility in API

Must verify:
- Flutter response stable
- No HTTP status regression
- No unexpected null values

------------------------------------------------------------
DAY 15 — SOFT LAUNCH MODE ACTIVATION
------------------------------------------------------------

Objective:
Activate limited production simulation.

Tasks:
- Enable ai_enabled for limited users
- Enable payments in sandbox
- Enable WhatsApp admin-only
- Monitor logs for anomaly
- Run exposure fairness stress test

Must verify:
- Sponsored rotation stable
- Premium weight cap respected
- No profile monopolizes slot
- No payment corruption
- No UI inconsistency

------------------------------------------------------------
DAY 16 — FINAL GOVERNANCE VERIFICATION & SEAL
