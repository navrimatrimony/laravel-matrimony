# Astrological Details Engine — Nakshatra, Charan, Rashi (model-based)

## उद्देश
- **DOB पासून API वापरून nakshatra/rashi/charan काढणार नाही.**  
- फक्त **model मधील fields** (rashi_id, nakshatra_id, charan) वरून engine implement करणार.  
- User थेट Rashi, Nakshatra, Charan निवडेल/टाकेल; तेच `profile_horoscope_data` मध्ये save होईल.

## डेटा स्त्रोत (आधीच अस्तित्वात)
- **Model:** `App\Models\ProfileHoroscopeData`
- **Table:** `profile_horoscope_data`
- **Fields:** `rashi_id`, `nakshatra_id`, `charan` (आणि gan_id, nadi_id, yoni_id, mangal_dosh_type_id, devak, kul, gotra)
- **Master tables:** `master_rashis`, `master_nakshatras` (आणि इतर lookups)

## Engine कसे implement करावे

### 1. Reusable Blade component
- **Component name:** `astrological-details-engine` (किंवा `horoscope-core-engine`)
- **Props:**  
  - `rashiId`, `nakshatraId`, `charan` (current values)  
  - `rashis` (Collection), `nakshatras` (Collection)  
  - `namePrefix` => `'horoscope'` (form name = `horoscope[rashi_id]`, `horoscope[nakshatra_id]`, `horoscope[charan]`)  
  - `readOnly`, `errors` (optional)
- **UI:** एकच card/block — तीन फील्ड्स:
  - **Rashi:** dropdown (master_rashis)
  - **Nakshatra:** dropdown (master_nakshatras)
  - **Charan:** select 1–4 (किंवा number input min=1 max=4)
- **कोणतीही API कॉल नाही** — फक्त form post वर controller मध्ये `horoscope.rashi_id`, `horoscope.nakshatra_id`, `horoscope.charan` save.

### 2. Controller (आधीच आहे)
- `ProfileWizardController` मध्ये `horoscope` section साठी आधीच:
  - `getSectionViewData('horoscope')` → `profile_horoscope_data`, `rashis`, `nakshatras` pass होतात.
  - Save वेळी `horoscope` array मधून `rashi_id`, `nakshatra_id`, `charan` घेऊन `profile_horoscope_data` मध्ये save होतो.
- **बदल:** view मध्ये फक्त Rashi/Nakshatra/Charan चा भाग नव्या component मध्ये ओढून देणे:  
  `<x-astrological-details-engine :rashis="..." :nakshatras="..." :values="..." name-prefix="horoscope" />`

### 3. Horoscope section view मध्ये
- आत्ता: सगळे fields एका grid मध्ये (rashi, nakshatra, charan, gan, nadi, yoni, …).
- नवा flow:  
  - **Rashi, Nakshatra, Charan** → `<x-astrological-details-engine />` (एक compact card, education-occupation-income प्रमाणे).  
  - बाकीचे (Gan, Nadi, Yoni, Mangal Dosh, Devak, Kul, Gotra) तसेच grid मध्ये ठेवता येतील किंवा स्वतंत्र block.

### 4. Snapshot / display
- `ManualSnapshotBuilderService` आणि profile show पृष्ठावर आधीच `rashi_id`, `nakshatra_id`, `charan` वापरले आहेत (relation `rashi`, `nakshatra`).
- Engine फक्त **input/save** साठी; snapshot logic सध्याचेच राहील.

### 5. Summary
| गोष्ट | कृती |
|--------|------|
| DOB → API | वापरू नये; काढून टाका जर कुठे असेल |
| Model | `ProfileHoroscopeData`: rashi_id, nakshatra_id, charan (बदल नाही) |
| UI | नवा `<x-astrological-details-engine>` — Rashi, Nakshatra, Charan dropdown/select |
| Save | सध्याचा wizard save (horoscope array → profile_horoscope_data) |
| Max | Charan 1–4 मर्यादित |

पुढचा पाऊल: `resources/views/components/astrological-details-engine.blade.php` तयार करणे आणि `horoscope.blade.php` मध्ये तो include करणे.
