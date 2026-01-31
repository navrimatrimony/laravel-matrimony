============================================================
ðŸ“‹ PHASE-2 FINAL BLUEPRINT â€” DECISIONED & FROZEN
============================================================

âš ï¸ IMPORTANT: THIS IS A FINALIZED BLUEPRINT FOR PHASE-2 DECISIONS
âš ï¸ NO NEW FEATURES WILL BE ADDED HERE
âš ï¸ THIS DOCUMENT IS NOT SSOT
âš ï¸ IMPLEMENTATION MUST RELY ON A SEPARATE PHASE-2 SSOT FILE

"All conflicting ideas have been resolved. This blueprint reflects FINAL Phase-2 decisions.

This document is a long-form reference blueprint containing full discussion, rationale, and historical context. It is NOT used for implementation."

============================================================
ðŸ“Œ CONTEXT (VERIFIED FACTS)
============================================================

âœ… Phase-1 MVP Status:
- Laravel backend exists and stable
- Flutter app exists (Phase-1 complete)
- MatrimonyProfile is single biodata source
- Interest lifecycle exists (send, accept, reject, withdraw)
- Basic search exists (age, caste, location)
- Photo upload exists (single photo)
- Sanctum API authentication for Flutter
- Web session authentication for Laravel website
- No AI implemented yet
- No payments implemented
- No advanced matching implemented

âœ… Current Architecture:
- User model = authentication only
- MatrimonyProfile model = biodata source
- Interest model = profile-to-profile connections

============================================================
ðŸ›ï¸ PLATFORM PRINCIPLES (CORE RULES)
============================================================

These principles may guide Phase-2 design decisions.
All features should reference these principles where applicable.

**Safety Over Speed:**
- Platform safety takes precedence over development speed
- User trust and data protection are non-negotiable
- Reversible mistakes preferred over permanent actions

**Soft Delete Policy:**
- Profile deletion may be soft delete only (no hard delete)
- Deleted profiles retained in database with recovery period
- Related data (interests, photos) handled during soft delete
- Recovery period duration needs discussion (30 days? 90 days?)

**Mandatory Audit Logs:**
- All admin actions may require audit logging
- Log entries include: action, admin ID, timestamp, affected entity, reason, before/after state
- Audit logs searchable and filterable
- Retention period needs discussion

**Mandatory Reason Fields:**
- High-risk admin actions may require mandatory reason fields
- User-impacting actions may require notification
- Profile suspension, deletion, image actions may need reasons
- Which specific actions need mandatory reasons? (Discussion required)

**No Silent Admin Actions:**
- Admin actions affecting users may require notifications
- Users should know when their profile status changes
- Profile suspension, deletion, image moderation may need notifications
- Notification method and timing? (Discussion required)

============================================================
A. Phase-2 INCLUDED FEATURES (FINAL)
============================================================

**1ï¸âƒ£ Demo / Testing Profiles & Simulation**
- System-generated demo profiles with internal `is_demo = true` flag
- Admin-controlled bulk creation (1â€“50 profiles per action)
- Demo profiles with single generic/placeholder photos
- Demo â†” Real interactions allowed (view, interest, accept, reject, block, shortlist)
- View-back behavior with admin-configured probability (0â€“100%)
- 24-hour frequency limits per demo-real pair
- Global admin toggle for search visibility
- Same completeness and visibility rules as real profiles

**2ï¸âƒ£ Admin Panel â€” Supreme Control**
- Profile suspend/unsuspend with mandatory reasons
- Soft delete only (no hard delete for real users)
- Image moderation (approve/reject) with mandatory reasons
- Abuse reporting system (open/resolved status)
- Mandatory reason enforcement for all admin actions
- User notifications for: suspension, unsuspension, soft deletion, image rejection
- Audit logs: admin_id, action_type, entity details, reason, timestamp, is_demo flag

**3ï¸âƒ£ Authentication (Backend Only)**
- Token-based authentication (Laravel Sanctum)
- JSON-based login/logout responses
- API versioning (/api/v1/*) with backward compatibility within v1
- Token lifecycle management (creation, refresh, expiration, revocation)

**4ï¸âƒ£ Profile Completeness & Visibility Rules**
- Completeness = (filled mandatory fields / total mandatory fields) Ã— 100
- Mandatory fields: Gender, DOB, Marital Status, Education, Location, Photo
- 70% threshold for search results and interest send/receive
- Admin override capability (per-profile, with reasons)
- Admin-configurable field settings via database table
- Simple dependent field logic (marital_status-based only)

**5ï¸âƒ£ Minimal Search & Filters**
- Essential filters: age, caste, location, height, marital status, education
- Backend enforcement of field "searchable" configuration
- Pagination and result limits

**6ï¸âƒ£ Interest, Shortlist & Block Lifecycle**
- Interest send/accept/reject/withdraw workflows
- Private shortlists (owner-only visibility)
- Hard blocking (removes from search, views, interests, shortlists)
- Block cancels existing interests and breaks accepted connections
- Unblock requires fresh interest to re-initiate
- No block notifications, no shortlist notifications

**7ï¸âƒ£ Admin Moderation & Safety Rules**
- Manual profile suspensions (no auto-expiry)
- Soft delete policy for real user profiles
- Image rejection with immediate hiding
- Abuse reporting workflow (open â†’ resolved)
- Mandatory reason fields for all admin actions
- User notifications for moderation actions
- Basic audit logging (no advanced features)

**8ï¸âƒ£ Profile View & View-Back Behavior**
- Profile view tracking for real and demo profiles
- View-back: demo profiles may view real profiles back (probability-controlled)
- 24-hour limits, no chaining/recursion
- Admin controls: global enable/disable, probability configuration

**9ï¸âƒ£ Notifications System (Backend Only)**
- Database-stored notifications only (no push/email)
- Types: profile views, interest actions, admin moderation actions
- Excluded: blocks, shortlists, internal admin actions
- 90-day retention, automatic cleanup
- Read/unread status, auto-mark on open
- Admin visibility for debugging/dispute resolution

============================================================
B. Phase-2 EXPLICITLY EXCLUDED FEATURES
============================================================

**Communication Features**
- In-app messaging, external communication (WhatsApp/Phone)
- Real-time messaging, file sharing, video/voice calling
- Message history, read receipts, typing indicators

**Payment & Monetization**
- Payment processing, subscription models
- Premium features, payment gateway integration
- Billing, invoicing, freemium models

**AI & Machine Learning**
- AI-based matching, personality compatibility
- Machine learning model integration
- Predictive matching algorithms, behavioral analysis

**Advanced Analytics**
- Complex analytics dashboards
- Predictive analytics, user behavior analysis
- Engagement trend analysis, advanced reporting

**Advanced Profile Features**
- Profile verification badges, multi-level verification
- Photo verification workflows, document verification
- Phone verification (SMS OTP)

**Admin Advanced Features**
- Multiple admin roles (moderator, viewer hierarchy)
- Dual approval flows, bulk moderation actions
- Appeals system, automated moderation
- Admin analytics dashboards

**Notification Advanced Features**
- Push notifications, email delivery
- Notification preferences, opt-out systems
- Advanced retention policies, user-controlled deletion

============================================================
C. Phase-3 / FUTURE IDEAS (REFERENCE ONLY)
============================================================

**Full Advanced Search & Ranking**
- Complex filter combinations, search result ranking algorithms
- Saved search preferences, search analytics

**API Versioning Infrastructure**
- Full versioning system (/api/v1/, /api/v2/)
- Version deprecation policies, legacy support, migration tooling

**Chat / Messaging**
- In-app messaging system, real-time communication (WebSockets)
- Message history, file sharing, video/voice calling

**Payments & Monetization**
- Payment processing, subscription models, premium features
- Payment gateway integration, billing and invoicing

**Advanced Profile Features**
- Profile verification badges, multi-level verification system
- Photo verification workflows, document verification, phone verification

**Long-Term Platform Vision**
- Strategic questions about platform evolution, market positioning
- Target audience evolution, competitive strategy, platform identity

============================================================
PHASE-2 BLUEPRINT STATUS
============================================================

- Status: FINAL & FROZEN
- Scope: Phase-2 only
- Modification rule:
  - Any change requires updating Phase-2 SSOT
  - Blueprint changes are allowed ONLY for documentation clarity, not scope change

- Next Step:
  - Create a separate PHASE-2_SSOT.md file
  - Implementation must strictly follow SSOT
- JSON-based login/logout responses instead of session-based
- Token lifecycle management (creation, refresh, expiration)

Why it may belong in Phase-2:
- Mobile applications require API authentication that works across platforms
- Session-based auth is not suitable for mobile clients
- Token-based systems provide better scalability for API access
- Foundation needed before mobile app development can begin

Risks / Concerns:
- Token security considerations (storage, transmission)
- Token refresh strategy complexity
- Migration path for existing session-based auth (if any exists)
- Performance implications of token validation

Discussion Questions:
- Should we use Laravel Sanctum or a custom token implementation?
- What should be the token expiration time?
- How should token refresh be handled?
- Should tokens be revocable?
- Do we need multiple device support per user?
- What happens to tokens when password is changed?

**DECISION BLOCK:**
- Candidate for Phase-2? (YES / NO / DEFER)
- Why?
- Open questions still unresolved: [List key questions]

-------------------------------------------
2. Profile Completeness & Visibility Rules
-------------------------------------------

What the feature is:
- Backend-calculated completeness percentage for matrimony profiles
- Classification of fields as mandatory vs optional
- Visibility restrictions preventing incomplete profiles from appearing in searches
- Rules governing when a profile can be viewed or contacted

Why it may belong in Phase-2:
- Ensures quality of profiles visible to users
- Encourages profile completion through restrictions
- Improves user experience by showing only complete profiles
- Reduces spam and low-effort profiles in search results

Risks / Concerns:
- Determining which fields are truly mandatory
- Risk of being too restrictive (fewer matches) vs too lenient (low quality)
- How to handle partial profile updates
- Edge cases (profiles that are complete but still shouldn't be visible)
- Impact on existing profiles when new mandatory fields are added

Discussion Questions:
- What is the minimum completeness threshold for visibility (e.g., 70%, 80%)?
- Which fields should be mandatory vs optional?
- Should there be different thresholds for different operations (search vs contact)?
- How should we handle profile updates that drop below threshold?
- Should admins be able to override visibility rules?
- How to handle existing profiles when new fields are added?

**DECISION BLOCK:**
- Candidate for Phase-2? (YES / NO / DEFER)
- Why?
- Open questions still unresolved: [List key questions]

-------------------------------------------
3. Advanced Search & Filters (Rule-Based)
-------------------------------------------

What the feature is:
- Search functionality with filters for: height, education, occupation, income
- Marital status filtering
- Religion and caste filtering logic
- Location-based search with configurable depth (city, state, region, etc.)
- Backend API endpoints for filtered profile searches

Why it may belong in Phase-2:
- Core functionality expected in matrimony platforms
- Users need to narrow down matches based on preferences
- Critical for user engagement and match discovery
- Foundation for future recommendation systems

Risks / Concerns:
- Performance implications of complex queries
- Privacy considerations for sensitive filters (religion, caste)
- How to handle "prefer not to say" options
- Search result ranking logic
- Pagination and result limits

Discussion Questions:
- What filters are essential for Phase-2 vs nice-to-have?
- Should filters be saved as user preferences?
- How should we handle null/empty values in search?
- What should be the default search radius for location?
- Should there be limits on number of active filters?
- How should we handle combination of inclusive/exclusive filters?

**DECISION BLOCK:**
- Candidate for Phase-2? (YES / NO / DEFER)
- Why?
- Open questions still unresolved: [List key questions]

-------------------------------------------
4. Interest, Shortlist & Block Lifecycle
-------------------------------------------

What the feature is:
- Interest send / accept / reject / withdraw workflows (may already exist, needs review)
- Shortlist feature (private list of profiles user is considering)
- Hard block functionality between users
- Effects of blocking on search results and interaction history
- State management for all these interactions

Why it may belong in Phase-2:
- Core interaction features required for basic platform functionality
- Essential for user safety (block feature)
- Expected user workflows in matrimony platforms
- Foundation for future features like notifications

Risks / Concerns:
- Complexity of state transitions (e.g., what if user blocks someone after interest sent?)
- Privacy implications of shortlist visibility
- How to handle block reversals (should they be allowed?)
- Notification implications for each action
- Database schema complexity for tracking states

Discussion Questions:
- Should users be able to see who shortlisted them?
- What happens to existing interests when a block is applied?
- Can blocked users still see historical interactions?
- Should interest withdrawal be time-limited?
- How many profiles can be in a shortlist?
- Should there be a cooldown period between interest actions?

**DECISION BLOCK:**
- Candidate for Phase-2? (YES / NO / DEFER)
- Why?
- Open questions still unresolved: [List key questions]

-------------------------------------------
5. Notification Foundation (Backend Only)
-------------------------------------------

What the feature is:
- Database-stored notifications table
- Interest-related notifications (sent, received, accepted, rejected)
- Notification read/unread status
- Foundation for future email and push notification integrations
- API endpoints for fetching and marking notifications

Why it may belong in Phase-2:
- Users need to know about interactions on the platform
- Essential for engagement and platform responsiveness
- Building the foundation now allows future extension without refactoring
- Can be implemented as database-only initially, with external services added later

Risks / Concerns:
- Database growth over time (notification cleanup/archival strategy)
- Notification delivery guarantees
- Performance implications of notification queries
- Potential for notification spam

Discussion Questions:
- What notification types are essential for Phase-2?
- Should notifications expire or be kept indefinitely?
- What should be the notification retention policy?
- Should there be notification preferences (opt-out for certain types)?
- How should we handle bulk notifications (e.g., daily digest)?
- Should notifications be real-time or batch-processed?

**DECISION BLOCK:**
- Candidate for Phase-2? (YES / NO / DEFER)
- Why?
- Open questions still unresolved: [List key questions]

-------------------------------------------
6. Admin Moderation & Safety Controls
-------------------------------------------

What the feature is:
- Profile suspend / unsuspend functionality
- Soft delete capability (see Platform Principles)
- Image moderation flagging system
- Abuse report handling workflow
- Mandatory reason/logging for all admin actions (see Platform Principles)
- Audit log concept (see Platform Principles)

Why it may belong in Phase-2:
- Critical for platform safety and user trust
- Legal/compliance requirements for content moderation
- Essential for handling inappropriate content or behavior
- Foundation for community guidelines enforcement

Risks / Concerns:
- Liability if moderation is insufficient
- Complexity of determining what constitutes abuse
- Admin workload considerations
- Appeals process (should there be one?)
- Privacy of reports vs transparency

Discussion Questions:
- What should trigger an automatic suspension vs manual review?
- How long should suspensions last (temporary vs permanent)?
- Who can file abuse reports (any user or verified users only)?
- Should reported users be notified immediately or after review?
- What information should be visible in audit logs?
- Should there be an escalation path for complex cases?
- Should bulk actions require dual admin approval?

**DECISION BLOCK:**
- Candidate for Phase-2? (YES / NO / DEFER)
- Why?
- Open questions still unresolved: [List key questions]

-------------------------------------------
7. Additional Profile Fields
-------------------------------------------

What the feature is:
- Additional profile fields beyond current 7 fields
- Possible fields: height, weight, occupation, income, native place, current city, family type, family details, lifestyle preferences, dietary preferences, languages spoken
- Multiple photos (gallery) instead of single photo
- Photo categories (profile, family, ceremony)
- Field validation and data type considerations

Why it may belong in Phase-2:
- Enhanced profiles may improve match quality
- Users may expect comprehensive profile information
- More searchable criteria may improve user experience
- Multiple photos may increase profile authenticity

Risks / Concerns:
- Privacy considerations for sensitive fields
- Storage implications for multiple photos
- Impact on existing profiles (backward compatibility)
- Determining which fields are culturally appropriate
- Migration complexity for existing data

Discussion Questions:
- Which additional fields are essential for Phase-2?
- Should new fields be nullable initially or required immediately?
- How to handle existing profiles when new fields are added?
- Maximum number of photos? Storage implications?
- Should family details be mandatory or optional?
- Which fields should be searchable vs display-only?
- How to handle privacy concerns for sensitive fields?

**DECISION BLOCK:**
- Candidate for Phase-2? (YES / NO / DEFER)
- Why?
- Open questions still unresolved: [List key questions]

============================================================
LAYER 2: SUPPORTING DISCUSSION
============================================================

This layer contains risks, alternatives, rationale, and trade-offs
that inform decisions about Layer 1 features.

-------------------------------------------
Database & Data Impact Considerations
-------------------------------------------

**Migration Risks:**
- Adding new required fields may make existing profiles "incomplete"
- Adding nullable fields may create data quality issues
- Data type changes may require data transformation
- Default values strategy needs definition

**Backward Compatibility:**
- Profile completeness calculation may change (Phase-1: 7 fields, Phase-2: X fields)
- Search/filter impact on existing functionality
- Photo storage changes (single photo to multiple photos)
- Interest system compatibility with new fields

**Data Quality:**
- How to handle partial profile updates
- Profile completeness scoring methodology
- Handling profiles that are technically complete but shouldn't be visible
- Data validation rules

**Discussion Questions:**
- Should new fields be nullable initially, then required later?
- How to handle existing data when field types change?
- Should incomplete profiles be marked differently?
- How to migrate single photo to multiple photo structure?

-------------------------------------------
Performance & Scalability Concerns
-------------------------------------------

**Search Performance:**
- Complex filter queries may be slow
- Need for indexing strategy
- Pagination and result limits
- Caching strategies

**Notification Performance:**
- Database growth over time
- Query performance for notification fetching
- Cleanup/archival strategy

**Analytics Performance:**
- Aggregation query performance on large datasets
- Real-time vs cached metrics
- Storage of historical data

**Discussion Questions:**
- What indexing strategy is needed for new search filters?
- How to optimize notification queries?
- Should metrics be calculated real-time or cached?
- What is acceptable query response time?

-------------------------------------------
Security & Privacy Considerations
-------------------------------------------

**Token Security:**
- Token storage and transmission security
- Token revocation strategy
- Multiple device management

**Data Privacy:**
- Privacy implications of detailed interaction history
- Sensitive filter data (religion, caste) handling
- Phone number exposure concerns
- Profile visibility and privacy settings

**Admin Security:**
- Admin action rate limiting
- Suspicious activity monitoring
- Admin misuse detection

**Discussion Questions:**
- What security measures are needed for token storage?
- How to handle privacy concerns for sensitive data?
- Should there be role-based permissions for admins?
- How to detect and prevent admin misuse?

-------------------------------------------
User Experience Considerations
-------------------------------------------

**Profile Completion:**
- Balance between encouraging completion and not being too restrictive
- User motivation for completing profiles
- Visibility penalties for incomplete profiles

**Communication UX:**
- User preferences for communication method
- Balance between platform control and user convenience
- Notification preferences and opt-out options

**Search UX:**
- Filter complexity vs ease of use
- Saved search preferences
- Search result ranking and relevance

**Discussion Questions:**
- How to encourage profile completion without being punitive?
- What is optimal number of filters for user experience?
- Should users be able to save search preferences?
- How should search results be ranked?

-------------------------------------------
Alternative Approaches
-------------------------------------------

**Profile Enhancement:**
- Option A: Add all fields at once vs Option B: Gradual field addition
- Option C: Make fields optional vs Option D: Make fields mandatory

**Communication:**
- Option A: In-app messaging vs Option B: External communication
- Option C: Hybrid approach vs Option D: Defer entirely

**Moderation:**
- Option A: Full moderation suite vs Option B: Basic moderation
- Option C: Automated moderation vs Option D: Manual only

**Discussion Questions:**
- Which alternative approach aligns with Phase-2 goals?
- What are the trade-offs of each alternative?
- Can we start simple and add complexity later?

============================================================
LAYER 3: PARKING LOT / FUTURE IDEAS
============================================================

This layer contains ideas that are clearly NOT Phase-2.
These may be considered for Phase-3 or later.

-------------------------------------------
AI & Machine Learning Features
-------------------------------------------

**NOT Phase-2:**
- AI-based personality matching
- Lifestyle compatibility algorithms
- AI-generated match recommendations
- Machine learning for match scoring

**Possible Phase-3+ Ideas:**
- Personality-based matching
- Lifestyle compatibility scoring
- Behavioral pattern analysis
- Predictive matching algorithms

**Note:** Phase-2 may include data collection structures that make future AI integration easier (see Layer 1: AI-Ready Data Hooks), but no AI logic in Phase-2.

-------------------------------------------
Advanced Analytics & Insights
-------------------------------------------

**NOT Phase-2:**
- Complex analytics dashboards
- Predictive analytics
- User behavior analysis
- Engagement trend analysis

**Possible Phase-3+ Ideas:**
- Advanced analytics dashboards
- User segmentation analysis
- Conversion funnel analysis
- Engagement trend visualization

**Note:** Phase-2 may include basic admin analytics (see Layer 1), but nothing fancy.

-------------------------------------------
Payment & Monetization
-------------------------------------------

**NOT Phase-2:**
- Payment processing
- Subscription models
- Premium features
- Payment gateway integration

**Possible Phase-3+ Ideas:**
- Premium membership tiers
- Payment processing
- Subscription management
- Freemium model features

-------------------------------------------
Advanced Communication Features
-------------------------------------------

**NOT Phase-2:**
- Video calling
- Voice calling
- Screen sharing
- Advanced messaging features (typing indicators, read receipts, file sharing)

**Possible Phase-3+ Ideas:**
- Video/voice calling integration
- Advanced messaging features
- Group messaging
- Communication history search

**Note:** Basic communication may be Phase-2 (see Layer 1), but advanced features are future.

-------------------------------------------
Profile Verification Systems
-------------------------------------------

**NOT Phase-2:**
- Phone verification (SMS OTP)
- Document verification (ID proof)
- Photo verification (admin review)
- Multi-level verification badges

**Possible Phase-3+ Ideas:**
- Phone verification system
- Document verification workflow
- Multi-level verification
- Verification badge system

**Note:** Email verification may already exist in Phase-1.

-------------------------------------------
Long-Term Platform Vision
-------------------------------------------

**10-Year Vision Questions (Not Phase-2 Scope):**
- What does the platform become?
- Market positioning and differentiation
- Target audience evolution
- Competitive strategy
- Platform identity and values

**Note:** These are strategic questions that inform Phase-2 but are not Phase-2 implementation scope.

-------------------------------------------
Feature Deprecation Strategy
-------------------------------------------

**NOT Phase-2:**
- Feature deprecation policies
- Deprecation timelines
- Legacy support strategies
- Feature retirement workflows

**Possible Phase-3+ Ideas:**
- Deprecation decision framework
- Deprecation communication strategies
- Legacy feature support
- Feature lifecycle management

**Note:** Considered for future phases when features need to be deprecated.

-------------------------------------------
Demo / Testing Profiles & Sandbox
-------------------------------------------

**Possible Phase-2 Feature (Lower Priority):**
- System-generated demo profiles
- Profiles marked with special flag indicating demo/test data
- Strict separation from real user profiles
- Admin controls to enable/disable demo visibility

**Why Consider for Phase-2:**
- Helps with testing and QA
- Useful for demos and onboarding
- Reduces reliance on real user data

**Why May Be Deferred:**
- Can use test data seeding instead
- May not be critical for Phase-2 launch
- Adds maintenance overhead

**Discussion Questions:**
- Is this needed for Phase-2 or can we defer?
- Should demo profiles be visible in production?
- How many demo profiles are needed?

**DECISION BLOCK:**
- Candidate for Phase-2? (YES / NO / DEFER)
- Why?
- Open questions still unresolved: [List key questions]

-------------------------------------------
Basic Admin Analytics (Non-Fancy)
-------------------------------------------

**Possible Phase-2 Feature (Lower Priority):**
- Simple backend-calculated metrics: total profiles, active vs inactive
- Interest statistics: sent / accepted / rejected ratios
- Reports count and status breakdown
- Basic aggregation queries, no complex dashboard

**Why Consider for Phase-2:**
- Admins need visibility into platform health
- Helps identify issues early
- Basic metrics required for decision-making

**Why May Be Deferred:**
- Can be added later without major refactoring
- May not be critical for Phase-2 launch
- Simple queries can be done ad-hoc initially

**Discussion Questions:**
- Is this needed for Phase-2 or can we defer?
- What metrics are essential vs nice-to-have?
- How often should metrics be calculated?

**DECISION BLOCK:**
- Candidate for Phase-2? (YES / NO / DEFER)
- Why?
- Open questions still unresolved: [List key questions]

-------------------------------------------
AI-Ready Data Hooks (NO AI)
-------------------------------------------

**Possible Phase-2 Feature (Lower Priority):**
- Storage of user preferences and interaction history in structured format
- Match outcome tracking placeholder (accepted, rejected, unknown)
- Data structures designed to be easily consumable by future AI/ML systems
- Explicit note: no AI logic, matching algorithms, or recommendations in Phase-2

**Why Consider for Phase-2:**
- Much easier to add data structures now than retrofit later
- Ensures data collection happens from the start
- Future-proofing for Phase-3 or later AI features
- No commitment to AI implementation, just data readiness

**Why May Be Deferred:**
- Risk of over-engineering for hypothetical future needs
- Can add data structures when AI is actually needed
- Storage overhead of additional tracking data

**Discussion Questions:**
- Is this needed for Phase-2 or can we defer?
- What interaction data should be tracked?
- How long should interaction history be retained?

**DECISION BLOCK:**
- Candidate for Phase-2? (YES / NO / DEFER)
- Why?
- Open questions still unresolved: [List key questions]

============================================================
ðŸ“ ASSUMPTIONS VS FACTS
============================================================

âœ… **VERIFIED FACTS:**
- Phase-1 MVP is complete and stable
- Laravel backend exists
- Flutter app exists (Phase-1)
- MatrimonyProfile is single biodata source
- Interest lifecycle exists
- Basic search exists
- No AI implemented yet
- No payments implemented

ðŸŸ¡ **ASSUMPTIONS (NOT VERIFIED):**
- Users want more features
- Profile enhancements will increase engagement
- Communication features are desired
- Admin control is needed
- Matching improvements will improve user experience
- Phase-2 should prepare for future AI (assumption)
- Safety principles are appropriate for Phase-2

â“ **NEEDS VERIFICATION:**
- User feedback on Phase-1
- Competitor feature analysis
- Market research
- Technical feasibility of proposed features
- Resource availability
- Timeline constraints
- Priority of features

============================================================
ðŸ“Œ DOCUMENT STATUS
============================================================

Version: 2.0 (Refactored)
Created: 2026-01-19
Last Refactored: 2026-01-19
Status: ROUGH BLUEPRINT â€” DISCUSSION DOCUMENT
Next Review: [To be scheduled]

This document is:
- âœ… Open for discussion
- âœ… Subject to change
- âœ… Not locked
- âŒ NOT SSOT
- âŒ NOT implementation-ready

**Refactoring Notes:**
- Consolidated repeated concepts (especially safety principles)
- Organized into 3 clear layers (Core, Supporting, Parking Lot)
- Added Platform Principles section for repeated rules
- Added Decision Blocks to core sections
- Reduced from ~4400 lines to ~800-1000 lines
- Preserved all important ideas while eliminating repetition
- Maintained tentative language throughout

**Next Steps:**
- Review Platform Principles section
- Fill in Decision Blocks for each core feature
- Prioritize features based on discussions
- Answer open questions
- Finalize Phase-2 scope
- Create SSOT document after decisions are made

============================================================
Revised Phase-2 Feature Set (Admin & Safety First)
============================================================

âš ï¸ DISCUSSION REQUIRED â€” NOT FINAL SCOPE
âš ï¸ This section prioritizes admin control and platform safety

This revised feature set may prioritize:
- Admin panel capabilities (supreme control)
- Demo / testing profiles (simulation without real users)
- Platform safety and control (moderation, audit, compliance)

All features below are:
- Marked as DISCUSSION REQUIRED
- Subject to review and change
- Using tentative language (may, could, possibly)
- Not yet finalized or locked

============================================================
CORE PHASE-2 FEATURES
============================================================

-------------------------------------------
1ï¸âƒ£ Demo / Testing Profiles & Simulation (HIGH PRIORITY)
-------------------------------------------

**What it is:**
- System-generated demo profiles that behave like real users
- Demo profiles can perform actions: view, interest, accept, reject
- Demo profiles are internally flagged (`is_demo = true`) but are behaviorally similar to real profiles in Phase-2
- Ability to simulate load and behavior including demo-real interactions
- Demo profiles clearly marked and never mistaken as real users
- Admin controls to enable/disable demo profile visibility

**Demo Profile Creation (Admin-Controlled):**
- Demo profiles WILL be created via Admin Panel.
- Admin MAY create demo profiles in BULK.
- Bulk generation limit: minimum 1, maximum 50 demo profiles per action.
- Bulk generation intended for testing, demos, and realistic data simulation.
- Demo profiles are stored in the same MatrimonyProfile table with an internal `is_demo = true` flag.

Explicitly exclude:
- Large-scale generators (hundreds/thousands)
- Scheduled or automatic demo profile creation
- Demo profile lifecycle automation (pause/archive/delete states)

**Demo Profiles with Photo:**
- Demo profiles WILL include a single profile photo.
- Photos will be generic / placeholder images.
- Demo profiles will follow the same single-photo upload and display flow as real profiles.
- Photo support exists only to improve realism during testing and demos.

Explicitly exclude:
- Photo pools
- Reuse limits
- Demo-specific photo moderation pipelines
- Multi-photo galleries

**Interaction Rules (Confirmed):**
- Demo â†” Demo interactions are ALLOWED.
- Demo â†” Real user interactions are ALLOWED in Phase-2.
- Demo profiles can:
  - View real user profiles
  - Be viewed by real user profiles (view-back supported)
  - Send interests
  - Receive interests
  - Accept / reject interests
  - Be blocked or shortlisted
- All interaction logic behaves identically to real profiles.

Explicitly prohibit:
- Conversion of demo profiles into real user profiles
- Any indication to end users that a profile is a demo profile

**Search Visibility:**
- Demo profiles MAY appear in search results.
- Demo profiles follow the same search filters and visibility rules as real profiles.
- Admin Panel WILL include a single global toggle to show or hide all demo profiles from search and discovery.

Explicitly exclude:
- Per-user or per-admin demo visibility rules
- Environment-based (testing vs production) mode switching in Phase-2

**Notifications & Logging:**
- Demo profile actions WILL generate notifications just like real profiles.
- Admin audit logs WILL internally mark demo-related actions using `is_demo` flag.
- No special notification suppression or demo-only logging rules in Phase-2.

**Explicit Phase-2 Exclusions (Reaffirm):**
- No demo profile lifecycle engine
- No demo analytics separation
- No emergency demo shutdown
- No demo photo verification
- No demo-to-real conversion
- No production/testing mode switch UI

"In Phase-2, demo profiles prioritize realism and admin confidence over strict isolation. Limited analytics pollution and user-facing ambiguity are accepted trade-offs for faster testing and iteration."

**Why it is important for Phase-2:**
- Allows testing and QA without real user data
- Enables load testing and behavior simulation
- Useful for demos and onboarding new team members
- Provides sample data for development environments
- Reduces reliance on seeding real user data
- Helps validate features before exposing to real users

**Risks / Concerns:**
- Accidental inclusion of demo profiles in production searches
- Demo profiles interacting with real users (ethical concerns)
- Maintenance overhead of keeping demo profiles realistic
- Storage costs for large numbers of demo profiles
- Potential confusion if demo profiles are too realistic
- Data cleanup and lifecycle management
- Trust implications if demo profiles are discovered by real users

**Open Discussion Questions:**
- Should demo profiles be visible in production or only in test environments?
- Can demo profiles interact with real users, or should they be isolated?
- How many demo profiles should be generated?
- Should demo profiles have photos (generic/placeholder)?
- Should demo profiles automatically interact with each other?
- Who should have access to create/modify demo profiles?
- Should there be different sets of demo profiles for different scenarios?
- What is the cleanup lifecycle for demo profiles?
- How to ensure demo profiles are never mistaken as real users?
- Should demo profiles be excluded from all analytics and metrics?

**Phase-2 Candidate? YES / NO / DEFER (to be decided)**

-------------------------------------------
2ï¸âƒ£ Admin Panel â€” Supreme Control (HIGH PRIORITY)
-------------------------------------------

**What it is:**
- Central admin dashboard for platform management
- Profile suspend / unsuspend functionality
- Soft delete profiles (no hard delete, see Platform Principles)
- Image moderation flagging system
- Abuse report handling workflow
- Mandatory reason field for all admin actions
- Comprehensive audit log of all admin actions (see Platform Principles)
- Admin action confirmation dialogs for dangerous operations
- Bulk action capabilities with safeguards

**Why it is important for Phase-2:**
- Critical for platform safety and user trust
- Legal/compliance requirements for content moderation
- Essential for handling inappropriate content or behavior
- Foundation for community guidelines enforcement
- Enables platform control and oversight
- Accountability through audit logging
- Prevents admin misuse through safeguards
- Allows efficient moderation workflows

**Risks / Concerns:**
- Liability if moderation is insufficient
- Complexity of determining what constitutes abuse
- Admin workload considerations
- Risk of admin misuse if safeguards are insufficient
- Privacy of reports vs transparency for users
- Appeals process considerations (should there be one?)
- Performance implications of audit logging
- Complexity of role-based permissions

**Open Discussion Questions:**
- Which admin actions are considered dangerous and need confirmation?
- Which actions should require dual admin approval?
- Which actions must notify users automatically?
- How to prevent and detect admin misuse?
- Should there be rate limits on admin actions?
- What information should be visible in audit logs?
- Who should have access to audit logs?
- Should there be an escalation path for complex cases?
- How long should suspensions last (temporary vs permanent)?
  - Should bulk actions require dual admin approval?
  - What is maximum bulk action size?
- Should admin actions have expiration dates (e.g., temporary suspensions)?

**Profile Field Configuration & Control (Admin-Managed):**

**Storage Decision (Captured Decision):**
- Profile field configuration WILL be stored in a DATABASE TABLE (not config files).
- Rationale:
  - Enables admin-controlled runtime changes
  - Avoids code changes and redeployments
  - Supports demo profiles, testing, and iteration
  - Aligns with Phase-2 goal of admin control
- Explicitly note that config files were considered but rejected for Phase-2 due to lack of admin flexibility.

**Scope of Admin Control (Phase-2):**
In Phase-2, Admin MAY be able to:
- Enable or disable individual profile fields
- Control field visibility (visible to users vs hidden)
- Control whether a field is searchable or display-only
- Mark fields as mandatory or optional (used by profile completeness logic)
- Define basic field type (text, number, select)

Explicitly state:
- Admin SHALL NOT be allowed to delete fields
- Admin SHALL NOT be allowed to create new database columns
- Admin control is limited to configuration, not schema mutation

**Dependent Field Logic (Limited Scope):**
- Phase-2 supports ONLY simple dependent fields
- Dependency limited to marital_status-based conditions
  Example:
    - If marital_status = divorced, show divorce_year
    - Otherwise, hide divorce_year
- Dependency rules are:
  - Single parent â†’ single child
  - Equality-based only
  - No nested, chained, or multi-condition logic

Explicitly exclude:
- Complex rule engines
- AND / OR logic builders
- Multi-level dependency graphs

**Search Integration Clarification:**
- Field "searchable" configuration will be enforced at BACKEND level
- Backend will ignore search parameters for fields marked as non-searchable

This is a configuration-level feature, not dynamic schema mutation:
- No runtime database schema changes
- No complex rule engines
- No validation graph or formula builder
- Intended to support profile completeness rules and search behavior
- Treated as a discussion-level Phase-2 candidate, not a locked requirement

**Phase-2 Candidate? YES / NO / DEFER (to be decided)**

-------------------------------------------
3ï¸âƒ£ Authentication (Backend Only)
-------------------------------------------

**What it is:**
- Token-based authentication system (conceptually similar to Sanctum)
- JSON-based login/logout responses instead of session-based
- Token lifecycle management (creation, refresh, expiration, revocation)
- Multiple device support per user (if needed)
- Token revocation capability

**Why it is important for Phase-2:**
- Mobile applications require API authentication that works across platforms
- Session-based auth is not suitable for mobile clients
- Token-based systems provide better scalability for API access
- Foundation needed before mobile app development can begin
- Essential for API security and user management

**Risks / Concerns:**
- Token security considerations (storage, transmission)
- Token refresh strategy complexity
- Migration path for existing session-based auth (if any exists)
- Performance implications of token validation
- Multiple device management complexity
- Token revocation and cleanup

**Open Discussion Questions:**
- Should we use Laravel Sanctum or a custom token implementation?
- What should be the token expiration time?
- How should token refresh be handled?
- Should tokens be revocable?
- Do we need multiple device support per user?
- What happens to tokens when password is changed?
- Should there be a maximum number of active tokens per user?
- How should token revocation be handled (immediate vs gradual)?

**Phase-2 Candidate? YES / NO / DEFER (to be decided)**

**API Versioning (Phase-2 Included):**
- Phase-2 WILL include API versioning.
- URL-based versioning will be used (/api/v1/*).
- All public API endpoints consumed by Flutter will be versioned.
- Only v1 will exist in Phase-2.
- No parallel versions, no deprecation tooling, no migration logic in Phase-2.
- Backward compatibility within v1 is mandatory (no breaking changes).

-------------------------------------------
4ï¸âƒ£ Profile Completeness & Visibility Rules
-------------------------------------------

**What it is:**
- Backend-calculated completeness percentage for matrimony profiles
- Classification of fields as mandatory vs optional
- Visibility restrictions preventing incomplete profiles from appearing in searches
- Rules governing when a profile can be viewed or contacted
- Admin override capability for visibility rules

**Completeness Calculation Logic:**
- Profile completeness SHALL be calculated at backend level.
- Completeness = (Filled mandatory fields / Total mandatory fields) Ã— 100.
- Only mandatory fields contribute to completeness in Phase-2.
- Optional fields do NOT affect completeness score.

Explicitly exclude:
- Weighted scoring
- AI-based scoring
- Field importance levels

**Visibility & Interaction Thresholds:**
- Minimum completeness required for appearing in search results: 70%.
- Minimum completeness required to send interests: 70%.
- Minimum completeness required to receive interests: 70%.
- Profile view SHALL NOT be restricted based on completeness.

**Mandatory Fields â€” Initial Phase-2 Set (Admin-Configurable):**
- Gender
- Date of Birth
- Marital Status
- Education (broad category)
- Location (city/state level)
- Single profile photo

Note:
- This list is initial and discussion-level.
- Admin Panel field configuration controls may adjust mandatory status without code changes.

**Admin Override (Controlled Scope):**
- Admin MAY temporarily override visibility for specific profiles.
- Override requires mandatory reason.
- Overrides are manual and per-profile only.
- No global disable or bulk override of completeness logic in Phase-2.

**Demo Profiles Integration:**
- Demo profiles SHALL follow the same completeness and visibility rules as real profiles.
- Demo profiles SHOULD be auto-generated with mandatory fields filled to ensure â‰¥70% completeness.
- This ensures realistic testing and interaction simulation.

**Explicit Phase-2 Exclusions:**
- No multiple thresholds per action
- No completeness badges or user-facing indicators
- No auto-reminder notifications
- No advanced completeness analytics

"Phase-2 completeness rules prioritize simplicity, predictability, and admin control. The system enforces a single, clear threshold to maintain profile quality without over-engineering."

**Why it is important for Phase-2:**
- Ensures quality of profiles visible to users
- Encourages profile completion through restrictions
- Improves user experience by showing only complete profiles
- Reduces spam and low-effort profiles in search results
- Maintains platform quality standards

**Risks / Concerns:**
- Determining which fields are truly mandatory
- Risk of being too restrictive (fewer matches) vs too lenient (low quality)
- How to handle partial profile updates
- Edge cases (profiles that are complete but still shouldn't be visible)
- Impact on existing profiles when new mandatory fields are added
- Admin override misuse potential

**Open Discussion Questions:**
- What is the minimum completeness threshold for visibility (e.g., 70%, 80%)?
- Which fields should be mandatory vs optional?
- Should there be different thresholds for different operations (search vs contact)?
- How should we handle profile updates that drop below threshold?
- Should admins be able to override visibility rules? What are the limits?
- How to handle existing profiles when new mandatory fields are added?
- Should profile completeness affect search ranking?
- Should incomplete profiles be marked visibly to admins?

**Phase-2 Candidate? YES / NO / DEFER (to be decided)**

-------------------------------------------
5ï¸âƒ£ Minimal Search & Filters (Slimmed)
-------------------------------------------

**What it is:**
- Search functionality with minimal essential filters
- Height range filter
- Marital status filter
- Education filter (high-level only, not detailed)
- Existing Phase-1 filters retained (age, caste, location)
- Backend API endpoints for filtered profile searches

**Why it is important for Phase-2:**
- Core functionality expected in matrimony platforms
- Users need basic ability to narrow down matches
- Minimal viable search for user engagement
- Foundation for future advanced search features
- Keeps Phase-2 scope manageable

**Risks / Concerns:**
- Performance implications of search queries
- Privacy considerations for sensitive filters
- How to handle "prefer not to say" options
- May be too limited for user satisfaction
- Search result ranking logic
- Pagination and result limits

**Open Discussion Questions:**
- What is the absolute minimum set of filters for usability?
- Which filters should be deferred to Phase-3?
- Should filters be saved as user preferences?
- How should we handle null/empty values in search?
- What should be the default search radius for location?
- Should there be limits on number of active filters?
- How should search results be ranked?
- Should search filters have validation rules?

**Phase-2 Candidate? YES / NO / DEFER (to be decided)**

-------------------------------------------
6ï¸âƒ£ Interest, Shortlist & Block Lifecycle
-------------------------------------------

**What it is:**
- Interest send / accept / reject / withdraw workflows
- Shortlist feature (private list of profiles user is considering)
- Hard block functionality between users
- Effects of blocking on search results and interaction history
- State management for all these interactions
- Notification foundation for interest-related actions

**Interest + Block Interaction Rules:**
- If a user blocks another user AFTER sending an interest, the interest SHALL be automatically cancelled.
- If a user blocks another user AFTER an interest has been accepted, the connection SHALL be immediately broken.
- Blocking acts as a hard stop: no search visibility, no profile views, no interests, no shortlist entries.
- Historical interaction records may remain for admin/audit visibility but are not user-accessible.

**Unblock Behavior:**
- Unblocking a user DOES NOT restore previous interests or connections.
- After unblock, a fresh interest must be sent to re-initiate interaction.
- This rule applies consistently to all users (real and demo).

**Block Scope:**
- Block removes the blocked profile from:
  - Search results
  - Profile views
  - Interest send/receive
  - Shortlists (auto-removed)
- Phase-2 does NOT include temporary blocks or scheduled block expiry.

**Shortlist Rules (Phase-2):**
- Shortlist is strictly PRIVATE.
- Only the owner can view their shortlist.
- Shortlisted users are NOT notified.
- Shortlist functionality is independent of interest lifecycle.
- No limits or analytics on shortlist in Phase-2.

**Profile View & View-Back:**
- Profile views are tracked.
- View-back functionality is supported for both real and demo profiles.
- Blocked users cannot view profiles or generate view-back events.

**Notifications (Phase-2 Scope):**
- Notifications WILL be generated for:
  - Profile view (view notification)
  - Interest sent
  - Interest received
  - Interest accepted
  - Interest rejected
- Notifications WILL NOT be generated for:
  - Block actions
  - Unblock actions
  - Shortlist actions

**Explicit Phase-2 Exclusions:**
- No block notifications
- No shortlist notifications
- No view analytics dashboards
- No interaction cooldown logic

"In Phase-2, interaction rules prioritize clarity and predictability. Blocking is immediate and absolute, shortlists remain private, and notifications are limited to views and interest-related actions only."

**Why it is important for Phase-2:**
- Core interaction features required for basic platform functionality
- Essential for user safety (block feature)
- Expected user workflows in matrimony platforms
- Foundation for future features like notifications
- Enables user engagement and interaction

**Risks / Concerns:**
- Complexity of state transitions (e.g., what if user blocks someone after interest sent?)
- Privacy implications of shortlist visibility
- How to handle block reversals (should they be allowed?)
- Notification implications for each action
- Database schema complexity for tracking states
- Edge cases in interaction lifecycle

**Open Discussion Questions:**
- Should users be able to see who shortlisted them?
- What happens to existing interests when a block is applied?
- Can blocked users still see historical interactions?
- Should interest withdrawal be time-limited?
- How many profiles can be in a shortlist?
- Should there be a cooldown period between interest actions?
- Should blocks be reversible, or permanent?
- How should blocking affect existing interests in both directions?
- Should shortlisted profiles be visible to the shortlisted user?

**Phase-2 Candidate? YES / NO / DEFER (to be decided)**

-------------------------------------------
7ï¸âƒ£ Admin Moderation & Safety Rules
-------------------------------------------

**What it is:**
- Abuse reporting system (users can report profiles/behavior)
- Temporary suspension capabilities (time-limited or permanent)
- Escalation paths for complex moderation cases
- Appeals process concept (discussion only, not necessarily Phase-2 implementation)
- Integration with admin panel (see feature #2)
- Notification system for user-impacting moderation actions

**Profile Suspension (Temporary):**
- Admin MAY suspend user profiles.
- Suspension removes profile from search and disables all interactions (views, interests, notifications).
- Suspension is MANUAL only (no auto-expiry in Phase-2).
- Mandatory reason required for suspend and unsuspend actions.
- Unsuspension restores normal profile behavior.

**Profile Deletion Policy:**
- Phase-2 allows ONLY soft delete for real user profiles.
- Hard delete of real user profiles is explicitly forbidden.
- Soft-deleted profiles are hidden from all user-facing views and interactions.
- Mandatory reason required for soft delete.
- Recovery policy is deferred to a future phase.

**Image Moderation (Simple):**
- Admin MAY approve or reject profile images.
- Rejected images are immediately hidden from user-facing views.
- Image rejection requires a mandatory reason.
- User WILL be notified when an image is rejected.

**Abuse Reporting (Minimal Workflow):**
- Any logged-in user MAY report another profile or behavior.
- Reports include free-text reason.
- Report status includes: open, resolved.
- Admin MAY take actions such as no action, profile suspension, or soft delete.
- No appeals workflow is included in Phase-2.

**Mandatory Reason Enforcement:**
- Mandatory reason is REQUIRED for:
  - Profile suspend
  - Profile unsuspend
  - Profile soft delete
  - Image rejection
  - Abuse report resolution
- Reasons are stored in admin audit logs.

**User Notifications (Phase-2 Scope):**
- Users WILL be notified for:
  - Profile suspension
  - Profile unsuspension
  - Profile soft deletion
  - Image rejection
- Users WILL NOT be notified for:
  - Internal admin actions
  - Abuse report submissions (except reporter confirmation)

**Audit Logs (Basic, Mandatory):**
- All admin actions WILL generate audit log entries.
- Audit logs include:
  - admin_id
  - action_type
  - entity_type and entity_id
  - reason
  - timestamp
  - is_demo flag (if applicable)
- No immutability, export, retention policy, or analytics in Phase-2.

**Explicit Phase-2 Exclusions:**
- No multiple admin roles
- No dual approval flows
- No bulk moderation actions
- No appeals system
- No automated moderation
- No admin analytics dashboards

"Phase-2 admin moderation prioritizes safety, clarity, and accountability using simple, manual controls. Advanced governance and automation are intentionally deferred."

**Why it is important for Phase-2:**
- Critical for platform safety and user trust
- Legal/compliance requirements for content moderation
- Essential for handling inappropriate content or behavior
- Foundation for community guidelines enforcement
- Protects users from abuse and harassment

**Risks / Concerns:**
- Liability if moderation is insufficient
- Complexity of determining what constitutes abuse
- Admin workload considerations
- Appeals process complexity (if implemented)
- Privacy of reports vs transparency for users
- False reporting concerns
- Moderation consistency across different admins

**Open Discussion Questions:**
- What should trigger an automatic suspension vs manual review?
- How long should suspensions last (temporary vs permanent)?
- Should there be time-limited suspensions with auto-expiration?
- Who can file abuse reports (any user or verified users only)?
- Should reported users be notified immediately or after review?
- Should there be an appeals process in Phase-2 or deferred?
- How to handle false reports?
- What is the escalation path for complex cases?
- Should moderation actions be transparent to users?
- How to ensure moderation consistency across admins?

**Phase-2 Candidate? YES / NO / DEFER (to be decided)**

-------------------------------------------
8ï¸âƒ£ Profile View & View-Back Behavior (Including Demo Profiles)
-------------------------------------------

**View-Back Definition:**
- View-back refers to a simulated reciprocal profile view.
- When a REAL user views a DEMO profile, the DEMO profile MAY view the REAL user's profile back.
- View-back behavior applies ONLY from demo profiles toward real profiles.

**Probability-Based Control:**
- View-back behavior is controlled by a global admin-configured probability percentage.
- Example: If probability is set to 30%, approximately 30 out of 100 eligible views may trigger a view-back.
- Probability is evaluated independently for each eligible view event.

**View-Back Action Scope:**
- A view-back results in:
  - A demo profile viewing the real user's profile
  - A stored profile view record
  - A view notification sent to the real user
- View-back does NOT trigger:
  - Interests
  - Shortlists
  - Messages or other interactions

**Frequency & Safety Limits:**
- A demo profile may perform at most one view-back toward the same real profile within a 24-hour window.
- View-back actions do not chain or recurse (no infinite loops).

**Admin Controls (Phase-2):**
- Admin MAY enable or disable view-back behavior globally.
- Admin MAY configure the probability percentage (0â€“100).
- No per-user, per-profile, or per-segment customization in Phase-2.

**Explicit Phase-2 Exclusions:**
- No AI-driven behavior
- No interest or message automation
- No analytics-based tuning
- No demographic-based probabilities

"Phase-2 view-back behavior is a controlled simulation mechanism designed to make demo profiles appear active and responsive, while remaining predictable and admin-controlled."

-------------------------------------------
9ï¸âƒ£ Notifications System (Backend Only)
-------------------------------------------

**Notification Scope:**
- Phase-2 notifications are DATABASE-STORED only.
- No push, email, or external delivery mechanisms in Phase-2.

**Notification Types (Included):**
- Profile view (including demo view-back)
- Interest sent
- Interest received
- Interest accepted
- Interest rejected
- Admin-triggered user-facing actions:
  - Profile suspended
  - Profile unsuspended
  - Profile soft deleted
  - Image rejected

**Notification Types (Excluded):**
- Block / unblock actions
- Shortlist actions
- Internal admin/audit actions
- Abuse report internal workflow events

**Data Model (Conceptual):**
- Notifications include:
  - user_id (recipient)
  - type
  - entity_type and entity_id
  - message (simple, server-generated)
  - is_read flag
  - created_at timestamp
  - is_demo_related flag (derived)

**Read / Unread Behavior:**
- Notifications are unread by default.
- Users MAY mark notifications as read (single or all).
- Notifications are auto-marked read when opened.

**Retention Policy:**
- Notifications are retained for 90 days.
- Older notifications may be cleaned up automatically.
- No user-controlled retention or export in Phase-2.

**User Controls:**
- Users CANNOT delete notifications in Phase-2.
- No notification preference or opt-out system.

**Admin Visibility:**
- Admin MAY view notifications for a specific user for debugging or dispute resolution.
- Admin CANNOT modify, delete, or resend notifications.

**Demo Profile Integration:**
- Demo profile actions generate notifications using the same rules.
- Demo-related notifications are internally identifiable via is_demo_related flag.

"Phase-2 notifications prioritize clarity and traceability using a simple database-backed system. Delivery optimizations and user preferences are intentionally deferred."

============================================================
DEFERRED TO PHASE-3 (NOT PHASE-2)
============================================================

âš ï¸ Discussion acknowledged, not ignored
âš ï¸ These features may be important but are explicitly deferred

The following features are explicitly marked as DEFERRED to Phase-3 or later.
This acknowledges they are important but beyond Phase-2 scope.

**Full Advanced Search & Ranking:**
- Complex filter combinations
- Search result ranking algorithms
- Saved search preferences
- Search analytics

**Admin Analytics Dashboards:**
- Complex analytics and visualization
- Predictive analytics
- User behavior analysis
- Engagement trend analysis
- Advanced reporting tools

**AI Readiness & ML Data Pipelines:**
- AI-based matching algorithms
- Machine learning model integration
- Personality-based matching
- Behavioral pattern analysis
- Predictive matching systems

**API Versioning Infrastructure:**
- Full API versioning system (/api/v1/, /api/v2/)
- Version deprecation policies
- Legacy API support
- Version migration tooling

**Chat / Messaging:**
- In-app messaging system
- Real-time communication (WebSockets)
- Message history
- File sharing in messages
- Video/voice calling

**Payments & Monetization:**
- Payment processing
- Subscription models
- Premium features
- Payment gateway integration
- Billing and invoicing

**Advanced Profile Features:**
- Profile verification badges
- Multi-level verification system
- Photo verification workflows
- Document verification
- Phone verification (SMS OTP)

**Chat / Messaging:**
- In-app messaging system
- Real-time communication (WebSockets)
- Message history
- File sharing in messages
- Video/voice calling

**Note:** Some of these deferred features may have foundational work in Phase-2
(e.g., data collection structures for future AI), but the full feature implementation
is deferred to Phase-3 or later.

============================================================
DISCUSSION SUMMARY
============================================================

This revised feature set prioritizes:
1. **Admin Control** â€” Supreme control through admin panel
2. **Safety & Moderation** â€” Comprehensive safety controls and moderation
3. **Demo Profiles** â€” Testing and simulation capabilities

All features above require discussion and finalization before implementation.

**Next Discussion Steps:**
- Review each feature individually
- Fill in "Phase-2 Candidate?" decisions
- Answer open discussion questions
- Prioritize features if needed
- Finalize Phase-2 scope
- Create implementation plan after decisions are made

============================================================
Phase-2 â€” Rough Day-wise Execution Plan (Indicative)
============================================================

âš ï¸ NON-BINDING, DISCUSSION-LEVEL PLAN ONLY âš ï¸
âš ï¸ This plan is indicative only, order and duration may change âš ï¸
âš ï¸ This is NOT an implementation commitment âš ï¸
âš ï¸ Used only for discussion and feasibility validation âš ï¸

- Day 0: Scope finalization, decisions, SSOT preparation
- Day 1â€“2: Authentication (token-based)
- Day 3â€“4: Admin Panel (basic moderation + audit logs)
- Day 5â€“6: Admin Profile Field Configuration
- Day 7â€“8: Demo Profiles with photo + interactions
- Day 9â€“10: Interest, Block, Shortlist completion
- Day 11â€“12: Profile completeness & visibility rules
- Day 13: Minimal advanced search filters
- Day 14: Integration testing & edge cases
- Day 15: Freeze, review, Phase-2 closure

============================================================
PHASE-2 SCOPE CLARIFICATIONS (ADDED 2026-01-21)
============================================================

**ADMIN ROLES & PERMISSIONS:**
- Multi-level admin roles (Super Admin / Moderator / Read-only) are NOT part of Phase-2.
- Phase-2 will use a single Admin role with basic permissions.
- Role hierarchy is deferred to Phase-3 or later.

**AUDIT LOGGING SCOPE:**
- Phase-2 requires ONLY basic audit logs (admin_id, action, entity, reason, timestamp).
- Immutable audit logs, long-term retention, checksums, exports, and compliance-grade features are OUT OF SCOPE for Phase-2.

**ADMIN SAFEGUARDS:**
- Mandatory reason fields and user notifications are required.
- Impact preview systems, cascade calculations, and bulk-operation preview engines are NOT required in Phase-2.

**DEMO PROFILES SYSTEM:**
- Demo profiles ARE part of Phase-2 implementation with basic photo support.
- Demo profiles include generic/placeholder photos for testing realism.
- Demo profiles follow same single-photo upload/display flow as real profiles.
- No advanced demo photo systems, analytics, or separate moderation pipelines.

**OUT OF SCOPE FOR PHASE-2:**
- Configuration versioning, rollback engines, preview-as-user simulation, and emergency admin governance systems are OUT OF SCOPE for Phase-2.

"Phase-2 prioritizes safety, clarity, and minimal viable control. Advanced governance, simulation, and compliance systems are intentionally deferred to avoid over-engineering."

"Phase-2 intentionally focuses on admin control, demo realism, and profile quality. Advanced governance, analytics, AI, and monetization features remain out of scope."

============================================================
END OF PHASE-2 REFERENCE BLUEPRINT
============================================================