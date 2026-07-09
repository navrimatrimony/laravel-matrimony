# Bulk Intake — Final Blueprint

> **Status:** Approved direction — implement phase-by-phase only from this document.  
> **Last updated:** 2026-07-09  
> **Audience:** Admin/Suchak operators, developers, reviewers

---

## 0. एका वाक्यात उद्देश

**Bulk Intake = biodata शोधणे + चुकीची माहिती दुरुस्त करणे + योग्य/अयोग्य ठरवणे + फक्त योग्य लोकांना WhatsApp परवानगी + नंतर automatic registration.**

**Bulk Intake profile factory नाही.** Profile फक्त governed path (`IntakeApprovalService` → `MutationService`) ने, consent नंतर.

---

## 1. मोठे नियम (कधीही भंग करू नका)


| नियम                                   | अर्थ                                              |
| -------------------------------------- | ------------------------------------------------- |
| `raw_ocr_text` immutable               | OCR मूळ text कधी बदलत नाही                        |
| `parsed_json` manually mutate नाही     | Machine parse output थेट edit नाही                |
| Final truth = `approval_snapshot_json` | Admin/user/suchak reviewed snapshot               |
| Bulk item मध्ये parsed copy नाही       | Profile data bulk row मध्ये store नाही            |
| `item_status` = technical only         | pending/parsed/failed — business status येथे नाही |
| No direct profile insert               | `MutationService` bypass नाही                     |
| Free OCR first                         | Paid AI/OCR या funnel साठी नाही                   |
| History remembers forever              | "लग्न झाले / नको" — भविष्यात auto block           |


**Authority chain (बदलायची नाही):**

```
IntakeCreationService → ParseIntakeJob → IntakeApprovalService → MutationService
```

---

## 2. संपूर्ण प्रवाह (50 biodata उदाहरण)

```
WhatsApp / upload → Bulk batch
        ↓
Free OCR + parse → parsed_json
        ↓
Admin/Suchak correction (7 fields) → approval_snapshot_json
        ↓
AUTOMATIC eligibility gate
  ├─ already on website?
  ├─ past history: married / not interested / wrong number / no suggest?
  ├─ fuzzy identity match (name + DOB + gender, spelling ok)
  └─ secondary: height, education (confirm)
        ↓
Survivors only (उदा. 50 → 10)
        ↓
WhatsApp permission message
  [हो] [नको] [लग्न झाले] [चुकीचा नंबर]
        ↓
"हो" → Smart registration (WhatsApp / web / app / blank form)
        ↓
approval_snapshot_json update (user edits)
        ↓
Governed profile create
```

**उदाहरण:**


| टप                      | संख्या      |
| ----------------------- | ----------- |
| Upload                  | 20          |
| Already on site         | −5 → 15     |
| Married (old record)    | −4 → 11     |
| Said don't suggest      | −1 → 10     |
| **WhatsApp permission** | **10**      |
| Said yes                | ~6          |
| Auto registration       | ~6 profiles |


---

## 3. आत्ता काय झाले आहे / काय चुकीचे झाले

### ✅ ठेवायचे (पाया बरोबर)


| केले                                                  | Phase       |
| ----------------------------------------------------- | ----------- |
| Admin 7-field correction workspace                    | A           |
| Save via `IntakeHumanReviewSnapshotService`           | A           |
| Duplicate hints (mobile, name+DOB, hash)              | B (partial) |
| Manual duplicate mark/clear                           | B (partial) |
| Free OCR parse path                                   | A           |
| Screening advisor (read-only suggestion)              | C (partial) |
| Manual screening in `item_meta_json.screening_review` | C (partial) |
| Screening filter pills + Ready for Consent            | C (partial) |


### ❌ चुकीचे / चुकीच्या क्रमाने


| समस्या                                                    | योग्य दिशा                                              |
| --------------------------------------------------------- | ------------------------------------------------------- |
| Admin ला प्रत्येकाला "Eligible/Stopped" क्लिक करावे लागते | **Automatic gate** default                              |
| 4 screening services + 7 UI pills                         | **1 eligibility engine** + साधे UI                      |
| Permanent cross-intake history नाही                       | **Identity history record** (mobile + fuzzy name + DOB) |
| Hints आहेत पण auto-stop नाहीत                             | Hints → **automatic reject** + admin override           |
| Ready for Consent UI लवकर आले                             | Gate + history **आधी**, नंतर queue                      |
| Duplicate layer correction मध्ये मिसळले                   | स्वतंत्र Phase B पूर्ण करा                              |


### 🔧 Phase R (पहिले करायचे) — Refactor, नवीन feature नाही

1. Screening services एकत्र करा → `BulkIntakeEligibilityService` (नाव अंतिम implement वेळी)
2. Batch page UI साधी करा — filters कमी, स्पष्ट labels
3. `status=needs_review` (technical) vs `screening=needs_review` (business) — नावे वेगळी करा
4. Automatic gate logic hints वर बांधा; manual screening = **override** म्हणून ठेवा
5. जुने C.1–C.4 tests green ठेवा

---

## 4. Phase order (फक्त या क्रमाने implement करा)


| Phase | नाव                    | एका वाक्यात                                     |
| ----- | ---------------------- | ----------------------------------------------- |
| **R** | Refactor               | जे आहे ते साफ करा, UI/service गोंधळ कमी         |
| **B** | Duplicate & History    | Auto match + permanent history + admin override |
| **C** | Eligibility Gate       | Automatic pass/fail — कोण WhatsApp ला योग्य     |
| **D** | WhatsApp Permission    | फक्त eligible ला permission message             |
| **E** | Smart Registration     | WhatsApp/web/app/blank form — user confirm      |
| **F** | Governed Profile Apply | `IntakeApprovalService` → `MutationService`     |
| **G** | Photo confirm          | नंतर                                            |
| **H** | Matching suggestions   | नंतर                                            |
| **I** | OCR learning hardening | नंतर                                            |


**एकाच वेळी एक phase.** पुढचा phase मागचा green + admin test complete नंतरच.

---

## 5. Phase details

---

### Phase R — Refactor

**Scope:**

- 4 screening services → 1 eligibility service (internal modules ok)
- UI: screening pills rename/simplify
- Manual screening = admin **override**, not primary gate
- No new routes, no migrations unless explicitly listed

**Out of scope:** WhatsApp, registration, new tables

**PowerShell tests:**

```powershell
cd E:\LaravelProjects\laravel-matrimony
php artisan optimize:clear
php artisan test --filter=AdminBulkIntakeCandidateCorrectionTest
php artisan test --filter=AdminBulkIntakeCandidateDisplayTest
php artisan test --filter=AdminBulkIntakeRoutesTest
```

**Admin panel check (5th standard):**

1. Login as admin
2. Bulk Intake → कोणताही batch उघडा
3. Page उघडतो का? (error नाही)
4. Correct candidate → Save → परत उघडा → माहिती दिसते का?
5. Screening badges/filters अजून काम करतात का?

**Git + Server:** [Section 10](#10-git--server-deploy)

---

### Phase A — Correction Workspace ✅ DONE

**7 editable fields only:** नाव, मोबाईल, DOB, उंची, लिंग, शिक्षण, ठिकाण

**Rules:** `approval_snapshot_json` only; no `parsed_json` / `raw_ocr_text` mutation

**Re-verify after each later phase.**

---

### Phase B — Duplicate & History

**Scope:**

- Automatic checks (no manual mark required):
  - Same mobile on existing profile/intake
  - Fuzzy name + DOB + gender match (`IntakeDuplicateFieldMatchEvaluator` reuse)
  - Secondary: height, education confirm
  - File/content hash hints
- **Permanent history record** by identity (mobile + fuzzy name + DOB):
  - `already_married`, `not_interested`, `wrong_number`, `do_not_suggest`, `no_response`
  - WhatsApp reply किंवा admin mark — दोन्ही history मध्ये
  - भविष्यात कोणत्याही biodata वरून auto block
- Admin **override**: "चुकीचा block — पुढे घ्या" / "हाताने बाहेर करा"
- UI: duplicate/history badge + reason; not mixed inside correction form

**Storage:** `item_meta_json` + intake history tables (no new candidate_master table)

**Out of scope:** WhatsApp send, profile create

**PowerShell tests:**

```powershell
cd E:\LaravelProjects\laravel-matrimony
php artisan optimize:clear
php artisan test --filter=BulkIntakeDuplicate
php artisan test --filter=AdminBulkIntakeCandidateDisplayTest
php artisan test --filter=AdminBulkIntakeCandidateCorrectionTest
```

**Admin panel check (5th standard):**

1. Same mobile दोन वेळा असलेला batch उघडा
2. दुसऱ्या row वर "आधीच आहे" सारखी खूण दिसते का?
3. पहिल्या व्यक्तीला "लग्न झाले" mark करा (किंवा test data)
4. नवीन biodata same mobile/nावाने upload करा
5. नवीन row **automatic बाहेर** पडतो का? (WhatsApp queue मध्ये नाही)
6. Admin "Override — पुढे घ्या" काम करतो का?

---

### Phase C — Eligibility Gate

**Scope:**

- One service: `eligibleForPipeline(item)` → `{ eligible: bool, reasons: [], source: auto|override }`
- Pass = ALL true:
  - Not blocked by history (Phase B)
  - Not duplicate of live profile
  - Not manual duplicate (unless overridden)
  - Usable mobile
  - Basic identity: name + gender + (DOB or age)
  - Not stopped by negative signals
- Admin override preserved
- Batch UI: simple buckets — `Eligible` / `Blocked` / `Needs check` (3 pills max)
- Remove/replace confusing 7-pill screening UI

**Out of scope:** WhatsApp send, registration

**PowerShell tests:**

```powershell
cd E:\LaravelProjects\laravel-matrimony
php artisan optimize:clear
php artisan test --filter=BulkIntakeEligibility
php artisan test --filter=AdminBulkIntakeCandidateDisplayTest
```

**Admin panel check (5th standard):**

1. Batch उघडा — 3 सोपे filters दिसतात का? (Eligible / Blocked / Needs check)
2. Mobile नसलेला उमेदवार → Blocked मध्ये जातो का?
3. "लग्न झाले" history असलेला → Blocked मध्ये जातो का?
4. सर्व बरोबर उमेदवार → Eligible मध्ये जातो का?
5. Override केल्यावर Eligible मध्ये येतो का?

---

### Phase D — WhatsApp Permission

**Scope:**

- फक्त Phase C `eligible` उमेदवारांना message
- Message:
  > नमस्कार, आम्ही नवरी-नवरा मॅट्रिमोनी आहोत. तुमच्या [नातेवाईक] चा biodata मिळाला. योग्य स्थळे सुचवू का? परवानगी द्या.
- Buttons: `[हो] [नको] [लग्न झाले] [चुकीचा नंबर]`
- Reply → history update (Phase B) + status update
- `intake_whatsapp_sessions` / `intake_whatsapp_messages` reuse
- No response → `no_response` history; don't re-contact aggressively

**Out of scope:** Registration form, profile create

**PowerShell tests:**

```powershell
cd E:\LaravelProjects\laravel-matrimony
php artisan optimize:clear
php artisan test --filter=BulkIntakeWhatsAppConsent
```

**Admin panel check (5th standard):**

1. Eligible row वर "Send permission" (किंवा batch send) दिसते का?
2. Send केल्यावर status "Permission sent" दिसते का?
3. Test phone वर message आला का?
4. "नको" reply केल्यावर row Blocked मध्ये जातो का?
5. "लग्न झाले" reply — पुन्हा नवीन biodata आला तर auto block होतो का?

---

### Phase E — Smart Registration

**Scope:** फक्त `consent_received` (हो) उमेदवार

#### Data display rule

```
approval_snapshot_json (priority 1)
  → parsed_json (fallback)
  → master/lookup resolve → display text
```

User ला **display text** दिसेल — `पुरुष`, `BE Computer`, `पुणे` — id/code नाही.  
User confirm/edit → `approval_snapshot_json` update → मग profile.

#### Smart paths

**Step 1 — सर्व summary एकाच message मध्ये:**

```
✓ नाव: राहुल शर्मा
✓ लिंग: पुरुष
⚠ उंची: 5 ft 8 in
...
```

- `✓` = high confidence / admin verified
- `⚠` = low confidence / needs check

**Step 2 — System path निवडतो:**


| Path         | कधी                | User action                                    |
| ------------ | ------------------ | ---------------------------------------------- |
| 🟢 Fast      | सर्व ✓             | 1 button: `[नोंदणी पूर्ण करा]`                 |
| 🟡 Targeted  | 1–3 ⚠              | फक्त ⚠ वर `[बदल]` + `[उरलेली बरोबर — पुढे जा]` |
| 🔴 Full edit | 4+ ⚠ किंवा missing | Default: `[सर्व एकाच वेळी बदला]` → web form    |


**Step 3 — नेहमी खाली 3 पर्याय (लहान text):**

```
• [वेबवर सर्व edit करा]
• [App/Website वरून नोंदणी]
• [रिकामा form WhatsApp वर मागवा]
```

#### Photo

- Biodata मधून photo असेल → दाखव → `[हो] [नवीन पाठव]`
- नसेल → `[फोटो पाठवा]` किंवा skip

**Out of scope:** Matching, learning promotion

**PowerShell tests:**

```powershell
cd E:\LaravelProjects\laravel-matrimony
php artisan optimize:clear
php artisan test --filter=BulkIntakeRegistration
```

**Admin panel check (5th standard):**

1. "हो" reply केलेला उमेदवार — status "Consent received" दिसतो का?
2. Test phone वर summary message आला का? (मराठी text, codes नाही)
3. सर्व बरोबर असेल तर **एक** "नोंदणी पूर्ण करा" button दिसतो का?
4. Web link उघडला → fields भरलेले दिसतात का?
5. Save नंतर admin panel मध्ये updated माहिती दिसते का?

---

### Phase F — Governed Profile Apply

**Scope:**

- फक्त registration complete + user confirmed
- `IntakeApprovalService` → `MutationService::applyApprovedIntake()`
- No direct DB write
- Bulk intake row links to created profile

**PowerShell tests:**

```powershell
cd E:\LaravelProjects\laravel-matrimony
php artisan optimize:clear
php artisan test --filter=BulkIntakeProfileApply
php artisan test --filter=IntakeApproval
```

**Admin panel check (5th standard):**

1. Registration पूर्ण झालेल्या row वर profile link दिसतो का?
2. Profile उघडला → नाव/मोबाईल बरोबर आहे का?
3. Duplicate profile तयार झाला नाही ना? (same mobile शोधा)

---

### Phase G / H / I — Later (outline only)


| Phase        | Scope                                           |
| ------------ | ----------------------------------------------- |
| G — Photo    | Confirm biodata photo or request new            |
| H — Matching | `MatchingService` reuse, privacy-safe cards     |
| I — Learning | Golden dataset, OCR regression, promotion rules |


Detail when Phase F complete.

---

## 6. Role boundaries


| Role   | Bulk intake                                       |
| ------ | ------------------------------------------------- |
| Admin  | All batches, correct, override, send consent      |
| Suchak | Own/assigned batches only — **separate UI later** |
| User   | No bulk access; self data after consent only      |


---

## 7. Storage map (no new tables until Phase B review)


| Data             | Where                                                    |
| ---------------- | -------------------------------------------------------- |
| OCR evidence     | `biodata_intake_ocr_attempts`, `raw_ocr_text`            |
| Machine parse    | `biodata_intakes.parsed_json`                            |
| Human reviewed   | `biodata_intakes.approval_snapshot_json`                 |
| Business flags   | `bulk_intake_batch_items.item_meta_json`                 |
| Identity history | Phase B: meta + intake history (TBD in implement prompt) |
| WhatsApp         | `intake_whatsapp_sessions`, `intake_whatsapp_messages`   |


---

## 8. What we deliberately do NOT build

- `candidate_master` table (now)
- CentralIntakeEngineService
- Paid OCR in this funnel
- Direct profile DB insert
- Bulk item parsed_json copy
- Auto WhatsApp before eligibility gate
- 10+ filter pills on batch page

---

## 9. PowerShell — सर्व tests (संपूर्ण suite)

Codex/Cursor मध्ये test लांब चालतात. **PowerShell मध्ये चालवा:**

```powershell
# Project folder मध्ये जा
cd E:\LaravelProjects\laravel-matrimony

# Cache clear
php artisan optimize:clear

# Bulk intake related (जलद — प्रत्येक phase नंतर हे चालवा)
php artisan test --filter=AdminBulkIntakeCandidateCorrectionTest
php artisan test --filter=AdminBulkIntakeCandidateDisplayTest
php artisan test --filter=AdminBulkIntakeRoutesTest

# Phase-specific (जेव्हा add केले तेव्हा)
php artisan test --filter=BulkIntakeDuplicate
php artisan test --filter=BulkIntakeEligibility
php artisan test --filter=BulkIntakeWhatsAppConsent
php artisan test --filter=BulkIntakeRegistration
php artisan test --filter=BulkIntakeProfileApply

# संपूर्ण test suite (वेळ लागेल — deploy आधी)
php artisan test

# Whitespace check
git diff --check
```

**टीप:** `--filter=` मध्ये file नाव किंवा test नाव दोन्ही चालते. Phase complete झाल्यावरच full `php artisan test` चालवा.

---

## 10. Git + Server deploy

### A) Local — code save + GitHub वर पाठवा

```powershell
cd E:\LaravelProjects\laravel-matrimony

# काय बदलले ते पहा
git status

# सर्व बदल stage करा
git add .

# Save (commit) — message बदला
git commit -m "Phase X: short description"

# GitHub वर पाठवा
git push origin main
```

### B) Server — live site update

```powershell
# Server वर login
ssh navri@31.97.228.15
```

Server terminal मध्ये:

```bash
cd /home/navri/htdocs/navrimilenavryala.com

# नवीन code घ्या
git pull origin main

# Cache clear
php artisan optimize:clear

# जर migration असेल (phase मध्ये सांगेल तेव्हाच)
php artisan migrate --force

# queue worker असेल तर restart (WhatsApp phase नंतर)
# php artisan queue:restart
```

### C) Deploy नंतर — 5 मिनिटे admin check

1. Browser: `https://navrimilenavryala.com/admin` → login
2. Bulk Intake → batch उघडा → error नाही ना?
3. त्या phase चा main feature एकदा वापरून पहा
4. जुने features (correction save) अजून काम करतात ना?

---

## 11. प्रत्येक phase साठी checklist

```
[ ] Phase scope बाहेर काही केले नाही
[ ] PowerShell tests green
[ ] git diff --check clean
[ ] Admin panel 5th-standard check केले
[ ] git commit + push
[ ] server pull + optimize:clear
[ ] live admin panel verify
[ ] User ला "phase complete" सांगितले
```

---

## 12. Phase implementation prompt template

पुढच्या window मध्ये Codex/Cursor ला द्यायचा prompt:

```text
Implement Phase [X] from docs/BULK-INTAKE-BLUEPRINT.md only.

Read blueprint first. No assumptions.
Preserve all earlier phase behavior.
No scope outside this phase.

After implementation:
- Run PowerShell tests listed for Phase [X]
- Provide: files changed, boundaries confirmed, admin check steps, rollback notes
- Do NOT commit unless asked
```

---

## 13. Rollback

```powershell
# शेवटचा commit काढायचा (local only — push केले नसेल)
git revert HEAD

# Specific commit (push झाले तर)
git revert <commit-hash>
git push origin main

# Server वर revert नंतर
ssh navri@31.97.228.15
cd /home/navri/htdocs/navrimilenavryala.com
git pull origin main
php artisan optimize:clear
```

---

## 14. Related docs (historical — blueprint override करते)


| File                                               | Content                              |
| -------------------------------------------------- | ------------------------------------ |
| `docs/phase-c2-manual-screening-summary.md`        | C.2 detail (superseded by Phase R/C) |
| `docs/phase-c3-screening-queue-filters-summary.md` | C.3 detail (superseded by Phase R/C) |
| `c:\Users\shank\Downloads\bulk-intake.txt`         | Original planning draft              |


**Conflict असेल तर `BULK-INTAKE-BLUEPRINT.md` final आहे.**

---

## 15. Next step

**Start Phase R (Refactor)** — screening services consolidate, UI simplify, automatic gate foundation.  
C.1–C.4 behavior preserve; tests green.

---

*End of blueprint.*