# Phase 5 Completeness Points

**Goal:** Production-grade matrimony website, shaadi.com-style.  
**Rule:** One point at a time — discuss → finalize → implement. No code changes until agreed.

---

## Priority list (summary)

| Priority | What to implement |
|----------|-------------------|
| **P0** | Registration → wizard redirect; User intake history page; User conflict diff view; Marriages = status-based partials (no JS toggle); create/store disallowed enforcement |
| **P1** | Unlock policy + Unlock confirmation UI; Contact request / Interest accept flow; Profile search filters complete; Photo verification flow explicit |
| **P2** | Profile completeness in search; Partner expectation in search; Report/abuse flow production-ready; Public profile visibility rules |
| **P3** | Dashboard polish; Registration verification; "Who viewed" type features; Performance |

---

## Point No 1 — Registration redirect & profile fields (shaadi.com-aligned)

### 1.1 Intent

- **Registration redirect:** After a user registers, they must be sent to **profile creation (wizard)** — not dashboard or home. SSOT says: *"Registration must redirect to: `/matrimony/profile/wizard/basic-info`"*. So the first screen after sign-up should be the wizard’s first step (Basic info).
- **Profile fields = shaadi.com windows:** The fields we show in each wizard section should match **what shaadi.com shows in each step/window**. UI should feel production-grade and familiar to users of shaadi.com.

### 1.2 Shaadi.com flow (reference)

From their public flow:

1. **Registration / Step 1 — Primary details**
   - Name (first + last)
   - Date of birth
   - Gender (Bride/Groom)
   - Religion, caste (and often sub-caste)
   - Email, mobile number

2. **Step 2 — Other information**
   - Education, profession, income
   - Location (current city, hometown) — **shaadi.com uses the same kind of location engine as ours (country → state → district → taluka → village). Our single-field location engine is aligned; no change needed.**
   - Height, weight, body type
   - Languages, diet, smoking/drinking (lifestyle)

3. **Step 3 — Photos**
   - Multiple photos (e.g. 2–4), with guidelines

4. **Step 4 — Mobile verification**
   - OTP / verify mobile

5. **Step 5 — Partner preference**
   - Age, height, location, education, etc.

Additional sections they expose in “Complete profile” / “Edit profile”:

- **About me / Family:** Family details, what you’re looking for, values.
- **Partner preferences:** Stored and used in search.

### 1.3 Our wizard vs shaadi.com (mapping)

We already have section-based wizard. Alignment:

| Our section | Shaadi.com equivalent | Fields we must have (shaadi-aligned) |
|-------------|------------------------|--------------------------------------|
| **Basic info** | Step 1 primary | Full name, DOB, gender, religion, caste, sub-caste, marital status, height, primary contact (mobile). Birth time, birth place optional. Physical: weight, complexion, build, blood group. |
| **Marriages** | Post-basic (if divorced/widowed) | Marriage/divorce/widow details — status-based partials only. |
| **Personal & family** | Step 2 part | Education, occupation, company, annual/family income, currency. Father/mother name & occupation. Brothers/sisters count. Family type. |
| **Siblings** | Extended family | Optional sibling rows (gender, marital status, occupation, city, notes). |
| **Relatives** | Extended family / About | Relatives/relations (name, relation, occupation, contact, notes). |
| **Alliance** | Community / native | Family surnames + native locations. |
| **Location** | Step 2 location | Current residence (city/area), address line. Work city. Native place. Optional: multiple addresses (village/area). |
| **Property** | Lifestyle / assets | Property summary (house, flat, agriculture). Optional assets. |
| **Horoscope** | Horoscope | Rashi, nakshatra, charan, gan, nadi, yoni, mangal dosh, devak, kul, gotra. |
| **Legal** | Optional | Legal cases (type, court, status, etc.). |
| **Contacts** | Step 1 + Step 2 | Primary + additional contacts (number, type, relation). |
| **About & preferences** | About me + Partner preference | About me, expectations, notes. Partner preference: city, caste, age min/max, income min/max, education. |
| **Photo** | Step 3 | Profile photo upload (production-grade: crop/guidelines later if needed). |

So for **Point 1** we are not inventing new features; we are:

1. **Redirect:** Ensuring that immediately after registration the user lands on **`/matrimony/profile/wizard/basic-info`** (or first incomplete section) — no dashboard first.
2. **Fields:** Keeping the same sections as above and ensuring each section contains the **same logical fields** as shaadi.com’s corresponding window (as in the table). No removal of existing wizard sections; only order/labels/required can be tuned to match shaadi.com UX.
3. **UI:** Making the wizard look production-grade (clear headings, spacing, validation messages, mobile-friendly). No full redesign — polish and consistency.

### 1.4 Implementation scope (Point 1 only)

- **Registration redirect**
  - In auth flow (e.g. `RegisteredUserController` or equivalent post-registration hook): after successful registration, redirect to `route('matrimony.profile.wizard.section', ['section' => 'basic-info'])` (or to first incomplete section if we add that logic). No new routes; only redirect target.
- **Profile fields**
  - Audit wizard sections against the table above; add any **missing** fields that shaadi.com has in that step (additive only). Do not remove or rename existing DB-backed fields. If a field exists in DB but not in UI, add it to the right section.
- **UI**
  - Same layout and sections; improve labels, placeholders, and section headings so they match “Basic info”, “Personal & family”, “Location”, “Partner preference”, etc. in a shaadi.com-like way. Ensure required fields are marked and validation messages are clear.

### 1.5 Constraints (unchanged)

- PHASE-5: No schema changes unless SSOT says so. No change to MutationService, ConflictDetectionService, lifecycle, or ProfileCompletionService (except possibly adding a new section with weight 0 if needed).
- create/store routes: Either remove or redirect to wizard; no duplicate create profile UI.
- All changes additive; no deletion of existing wizard sections or fields.

### 1.6 Point 1 — Implemented

- **Redirect:** After registration, if user has no matrimony profile, redirect is now to `matrimony.profile.wizard.section` with `section=basic-info` (first step). No dashboard in between. (File: `App\Http\Controllers\Auth\RegisteredUserController.php`.)
- **Fields:** Wizard sections aligned to shaadi.com: (1) **Basic info** = Step 1 only: Full name, DOB, Gender, Religion, Caste, Marital status, Height, Primary contact (mobile). Dependent blocks: Marriages & Children (divorce/widowed/separated) same format. Birth time, birth place, serious intent, weight, complexion, physical build, blood group removed from UI; values preserved in snapshot from profile. (2) **Personal & family** = Step 2: Education, profession, income, family, plus Weight and Body type (moved from basic). (3) **Location** = our existing engine (same as shaadi.com per user finding). No schema change; no removal of wizard sections.
- **Copyright:** All labels and copy are our own; no verbatim text from any third-party site.

### 1.7 आपल्याकडे आहेत पण shaadi.com वर नाहीत (काढायची चर्चा साठी)

Reference: shaadi.com चा public flow फक्त Steps 1–5 + “About me / Family” आणि “Partner preferences” सांगतो. खालील आपल्याकडे आहेत, पण त्यांच्या त्या flow मध्ये स्पष्ट नाहीत. म्हणून “चुकीची माहिती भरून घेतो” या दृष्टीने **कोणती फील्ड/सेक्शन काढायची आणि कोणती ठेवायची** हे ठरवण्यासाठी यादी.

---

#### A) पूर्ण सेक्शन्स (wizard steps) — आपल्याकडे dedicated step, shaadi.com वर असे वेगळे step नाही

| आपला section | Shaadi.com वर | Dependency (form/backend) |
|--------------|----------------|---------------------------|
| **Horoscope** | त्यांच्या main flow मध्ये full Vedic horoscope (rashi, nakshatra, gan, nadi, yoni, mangal dosh, devak, kul, gotra) स्पष्ट नाही; काही sites “Manglik” किंवा basic star दाखवतात | `buildSectionSnapshot(horoscope)`, `profile_horoscope_data` table, ManualSnapshotBuilderService मध्ये `horoscope` key. Section weight = 0. |
| **Legal** | Legal cases (type, court, status) त्यांच्या public flow मध्ये नाही | `buildSectionSnapshot(legal)`, `profile_legal_cases` table, snapshot मध्ये `legal_cases`. Section weight = 0. |
| **Property** | Property summary + assets त्यांच्या steps मध्ये “Lifestyle” सारखं vague; dedicated property step नाही | `buildSectionSnapshot(property)`, `profile_property_summary`, `profile_property_assets`, snapshot मध्ये `property_summary`, `property_assets`. Section weight = 0. |
| **Siblings** | “Family details” vague; आपल्यासारखी sibling-by-row (gender, marital, occupation, city, notes) table नाही | `buildSectionSnapshot(siblings)`, `profile_siblings` table, snapshot मध्ये `siblings`. Section weight = 0. |
| **Relatives** | Relatives ची table/rows त्यांच्या flow मध्ये स्पष्ट नाही | `buildSectionSnapshot(relatives)`, `profile_relatives` table, snapshot मध्ये `relatives`. Section weight = 0. |
| **Alliance** | Family surnames + native (alliance networks) — त्यांच्याकडे “native” location मध्ये असू शकतं; वेगळा alliance section नाही | `buildSectionSnapshot(alliance)`, `profile_alliance_networks` table, snapshot मध्ये `alliance_networks`. Section weight = 0. |

**निर्णय साठी:** वरील कोणता section **काढायचा** (UI मधून hide / optional करायचा) आणि कोणता **ठेवायचा** हे तुम्ही ठरवाल. Dependency म्हणजे: जर section काढला तर route, SECTIONS array, buildSectionSnapshot आणि MutationService/ManualSnapshotBuilderService मधील त्या key चा हाताळणी बदलावी लागेल (किंवा optional/empty मानून ठेवावी लागेल).

---

#### B) Individual फील्ड्स (profile/wizard) — आपल्याकडे आहेत, shaadi.com च्या लिखित flow मध्ये नाहीत

| आपली field (किंवा विषय) | Shaadi.com वर | कोठे वापर (आपल्या form मध्ये) |
|---------------------------|----------------|----------------------------------|
| **birth_time** | Step 1 मध्ये स्पष्ट नाही | Basic info (optional) |
| **birth_place** (birth_city_id, birth_taluka_id, birth_district_id, birth_state_id) | जन्मस्थान असं वेगळं स्पष्ट नाही | Basic info (optional) |
| **complexion_id** | “Body type” आहे; complexion वेगळं स्पष्ट नाही | Basic info |
| **blood_group_id** | Main flow मध्ये नाही | Basic info |
| **serious_intent_id** | स्पष्ट नाही | (असल्यास basic/intent related) |
| **taluka_id, district_id** (location) | “Current city, hometown” — तलुका/जिल्हा granular नाही | Location |
| **Marriages + Children** (विभाग) | Divorce/widow details काही sites वर असतात; आपल्यासारखी status-based partials + children rows कदाचित नाहीत | Basic info / Marriages section |
| **Education history** (rows), **Career history** (rows) | Education, profession, income — साधं; rows/degree-wise नाही | Personal & family |
| **Children** (rows: name, gender, age, lives_with) | “Family details” vague | Personal & family |

वरील पैकी कोणती फील्ड **काढायची** (किंवा optional/हलकी करायची) आणि कोणती **ठेवायची** हे ठरवता येईल. Form dependency: जर एखादी फील्ड काढली तर validation आणि snapshot मधील ती key optional राहिली पाहिजे किंवा SSOT नुसार बदल.

---

#### C) Admin/Internal फील्ड्स (user माहिती भरत नाही — यांची काढणी चर्चेचा विषय नाही)

- `edited_by`, `edited_at`, `edit_reason`, `edited_source`, `admin_edited_fields`, `visibility_override`, `profile_visibility_mode`, `contact_unlock_mode`, `safety_defaults_applied`, `photo_approved`, `photo_rejected_at`, `is_suspended`, `is_demo` — हे backend/admin/SSOT साठी आहेत; user “चुकीची माहिती” म्हणून भरत नाही.

---

**पुढची चर्चा:** A मधील कोणते section ठेवायचे / optional करायचे / hide करायचे; B मधील कोणती individual फील्ड ठेवायची / काढायची. Dependency असल्यास तशाच आपल्याला हवी (म्हणजे ज्या form मध्ये ती अवलंबनात आहे तिथे ती राहिली पाहिजे); त्याबद्दल विचार करायचा असेल तर तो पुढच्या पॉईंट वर करू.

---

## Point No 2 — User intake history page (P0)

- **What:** A dedicated user-facing page listing all their biodata intakes (uploaded, parsed, approved, applied) with status and link to preview/status.
- **Where:** e.g. `/intake` or `/my-intakes` (list). From dashboard, link “My biodata uploads” or “Intake history”.
- **Detail:** To be finalized in discussion before implementation.

### Point 2 — Implemented

- **Route:** `GET /intake` → `intake.index` (IntakeController::index). Lists only current user's intakes (`uploaded_by = auth()->id()`), ordered by `created_at` desc.
- **View:** `resources/views/intake/index.blade.php` — title "My biodata uploads", description, "Upload new biodata" button, table (Uploaded date, File/Source, Parse status, Approved, Actions). Actions: "Status" (always), "Preview & approve" (when parsed and not approved). Empty state with CTA to upload. Back to Dashboard link when list is non-empty.
- **Authorization:** preview, status, approve methods now abort(403) if `intake->uploaded_by !== auth()->id()`.
- **Dashboard:** Quick Actions includes "My biodata uploads" card linking to `intake.index`. No-profile block includes "My biodata uploads" link alongside "Create Matrimony Profile".
- **Main navigation (top menu):** "My biodata uploads" added in `layouts/navigation.blade.php` (desktop and responsive), before "Upload Biodata", so user always sees the link to intake history from any page.

### User intake vs Admin intake (no confusion)

| | **User intake** | **Admin intake** |
|--|------------------|-------------------|
| **Controller** | `App\Http\Controllers\IntakeController` | `App\Http\Controllers\Admin\AdminIntakeController` |
| **Routes** | `auth` middleware, `/intake`, `/intake/upload`, `/intake/preview/{id}`, `/intake/status/{id}`, etc. | `auth` + `admin`, `/admin/biodata-intakes`, `/admin/biodata-intakes/{id}`, attach | 
| **Views** | `resources/views/intake/*` (index, upload, preview, status, approval) | `resources/views/admin/intake/*` (index, show) |
| **Purpose** | User sees own uploads, preview, approve, status | Admin lists all intakes, show detail, attach to profile |

---

## Point No 3 — User conflict diff view (P0)

- **What:** When profile is in conflict, user sees a clear “what changed” / diff view (e.g. old vs new value), not only “contact support”.
- **Where:** Profile or intake/conflict page.
- **Detail:** To be finalized in discussion before implementation.

### Point 3 — Implemented

- **Where:** User’s own profile show page (`matrimony.profile.show`). When `lifecycle_state === conflict_pending` and `hasBlockingConflicts`, the alert now includes a **"What changed"** section.
- **Data:** Controller loads PENDING `ConflictRecord`s for the profile only when owner views own profile and state is conflict_pending; passed as `conflictRecords`.
- **UI:** For each conflict: field name (humanized), Current value (old_value, red box), Proposed value (new_value, green box). Read-only. Message: "Below is what changed (current vs proposed). Admin will resolve; you can contact support if needed."

---

## Point No 4 — Marriages status-based partials (P0)

- **What:** Replace single marriages template + JS show/hide with status-based partials (e.g. divorced, widowed, separated, married) and server-side render. No generic JS toggling per SSOT.
- **Detail:** To be finalized in discussion before implementation.

### Point 4 — Implemented

- **Partials:** `resources/views/matrimony/profile/wizard/sections/marriage_partials/` — `marriages_married.blade.php`, `marriages_divorced.blade.php`, `marriages_widowed.blade.php`, `marriages_separated.blade.php`. Each has only the fields for that status (server-side; no JS show/hide of internal divs).
- **Initial load:** basic_info computes `$currentMarriageStatusKey` from profile/old; includes the matching partial in `#marriage-fields-inner`. Marriage container and children section visibility set server-side from that key.
- **Dropdown change:** On marital status change, JS fetches `GET /matrimony/profile/wizard/marriage-fields?status=<key>`. Controller returns only the partial HTML for that status; JS sets `marriage-fields-inner.innerHTML` to the response. So “dropdown badalala tar value hi” — the selected status drives which partial is shown; HTML comes from server.
- **Route:** `matrimony.profile.wizard.marriage-fields`. No generic JS toggling of same-page divs; content is server-rendered per status.

---

## Point No 5 — create/store routes disallowed (P0)

- **What:** Enforce SSOT: no direct use of `matrimony.profile.create` / `matrimony.profile.store`. Either remove routes or redirect to wizard. Single editing path = wizard only.
- **Detail:** To be finalized in discussion before implementation.

### Point 5 — Implemented

- **create/store deleted as actions:** `GET /matrimony/profile/create` and `POST /matrimony/profile/store` no longer call MatrimonyProfileController. Both redirect to wizard: `matrimony.profile.wizard.section` with `section=basic-info`. POST also shows info: "Please use the profile wizard to create or update your profile."
- **Route names kept** so existing `route('matrimony.profile.create')` / `route('matrimony.profile.store')` links do not break; they just redirect. Single editing path = wizard only; no confusion.

---

## P1 / P2 / P3 points (short)

- **P1:** Unlock confirmation UI; Interest/contact request flow; Search filters; Photo verification flow.
- **P2:** Profile completeness in search; Partner expectation in search; Report/abuse production-ready; Visibility rules.
- **P3:** Dashboard polish; Registration verification (email/OTP); “Who viewed”; Performance.

### P1 — Unlock policy + Unlock confirmation UI (implemented)

- **Unlock policy:** On profile show, when viewing another profile and contact is not visible, a small policy box is shown under Contact Information: "Contact policy: Contact number is shared only after the other person accepts your interest. We do not reveal contact without mutual interest."
- **Unlock confirmation:** "Send Interest" button no longer submits directly. On click, a modal opens with: "If they accept your interest, their contact number will be revealed to you. Contact is shared only after mutual interest (our contact policy)." Buttons: [Cancel] [Yes, send interest]. On "Yes, send interest" the form is submitted. File: `resources/views/matrimony/profile/show.blade.php`.

### P1 — Interest flow verified + fixes (no "Request contact" step for now)

- **Flow verified:** Send interest → Sent list; Receiver sees in Received → Accept/Reject; Accept → `profile_contact_visibility` + `contact_access_log` created (when receiver `contact_unlock_mode === 'after_interest_accepted'`), sender gets `InterestAcceptedNotification`; Contact visibility on profile show via `ContactVisibilityPolicyService::canViewContact`. Withdraw = sender cancels pending interest (delete); routes use `{interest}` model binding (accept/reject/withdraw).
- **Fixes applied:**
  1. **Block guards in send interest:** If receiver has blocked sender → "You cannot send interest to this profile." (no reveal). If sender has blocked receiver → "You have blocked this profile. Unblock to send interest." (`InterestController::store` + `Block` model).
  2. **Gender placeholder bug:** Sent/Received/Dashboard used `$profile->gender` (relation object) and compared to `'male'/'female'`. Fixed to `$profile->gender?->key` in `resources/views/interests/sent.blade.php`, `received.blade.php`, `dashboard.blade.php`.
  3. **Eager load gender:** `Interest::with('receiverProfile.gender')` and `senderProfile.gender` in `InterestController::sent/received` and `DashboardController` so placeholder logic has gender loaded.
- **Request contact:** Separate "Request contact" step deferred; user will decide later. Current flow = Send Interest only.

### P1 — Photo verification flow (implemented)

- **Flow:** Upload (wizard photo section or profile upload) → if `photo_approval_required` admin setting is ON, `photo_approved` = false. User sees "Your photo is under review" on dashboard and own profile show. Admin sees uploaded photo on profile with "Photo pending review" badge; Approve / Reject (reason required, audit log). Reject → user gets `ImageRejectedNotification`, sees rejection reason on profile. Approve → `photo_approved` = true, photo visible everywhere.
- **Changes:** Dashboard + own profile show show pending notice when `profile_photo` set and `photo_approved === false` and `photo_rejected_at` null. Wizard photo section: hint "If photo verification is enabled, your photo will be visible to others after admin approval." Admin profile show: always show uploaded image when present; badge "Photo pending review" when pending.
- **Admin panel option:** Admin → Settings → **Photo approval**. Toggle: "Approval not required" (default ON) = photos visible immediately; "Photo approval required" = new uploads hidden until admin approves. Admin can approve/reject from profile show. Default = approval not required.
- **Manual test (खालील steps follow करून verify करा):**
  1. **Photo approval OFF (default):** Admin → Settings → Photo approval → ensure "Approval NOT required" (toggle OFF). Or `php artisan tinker` → `\App\Models\AdminSetting::setValue('photo_approval_required', '0');`. Login as user → Wizard Photo section किंवा profile photo upload → upload image → save. Dashboard व profile show वर ताबडतोब photo दिसावं; "under review" message नसावं.
  2. **Photo approval ON:** Admin → Settings → Photo approval → turn ON "Photo approval REQUIRED". Or `AdminSetting::setValue('photo_approval_required', '1');`. Same user (किंवा नवा user) login → photo upload → save. Dashboard वर placeholder + "Your photo is under review." दिसावं. Own profile show वर uploaded photo + "Your photo is under review. It is not visible to others until approved."
  3. **Admin review:** Admin login → Profiles → एक profile open ज्याचा photo pending आहे. Uploaded photo दिसावं आणि "Photo pending review" badge दिसावं. Approve Image → reason (min 10 chars) → Submit. Profile वरून badge गायब व photo approved. User च्या dashboard वर आता photo दिसावं, under review message नसावं.
  4. **Reject:** दुसऱ्या profile वर photo upload (approval ON) → Admin → Reject Image → reason → Submit. User च्या profile show वर "Your profile photo was removed by admin" + reason दिसावं; photo placeholder.
  5. **Reset (optional):** `AdminSetting::setValue('photo_approval_required', '0');` so default for next tests.

### P3 — Mobile OTP verification (implemented)

- **Flow:** Logged-in user → Dashboard → **Verify mobile** (Quick Action) → `/mobile-verify`. Enter mobile (10-digit) → **Send OTP** → OTP screen वर दिसतो (dev mode). Enter 6-digit OTP → **Verify** → `mobile_verified_at` set, redirect to dashboard. Admin setting: `mobile_verification_mode` = `off` | `dev_show` | `live` (off = page redirects; dev_show = OTP on screen; live = future SMS API).
- **Manual test:**
  1. Setting चालू करा: `php artisan tinker` → `\App\Models\AdminSetting::setValue('mobile_verification_mode', 'dev_show');` (किंवा `php artisan db:seed --class=AdminSettingSeeder`).
  2. Login करा (उदा. manualtest@example.com).
  3. Dashboard → **Verify mobile** (Quick Actions मध्ये) क्लिक करा.
  4. Mobile number टाका (10 digits) → **Send OTP** क्लिक करा.
  5. पुढच्या पेजवर पिवळ्या बॉक्समध्ये **"For testing, your OTP is: XXXXXX"** दिसावं.
  6. तो 6-digit OTP खालच्या "Enter 6-digit OTP" मध्ये टाका → **Verify** क्लिक करा.
  7. "Mobile number verified successfully." नंतर dashboard वर यावं. (Skip for now ने OTP न टाकता dashboard ला जाऊ शकतो.)
  8. Verification बंद करण्यासाठी: `AdminSetting::setValue('mobile_verification_mode', 'off');` — मग Verify mobile लिंकवर जाल तर "Mobile verification is currently disabled." दिसेल.

### P3 — Registration flow: mobile required + OTP step (implemented)

- **Registration:** Mobile number is **required** (10 digits). User registers with name, email, mobile, password. Stored as digits-only.
- **Post-registration redirect (admin-controlled):** If admin setting **Redirect to OTP step after registration** is ON and **Mobile verification mode** is not `off`, user is sent to `/mobile-verify` (OTP page) instead of the wizard.
- **OTP page (when from registration):** Shows "Step 1 of 2: Verify your mobile". User can **Verify** (OTP → then redirect to wizard) or **Skip / Verify later** (redirect to wizard without verifying). Either way, user can use the wizard; mobile verification is encouraged but not blocking.
- **After verify or skip:** Redirect to profile wizard (basic-info). Session keys `intended_after_verify` and `from_registration` control this.
- **Admin setting (mandatory):** Admin → Settings → **Registration & mobile verification**. (1) **Redirect to OTP step after registration** — ON = show OTP page after sign-up (with skip); OFF = go straight to wizard. (2) **Mobile verification mode** — off | dev_show | live. Default: redirect ON, mode dev_show. Email verification is de-emphasised; mobile is primary.
- **Files:** `RegisteredUserController` (mobile required, redirect logic), `MobileOtpController` (show/skip/verify, intended URL), `auth/register.blade.php` (mobile required), `auth/mobile-verify.blade.php` (Skip/Verify later section), `admin/mobile-verification-settings/index.blade.php`, routes, admin nav.

---

### P1 — Profile search filters complete (चर्चेसाठी — savistar)

**उद्देश:** Search/browse page production-grade, shaadi.com-style filters; UI आणि backend एकमेकाशी जुळलेले; जे filters आहेत ते काम करतात आणि जे matrimony search मध्ये अपेक्षित आहेत ते add करता येतील.

**आत्ताची स्थिती (current state):**

1. **Backend (`MatrimonyProfileController::index`):**
   - Filters: `caste_id`, `city_id` (location), `age_from` / `age_to`, `height_from` / `height_to`, `marital_status` (key) or `marital_status_id`, `education` (highest_education text). सगळे **enabled + searchable** (ProfileFieldConfigurationService) अंतर्गत; जर field searchable नसेल तर filter लागू होत नाही.
   - Lifecycle: फक्त active, non-suspended; 70% completeness (sqlSearchVisible); blocked exclude; demo_profiles_visible_in_search admin toggle.
   - View ला pass होतं: `profiles`, `cities` (सगळे cities).

2. **UI (`resources/views/matrimony/profile/index.blade.php`):**
   - Form मध्ये: Age From/To, **Caste (text input)**, **Location (text input)**, Height From/To, Marital Status (dropdown: single/divorced/widowed), Education (text), Per page.
   - **Mismatch:** Backend `caste_id` आणि `city_id` expect करतो; form मध्ये `caste` आणि `location` (free text) पाठवतं — म्हणून **Caste आणि Location filters प्रत्यक्षात लागू होत नाहीत**.
   - `$cities` view ला दिलेलं आहे पण form मध्ये city dropdown नाही.
   - **Gender filter अजिबात नाही** — backend मध्येही नाही. Matrimony मध्ये साधारणतः “Brides” / “Grooms” किंवा “Looking for: Male/Female” filter असतो; तो येथे add करायचा का हे ठरवणं.

3. **List card (search results):**
   - Display: `$matrimonyProfile->gender`, `->location`, age from DOB. `gender` हे relation (MasterGender) आहे — direct `->gender` string नाही; `gender?->key` वापरावं. Location साठी city/state relation वापरावं (जे आत्ता आहे ते बरोबर दिसतं का ते verify).

**Scope (या point अंतर्गत काय करायचं):**

| Item | Proposal | Notes |
|------|----------|--------|
| **Caste** | UI मध्ये caste **dropdown** (caste_id); backend already caste_id ला support करतो. Master Caste list वरून; optional “Any”. | Additive; ProfileFieldConfig मध्ये caste searchable आहे. |
| **Location** | UI मध्ये **city dropdown** किंवा state → city hierarchy (जे आत्ता backend city_id वापरतं ते काम करेल). `$cities` already pass होतं; form मध्ये city_id select add. | Optional: state filter (state_id) backend मध्ये add करणं; आत्ता फक्त city_id आहे. |
| **Gender** | **Gender filter add:** Backend मध्ये `gender_id` filter; UI मध्ये “Looking for” / “Show: Brides / Grooms” (किंवा Male/Female) dropdown. Logged-in user चा profile gender असल्यास default “opposite gender” set करता येईल. | Matrimony standard; backend मध्ये gender_id filter add (additive). |
| **Religion** | Optional: जर backend मध्ये religion_id searchable असेल तर religion dropdown filter; नाही तर या point मध्ये skip. | Field config मध्ये religion_id searchable आहे. |
| **Education** | सध्या text input ठेवू शकतो; किंवा जर education master list असेल तर dropdown. | Backend highest_education text match करतं. |
| **Marital status** | Already dropdown; key/ID backend ला जुळतं. | फक्त label/options shaadi-style करणं. |
| **Result card** | Gender display fix: `gender?->key`; location city/state name from relation. | Same pattern as interests/dashboard. |

**Out of scope (या point मध्ये नाही; पुढे P2 / इतर):**

- Partner preference मधून search form **pre-fill** (user’s saved preference as default) — P2 “Partner expectation in search” सोबत.
- Search results मध्ये **profile completeness %** दाखवणं — P2.
- Sort by “match” / relevance (future).
- ProfileFieldConfigurationService / MutationService / lifecycle मध्ये structural change नाही — फक्त additive filters आणि UI.

**चर्चेसाठी प्रश्न:**

1. **Gender filter:** “Looking for: Brides / Grooms” (किंवा Male/Female) add करायचं का? Default logged-in user च्या उलट gender ठेवायचं का?
2. **Location:** फक्त city dropdown पुरेसं का, किंवा state → city (cascading) हवं?
3. **Religion filter:** या point मध्ये include करायचं का?
4. **Caste dropdown:** सगळे castes एका dropdown मध्ये (मोठी list); किंवा religion निवडल्यावर caste filter narrow down (cascading) — ते later phase मध्ये ठेवायचं?

हे ठरल्यावर implementation plan (exact controller params, view fields, master data pass) लिहून implement करता येईल.

---

## Document status

- **Point 1:** Explained above; ready for your feedback. After you confirm, implementation plan (exact files and changes) will be written here, then implemented.
- **Points 2–5 and P1–P3:** To be detailed and implemented one by one after Point 1 is done.

**Lakshyat thev:** Implementation shaadi.com sarkhach, UI production-grade, SSOT and PHASE-5 constraints unchanged.

-------------------------------
# DAY-33 (SSOT ADD) — Production Governance: Contact Requests + Completeness Policy (Admin-Controlled)

**PROJECT:** Laravel Matrimony  
**MODE:** PHASE-5 SSOT STRICT  
**SCOPE:** Policy + UX Spec Only (No code here)  
**GOAL:** Production-grade governance for:
1) Contact Request / Consent-based Contact Reveal
2) Profile Completeness Policy (search + actions gating)

---

## PART A — Contact Request System (Consent + Privacy + Governance)

### A1) Core Principle
- **Interest Accept = Mutual only (messaging allowed).**
- **Contact details are hidden by default.**
- Contact reveal happens **ONLY** via explicit receiver consent through **Request Contact**.
- Receiver retains control: **scope-based reveal + duration + revoke**.

---

### A2) Entities (Conceptual; naming can follow existing conventions)
1) **contact_requests**
   - sender_user_id
   - receiver_user_id
   - reason_code (required)
   - reason_text (optional, only when reason_code = other)
   - requested_scopes (Email / WhatsApp / Call) [sender asks]
   - status: pending | accepted | rejected | expired | revoked | cancelled
   - created_at, updated_at
   - expires_at (for pending expiry)
   - cooldown_ends_at (set on reject)

2) **contact_grants** (created on accept)
   - contact_request_id
   - sender_user_id
   - receiver_user_id
   - approved_scopes (receiver chooses subset)
   - approval_mode: once | days_7 | days_30
   - granted_at
   - valid_until
   - revoked_at (nullable)

3) **contact_audit_events** (transparency + governance)
   - actor_user_id
   - subject_user_id (receiver)
   - counterparty_user_id (sender)
   - event_type:
     - request_created
     - request_cancelled
     - request_accepted
     - request_rejected
     - request_expired
     - grant_revoked
     - grant_expired
     - contact_viewed
   - metadata (scopes, reason_code, approval_mode, timestamps)

> NOTE: Requests and Grants are separate to keep state machine clean and to support revoke/transparency safely.

---

### A3) Sender UX — Viewer Profile Page (Button states)
- No request/grant exists → **Request Contact**
- Request status = pending → **Request Sent (Pending)** (optionally show Cancel)
- Grant valid (accepted and now < valid_until and not revoked) → **View Contact**
- Request status = rejected and now < cooldown_ends_at → **Request Rejected (Cooling period active)** show cooldown end date
- Request status = expired → **Request Expired** (allow new request if cooldown not active)
- Grant revoked → **Contact no longer available** (new request allowed only if policy allows; typically yes after revoke with cooldown optional)

---

### A4) Sender UX — “Request Contact” Modal (Required)
- **Why are you requesting contact?** (dropdown, required)
  - talk_to_family
  - meet
  - need_more_details
  - discuss_timeline
  - other (requires short reason_text)
- **Requested scopes** (checkboxes)
  - Email
  - WhatsApp
  - Call
- Copy guidance:
  - “They can approve only what they’re comfortable sharing.”
  - “Misuse can be reported and may lead to suspension.”

---

### A5) Receiver UX — Inbox → Requests
Tabs:
1) **Requests (Pending)**
   - Card shows sender snapshot + reason + requested scopes
   - Actions: **Approve** / **Reject**
2) **Access Granted (Active)**
   - Card shows who has access + approved scopes + granted_at + valid_until
   - Action: **Revoke Access**
3) **History**
   - accepted/rejected/expired/revoked/cancelled (read-only with details)

Approve modal (receiver chooses):
- Approval duration:
  - Approve once
  - Approve for 7 days
  - Approve for 30 days
- Approved scopes (subset of requested scopes):
  - Email only / WhatsApp only / Call only / combinations

Reject action:
- Must set request.status = rejected
- Must set cooldown_ends_at = now + reject_cooldown_days
- Must notify sender with cooldown end date

---

### A6) Partial Reveal Rules (Scope-based visibility)
- Sender can view ONLY approved scopes.
- UI must display:
  - “Shared scope(s): …”
  - “Valid until: …”
  - “Receiver can revoke anytime”
- Policy clarification:
  - “WhatsApp/Call only” is **policy + UI guidance**; cannot technically prevent misuse of phone number.
  - Always include “Report abuse” affordance.

---

### A7) Revoke Access (Receiver trust control)
- Receiver can revoke any active grant anytime.
- Revoke effect:
  - grant.revoked_at set
  - sender sees: **Contact no longer available**
  - “View Contact” becomes disabled
  - audit event recorded
  - notification to sender: “Access revoked”

---

### A8) State Machine (Requests)
contact_requests.status:
- pending
- accepted
- rejected
- expired
- revoked
- cancelled

Allowed transitions:
- pending → accepted | rejected | expired | cancelled
- accepted → revoked | expired (grant validity ends)
- rejected → (new request allowed only after cooldown ends)
- expired/revoked/cancelled → new request allowed only if cooldown not active and rate limits allow

---

### A9) Cooling Period Policy (Reject cooldown)
**Rule:** If receiver rejects a contact request, sender cannot request again until cooldown ends.

- Default: **reject_cooldown_days = 90** (3 months)
- Enforced at request-create time:
  - If now < cooldown_ends_at for sender→receiver pair, block with clear UI message and show date.

---

### A10) Anti-spam / Abuse Controls (Policy)
Admin-configurable:
- pending_expiry_days (default 7)
- max_contact_requests_per_day_per_sender (optional; default sensible value)
- max_pending_requests_per_sender (optional)
- block requests if sender profile below min completeness threshold (see Part B)

---

### A11) Notifications (Required)
Receiver:
- “New contact request received” (includes reason)
Sender:
- “Your request was accepted” (include approved scopes + valid_until)
- “Your request was rejected” (include cooldown end date)
- “Your request expired”
- “Your access was revoked”

---

### A12) Transparency Screen (Receiver)
Receiver has “Contact Requests & Access” view:
- Who requested my contact (history)
- Who currently has access (active grants)
- When accepted/rejected/revoked/expired
- Revoke button available from the same screen

---

### A13) Governance
- All policy setting changes must record:
  - **admin_audit_logs entry**
  - **reason mandatory**
  - old_value → new_value diff
  - admin_id + timestamp

---

## PART B — Profile Completeness Policy (Admin-Controlled)

### B1) Core Principle
Completeness rules are **policy-driven** (admin-configurable), not hard-coded.
Goals:
- Improve search quality
- Reduce spam / empty profiles
- Keep onboarding friendly (grace period)

---

### B2) Admin Panel Section: “Profile Completeness Policy” (Required Controls)

#### Threshold Settings
1) **Search visibility threshold (%)**
   - key: min_completeness_for_search_visibility
2) **Demote below (%)** (optional; soft-gating)
   - key: search_rank_demote_below_completeness
3) **Badge below (%)** (optional)
   - key: show_incomplete_badge_below

#### Action Eligibility Thresholds
4) **Minimum completeness to send interest (%)**
   - key: min_completeness_to_send_interest
5) **Minimum completeness to request contact (%)**
   - key: min_completeness_to_request_contact
6) **Minimum completeness to message (%)** (only if messaging exists)
   - key: min_completeness_to_message

#### Photo Policies
7) **Require photo for search visibility** (toggle)
   - key: require_photo_for_search_visibility
8) **Require primary photo for contact request** (toggle)
   - key: require_primary_photo_for_contact_request

#### Required Fields Checklist (Search visibility)
9) Admin-managed checklist:
   - dob present
   - gender present
   - religion present
   - caste present
   - city present
   - marital_status present
   - 1 primary photo present (optional, if toggled)
   - conditional: if marital_status != never_married → children info required (optional)

> Rule: Even if % is high, missing a mandatory field can still restrict visibility.

#### Grace Period Strategy
10) **New user grace period** (hours/days)
   - key: new_user_grace_period_hours
   - During grace, admin defines which surfaces allow relaxed visibility:
     - search
     - recommendations
     - discovery feeds

---

### B3) Advanced Controls (Optional, but supported by policy spec)

#### Surface-Specific thresholds
Admin can set different thresholds by surface:
- Search Results
- Recommended Matches
- New Profiles / Discovery Feed
- Contact Request Eligibility
- Interest/Messaging Eligibility

#### Hard vs Soft Restriction Mode
- Hard Gate: below threshold → hidden
- Soft Mode: visible but demoted + badge

#### Completeness Weight Configuration (Advanced)
Admin can configure section weights (optional):
- Basic Info
- Photos
- Education/Career
- Family
- About Me
- Preferences
Purpose: prevent “percentage gaming”.

#### Behaviour-based rules (Optional)
- Activity boost (recently active profiles get slight visibility preference)
- Photo verified boost (if that system exists)

---

### B4) Governance
All changes must:
- require admin reason
- write admin_audit_logs
- show old→new diffs
- enforce allowed ranges (example):
  - thresholds: 0–100
  - reject_cooldown_days: 7–365
  - pending_expiry_days: 1–30

---

## PART C — Acceptance Tests (Spec-level)

### C1) Contact Requests
1) Pending request shows “Request Sent (Pending)”
2) Reject creates cooldown and blocks re-request until cooldown end date
3) Accept creates grant with correct scopes + duration
4) Sender sees only approved scopes
5) Revoke disables View Contact and shows “Contact no longer available”
6) Expired pending requests show “Expired” and allow re-request if cooldown not active
7) All actions emit notifications and audit events

### C2) Completeness Policy
1) Search hides profiles below min threshold (or demotes if soft mode)
2) Required fields checklist overrides % (missing mandatory field restricts)
3) Interest/contact request buttons disabled below action thresholds
4) Photo requirement toggles enforce correctly
5) Grace period relaxes gating for defined time window
6) All setting changes appear in admin_audit_logs with reason and diffs

---

## Non-goals (Day-33)
- No monetization/credits integration unless explicitly planned.
- No architecture refactor; implement via minimal, SSOT-compliant additions.
- No silent overwrites; maintain auditability and integrity.