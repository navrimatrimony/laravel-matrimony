# Geo JSON files – State, District, Subdistrict (Taluka), Village

या फोल्डरमध्ये भारत/इतर देशांच्या state, district, subdistrict (taluka), village यांच्या list च्या JSON files ठेवा.

## फाइल्स कुठे ठेवायच्या

सर्व JSON files **याच फोल्डरमध्ये** ठेवा:

| फाइल नाव        | अर्थ                          | DB टेबल  |
|------------------|-------------------------------|----------|
| `states.json`    | राज्ये                        | states   |
| `districts.json` | जिल्हे                        | districts|
| `talukas.json`   | तालुके / subdistricts         | talukas  |
| `villages.json`  | गावे                          | villages |

**Path:** `database/seeders/data/geo/`

- `database/seeders/data/geo/states.json`
- `database/seeders/data/geo/districts.json`
- `database/seeders/data/geo/talukas.json`
- `database/seeders/data/geo/villages.json`

## DB structure (reference)

Seeder लिहिताना ही structure वापरावी:

- **states:** `id`, `country_id`, `name` (states हे country_id ने जोडलेले आहे; भारतासाठी country_id = 1 असेल तर ते आधी countries टेबलमध्ये असावे.)
- **districts:** `id`, `state_id`, `name`
- **talukas:** `id`, `district_id`, `name` (subdistrict = taluka)
- **villages:** `id`, `taluka_id`, `name`, `is_active` (default true)

## JSON structure सुचना

तुमच्या JSON मध्ये जर **id आधीच असेल** तर तो वापरता येईल; नाहीतर seeder मध्ये auto `id` देखील देता येतील. Parent reference साठी:

- **districts:** प्रत्येक row मध्ये **`statecode`** (`states.json` शी जुळणारा). अनेक राज्ये असतील तेव्हा हे आवश्यक; फक्त एकच राज्य असेल तर `statecode` शिवायचे जुने JSON पण चालते (fallback).
- **talukas:** `district_id` किंवा district code/name
- **villages:** `taluka_id` किंवा taluka code/name

एकदा JSON files या path वर ठेवल्या की, पुढच्या चरणात त्यावरून `GeoSeeder` (किंवा वेगवेगळे seeders) चालू करून DB मध्ये भरता येईल.

---

**Summary:** सर्व state, district, subdistrict, village च्या JSON files **`database/seeders/data/geo/`** मध्ये ठेवा (`states.json`, `districts.json`, `talukas.json`, `villages.json`).

**Location search:** Village/city + taluka/district search, pincode, Marathi — engine frozen March 2026 (`LocationSearchService`).
