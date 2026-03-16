# OCR → Parsed JSON → Form: Step-by-step plan (temporary)

> NOTE: Working notes file. Safe to delete after implementation.

## Goals
- **G1**: OCR मधील 100% माहिती काही ना काही key म्हणून `parsed_json` मध्ये यावी (किमान raw / *_notes / *_other फील्ड्समधून).
- **G2**: `parsed_json` मधील 100% माहिती Intake Preview फॉर्ममध्ये **कुठेतरी** दिसावी (editable किंवा read‑only), काहीही लपून राहू नये.
- **G3**: Existing rules न मोडता – फक्त **additive** बदल.

---

## Phase A – OCR → Parsed JSON (काहीही गाळू नको)

### A1. Core text safety net
- [ ] `BiodataParserService::parse()` मध्ये:
  - [ ] Raw text normalise झाल्यानंतर (sections split झाल्यावर) उरलेला न मॅप झालेला टेक्स्ट `core.additional_notes` मध्ये टाकणे.
  - [ ] `extended_narrative` आधीच असेल तर, त्यात merge करणे (array / object safe merge).

### A2. Family structures (siblings + relatives)
- [ ] `extractFamilyStructures()`:
  - [ ] Sibling आणि relatives regex जी match होतात त्यांचे **सगळे fragments** `familyStructures['raw_lines']` सारख्या field मध्ये ठेवणे.
  - [ ] जे fragments structured `siblings` / `relatives` मध्ये map होत नाहीत, ते `familyStructures['residual_text']` मध्ये concatenate करणे.
- [ ] `structureRelativeRows()` / `splitRelativeRowsByShri()`:
  - [ ] Failure cases मध्ये row drop करण्याऐवजी, किमान `{ relation_type: ?, name: '', notes: original_line }` म्हणून push करणे.

### A3. Final parsed_json completeness
- [ ] `parse()` result तयार करताना:
  - [ ] `core.other_relatives_text` मध्ये `familyStructures['other_relatives_text']` + `residual_text` merge करणे.
  - [ ] `relatives` array मध्ये किमान notes level data हमखास ठेवणे (कोणतीही line silently drop नको).

---

## Phase B – Parsed JSON → Preview Form

### B1. Core section
- [ ] `IntakeController::preview()`:
  - [x] `coreData` मधील unknown keys `intakeProfile` वर copy करणे (झाले आहे).
  - [ ] `annual_income`, `family_income`, `other_relatives_text`, इ. सारखी fields कुठे UI मध्ये surface होतात ते तपासून, नसतील तर:
    - [ ] Basic Info / Property / About me सेक्शन्समध्ये read‑only text/textarea म्हणून दाखवणे.

### B2. Siblings
- [ ] Priority order:
  1. `parsed_json.siblings` → direct mapping to `profileSiblings`.
  2. जर `siblings` रिकामे असतील तर: relatives मधून बहिण/भाऊ (`relation`/`relation_type`) काढून सिब्लिंग rows बनवणे.
- [x] Helper methods `extractSiblingRowsFromParsedRelatives` / `mergeParsedSiblingsIntoProfileSiblings` add केले.
- [ ] Ensure mapping fields:
  - [ ] `name` → Name
  - [ ] `contact_number` → Mobile
  - [ ] `occupation` → Occupation
  - [ ] address‑like notes (असतील तर) → Address / Additional info

### B3. दाजी (sister's husband)
- [x] `partitionAndStructureRelativesForIntake()` मध्ये दाजी rows वेगळे घेऊन `mergeDajiIntoSiblings()` मध्ये spouse म्हणून merge करणे.
- [ ] Spouse details mapping enrich करणे:
  - [ ] दाजिच्या struct मधील `contact_number` → Spouse Mobile
  - [ ] `occupation_title` → Spouse Occupation
  - [ ] `address_line` → Spouse Address / Notes

### B4. Relatives – Paternal vs Maternal vs Ajol
- [x] Paternal mapping: `चुलते` → `paternal_uncle`, `आत्या` → `paternal_aunt`, इ.
- [x] आजोळ → `maternal_address_ajol` (Maternal address – Ajol) मध्ये map.
- [ ] Maternal relatives (मामा, मावशी, इ.) साठी full mapping cross‑check करणे SSOT टेबलशी (`PHASE-5 SSOT` मधील relatives matrix).
- [ ] जे relatives कोणत्याही known type मध्ये बसत नाहीत ते:
  - [ ] `Other Relatives` textarea मध्ये append करणे (आत्ताच्या `other_relatives_text` flow ला reuse करून).

### B5. Generic JSON surfacing
- [ ] Preview Blade (`resources/views/intake/preview.blade.php`):
  - [x] Parsed JSON viewer जोडले आहे (top‑right).  
  - [ ] Optional: Dev‑only small panel per section (behind env flag) जे त्या section साठी un-mapped keys दाखवेल → पुढील mapping साठी मदत.

---

## Phase C – Testing (at least one concrete biodata)

- [ ] ज्या biodata साठी तू सध्या example देत आहेस (भाऊ/बहिण/दाजी/आजोळ):
  - [ ] Admin मध्ये त्याचा `parsed_json` export करणे.
  - [ ] वरच्या rules लागू केल्यावर:
    - [ ] Raw OCR मधली प्रत्येक ओळ either:
      - structured field (core/siblings/relatives/contacts/…)
      - किंवा `*_notes` / `*_text` fallback मध्ये आहे याची खात्री.
    - [ ] Preview form मध्ये प्रत्येक structured field योग्य जागी दिसतो का cross‑check करणे.

---

हा plan reference म्हणून ठेवू. पुढच्या स्टेपमध्ये आपण Phase A → मग Phase B वर कोड बदल करू; काम पूर्ण झाल्यावर `_ocr-json-plan.md` फाइल delete करू.

