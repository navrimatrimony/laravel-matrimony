# Education / Occupation / Income Engine — Specification

## 1. कोणती फील्ड्स असतील (as-is + engine scope)

| Field | Type | Storage | Section |
|-------|------|---------|---------|
| **highest_education** | Free text | `matrimony_profiles.highest_education` | personal-family |
| **specialization** | Free text | `matrimony_profiles.specialization` | personal-family |
| **occupation_title** | Free text | `matrimony_profiles.occupation_title` | personal-family |
| **company_name** | Free text | `matrimony_profiles.company_name` | personal-family |
| **annual_income** | Number (float) | `matrimony_profiles.annual_income` | personal-family |
| **family_income** | Number (float) | `matrimony_profiles.family_income` | personal-family |
| **income_currency_id** | FK (dropdown) | `matrimony_profiles.income_currency_id` | personal-family |

**Engine मध्ये फक्त हे 7 फील्ड्स** ठेवता येतात. Parent (father/mother) आणि family overview (family_type_id, family_status, family_values, family_annual_income) वेगळ्या components मध्ये आहेत — ते या engine मध्ये गाठणे optional.

---

## 2. कोणता फील्ड कोणत्या फील्डवर dependent आहे

**स्पष्ट नियम: "Y depends on X" = Y चा अर्थ/options X वर अवलंबून.**

| जो फील्ड अवलंबून आहे (dependent) | ज्या फील्डवर अवलंबून आहे (depends on) | कसं अवलंबून |
|-----------------------------------|----------------------------------------|---------------|
| **annual_income** | **income_currency_id** | annual_income चे unit = निवडलेली currency (INR/USD/…). Currency निवडल्याशिवाय amount चा unit स्पष्ट नाही. |
| **family_income** | **income_currency_id** | family_income चे unit = निवडलेली currency. Same as above. |
| **income_currency_id** | — | कोणत्याही दुसऱ्या फील्डवर अवलंबून नाही. Default: INR. |
| **highest_education** | — | अवलंबून नाही. |
| **specialization** | — | सध्या अवलंबून नाही. (पुढे highest_education dropdown आणल्यास specialization options education वर depend करू शकतात.) |
| **occupation_title** | — | अवलंबून नाही. |
| **company_name** | — | अवलंबून नाही. |

**एक ओळीत:**  
- **income_currency_id** वर **annual_income** आणि **family_income** दोन्ही dependent (त्याच currency मध्ये दोन्ही amount).  
- बाकीची ५ फील्ड्स (highest_education, specialization, occupation_title, company_name, आणि currency स्वतः) कोणत्याही दुसऱ्या फील्डवर **dependent नाहीत**.

---

## 3. कोणते options (dropdown / master) आहेत

| Field | Options source | Notes |
|-------|----------------|-------|
| **income_currency_id** | **MasterIncomeCurrency** (`master_income_currencies`) | `id`, `code` (INR, USD, …), `symbol`, `is_default`, `is_active`. Controller: `MasterIncomeCurrency::where('is_active', true)->get()`. |
| **highest_education** | — | Free text (no master). |
| **specialization** | — | Free text (no master). |
| **occupation_title** | — | Free text (no master). |
| **company_name** | — | Free text (no master). |
| **annual_income / family_income** | — | Number input; unit = selected currency. |

सध्या **एकच dropdown**: Currency. बाकी सर्व text/number inputs.

---

## 4. यात आणखी काय add करता येईल (future)

| Addition | Purpose |
|----------|--------|
| **MasterEducation** | highest_education → dropdown (e.g. 10th, 12th, Graduate, Post Graduate, PhD). Search/filter साठी सोपे. |
| **MasterOccupation** (or categories) | occupation_title → dropdown + "Other" free text. |
| **Specialization options** | Education नंतर stream/specialization dropdown किंवा typeahead (education-dependent optional). |
| **Income range bands** | Exact number ऐवजी optional "range" (e.g. Below 2 Lakh, 2–5 Lakh, …) — family-overview मधील `family_annual_income` options सारखे. |
| **Employment type** | Government / Private / Business / Self-employed — नवीन optional field (column + master जर हवा असेल). |
| **Working sector / industry** | Industry dropdown (IT, Healthcare, Government, …) — optional. |

Engine बांधताना सध्या **as-is 7 फील्ड्स + currency options** पुरे; वरील सर्व "add" पुढच्या phase मध्ये master tables आणि optional fields म्हणून करता येतात.

---

## 5. Snapshot / Mutation (existing)

- **buildPersonalFamilySnapshot** आधीच ही core फील्ड्स म्युटेशन स्नॅपशॉट मध्ये टाकतो: `highest_education`, `specialization`, `occupation_title`, `company_name`, `annual_income`, `family_income`, `income_currency_id`.
- **MutationService** CORE मध्ये ही फील्ड्स apply करतो (FieldRegistry / fillable अनुसार).
- Engine बनवताना फक्त **एक partial (view)** + optional **namePrefix** (intake साठी) add करायचे; save path सध्या असाच राहील (personal-family snapshot).

---

## 6. Engine layout (suggested)

- **Row 1:** Highest Education | Specialization  
- **Row 2:** Occupation Title | Company Name  
- **Row 3:** Annual Income | Family Income | Income Currency (dropdown)  
- Spacing: Basic Info प्रमाणे (e.g. space-y-7, gap-7, label mb-2), सर्व inputs एकसमान height (e.g. h-[42px]).

या फाइलचा उपयोग education/occupation/income engine implement करताना single reference म्हणून करता येईल.
