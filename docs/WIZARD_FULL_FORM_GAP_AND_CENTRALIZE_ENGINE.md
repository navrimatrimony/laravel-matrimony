# Wizard Full Form — Gap Analysis & Centralize Engine

**URL:** `http://127.0.0.1:8000/matrimony/profile/wizard/full`  
**Purpose:** Form आपल्याला **centralize engine** (Controlled Option Engine) मध्ये develop करायचे आहे; या दस्तऐवजात **काय काय फॉर्ममध्ये नाही** आणि **काय काय centralize engine वापरून बनवायचे** आहे ते स्पष्ट केले आहे.

---

## 1. Full form मध्ये आता काय include आहे (full.blade.php)

| Include | Section |
|--------|---------|
| basic_info | Basic Information + Marital engine (inside basic_info) |
| physical | Physical details |
| personal_family | Education, Career & Family (education-career + parent + family-overview) |
| siblings | Siblings |
| relatives | Relatives |
| alliance | Alliance |
| property | Property |
| horoscope | Horoscope |
| contacts | Contacts |
| about_me | About me (narrative + expectations) |
| about_preferences | Partner preferences |
| photo | Photo |

---

## 2. Full form मध्ये **कमी आहे** (Missing)

### 2.1 Location section — **नाही**

- **Controller:** `getSectionViewData('full')` मध्ये `getSectionViewData('location', $profile)` **merge केले आहे** (data पाठवतो).
- **View:** `full.blade.php` मध्ये `@include('...location')` **नाही**. त्यामुळे full edit मध्ये **residence / work address** edit करता येत नाही.
- **Action:** Full view मध्ये Location section add करावा (आणि controller मध्ये location snapshot full साठी apply होतो का ते verify करावे).

### 2.2 About Me data — **full लोड झाल्यावर रिकामे**

- **Controller:** `getSectionViewData('full')` मध्ये **about-me** call **नाही**. म्हणून `extendedAttrs` (narrative_about_me, narrative_expectations) full page लोड करताना **pass होत नाही**.
- **View:** about_me.blade.php include आहे, पण `$extendedAttrs ?? new \stdClass()` रिकामे मिळतं.
- **Action:** Full merge मध्ये `getSectionViewData('about-me', $profile)` add करावा जेणेकरून About Me चे मूल्ये भरलेली दिसतील.

### 2.3 Legal section — **wizard मध्ये अस्तित्वात नाही**

- **Wizard steps:** कोणत्याही step मध्ये **legal** नाही (`ProfileWizardController` मध्ये section list मध्ये legal नाही).
- **View:** `legal.blade.php` **नाही** wizard/sections मध्ये.
- **Data:** MutationService / snapshot मध्ये `legal_cases` आहे; परंतु manual wizard/full मध्ये legal cases edit करण्याचा कोणताही UI नाही.
- **Action:** जर legal cases edit करायचे असतील तर नवीन section **legal** (view + controller case + validation + snapshot) add करावा. Registry मध्ये `entity.legal_case_type` आधीच आहे.

---

## 3. Centralize engine — काय वापरले जात नाही

**Controlled Option Engine** = `ControlledOptionRegistry` + `ControlledOptionEngine` + `ControlledOptionLabelResolver` + **`ControlledOptionFormEngine`** + **`<x-forms.controlled-select>`** component.

- **Registry मध्ये असलेले field keys (उदा.):**
  - basic: `basic.gender`, `basic.marital_status`
  - core: `core.religion`, `core.caste`, `core.sub_caste`
  - physical: `physical.complexion`, `physical.blood_group`, `physical.physical_build`
  - education: `education.income_currency`, `education.working_with`, `education.profession`
  - preference: `preference.religion`, `preference.caste` (multi)
  - horoscope: `horoscope.nadi`, `horoscope.gan`, `horoscope.rashi`, `horoscope.nakshatra`, `horoscope.yoni`, `horoscope.mangal_dosh_type`
  - entity: `entity.address_type`, `entity.contact_relation`, `entity.child_living_with`, `entity.asset_type`, `entity.ownership_type`, `entity.legal_case_type`

**Wizard मध्ये आज:**
- **कोणत्याही view मध्ये `<x-forms.controlled-select>` किंवा ControlledOptionFormEngine चा direct उपयोग नाही.**
- Dropdowns controller मधून collections मिळवतात (उदा. `$genders`, `$complexions`, `$rashis`) आणि raw `<select>` / custom components (उदा. religion-caste-selector, horoscope-engine) वापरतात.

त्यामुळे **form centralize engine म्हणून develop करायचे** म्हणजे:
- जे जे fields **ControlledOptionRegistry** मध्ये आहेत, तेथे **dropdown/select UI** साठी **`<x-forms.controlled-select>`** वापरावे (field key = registry key, name = form name, selected = current value).
- Validation आणि save वेळेस **ControlledOptionEngine** / FormEngine चा उपयोग करावा (आधीच MutationService मध्ये engine वापर आहे; wizard validation मध्येही same engine rules लागू करावेत).

---

## 4. Centralize engine अंतर्गत बदल करण्यासाठी सूचित ठिकाणे

| Section / File | Controlled fields (Registry key) | सध्याचा UI | सूचित बदल |
|----------------|-----------------------------------|------------|------------|
| basic_info | gender → `basic.gender` | Custom Male/Female buttons + hidden | Optional: ControlledSelect single (किंवा existing buttons + engine for validation only). |
| basic_info | religion, caste, sub_caste → `core.religion`, `core.caste`, `core.sub_caste` | x-profile.religion-caste-selector | Component आतून FormEngine + ControlledSelect वापरावा किंवा replace with three ControlledSelects. |
| basic_info (marital_engine) | marital_status → `basic.marital_status`; child_living_with → `entity.child_living_with` | Raw selects / collections | Dropdowns → ControlledSelect (field key अनुसार). |
| physical | complexion, blood_group, physical_build → `physical.*` | x-physical-engine (आतून models) | Physical engine आतून ControlledSelect वापरावा. |
| personal_family (education/income) | income_currency, working_with, profession → `education.*` | Components / raw | Dropdowns → ControlledSelect. |
| horoscope | rashi, nakshatra, gan, nadi, yoni, mangal_dosh_type → `horoscope.*` | x-profile.horoscope-engine (collections) | Horoscope engine आतून FormEngine + ControlledSelect वापरावा. |
| contacts | contact_relation → `entity.contact_relation` | contactRelations collection | Relation dropdown → ControlledSelect. |
| about_preferences | preferred religions/castes → `preference.religion`, `preference.caste` | allReligions, allCastes | Multi-select → ControlledSelect (multiple). |
| property | asset_type, ownership_type → `entity.asset_type`, `entity.ownership_type` | Raw/collections | Dropdowns → ControlledSelect. |
| Legal (नवीन section) | legal_case_type → `entity.legal_case_type` | — | नवीन section मध्येच ControlledSelect वापरावा. |

---

## 5. थोडक्यात — “आज काय कमी आहे” आणि “काय करायचे”

**आज full form मध्ये कमी आहे:**
1. **Location** — view मध्ये include नाही (data controller पासून येतो).
2. **About Me data** — full merge मध्ये about-me data नाही, म्हणून About Me रिकामे दिसते.
3. **Legal section** — wizard मध्ये अस्तित्वातच नाही (view + controller + snapshot).

**Centralize engine मध्ये develop करण्यासाठी:**
- सर्व master-backed / dropdown fields **ControlledOptionRegistry** नुसार **`<x-forms.controlled-select>`** आणि **ControlledOptionFormEngine** वापरून बनवावेत.
- Validation आणि save path मध्ये **ControlledOptionEngine** चा उपयोग करावा (inactive/disallowed values reject).

हे दस्तऐवज पुढे पाठपुरावा साठी वापरता येईल: प्रथम Location + About Me data fix, नंतर section-by-section ControlledSelect लागू करणे, आणि शेवटी Legal section (आवश्यक असल्यास) add करणे.
