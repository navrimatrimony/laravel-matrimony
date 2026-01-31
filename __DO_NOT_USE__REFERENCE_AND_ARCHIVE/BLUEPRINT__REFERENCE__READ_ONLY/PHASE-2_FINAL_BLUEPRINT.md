============================================================
📋 PHASE-2 FINAL BLUEPRINT — DECISIONED & FROZEN
============================================================

⚠️ IMPORTANT: THIS IS A FINALIZED BLUEPRINT FOR PHASE-2 DECISIONS
⚠️ NO NEW FEATURES WILL BE ADDED HERE
⚠️ THIS DOCUMENT IS NOT SSOT
⚠️ IMPLEMENTATION MUST RELY ON A SEPARATE PHASE-2 SSOT FILE

"This document records FINAL Phase-2 decisions. It is a decision reference, NOT the execution SSOT."

============================================================
📌 CONTEXT (VERIFIED FACTS)
============================================================

✅ Phase-1 MVP Status:
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

✅ Current Architecture:
- User model = authentication only
- MatrimonyProfile model = biodata source
- Interest model = profile-to-profile connections

============================================================
A. Phase-2 INCLUDED FEATURES (FINAL)
============================================================

**1️⃣ Demo / Testing Profiles & Simulation**
- System-generated demo profiles with internal `is_demo = true` flag
- Admin-controlled bulk creation (1–50 profiles per action)
- Demo profiles with single generic/placeholder photos
- Demo ↔ Real interactions allowed (view, interest, accept, reject, block, shortlist)
- View-back behavior with admin-configured probability (0–100%)
- 24-hour frequency limits per demo-real pair
- Global admin toggle for search visibility
- Same completeness and visibility rules as real profiles

**2️⃣ Admin Panel — Supreme Control**
- Profile suspend/unsuspend with mandatory reasons
- Soft delete only (no hard delete for real users)
- Image moderation (approve/reject) with mandatory reasons
- Abuse reporting system (open/resolved status)
- Mandatory reason enforcement for all admin actions
- User notifications for: suspension, unsuspension, soft deletion, image rejection
- Audit logs: admin_id, action_type, entity details, reason, timestamp, is_demo flag

**3️⃣ Authentication (Backend Only)**
- Token-based authentication (Laravel Sanctum)
- JSON-based login/logout responses
- API versioning (/api/v1/*) with backward compatibility within v1
- Token lifecycle management (creation, refresh, expiration, revocation)

**4️⃣ Profile Completeness & Visibility Rules**
- Completeness = (filled mandatory fields / total mandatory fields) × 100
- Mandatory fields: Gender, DOB, Marital Status, Education, Location, Photo
- 70% threshold for search results and interest send/receive
- Admin override capability (per-profile, with reasons)
- Admin-configurable field settings via database table
- Simple dependent field logic (marital_status-based only)

**5️⃣ Minimal Search & Filters**
- Essential filters: age, caste, location, height, marital status, education
- Backend enforcement of field "searchable" configuration
- Pagination and result limits

**6️⃣ Interest, Shortlist & Block Lifecycle**
- Interest send/accept/reject/withdraw workflows
- Private shortlists (owner-only visibility)
- Hard blocking (removes from search, views, interests, shortlists)
- Block cancels existing interests and breaks accepted connections
- Unblock requires fresh interest to re-initiate
- No block notifications, no shortlist notifications

**7️⃣ Admin Moderation & Safety Rules**
- Manual profile suspensions (no auto-expiry)
- Soft delete policy for real user profiles
- Image rejection with immediate hiding
- Abuse reporting workflow (open → resolved)
- Mandatory reason fields for all admin actions
- User notifications for moderation actions
- Basic audit logging (no advanced features)

**8️⃣ Profile View & View-Back Behavior**
- Profile view tracking for real and demo profiles
- View-back: demo profiles may view real profiles back (probability-controlled)
- 24-hour limits, no chaining/recursion
- Admin controls: global enable/disable, probability configuration

**9️⃣ Notifications System (Backend Only)**
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
PHASE-2 BLUEPRINT STATUS
============================================================

- Status: FINAL & FROZEN
- Scope: Phase-2 only
- Fixed Constants:
  - Profile completeness threshold: 70%
  - Demo bulk creation limit: 1–50 profiles per action
  - Notification retention: 90 days
  - View-back frequency limit: 24 hours per demo-real pair
  - API version: v1 (with backward compatibility)

- Modification rule:
  - Any change requires updating Phase-2 SSOT
  - Blueprint changes are allowed ONLY for documentation clarity, not scope change

- Next Step:
  - Create a separate PHASE-2_SSOT.md file
  - Implementation must strictly follow SSOT