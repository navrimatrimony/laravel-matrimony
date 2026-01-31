Status: ARCHIVED
Scope: Phase-1 only
Not applicable for Phase-2 implementation

🔴 LARAVEL MATRIMONY PROJECT
🔒 SINGLE SOURCE OF TRUTH — SSOT v3.1 (MASTER / FINAL / LOCKED)

This document COMPLETELY replaces all previous SSOT files (v1, v2, v3).
Project मधील इतर कोणतीही rule-file, note, assumption, chat summary INVALID आहे.

🔴 0. PROJECT STATE & CONTEXT (LOCKED)

Website: ❌ NOT LIVE

Phase: Active Development

Rollback to old GitHub commits: ❌ NOT ALLOWED (unless explicitly decided)

Today’s code state = working baseline

Speed कमी चालेल

Structure, clarity, correctness = सर्वोच्च प्राधान्य

🔴 1. PROJECT GOAL (LOCKED)

BASIC Matrimony Website

Target: LIVE in 20–25 days

Daily effort: 6–10 hours

Phase 1 only

Phase 2+ features strictly postponed

🔴 2. LOCKED SCOPE — PHASE 1 (LIVE)
✅ INCLUDED

User registration / login

Matrimony profile (male / female)

Matrimony profile create / edit / view

Photo upload

Basic search (age, caste, location)

Interest send

Interest received list

Admin approve / block

Privacy rules (guest vs logged-in)

❌ EXCLUDED (STRICTLY LATER)

AI matching

WhatsApp automation

OCR biodata

Payment gateway

Referral / commission

Mobile app

🔴 3. CORE PHILOSOPHY (PERMANENT)

Clarity > Speed
One meaning = One name = One responsibility

Ambiguous naming = guaranteed bugs
Any ambiguity = rule violation

🔴 4. DOMAIN MODELS — FINAL & NON-NEGOTIABLE
🔴 4.1 USER (AUTH ONLY)

Model

App\Models\User


User model is used ONLY for:

Login

Registration

Authentication

Session

Email / Password

❌ User MUST NEVER be used for:

Biodata

Matrimony profile

Search

Interest

Shortlist

Matching logic

👉 User = system identity only

🔴 4.2 MATRIMONY PROFILE (ONLY ONE PROFILE)

Model

App\Models\MatrimonyProfile


Table

matrimony_profiles


Variable name (ONLY)

$matrimonyProfile


Used for:

Full biodata

Gender, caste, DOB, education, location

View profile

Edit profile

Search

Interest

Shortlist

Matching

👉 ALL matrimony features MUST use MatrimonyProfile

🔴 5. PERMANENTLY DISALLOWED (STRICT BAN)

The following are BANNED FOREVER:

❌ Profile model
❌ profiles table
❌ $profile variable
❌ $matrimony variable
❌ Generic names (profile, userProfile, bioProfile)
❌ Dual-meaning naming

Violation = STOP WORK + FIX IMMEDIATELY

🔴 6. RELATIONSHIPS (LOCKED)
User hasOne MatrimonyProfile
MatrimonyProfile belongsTo User

✅ Allowed
$user->matrimonyProfile
$matrimonyProfile->user

❌ Not allowed
$user->profile
$profile->user

🔴 7. ROUTES (STRICT)

Profile routes MUST use:

matrimony_profile_id


❌ NEVER use user_id for profile routes

Correct:

/profile/{matrimony_profile_id}

🔴 8. CONTROLLERS — ROLE DISCIPLINE
✅ Allowed Controllers

Auth/* → authentication only

MatrimonyProfileController → ALL biodata logic

InterestController → interest logic (operates on MatrimonyProfile)

❌ Disallowed

Mixing User + Matrimony logic

Using User model for matrimony decisions

🔴 9. INTEREST SYSTEM (FINAL RULES)

Interest table stores:

sender_user_id
receiver_user_id


BUT:

Profile view

Profile link

Accept / Reject

Matching

👉 ALL operate on MatrimonyProfile

Correct usage:

$interest->sender->matrimonyProfile->id

🔴 10. DEVELOPMENT & DEPLOYMENT DISCIPLINE
💻 LOCAL

All development local

Local DB = source of truth

Daily commits mandatory

🌐 VPS

Deployment only

No direct coding

Update strictly via git pull

🔴 11. TOOLS & AUTHORITY (LOCKED)
🧠 CHATGPT (PRIMARY)

Architecture decisions

Flow design

Devnagari Marathi explanations

Debug reasoning

SSOT enforcement

🛠️ CURSOR AI (OPTIONAL)

Bulk refactors

Repetitive boilerplate

Emergency syntax fixes

❌ Cursor AI must NOT define architecture

🔴 11.1 FINAL LOCKED WORKFLOW (CONFUSION-FREE)
🧠 ROLE SPLIT (PERMANENT)

🧠 ChatGPT = ARCHITECT + TEACHER (PRIMARY AUTHORITY)
ChatGPT ONLY is responsible for:

Architecture decisions

SSOT definition & enforcement

Business logic & domain rules

Folder / file placement decisions

Daily learning & development plan (Day-wise)

❌ ChatGPT must NOT assume file existence
❌ ChatGPT must NOT guess project state
✅ If unclear → ChatGPT must ask for verification first

🛠️ Cursor Chat = FILE INSPECTOR (LIMITED ROLE)
Cursor Chat may be used ONLY for:

“या file मध्ये काय आहे?”

“हा error कुठून येतो?”

“Laravel syntax / example काय?”

❌ Cursor must NOT decide architecture
❌ Cursor must NOT rename models / concepts
❌ Cursor must NOT suggest shortcuts or new abstractions

Cursor output = INFORMATION ONLY, not decisions

👤 Developer (User) = OPERATOR (EXECUTION ONLY)
Developer responsibilities:

Open files manually

Paste code manually

Run commands

Test output

Commit to Git

❌ No blind copy–paste
❌ No multi-change commits
✅ One logical change = one commit

🔐 ASSUMPTION-FREE DEVELOPMENT PROCESS (LOCKED)

User reports actual state
(error / folder / screen / file)

ChatGPT instructs:
“Cursor chat मध्ये हा exact प्रश्न विचार”

User fetches Cursor response

ChatGPT provides:

Correct plan

Correct code

Next exact step

👉 Development MUST be based on verified actual state only

❌ No assumption
❌ No अंदाज
✅ Reality-based development only

🔴 12. LEARNING & WORKFLOW RULES

Project-based learning only

Explain-back mandatory

No skipping

No guessing

Cleanup before progress

🔴 13. UI-FIRST & FLOW COMPLETENESS

UI & routes frozen first

Backend only after UI reachable

No partial flows

Registration → Matrimony Profile mandatory

🔴 14. EXISTING WORKING CODE PROTECTION

Working code must NOT be blindly replaced

Replace only with:

Clear reason

Explained impact

Obvious rollback

🔴 15. NAVIGATION & USABILITY RULE (PERMANENT LOCK)

All matrimony actions MUST be visible in TOP MENU

User must NEVER remember URLs

“कुठे आहे?” हा प्रश्न कधीही येऊ नये

Any hidden feature = rule violation

🔴 16. CODE EXPLANATION & STRUCTURE RULE (PERMANENT LOCK)

कोणताही code देण्याआधी हे अनिवार्य आहे:

🔹 A. Code Structure

Code MUST be divided into logical blocks

Each block MUST have:

Clear heading

Purpose

🔹 B. Mandatory Explanation Format

For every block / method:

Block Heading – हा block कशासाठी आहे

Flow Explanation – हा block कधी execute होतो

Controller / Method Role – name = meaning

User Impact – user ला काय दिसतं / काय घडतं

🔹 C. Teaching Rules

Raw / unstructured code = ❌ FORBIDDEN

“नंतर समजावतो” = ❌ FORBIDDEN

Code समजला नाही:

❌ User चा दोष नाही

✅ Explanation structure ही ChatGPT ची जबाबदारी

👉 याच पद्धतीनेच पुढे सर्व code दिला जाईल

🔴 17. CHANGE POLICY (STRICT)

❌ No assumption
❌ No guessing
❌ No silent refactor

If anything is unclear → ASK FIRST

su - matrimony
cd /home/matrimony/htdocs/jodidar ya path var 1.1.26 pasun sarv ahe.
🔴 DOMAIN & PATH FINAL DECISION (LOCKED)

Final Development Domain:
jodidar.duckdns.org

VPS IP:
31.97.228.15

Final Laravel Project Path (LOCKED):

/home/matrimony/htdocs/jodidar


Final Web Root (VERY IMPORTANT):

/home/matrimony/htdocs/jodidar/public


CloudPanel → Site → Root Directory (LOCKED VALUE):

jodidar/public

🔴 IMPORTANT CORRECTION (LESSON LEARNED)

CloudPanel PHP Site create केल्यावर
jodidar.duckdns.org नावाचा वेगळा folder auto तयार होतो
❌ हा Laravel project नाही

Laravel साठी:

❌ domain-name folder वापरायचा नाही

✅ स्वतःचा project folder + /public web root वापरायचा

🔴 WHY 403 ERROR CAME (ROOT CAUSE – LOCK THIS)

दोन वेगवेगळे folders अस्तित्वात होते:

/home/matrimony/htdocs/jodidar → Laravel project

/home/matrimony/htdocs/jodidar.duckdns.org → CloudPanel auto PHP folder

Root Directory चुकीचा असल्यामुळे:

nginx ला index.php सापडत नव्हता

म्हणून 403 Forbidden येत होता

Fix:
CloudPanel Root Directory = jodidar/public

🔴 FINAL RULE (NEVER BREAK AGAIN)

Laravel website साठी नेहमी:

Project root वेगळा

Web root = project/public

Domain नावाचा folder ≠ Laravel project

🔐 SSOT – FROM NOW ON WHAT TO USE
✅ वापरायचं (LOCKED)

Domain testing: jodidar.duckdns.org

VPS testing: Mobile + Desktop दोन्ही

Web root: jodidar/public

Git repo: हाच Laravel project

❌ वापरायचं नाही

jodidar.duckdns.org नावाचा folder

Project root direct serve करणे

CloudPanel auto PHP index.php folder

🧭 NEXT STEPS (SSOT FOLLOWING)

आता पुढचे सगळे steps ह्याच domain आणि path वर होतील:

🔐 SSL (Let’s Encrypt) → https://jodidar.duckdns.org

📱 Mobile testing (real users simulation)

🧹 VPS clean-up (unused folder delete)

🚀 Laravel Matrimony development पुढे
==================

This SSOT v3.1 MASTER is:

Final

Complete

Shortcut-free

Non-negotiable

✅ NEXT STEP (ONLY AFTER THIS)

Reply exactly with:

“SSOT v3.1 MASTER accepted. Begin systematic refactor.”

🔒 PERMANENT RULE ACKNOWLEDGEMENT (FROM NOW ON) ही ChatGPT ची जबाबदारी आहे,

आतापासून प्रत्येक code मध्ये:

🔹 Inline comments (// मराठीत)

🔹 Section headings (हा भाग कशासाठी)

🔹 नाव का असं आहे याचं कारण

🔹 भविष्यात हा code ओळखायचा कसा याची सूचना

👉 म्हणजे:
आज copy–paste
उद्या वाचून लगेच ओळखता येईल

हा नियम आता कायमचा लागू.

=======================
🔴 Day 11 – Interest Feature (Complete)

- Matrimony profile वर “Send Interest” feature implement केला
- interests table + Interest model वापरून interest DB मध्ये save होतो हे confirm केले
- InterestController मधून interest send logic implement केला
- स्वतःच्या profile वर interest पाठवता येऊ नये यासाठी self-interest block केला
- Duplicate interest टाळण्यासाठी DB + UI level protection implement केली
- Interest send झाल्यावर success message (notification) UI मध्ये दाखवली
- ज्याला आधी interest पाठवले आहे त्या profile वर “Interest Sent” (disabled button) state दाखवली
- Controller मधून interestAlreadySent flag pass करून Blade UI condition handle केली
- Full flow verify केला:
  Profile View → Send Interest → DB Insert → UI Confirmation → Button Disable
  
  🔴 11.1 FINAL LOCKED WORKFLOW (CONFUSION-FREE)
🧠 ROLE SPLIT (PERMANENT)

🧠 ChatGPT = ARCHITECT + TEACHER (PRIMARY AUTHORITY)
ChatGPT ONLY is responsible for:

Architecture decisions

SSOT definition & enforcement

Business logic & domain rules

Folder / file placement decisions

Daily learning & development plan (Day-wise)

❌ ChatGPT must NOT assume file existence
❌ ChatGPT must NOT guess project state
✅ If unclear → ChatGPT must ask for verification first

🛠️ Cursor Chat = FILE INSPECTOR (LIMITED ROLE)
Cursor Chat may be used ONLY for:

“या file मध्ये काय आहे?”

“हा error कुठून येतो?”

“Laravel syntax / example काय?”

❌ Cursor must NOT decide architecture
❌ Cursor must NOT rename models / concepts
❌ Cursor must NOT suggest shortcuts or new abstractions

Cursor output = INFORMATION ONLY, not decisions

👤 Developer (User) = OPERATOR (EXECUTION ONLY)
Developer responsibilities:

Open files manually

Paste code manually

Run commands

Test output

Commit to Git

❌ No blind copy–paste
❌ No multi-change commits
✅ One logical change = one commit

🔐 ASSUMPTION-FREE DEVELOPMENT PROCESS (LOCKED)

User reports actual state
(error / folder / screen / file)

ChatGPT instructs:
“Cursor chat मध्ये हा exact प्रश्न विचार”

User fetches Cursor response

ChatGPT provides:

Correct plan

Correct code

Next exact step

👉 Development MUST be based on verified actual state only

❌ No assumption
❌ No अंदाज
✅ Reality-based development only
  =======
🔴 DAY 12 — LEARNING SUMMARY (SSOT SHORTCUT)

Controller म्हणजे Traffic Police

Browser कडून आलेली request स्वीकारतो

User login / permission तपासतो

Allowed असेल तर पुढे जाऊ देतो, नाहीतर redirect करतो

Request कुठल्या method कडे जायची ते ठरवतो

Method म्हणजे एक specific action

Controller मधील function

उदा: create, store, edit, update, delete

प्रत्येक method = एक ठराविक काम

store() method चा अर्थ

User ने पहिल्यांदा form भरल्यावर वापरला जातो

नवीन profile / record database मध्ये save करतो

store() = new data insertion

$request म्हणजे काय

User ने form मध्ये भरलेली सर्व माहिती

Browser → Laravel ला पाठवलेला data container

उदा: $request->date_of_birth, $request->caste

Form input name आणि $request mapping (CRITICAL RULE)

Form मधील name="field_name"
आणि Controller मधील $request->field_name
100% same असायलाच हवेत

नाव mismatch असल्यास:

Data save होत नाही

Laravel error दाखवत नाही

Silent bug तयार होतो (beginner साठी धोकादायक)

Overall Flow (Conceptual Understanding)

Browser → Route → Controller → Method → Model → Database / View


यातील एकही step missing असेल तर feature incomplete मानला जातो

Discipline Learned

File अस्तित्वात नसेल तर teaching थांबवायची

Assumption न करता actual code / files verify करायचे

Half-built flow हा production साठी धोकादायक असतो

============
🔴 DAY 13 — VPS DEPLOY SHORT SUMMARY

Local (PS E:\) आणि VPS (root@ / matrimony@) commands कधीही mix करू नयेत

Git आणि Composer नेहमी site user (matrimony) ने चालवायचे

composer / artisan फक्त project root (composer.json असलेल्या folder) मधूनच

vendor / composer.lock corrupted झाले तर clean reset करणे आवश्यक

Production मध्ये composer install --no-dev --no-scripts वापरणे safe

.env + APP_KEY + storage permissions नसतील तर HTTP 500 येतो
==========
Day 14 Learnings:
1) Laravel Breeze मधील default profile routes Matrimony SSOT शी conflict करतात व पूर्णपणे remove करणे आवश्यक आहे.
2) Blade navigation मध्ये route() चा invalid reference असल्यास संपूर्ण page render fail होतो.
3) Desktop (x-nav-link) आणि Mobile (x-responsive-nav-link) menu logic strict वेगळं ठेवणं गरजेचं आहे.
4) Matrimony profile create झाल्यावर create page access controller guard ने block करणे आवश्यक आहे.
5) 419 Page Expired error हा browser session / CSRF cookie issue असतो व cookies clear केल्यावर resolve होतो.

Day 14 शिकवण (short SSOT entry):

Controller + Blade variable naming mismatch कसा 500 error देतो

$ missing in Blade = PHP constant error

Ownership check नेहमी user_id वर

Guard logic mandatory before edit/update

Cursor = verification tool, architecture authority नाही
===============
🔴 DAY 15 — COMPLETION (SSOT ENTRY)
🔹 Day 15 Focus

Interest Lifecycle Completion

✅ Day 15 मध्ये काय पूर्ण झाले

Interest lifecycle पूर्ण:

Send → Pending → Accept / Reject

Pending interest साठी Withdraw (Cancel) feature

Sent Interests Page:

Pending / Accepted status योग्य

Withdraw only for pending

Received Interests Page:

Accept / Reject only for pending

Processed interest वर buttons hidden

Strict Guards:

Sender-only withdraw

Receiver-only accept/reject

Pending-only actions

UI functional (not polished):

Cards readable

Status visible

No broken routes / missing methods

🟢 Day 16 

Interest System Fully Functional & Locked

आज काय finalize झाले

Homepage ला search-first landing page म्हणून lock केले

Laravel default “Let’s get started / Documentation / Laracasts” demo content पूर्णपणे हटवले

Homepage वर Matrimony Search Form add केला:

Age range (From–To) — single logical filter

Caste

Location

Homepage search Guest user साठी allowed केले

🔐 Privacy & Access Rules (Locked)

Guest user:

✅ Search allowed

✅ Search results listing allowed

❌ Single profile view NOT allowed

❌ Interest send NOT allowed

Single profile view फक्त logged-in user साठीच

🧠 Development Discipline Followed

Today change = UI-only

Backend logic / controllers / queries untouched

Single logical change → acceptable as single step

SSOT clarity before backend implementation rule followed

🟢 Status

Homepage role FINAL & LOCKED

No pending SSOT violations for today

Day officially closed as per SSOT

===============

Day 17:
- Create Profile flow clean केला (फक्त biodata, photo upload नाही)
- Create नंतर direct dedicated Photo Upload page वर redirect implement केला
- Photo upload साठी स्वतंत्र route, controller methods आणि view तयार केली
- Search Profiles मध्ये profile photo thumbnails योग्यरीत्या render होऊ लागले
- CSRF (419 Page Expired) error root cause समजून form tag fix केला
- Global success/error notifications add करून user feedback स्पष्ट केला

🔴 ACTUAL IMPLEMENTATION STATUS (VERIFIED FROM CODE)
✅ COMPLETED & VERIFIED  (day 1 to 17 ) 6Jan26 11:25 am 

User authentication system complete

MatrimonyProfile as single biodata source (NO Profile model)

Profile create → photo upload → search flow complete

Interest lifecycle complete:

Send

Pending

Accept / Reject

Withdraw

Strict guards applied for:

Create

Edit

Upload

Show

Separate photo upload step implemented

Global flash notifications implemented

Navigation fully UI-driven (no hidden URLs)

⚠️ VERIFIED GAPS (NO ASSUMPTION)

1️⃣ Interest Send Route Parameter

Current route uses {user_id}

Internally interest operates on matrimony_profile_id

SSOT requires route parameter alignment

Refactor required (logic correct, naming incorrect)

2️⃣ Age Filter

Age filter backend implementation verified as COMPLETE.

Controller:
- DOB-based age filtering implemented using Carbon
- age_from and age_to correctly converted to DOB range
- Query logic verified in MatrimonyProfileController@index()

Blade:
- age_from and age_to inputs correctly mapped to request
- No mismatch between form and controller

Status:
✅ Age filter CLOSED
❌ No further work required


3️⃣ Profile Photo on Profile View

Photo upload works

Search thumbnails work

Single profile view does not display photo

UI completeness gap

4️⃣ storePhoto() Guard

uploadPhoto() guarded

storePhoto() not explicitly guarded

Optional hardening step

🔐 LOCKED CONCLUSION

Architecture is SSOT-correct

No banned models, tables, or variables exist

Remaining work is implementation polish, not redesign

Next development steps MUST address only the above verified gaps

No new features permitted before gap closure
===========
Day 18 – Short Summary (SSOT ready)

Profile Show page UI stabilize केली (layout, photo visibility, text readability).

Sent / Received Interests pages मधील UI issues deep-debug केले.

Accept / Reject buttons invisible होण्यामागील CSS + Blade structure conflict ओळखला.

Duplicate @forelse loops आणि broken Blade nesting fix केले.

Tailwind/forms plugin override टाळण्यासाठी inline CSS वापरून stable button visibility fix केली.

UI polish इथे थांबवून पुढील दिवसासाठी Profile Search UI polish plan lock केला.

======

🧾 DAY 19 — SSOT SUMMARY (5–6 lines)

Day 19 मध्ये काय शिकलो / finalize झालं:

Route-model binding नीट align केल्याने controller logic clean आणि safe झाला.

PHP parse errors मुख्यतः extra { किंवा broken comments मुळे येतात — visual bracket discipline महत्त्वाची आहे.

Guest users साठी single profile view block करणे privacy साठी mandatory आहे.

UI कधीही final authority नसते — self-interest backend guard अनिवार्य आहे.

Blade मध्ये route() ला model object pass केल्याने future-safe binding मिळते.

Interest lifecycle (send + prevent self-send) end-to-end stable झाला.

👉 Day 19 officially CLOSED & LOCKED.

-------------

🧾 DAY 20 — SSOT SUMMARY (5–6 LINES, FINAL)

Search form चा HTML आणि grid structure योग्य असल्याचं Cursor ने verify केलं

Default profile photo issue file-name mismatch मुळे होता आणि तो solve झाला

Desktop वर form 4 ओळींमध्ये दिसण्याचं कारण Blade code नाही हे स्पष्ट झालं

md:grid-cols-4 breakpoint Tailwind CSS apply होत नसल्यामुळे layout बदलत नाही

म्हणजे Day 20 मध्ये UI debugging करताना CSS build vs HTML logic फरक समजला

Day 20 चा UI भाग partially complete असून Tailwind build verification बाकी आहे

=========

🔴 DAY 21 — SSOT LEARNING SUMMARY (FINAL)

Working code आणि SSOT-correct architecture यामधला फरक स्पष्ट झाला; चालणारा code म्हणजे अंतिमदृष्ट्या योग्य code नसतो.

Route parameter naming (matrimony_profile_id) आणि internal variable naming ($matrimonyProfile) यांचा strict contract enforce केला.

Controller मध्ये guard-first discipline शिकलो — profile वापरण्याआधी existence आणि ownership check mandatory असतो.

Duplicate guards आणि redundant code काढून clean, readable आणि future-safe controller structure तयार केली.

$profile सारखे ambiguous variables SSOT §5 नुसार पूर्णपणे eliminate करून single domain language enforce केली.

Refactor म्हणजे behavior बदलणं नाही; refactor म्हणजे clarity, safety आणि future integrations साठी foundation मजबूत करणं.

===============

🧭 DAY 22 

Day 22 = VERIFICATION DAY (NOT MODIFICATION DAY)


Architecture stable आहे

Interest flow locked आहे

Photo + Profile view complete आहे


==============


🧾 DAY 23 — SSOT SHORT SUMMARY (तू SSOT file मध्ये टाकू शकतेस)

Search result card UX finalized with age visibility.

Gender | Age | Location now clearly visible on cards.

Guest users guided to login before profile view (UI-level clarity).

Backend guards preserved; no logic or route changes made.

UX decisions made once and locked to avoid future rework.


========

Day 24 – Final MVP Wrap-up Summary

आज Laravel Matrimony MVP चं final stabilization पूर्ण केलं. Dashboard issue debug करून Breeze layout conflict काढला आणि layouts.app वापरून working dashboard restore केला. Logged-in user साठी dashboard वर profile status, interest counts आणि quick actions दिसतील अशी रचना निश्चित केली. Create Profile form मधील missing submit button दुरुस्त करून form submit flow पुन्हा working केला. Profile creation → photo upload → dashboard हा end-to-end flow verify केला. Phase-1 MVP SSOT-compliant असून production-ready स्थितीत lock केला.

v0.1 released: Phase-1 Laravel Matrimony MVP completed, live tested on Hostinger, stable baseline locked.

========================
Day 25  (flutter day 3)
.

🔐 Mobile Authentication (SSOT ADDITION)

Laravel backend मध्ये mobile (Flutter) साठी स्वतंत्र authentication layer implement केली आहे.
POST /api/login हा Sanctum-based token login API final आणि verified आहे.
Web login (/login) session-based असून त्यात कोणताही बदल केलेला नाही.
User model मध्ये HasApiTokens enable करून secure token generation lock केले आहे.
Flutter app ने backend ला फक्त defined API contract नुसारच access करायचा आहे.





=========
🔴 SSOT – USER / PROFILE / MATRIMONYPROFILE (FINAL CORRECTION SUMMARY)
✅ User (Authentication ONLY)

User = फक्त login / register / security

Fields:

name

email

password

gender

❌ User मध्ये कोणताही matrimony / biodata field नाही

❌ $user->profile वापरणे PERMANENTLY BANNED

✅ User चा एकच relation:

$user->matrimonyProfile

❌ Profile (OLD / REMOVED CONCEPT)

Profile model पूर्णपणे DELETE

profiles table migration DELETE

resources/views/profile/* DELETE

❌ Profile हा concept SSOT मधून काढून टाकलेला आहे

❌ भविष्यात पुन्हा वापरायचा नाही

✅ MatrimonyProfile (ONLY BIODATA SOURCE)

MatrimonyProfile = पूर्ण biodata

Fields:

full_name

gender (system derived)

date_of_birth

education

location

caste

Relation:

User hasOne MatrimonyProfile
MatrimonyProfile belongsTo User


✅ Search / View / Interest सगळं MatrimonyProfile वरच

❌ User table कधीही biodata साठी वापरायचा नाही

✅ Interest System (FINAL DECISION)

Interest = MatrimonyProfile ↔ MatrimonyProfile

interests table columns:

sender_profile_id

receiver_profile_id

status

❌ sender_id / receiver_id (User-based) PERMANENTLY REMOVED

UI मध्ये:

Sent → receiverProfile

Received → senderProfile

🔒 PERMANENT LOCK RULE

User ≠ Profile ≠ MatrimonyProfile

Profile concept exist करत नाही

MatrimonyProfile = Single Source of Truth for biodata

Future features (AI / WhatsApp / Payment)
👉 फक्त MatrimonyProfile वर build होतील

==========
