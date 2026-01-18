============================================================
📘 FLUTTER MATRIMONY APP – SSOT v2.4 (FINAL & LOCKED)
============================================================

🟢 PURPOSE
------------------------------------------------------------
• Flutter Android App साठी SINGLE SOURCE OF TRUTH
• Laravel MVP v0.1 वर EXACT आधारित
• Assumption = 0
• Confusion = 0
• Rework = 0

============================================================
0️⃣ SSOT STATUS
============================================================

▶ Version        : v2.4
▶ Project Start  : New Flutter Project Folder
▶ Based on       : Current Laravel project (actual files only)
▶ Change Policy  :
   - SSOT update केल्याशिवाय काहीही बदल नाही
   - माझं मत / approach बदलणार नाही

============================================================
1️⃣ CORE PRINCIPLE (MOST IMPORTANT)
============================================================

🟡 Flutter App = EXACT Laravel MVP MIRROR

❌ एकही extra feature नाही  
❌ एकही extra field नाही  
❌ एकही missing field नाही  

▶ Laravel MVP जेवढं → Flutter App तेवढंच + UI

============================================================
2️⃣ ACTUAL MATRIMONY PROFILE – FINAL FIELD LIST
============================================================

(Verified from migration + model + blade forms)

🧾 ONLY THESE 7 FIELDS EXIST

------------------------------------------------------------
| Field Name       | Source                              |
------------------------------------------------------------
| full_name        | migration + create/edit blade       |
| gender           | system-derived (user.gender)        |
| date_of_birth    | migration + blade                   |
| caste            | migration + blade                   |
| education        | migration + blade                   |
| location         | migration + blade                   |
| profile_photo    | migration + edit blade              |
------------------------------------------------------------

▶ हेच 7 fields = Flutter MVP profile

ℹ️ NOTES
• gender → user input नाही
• profile_photo → single photo only
• validation → Laravel मध्ये नाही, Flutter मध्येही नाही

============================================================
3️⃣ STRICT NON-NEGOTIABLE RULES (ALL LOCKED)
============================================================

------------------------------------------------------------
RULE 1️⃣ : MVP PARITY
------------------------------------------------------------
• Laravel पेक्षा कमी ❌
• Laravel पेक्षा जास्त ❌
• EXACT parity mandatory ✅

------------------------------------------------------------
RULE 2️⃣ : FILE-FIRST PROCESS (MANDATORY)
------------------------------------------------------------
• File पाहिल्याशिवाय एकही code नाही

FLOW:
1) मी सांगेन → कोणते files हवेत
2) तू direct files attach करशील
3) मगच code / instruction

▶ File नाही → Teaching नाही ❌

------------------------------------------------------------


------------------------------------------------------------
RULE 4️⃣ : FORGETTING = NOT ALLOWED
------------------------------------------------------------
• सकाळी सांगितलेले points = संध्याकाळी पूर्ण
• 4 points सांगितले → 4 शिकवलेच जातील
• mid-way stop ❌

------------------------------------------------------------
RULE 5️⃣ : DEFINITION FIRST POLICY
------------------------------------------------------------
Daily order:
1) Definition (final)
2) Scope (काय आहे / काय नाही)
3) Code
4) Commit

▶ Definition शिवाय code ❌

------------------------------------------------------------
RULE 6️⃣ : UI = PRODUCTION CORRECT
------------------------------------------------------------
• Field mismatch = bug
• Wrong label = bug
• Missing field = bug

▶ "नंतर ठीक करू" ❌

============================================================
4️⃣ FLUTTER APP FUNCTIONAL SCOPE (PHASE 1 = CURRENT LARAVEL)
============================================================

🟢 MUST HAVE (ONLY MVP)

▶ Authentication
• Register
• Login
• Logout
• Forgot password
• Reset password
• Email verification (notice + resend)
• Confirm password
• Change password
• Note: Laravel मध्ये route/feature actual अस्तित्वात आणि verified असेल तरच Phase 1 मध्ये; नाहीतर Phase 2 मध्ये shift

▶ Matrimony Profile
• Create profile
• Edit profile
• View own profile
• View other profiles
• send interest 
• sent received interest list with accept /reject / withdraw


▶ Photo
• Upload profile photo
• Replace profile photo

▶ Browse
• Profile list
• Profile detail view
• Search filters: age_from, age_to, caste, location

▶ System / Utility
• API health check (/ping)

------------------------------------------------------------
🔴 STRICTLY NOT ALLOWED (PHASE 1)
------------------------------------------------------------
❌ Chat
❌ Advanced filters (beyond age/caste/location)
❌ Multiple photos
❌ Payments
❌ Extra biodata
❌ Admin features

============================================================
4️⃣A FUTURE SCOPE (PHASE 2+ — NOT NOW)
============================================================
• This section is ONLY a backlog, not for current build.
• Anything here is NOT allowed in Phase 1.

🟡 FUTURE CANDIDATES (subject to validation later)
• Search filters (advanced / ranking / preferences)
• Chat / messaging
• Multiple photos & gallery
• Payments / subscriptions
• Admin panel
• Profile verification
• Match recommendations

============================================================
4️⃣B API & DATA CONTRACTS (PHASE 1 — LOCKED)
============================================================
• कोणताही API response assume करायचा नाही.
• प्रत्येक endpoint साठी success + error response आधी Laravel मधून verify करणे mandatory.

▶ ERROR CONTRACT FREEZE (REQUIRED)
• 401 / 403 / 404 / 422 / 500 response shape + messages freeze करणे
• Error contract freeze झाल्याशिवाय Flutter integration सुरु करायचा नाही

▶ PROFILE-EXISTS DECISION RULE (AUTHORITATIVE)
• Login नंतर GET /matrimony-profile call
• 404 => Create Profile screen
• Success => Home (Profile List) screen

▶ PHOTO URL CLARITY (AUTHORITATIVE)
• profile_photo null => default image
• profile_photo value => {BASE_URL}/uploads/matrimony_photos/{profile_photo}

▶ SEARCH FILTER QUERY (PHASE 1)
• age_from, age_to, caste, location = query params

▶ HEALTH CHECK
• GET /ping => { "status": "api alive" }

============================================================
4️⃣C LARAVEL API VERIFY CHECKLIST (PHASE 1 — MANDATORY)
============================================================
For each endpoint, verify in Laravel BEFORE Flutter integration:

1) Route exists (method + path)
2) Auth middleware correct (auth:sanctum where required)
3) Success response shape matches contract
4) Error responses confirmed (401/403/404/409/422/500)
5) Field names match exactly (no extra/missing)
6) Photo URL path returns valid public URL
7) Create vs Update behavior correct (no updateOrCreate for create)
8) Postman smoke test saved (request + response)

============================================================
4️⃣D UI READINESS CHECKLIST (PHASE 1 — MANDATORY)
============================================================
UI ready मानण्यासाठी खालील सर्व screens functional + polished असणे आवश्यक:

1) Auth Screens
   • Login UI (error message + loading state)
   • Register UI (error message + loading state)
   • Logout visible action

2) Profile Screens
   • Create profile form (7 fields only)
   • Edit profile form (prefilled values)
   • Upload/replace photo UI + preview

3) Browse Screens
   • Profile list (card UI, photo placeholder if null)
   • Profile detail screen (all profile fields visible)

4) Interests
   • Send interest CTA on profile detail
   • Sent list + Received list
   • Accept / Reject / Withdraw actions visible + status display

5) Empty / Error States
   • No profiles found
   • No interests found
   • Unauthorized / token expired message

6) Consistency Rules
   • Labels exactly match Laravel fields
   • Missing field = bug
   • Wrong label = bug

============================================================
5️⃣ FLUTTER PROJECT STRUCTURE (LOCKED)
============================================================

flutter_matrimony_android/
|
|-- lib/
|   |-- main.dart
|
|   |-- core/
|   |   |-- api_client.dart
|   |   |-- api_routes.dart
|   |   |-- auth_storage.dart
|
|   |-- features/
|   |   |-- auth/
|   |   |-- matrimony_profile/
|   |   |-- photo/
|   |   |-- home/
|
|   |-- widgets/

▶ No extra folders  
▶ No architecture experiments

============================================================
6️⃣ PROCESS SAFETY & EXECUTION RULES
============================================================

------------------------------------------------------------
RULE 7️⃣ : DIRECT FILE vs SEARCH RESPONSIBILITY
------------------------------------------------------------

A) DIRECT FILES (User uploads)
• migration
• model
• controller
• blade
• routes

B) SEARCH REQUIRED (Cursor only)
• $user कुठे कुठे वापरले आहे
• relations usage
• routes mapping
• middleware lookup

▶ Cursor prompt मी देईन

------------------------------------------------------------
RULE 8️⃣ : ASSUMED CODE LOCATION = FORBIDDEN
------------------------------------------------------------
❌ "हा code शोध"
❌ "बहुधा इथे असेल"

ALLOWED:
• File आधी पाहणे
• Exact line numbers
• delete X–Y, paste after Z

------------------------------------------------------------
RULE 9️⃣ : PASTE SAFETY + EXPLANATION
------------------------------------------------------------
• exact location सांगणे
• वरचा code काय करतो
• खालचा code काय करतो
• जुना code का काढतो
• नवीन code का योग्य

▶ code + reasoning = learning

------------------------------------------------------------
RULE 🔟 : WEBSITE BREAK PROTECTION
------------------------------------------------------------
• risky change step-by-step
• आधी explanation
• मग instruction

❌ "आधी paste करा, मग पाहू"

------------------------------------------------------------
RULE 11️⃣ : ROLLBACK & SAFE STATE POLICY
------------------------------------------------------------

कोणत्याही day च्या teaching दरम्यान:

• जर working feature break झाला
• किंवा app unstable झाला
• किंवा पुढे जाणं risky वाटलं

तर:

A) आधीचा LAST WORKING STATE = SAFE STATE मानायचा
B) त्या state वर code rollback करायचा
C) तो day "Completed" मानायचा नाही
D) पुढचा day सुरू होणार नाही

⚠️ नियम:

• Bug fix करताना पुढे जाऊन शिकवणं = NOT ALLOWED
• Half-working state ला "ठीक आहे" म्हणणं = NOT ALLOWED
• Stable state restore केल्याशिवाय पुढे जाणं = SSOT VIOLATION

------------------------------------------------------------
============================================================
6️⃣A CODE EXECUTION SOURCE OF TRUTH (NEW — v2.0)
============================================================

RULE 12️⃣ : CHATGPT ≠ CODE WRITER
------------------------------------------------------------

• ChatGPT कडून थेट code लिहून घेणे STRICTLY FORBIDDEN ❌
• ChatGPT = Planning, Teaching, Prompt Design ONLY ✅
• Actual code writing = Cursor AI / VS Code ONLY ✅

------------------------------------------------------------
RULE 13️⃣ : CURSOR IS THE ONLY CODE AUTHORITY
------------------------------------------------------------

• File creation
• File deletion
• Refactor
• Rename
• Line-level edits
• Line numbers / delete-paste guidance = explanation only, execution Cursor मध्येच

▶ हे सर्व Cursor मध्येच होईल
▶ ChatGPT कधीही “हा code paste कर” म्हणणार नाही

------------------------------------------------------------
RULE 14️⃣ : PROMPT-FIRST WORKFLOW
------------------------------------------------------------

DAILY FLOW (v2.0):

1) ChatGPT:
   • काय करायचं आहे (Goal)
   • Scope (Allowed / Not Allowed)
   • Cursor साठी EXACT prompt तयार करणे

2) Cursor:
   • Full project scan
   • Exact code generation / edit

3) ChatGPT:
   • Cursor ने केलेल्या code चे explanation
   • Learning extraction
   • Risk analysis

------------------------------------------------------------
RULE 15️⃣ : NO FILE ASSUMPTION GUARANTEE
------------------------------------------------------------

• File अस्तित्वाचा अंदाज = NOT ALLOWED
• Line numbers सांगणे = NOT REQUIRED (Cursor handles)
• ChatGPT ला file tree आठवण्याची गरज नाही

▶ Assumption risk permanently eliminated

============================================================
7️⃣ DAILY DISCIPLINE (MANDATORY)
============================================================

DAILY FLOW:
• तू:
  - required files attach करशील
• मी:
  - exact file path
  - exact line number
  - वरचा / खालचा reference देईन

DAY END:
• Git push
• 4–5 ओळी summary (आज काय शिकलो)
• Next day required files list

============================================================
9️⃣ DAY-WISE DETAILED EXECUTION PLAN (DEPENDENCY SAFE)
============================================================

⚠️ CORE RULE (APPLIES TO ALL DAYS)
------------------------------------------------------------
• जे शिकवायचं आहे ते आधी 100% READY असायलाच हवंच
• जे READY नाही → तो topic त्या day ला INVALID
• “नंतर शिकवू”, “skip करू” = STRICTLY FORBIDDEN
• Infra failures (SDK/Gradle/Emulator) = fix-only, feature progress नाही; day pause allowed
• Flutter Day-wise flow नुसार API तयार करणे mandatory (day सुरू होण्यापूर्वी त्या day ची API ready + tested असलीच पाहिजे)

============================================================
🟢 DAY 1 — Flutter Environment & Project Skeleton ONLY
============================================================

🎯 Objective
• Flutter project RUN होतो का ते verify करणे
• Login / API / Network = NOT ALLOWED

✅ Prerequisites (ALL MUST BE READY)
• Flutter SDK installed
• Android Studio + SDK working
• Emulator / real device connected

❌ जर काही missing असेल:
• Day 1 START होणार नाही

📚 What will be taught
• flutter doctor check
• New Flutter project create
• Folder structure explain
• main.dart → basic MaterialApp
• Emulator वर Hello World run

🚫 NOT ALLOWED TODAY
• Login ❌
• API calls ❌
• Token ❌

✔️ Completion Rule
• App successfully emulator वर run झाला पाहिजे

============================================================
🟢 DAY 2 — Static Screens & Navigation (NO BACKEND)
============================================================

🎯 Objective
• Backend शिवाय Flutter navigation clear करणे

✅ Prerequisites
• Day 1 complete
• App runs without error

📚 What will be taught
• Login screen UI (static)
• Register screen UI (static)
• Home dummy screen
• Navigator.push / pop
• Route flow explanation

🚫 NOT ALLOWED
• Actual login logic ❌
• API hit ❌

✔️ Completion Rule
• Screens manually navigate होतात

============================================================
🟢 DAY 3 — API READINESS VERIFICATION (NO UI CHANGE)
============================================================

🎯 Objective
• Laravel login API READY आहे का ते verify करणे

✅ Prerequisites
• Laravel login API exists
• API endpoint tested (Postman / browser)
• तू required backend files attach करशील

❌ जर API ready नसेल:
• Login शिकवला जाणार नाही

📚 What will be taught
• API contract reading
• Request / response structure
• Flutter API client skeleton (call नाही)

🚫 NOT ALLOWED
• Login integration ❌
• Token store ❌

✔️ Completion Rule
• Backend contract fully understood
• Flutter API client skeleton ready

============================================================
🟢 DAY 4 — LOGIN PRACTICAL (REAL, NOT ASSUMED)
============================================================

🎯 Objective
• Actual login working करणे

✅ Prerequisites (STRICT GATE)
• Day 3 API verified
• Valid test user exists
• Error responses known

❌ यापैकी काही missing असेल:
• DAY 4 = INVALID
• Login शिकवला जाणार नाही

📚 What will be taught
• Login API call
• Success / failure handling
• Simple token store (memory only)

✔️ Completion Rule
• Login success → Home
• Login fail → Error message

============================================================
🟢 DAY 5 — REGISTER FLOW (REAL)
============================================================

🎯 Objective
• User register → login flow complete

✅ Prerequisites
• Laravel register API verified
• Validation rules known

📚 What will be taught
• Register form mapping
• Register API call
• Auto-login after register

🚫 NOT ALLOWED
• Profile create ❌

✔️ Completion Rule
• User register + login successful

============================================================
🟢 DAY 6 — MATRIMONY PROFILE CREATE (7 FIELDS ONLY)
============================================================

🎯 Objective
• Exact Laravel MVP profile create

✅ Prerequisites
• Logged-in state stable
• MatrimonyProfile store API verified
• Field list frozen (7 fields)

❌ API unclear असेल:
• DAY 6 = CANCELLED

📚 What will be taught
• Create profile form
• Field ↔ API mapping
• Success redirect logic

✔️ Completion Rule
• Profile successfully created

============================================================
🟢 DAY 7 — PROFILE EDIT (NO NEW FIELD)
============================================================

🎯 Objective
• Profile update working

✅ Prerequisites
• Profile already exists
• Update API verified

📚 What will be taught
• Prefilled edit form
• Update API call
• Success feedback

✔️ Completion Rule
• Profile successfully updated

============================================================
🟢 DAY 8 — PHOTO UPLOAD (MOST SENSITIVE DAY)
============================================================

🎯 Objective
• Single profile photo upload

✅ Prerequisites (ALL REQUIRED)
• Logged-in user
• Profile exists
• Upload API tested
• Android permissions clear

❌ काहीही missing असेल:
• DAY 8 = INVALID

📚 What will be taught
• Image picker
• Multipart upload
• Photo preview

✔️ Completion Rule
• Photo uploaded & visible

============================================================
🟢 DAY 9 — PROFILE LIST & VIEW
============================================================

🎯 Objective
• Browse & view profiles

✅ Prerequisites
• Backend list API verified
• Photo path clarity

📚 What will be taught
• Profile list screen
• Card UI
• Detail view screen

✔️ Completion Rule
• Profiles list & view working

============================================================
🟢 DAY 10 — INTEREST SEND + FINAL VERIFICATION
============================================================

🎯 Objective
• Complete MVP loop & parity verification

✅ Prerequisites
• Profile list & view working
• Interest API verified

📚 What will be taught
• Interest send API
• Success handling
• Final parity checklist

✔️ Completion Rule
• End-to-end MVP verified

============================================================


ℹ️ CHECKBOX WORKING RULE:
• ⬜  = Not started
• ☑️  = Completed
• ❌  = Blocked (reason summary मध्ये लिहायचा)
• Checkbox बदलणे = SSOT change नाही (LOCK safe)
------------------------------------------------------------
| Day | Learning Scope                                     | Done | Summary 		 |
------------------------------------------------------------
| 1   | Flutter setup & project skeleton (NO login)        | ⬜   | completed        |
| 2   | Static UI screens & navigation (NO backend)        | ⬜   |         |
| 3   | Backend API readiness verification                 | ⬜   |         |
| 4   | Login practical (API verified, real login)         | ⬜   |         |
| 5   | Register flow (real, no profile)                   | ⬜   |         |
| 6   | Matrimony profile create (7 fields)                | ⬜   |         |
| 7   | Profile edit (existing profile only)               | ⬜   |         |
| 8   | Photo upload (single photo, strict prerequisites)  | ⬜   |         |
| 9   | Profile list & profile detail view                 | ⬜   |         |
| 10  | Interest send & final parity verification          | ⬜   |         |
------------------------------------------------------------

▶ Day incomplete = next day start नाही ❌

============================================================
Daywise learning summary (FORMAT)
============================================================
------------------------------------------------------------
Date      : YYYY-MM-DD
Day       : Day X
Status    : ☑️ Completed / ❌ Blocked
------------------------------------------------------------

आज काय शिकलो:
1) 
2) 
3) 
4) 

आज अडचण काय आली (असल्यास):
• 

Decision / Note (important):
• 

Next Day Prerequisite (जर काही लागणार असेल तर):
• 
------------------------------------------------------------
============================================================
🔐 SSOT LOCK CONFIRMATION (DO NOT EDIT)
============================================================

SSOT Name   : Flutter Matrimony App – SSOT
Version     : v2.4
Project     : flutter_matrimony_android
Locked On   : 2026-01-16

Integrity Lock (Manual Checksum):
FM-SSOT-v2.4-CURSORCODE-CHATGPTPLAN-PROMPTFIRST-NOASSUME-LOCKED



RULES SEELED BY THIS LOCK:
• Above content MUST NOT be edited.
• If any rule/word/section changes → increment SSOT version.
• Day-wise checkbox & summary updates DO NOT break lock.

============================================================
🔒 FINAL LOCK STATEMENT
============================================================

FLUTTER SSOT v2.4 IS FINAL

▶ याच्या बाहेर काहीही झालं
▶ तर ते BUG समजलं जाईल