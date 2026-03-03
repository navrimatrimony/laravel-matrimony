# Profile Fields & Forms — Audit Report

**Purpose:** Document where every profile field currently appears (which wizard section/screen), what the intended shaadi.com-style arrangement is, and what is wrong or inconsistent. Use this report to drive a solution (e.g. field placement, section order, missing/duplicate fields).

**Constraints (PHASE-5):** No deletion or renaming of existing DB columns; no structural change to MutationService/ConflictDetectionService. Additive fixes only (e.g. add field to a section, reorder within section, add to fillable if missing).

---

## 1. Wizard section order (current)

The wizard has these sections in this order (ProfileWizardController::SECTIONS):

| Order | Section key        | Screen name (conceptual) |
|-------|--------------------|---------------------------|
| 1     | basic-info         | Basic info                |
| 2     | marriages          | Marriages                 |
| 3     | personal-family    | Personal & family         |
| 4     | siblings           | Siblings                  |
| 5     | relatives          | Relatives                 |
| 6     | alliance           | Alliance                  |
| 7     | location           | Location                  |
| 8     | property           | Property                  |
| 9     | horoscope          | Horoscope                 |
| 10    | legal              | Legal                     |
| 11    | about-preferences  | About & preferences       |
| 12    | contacts           | Contacts                  |
| 13    | photo              | Photo                     |

There is also a **full** view that includes: basic_info, personal_family, siblings, relatives, alliance, location, property, horoscope, legal, contacts, about_preferences (and optionally marriage/children from basic_info). Photo is not in the full blade include list (full.blade.php); it may be on a separate step or missing from full edit.

---

## 2. Shaadi.com-aligned target (reference)

From Phase 5 completeness doc:

| Our section        | Shaadi equivalent | Expected fields (shaadi-aligned) |
|--------------------|-------------------|-----------------------------------|
| Basic info         | Step 1 primary    | Full name, DOB, gender, religion, caste, sub-caste, marital status, height, primary contact. Optional: birth time, birth place. Physical: weight, complexion, build, blood group. |
| Marriages          | Post-basic        | Marriage/divorce/widow details — status-based partials. |
| Personal & family  | Step 2            | Education, profession, income, family. Father/mother name & occupation. Brothers/sisters count. Family type. |
| Siblings           | Extended family   | Optional sibling rows. |
| Relatives          | Extended / About  | Relatives (name, relation, occupation, contact, notes). |
| Alliance           | Community/native  | Family surnames + native locations. |
| Location           | Step 2 location   | Current residence (city/area), address line. Work city. Native place. Optional: multiple addresses. |
| Property           | Lifestyle/assets  | Property summary, optional assets. |
| Horoscope          | Horoscope         | Rashi, nakshatra, gan, nadi, yoni, mangal dosh, etc. |
| Legal              | Optional          | Legal cases. |
| Contacts           | Step 1 + 2        | Primary + additional contacts. |
| About & preferences| About + Partner   | About me, expectations. Partner preference: city, caste, age min/max, income, education. |
| Photo              | Step 3            | Profile photo upload. |

---

## 3. Current field placement (by section)

### 3.1 basic-info

**Form fields present:**  
full_name, date_of_birth, gender_id, marital_status_id, religion_id, caste_id, sub_caste_id (via religion-caste-selector), height_cm, primary_contact_number.  
**Dependent blocks:** Marriages (status-based partials via JS fetch), Children (included children.blade.php).

**Issues:**
- Birth time, birth place (birth_city_id, birth_taluka_id, birth_district_id, birth_state_id) — in DB and in MutationService snapshot but **not on basic-info form** (doc says optional).
- Weight, complexion, physical build, blood group — doc says Basic or “moved to Personal & family”; currently **only in personal_family** (weight_kg, physical_build_id). complexion_id, blood_group_id **not on any section form** in the section-by-section wizard (only in full snapshot if at all).
- Primary contact appears in **both basic-info and contacts**; contacts section has primary_contact_number again. Duplication/confusion.
- Serious intent — in DB (serious_intent_id); **not on any wizard form** in current views.

### 3.2 marriages

**Form fields:** Status-based partials (marriage_year, divorce_year, divorce_status, separation year, etc.) via marriages_divorced, marriages_widowed, marriages_separated, marriages_married.  
**Also:** basic_info includes marriage partials and children; so marriage/children are partly under basic-info, partly a separate marriages section. The **marriages** section view (marriages.blade.php) itself contains a single marriage block with mixed visibility classes (marriage-divorced, marriage-separated, etc.) — possible duplication or confusion with basic_info’s marriage partials.

**Issues:**
- Two places for marriage data: (1) inside basic_info (marriage partials + children), (2) standalone marriages section. Unclear which is source of truth for “section” flow vs “full” flow.
- Children: only in basic_info (when status = divorced/separated/widowed). personal_family has a placeholder “Add children, education and career from Full Edit” — so children are not in personal_family form but are in basic_info.

### 3.3 personal-family

**Form fields:**  
highest_education, specialization, occupation_title, company_name, annual_income, family_income, income_currency_id, father_name, father_occupation, mother_name, mother_occupation, brothers_count, sisters_count, family_type_id, weight_kg, physical_build_id.

**Issues:**
- **MatrimonyProfile $fillable** does NOT include: specialization, occupation_title, company_name, annual_income, family_income, father_name, father_occupation, mother_name, mother_occupation, brothers_count, sisters_count. They exist in DB (migration add_phase5b_core_fields) and in ManualSnapshotBuilderService/ProfileWizardController snapshot build. If MutationService sets them via setAttribute, they may still save; if not, these fields will not persist when editing via model. **Needs verification/fix.**
- Education history (rows) and Career history (rows) — not in this form. Doc says “Education, profession, income” and “rows/degree-wise” may be separate. Currently education_history and career_history are only in **full** snapshot (ManualSnapshotBuilderService) and likely in full edit; **not in section personal-family** view.
- “Add children, education and career history from Full Edit” — confirms children/education/career rows are only in full, not in step-by-step wizard. So step-by-step wizard is missing education history and career history rows.

### 3.4 siblings

Section exists; form has sibling rows (typical: gender, marital status, occupation, city, notes). Not audited in detail here; dependency: profile_siblings table, snapshot key `siblings`.

### 3.5 relatives

Section exists; form has relative rows. Snapshot key `relatives`, table profile_relatives.

### 3.6 alliance

Section exists; family surnames + native. Snapshot key `alliance_networks`.

### 3.7 location

**Form fields (conceptual):**  
country_id, state_id, district_id, taluka_id, city_id (hidden), address_line; work_city_id, work_state_id (hidden); native_city_id, native_taluka_id, native_district_id, native_state_id (hidden).  
UI: “Search village or city (residence)”, “Address line”, “Work location”, “Native place”, and repeatable “Addresses (village/area)” with address_type, village_id, taluka, district, state, country, pin_code.

**Issues:**
- work_city_id, work_state_id exist in form (hidden); **MatrimonyProfile fillable** does not list work_city_id, work_state_id — may be in migration only. Needs to be in fillable if applied to profile.
- Address engine is shaadi.com-style (city/village search); that part is fine. Arrangement (residence vs work vs native) is on one screen; doc says “Current residence, address line, work city, native place” — matches.

### 3.8 property

Section exists; property_summary, property_assets. Not enumerated here.

### 3.9 horoscope

Section exists; rashi, nakshatra, etc. Snapshot key `horoscope`, table profile_horoscope_data.

### 3.10 legal

Section exists; legal_cases. Snapshot key `legal_cases`.

### 3.11 about-preferences

**Form fields:**  
preferences: preferred_city, preferred_caste, preferred_age_min, preferred_age_max, preferred_income_min, preferred_income_max, preferred_education.  
extended_narrative: narrative_about_me, narrative_expectations, additional_notes.

**Issues:**
- Partner preferences and “About me” are on one screen; matches doc.

### 3.12 contacts

**Form fields:**  
primary_contact_number, then repeatable contacts[] (contact_name, phone_number, relation_type, is_primary).

**Issues:**
- **Primary contact is in both basic-info and contacts.** So same data can be edited in two places; user may not know which one “wins” (likely the last saved section). Recommendation: show primary in one place only, or make one section the canonical (e.g. basic-info) and contacts only “additional” with a read-only primary line.

### 3.13 photo

**Form fields:**  
profile_photo (file upload).  
**Issues:**  
- Photo is required (required attribute on input). Doc says “Profile photo upload”; optional vs required is a product choice.  
- full.blade.php does **not** include photo.blade.php; so “Full Edit” form does not contain the photo section. Users editing via “full” cannot change photo without going to photo section separately.

---

## 4. Fields in DB / snapshot but not on any section form

- **birth_time** — in fillable and snapshot; not on basic-info form (doc: optional).
- **birth_city_id, birth_taluka_id, birth_district_id, birth_state_id** — in fillable; not on basic-info (doc: birth place optional).
- **native_city_id, native_taluka_id, native_district_id, native_state_id** — in fillable; used in location via “Native place” search; OK.
- **complexion_id, blood_group_id** — in fillable; **not on any section form** (doc said moved to Personal or optional in Basic).
- **serious_intent_id** — in fillable; **not on any wizard form**.
- **work_city_id, work_state_id** — used in location (hidden); **not in MatrimonyProfile $fillable** (check migration).
- **specialization, occupation_title, company_name, annual_income, family_income, father_name, father_occupation, mother_name, mother_occupation, brothers_count, sisters_count** — on personal_family form but **not in MatrimonyProfile $fillable**. Either add to fillable or ensure they are applied via MutationService/snapshot path and stored (e.g. extended or core apply).

---

## 5. Duplication and consistency

| Issue | Detail |
|-------|--------|
| Primary contact | basic-info and contacts both have primary_contact_number. |
| Marriage/children | basic_info includes marriage partials + children; marriages section also has a form. Need single source: either only basic_info (with marriage/children) or only marriages section; and children only once. |
| Full edit vs section | full.blade.php includes multiple sections but not photo; and “full” may not receive the same section-specific validation/flow as step-by-step. |
| Weight / body type | Only in personal_family; doc said “Weight and Body type moved from basic”. complexion and blood_group are not on any form. |

---

## 6. Section order vs user expectation

- Shaadi.com: Step 1 = Primary (name, DOB, gender, religion, caste, marital, height, contact); Step 2 = Other (education, profession, income, location, family); Step 3 = Photo; Step 4 = Mobile verify; Step 5 = Partner preference.  
- Our order: basic-info (≈ Step 1), then marriages, personal-family, siblings, relatives, alliance, location, property, horoscope, legal, about-preferences, contacts, photo.  
- So we have **contacts** before **photo**, whereas many flows put contact in Step 1 and photo as Step 3. Our “basic-info” has primary contact; then contacts section comes much later. So “primary contact” is early, “additional contacts” late — acceptable if clarified.  
- Photo is last; that matches “Step 3” in spirit.  
- About & preferences (partner preference) after location/property/horoscope/legal is fine.

---

## 7. Summary of problems (for solution design)

1. **Primary contact** in two sections (basic-info + contacts); decide canonical place and avoid double edit.
2. **Marriage/children** in two places (basic_info embedded + marriages section); clarify single source and hide or repurpose the other.
3. **MatrimonyProfile $fillable** missing several Phase5B fields (father_name, mother_name, specialization, occupation_title, company_name, annual_income, family_income, brothers_count, sisters_count, father_occupation, mother_occupation; and work_city_id, work_state_id if they exist on profile table). Either add to fillable or guarantee they are written via MutationService/snapshot.
4. **Fields not on any form:** birth_time, birth_place (birth_*), complexion_id, blood_group_id, serious_intent_id. Add to appropriate section (basic or personal) or explicitly mark “optional / not in wizard”.
5. **Full edit** does not include photo section; add photo to full or document that photo is only via photo section.
6. **Education history / Career history** (row-based) only in full snapshot; not in step-by-step personal-family. Either add to personal-family or document “only in Full Edit”.
7. **Arrangement/labels:** Ensure each section’s heading and field order match a single mental model (e.g. shaadi.com order within each block) and that required vs optional is consistent.

---

## 8. Files reference

- **Wizard sections order:** `App\Http\Controllers\ProfileWizardController::SECTIONS`
- **Section views:** `resources/views/matrimony/profile/wizard/sections/*.blade.php`
- **Full edit view:** `resources/views/matrimony/profile/wizard/sections/full.blade.php` (includes basic_info, personal_family, siblings, relatives, alliance, location, property, horoscope, legal, contacts, about_preferences; does not include photo or marriages as a separate include — marriages/children come from basic_info).
- **Snapshot build:** `App\Services\ManualSnapshotBuilderService::buildFullManualSnapshot`
- **Profile model:** `App\Models\MatrimonyProfile` ($fillable, $table = matrimony_profiles)
- **Doc reference:** `docs/Phase 5 completeness points.md` §1.3 (Our wizard vs shaadi.com mapping)

---

*End of report. Use this to propose a concrete field-to-section mapping and order, and to fix fillable/missing fields and duplicate placement.*
