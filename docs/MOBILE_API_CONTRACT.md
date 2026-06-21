# Mobile API Contract

Laravel project: `laravel-matrimony`

Base path: `/api/v1`

Use these headers for authenticated JSON calls:

```http
Accept: application/json
Authorization: Bearer <sanctum_token>
```

## Auth

### POST `/api/v1/register`

Creates an auth user and returns a Sanctum token.

Request:

```json
{
  "name": "User Name",
  "email": "user@example.com",
  "password": "Password value accepted by Laravel password defaults"
}
```

Rules:

- `name`: required string, max 255
- `email`: required lowercase email, unique in `users.email`, max 255
- `password`: required, Laravel `Rules\Password::defaults()`
- Extra account-level `gender` input from old clients is ignored. Matrimony gender must be sent later as `gender_id` on the matrimony profile payload.

Success response: HTTP 200

```json
{
  "success": true,
  "message": "Registration successful",
  "token": "<plain_text_sanctum_token>",
  "user": {
    "id": 1,
    "name": "User Name",
    "email": "user@example.com"
  }
}
```

### POST `/api/v1/login`

Request:

```json
{
  "email": "user@example.com",
  "password": "secret"
}
```

Success response: HTTP 200

```json
{
  "success": true,
  "message": "Login successful",
  "token": "<plain_text_sanctum_token>",
  "user": {
    "id": 1,
    "email": "user@example.com"
  }
}
```

Invalid credentials response: HTTP 401

```json
{
  "success": false,
  "message": "Invalid credentials"
}
```

### POST `/api/v1/logout`

Requires bearer token. Revokes only the current token.

Success response: HTTP 200

```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

## Lookup Options

### GET `/api/v1/genders`

Public read-only endpoint. Returns active governed gender options from `master_genders` for mobile profile create/update. Flutter must use the returned `id` as `gender_id` in `POST /api/v1/matrimony-profile` and `PUT /api/v1/matrimony-profile`; do not hardcode gender IDs and do not use account/user gender.

Success response: HTTP 200

```json
[
  {
    "id": 1,
    "key": "male",
    "label": "Male",
    "label_mr": "वर"
  },
  {
    "id": 2,
    "key": "female",
    "label": "Female",
    "label_mr": "वधू"
  }
]
```

### GET `/api/v1/profile/basic-physical-options`

Requires bearer token. Returns read-only governed options for the mobile Basic + Physical profile setup section. Master options come from existing Laravel master tables; fixed enum options come from the same translation-backed web wizard options.

Success response: HTTP 200

```json
{
  "mother_tongues": [
    { "id": 1, "key": "marathi", "label": "Marathi", "label_en": "Marathi", "label_mr": "मराठी" }
  ],
  "complexions": [
    { "id": 1, "key": "fair", "label": "Fair", "label_en": "Fair", "label_mr": "गोरा" }
  ],
  "blood_groups": [
    { "id": 1, "key": "A+", "label": "A+", "label_en": "A+", "label_mr": null }
  ],
  "physical_builds": [
    { "id": 1, "key": "average", "label": "Average", "label_en": "Average", "label_mr": "मध्यम" }
  ],
  "spectacles_lens": [
    { "key": "no", "label": "No", "label_en": "No", "label_mr": "नाही" }
  ],
  "physical_conditions": [
    { "key": "none", "label": "None", "label_en": "None", "label_mr": "नाही" }
  ]
}
```

### GET `/api/v1/profile/education-career-options`

Requires bearer token. Returns read-only governed options for APK Edit All Education + Career fields.

Sources:

- `education_degrees`: `master_education` via `App\Models\EducationDegree`
- `occupation_categories`: `master_occupation_categories` via `App\Models\OccupationCategory`
- `occupations`: `master_occupations` via `App\Models\OccupationMaster`
- `custom_occupations`: logged-in user's `master_occupation_custom` rows via `App\Models\OccupationCustom`

Success response: HTTP 200

```json
{
  "education_degrees": [
    {
      "id": 1,
      "code": "B.E.",
      "label": "B.E.",
      "label_en": "B.E.",
      "label_mr": null,
      "full_form": "Bachelor of Engineering",
      "category_id": 1,
      "category_label": "Engineering",
      "category_label_mr": null
    }
  ],
  "occupation_categories": [
    {
      "id": 1,
      "label": "Technology",
      "label_en": "Technology",
      "label_mr": null,
      "legacy_working_with_type_id": null
    }
  ],
  "occupations": [
    {
      "id": 10,
      "label": "Software Engineer",
      "label_en": "Software Engineer",
      "label_mr": null,
      "category_id": 1,
      "category_label": "Technology",
      "category_label_mr": null
    }
  ],
  "custom_occupations": [
    {
      "id": 50,
      "label": "Family Business",
      "label_en": "Family Business",
      "label_mr": null,
      "status": "pending"
    }
  ]
}
```

### GET `/api/v1/profile/marital-lifestyle-options`

Requires bearer token. Returns read-only governed options for APK Edit All Marital + Lifestyle fields.

Sources:

- `marital_statuses`: `master_marital_statuses` via `App\Models\MasterMaritalStatus`
- `diets`: `master_diets` via `App\Models\MasterDiet`
- `smoking_statuses`: `master_smoking_statuses` via `App\Models\MasterSmokingStatus`
- `drinking_statuses`: `master_drinking_statuses` via `App\Models\MasterDrinkingStatus`

Success response: HTTP 200

```json
{
  "marital_statuses": [
    { "id": 1, "key": "never_married", "label": "Never Married", "label_en": "Never Married", "label_mr": null }
  ],
  "diets": [
    { "id": 1, "key": "vegetarian", "label": "Vegetarian", "label_en": "Vegetarian", "label_mr": null }
  ],
  "smoking_statuses": [
    { "id": 1, "key": "non_smoker", "label": "Non-smoker", "label_en": "Non-smoker", "label_mr": null }
  ],
  "drinking_statuses": [
    { "id": 1, "key": "non_drinker", "label": "Non-drinker", "label_en": "Non-drinker", "label_mr": null }
  ]
}
```

### GET `/api/v1/profile/remaining-profile-options`

Requires bearer token. Returns read-only governed options for APK Edit All Family + Horoscope fields. These options reuse existing website wizard master sources and preserve the same source ordering.

Sources:

- `family_types`: `master_family_types` via `App\Models\MasterFamilyType`
- `family_statuses`: `components.family.status_options` translation array, in website source order
- `family_values`: `components.family.values_options` translation array, in website source order
- `occupation_categories`: `master_occupation_categories` via `App\Models\OccupationCategory`
- `occupations`: `master_occupations` via `App\Models\OccupationMaster`
- `custom_occupations`: logged-in user's `master_occupation_custom` rows via `App\Models\OccupationCustom`
- `rashis`: `master_rashis`
- `nakshatras`: `master_nakshatras`
- `gans`: `master_gans`
- `nadis`: `master_nadis`
- `yonis`: `master_yonis`
- `mangal_dosh_types`: `master_mangal_dosh_types`
- `varnas`: `master_varnas`
- `vashyas`: `master_vashyas`
- `rashi_lords`: `master_rashi_lords`
- `birth_weekdays`: `components.horoscope.weekdays` translation array, matching the website Monday-to-Sunday dropdown order

Success response: HTTP 200

```json
{
  "family_types": [
    { "id": 1, "key": "joint", "label": "Joint", "label_en": "Joint", "label_mr": "संयुक्त कुटुंब" }
  ],
  "family_statuses": [
    { "key": "simple", "label": "Simple", "label_en": "Simple", "label_mr": "साधे" },
    { "key": "middle_class", "label": "Middle Class", "label_en": "Middle Class", "label_mr": "मध्यम वर्ग" }
  ],
  "family_values": [
    { "key": "traditional", "label": "Traditional", "label_en": "Traditional", "label_mr": "परंपरागत" }
  ],
  "occupations": [
    { "id": 10, "label": "Teacher", "label_en": "Teacher", "label_mr": null, "category_id": 1, "category_label": "Education", "category_label_mr": null }
  ],
  "custom_occupations": [],
  "rashis": [
    { "id": 1, "key": "mesha", "label": "Mesha (Aries)", "label_en": "Mesha (Aries)", "label_mr": "मेष" }
  ],
  "nakshatras": [
    { "id": 1, "key": "ashwini", "label": "Ashwini", "label_en": "Ashwini", "label_mr": "अश्विनी" }
  ],
  "gans": [],
  "nadis": [],
  "yonis": [],
  "mangal_dosh_types": [],
  "varnas": [],
  "vashyas": [],
  "rashi_lords": [],
  "birth_weekdays": [
    { "key": "Monday", "label": "Monday", "label_en": "Monday", "label_mr": "सोमवार" }
  ]
}
```

## Matrimony Profile

All profile mutation goes through the governed mutation layer. The mobile create path creates a draft profile with `MutationService::createDraftProfileForUser()` and applies submitted profile data with `MutationService::applyManualSnapshot()`.

### POST `/api/v1/matrimony-profile`

Requires bearer token. Creates the logged-in user's matrimony profile. Returns HTTP 409 if a profile already exists.

Request:

```json
{
  "full_name": "Candidate Name",
  "gender_id": 1,
  "date_of_birth": "1998-04-15",
  "birth_time": "10:30",
  "birth_city_id": 456,
  "birth_place_text": "Pune",
  "caste": "Maratha",
  "highest_education": "B.E.",
  "education_slots": "[{\"t\":\"d\",\"id\":1}]",
  "location_id": 123,
  "mother_tongue_id": 1,
  "marital_status_id": 1,
  "has_children": false,
  "height_cm": 168,
  "weight_kg": 58,
  "complexion_id": 1,
  "blood_group_id": 1,
  "physical_build_id": 1,
  "spectacles_lens": "contact_lens",
  "physical_condition": "none",
  "diet_id": 1,
  "smoking_status_id": 1,
  "drinking_status_id": 1,
  "occupation_master_id": 10,
  "company_name": "Navri Tech",
  "work_location_text": "Pune, Maharashtra",
  "father_name": "Father Name",
  "father_occupation_master_id": 10,
  "father_extra_info": "Runs a business",
  "mother_name": "Mother Name",
  "mother_occupation_master_id": 11,
  "mother_extra_info": "Homemaker",
  "family_type_id": 1,
  "family_status": "middle_class",
  "family_values": "traditional",
  "has_siblings": true,
  "other_relatives_text": "Relatives settled in Pune",
  "property_details": "Own house",
  "rashi_id": 1,
  "nakshatra_id": 1,
  "charan": 2,
  "gan_id": 1,
  "nadi_id": 1,
  "yoni_id": 1,
  "varna_id": 1,
  "vashya_id": 1,
  "rashi_lord_id": 1,
  "mangal_dosh_type_id": 1,
  "devak": "Devak",
  "kul": "Kul",
  "gotra": "Gotra",
  "navras_name": "Navras",
  "birth_weekday": "Monday",
  "narrative_about_me": "Short profile introduction."
}
```

Rules:

- `full_name`: required string, max 255
- `gender_id`: required, must exist as an active `master_genders.id`
- `date_of_birth`: required date
- `birth_time`: nullable string, max 20
- `birth_city_id`: nullable, must exist in `addresses.id`
- `birth_place_text`: nullable string, max 255
- `caste`: required string, max 255
- `highest_education`: required string, max 255
- `education_slots`: nullable JSON string, max 8192; selected degree IDs must exist in `master_education.id`; when supplied, Laravel resolves it into `highest_education`
- `location_id`: required, must exist in `addresses.id`
- `mother_tongue_id`: nullable, must exist as an active `master_mother_tongues.id`
- `marital_status_id`: nullable, must exist as an active `master_marital_statuses.id`
- `has_children`: nullable boolean
- `height_cm`: nullable integer, min 50, max 250
- `weight_kg`: nullable integer, min 20, max 250
- `complexion_id`: nullable, must exist as an active `master_complexions.id`
- `blood_group_id`: nullable, must exist as an active `master_blood_groups.id`
- `physical_build_id`: nullable, must exist as an active `master_physical_builds.id`
- `spectacles_lens`: nullable, one of `no`, `spectacles`, `contact_lens`, `both`
- `physical_condition`: nullable, one of `none`, `physically_challenged`, `hearing_condition`, `vision_condition`, `other`, `prefer_not_to_say`
- `diet_id`: nullable, must exist as an active `master_diets.id`
- `smoking_status_id`: nullable, must exist as an active `master_smoking_statuses.id`
- `drinking_status_id`: nullable, must exist as an active `master_drinking_statuses.id`
- `occupation_master_id`: nullable, must exist in `master_occupations.id`
- `occupation_custom_id`: nullable, must exist in `master_occupation_custom.id` for the logged-in user; cannot be sent together with `occupation_master_id`
- `company_name`: nullable string, max 255
- `work_location_text`: nullable string, max 255
- `father_name`, `father_occupation`, `mother_name`, `mother_occupation`: nullable string, max 255
- `father_occupation_master_id`, `mother_occupation_master_id`: nullable, must exist in `master_occupations.id`
- `father_occupation_custom_id`, `mother_occupation_custom_id`: nullable, must exist in the logged-in user's `master_occupation_custom.id`; cannot be sent together with the matching master occupation ID
- `father_extra_info`, `mother_extra_info`: nullable string, max 1000
- `family_type_id`: nullable, must exist as an active `master_family_types.id`
- `family_status`: nullable string, must be one of the website `components.family.status_options` keys
- `family_values`: nullable string, must be one of the website `components.family.values_options` keys
- `has_siblings`: nullable boolean
- `other_relatives_text`, `property_details`: nullable string, max 4000
- `rashi_id`, `nakshatra_id`, `gan_id`, `nadi_id`, `yoni_id`, `mangal_dosh_type_id`: nullable active master IDs
- `varna_id`, `vashya_id`, `rashi_lord_id`: nullable active Ashtakoota master IDs
- `charan`: nullable integer, min 1, max 4
- `devak`, `kul`, `gotra`, `navras_name`: nullable string, max 255
- `birth_weekday`: nullable string, must be one of the website weekday dropdown values: `Monday`, `Tuesday`, `Wednesday`, `Thursday`, `Friday`, `Saturday`, `Sunday`
- `narrative_about_me`: nullable string, max 5000

Governance note:

- `caste` is accepted as a mobile compatibility input.
- When it resolves against existing master caste data, the governed write stores `caste_id`.
- Raw legacy `caste` text is not a governed write target.
- Matrimony gender source is `matrimony_profiles.gender_id`; `users.gender` is not a runtime fallback for profile matching, visibility, comparison, or display.
- Parent contact numbers, sibling contact numbers, relative contact numbers, contact unlock/payment fields, and partner expectations are intentionally not accepted in this mobile contract.
- Sibling and relative repeaters are intentionally deferred until a row-preserving, privacy-safe mobile contract is added.

Success response: HTTP 200

```json
{
  "success": true,
  "message": "Matrimony profile created",
  "profile": {
    "id": 10,
    "user_id": 1,
    "full_name": "Candidate Name",
    "gender_id": 1,
    "date_of_birth": "1998-04-15",
    "birth_time": "10:30",
    "birth_city_id": 456,
    "birth_place_text": "Pune",
    "birth_place_label": "Pune, Maharashtra",
    "highest_education": "B.E.",
    "location_id": 123,
    "caste_id": 5,
    "gender": "male",
    "country_id": 1,
    "state_id": 2,
    "district_id": 3,
    "taluka_id": 4,
    "mother_tongue_id": 1,
    "mother_tongue_label": "Marathi",
    "marital_status_id": 1,
    "marital_status_label": "Never Married",
    "has_children": false,
    "height_cm": 168,
    "weight_kg": 58,
    "complexion_id": 1,
    "complexion_label": "Fair",
    "blood_group_id": 1,
    "blood_group_label": "A+",
    "physical_build_id": 1,
    "physical_build_label": "Average",
    "spectacles_lens": "contact_lens",
    "physical_condition": "none",
    "diet_id": 1,
    "diet_label": "Vegetarian",
    "smoking_status_id": 1,
    "smoking_status_label": "Non-smoker",
    "drinking_status_id": 1,
    "drinking_status_label": "Non-drinker",
    "occupation_master_id": 10,
    "occupation_master_label": "Software Engineer",
    "occupation_custom_id": null,
    "occupation_custom_label": null,
    "company_name": "Navri Tech",
    "work_location_text": "Pune, Maharashtra",
    "work_location_label": "Pune, Maharashtra",
    "father_name": "Father Name",
    "father_occupation_master_id": 10,
    "father_occupation_master_label": "Business",
    "father_extra_info": "Runs a business",
    "mother_name": "Mother Name",
    "mother_occupation_master_id": 11,
    "mother_occupation_master_label": "Homemaker",
    "mother_extra_info": "Homemaker",
    "family_type_id": 1,
    "family_type_label": "Joint",
    "family_status": "middle_class",
    "family_values": "traditional",
    "has_siblings": true,
    "other_relatives_text": "Relatives settled in Pune",
    "property_details": "Own house",
    "rashi_id": 1,
    "rashi_label": "Mesha (Aries)",
    "nakshatra_id": 1,
    "nakshatra_label": "Ashwini",
    "charan": 2,
    "gan_id": 1,
    "gan_label": "Dev",
    "nadi_id": 1,
    "nadi_label": "Adi",
    "yoni_id": 1,
    "yoni_label": "Ashwa",
    "varna_id": 1,
    "varna_label": "Brahmin",
    "vashya_id": 1,
    "vashya_label": "Manav",
    "rashi_lord_id": 1,
    "rashi_lord_label": "Sun",
    "mangal_dosh_type_id": 1,
    "mangal_dosh_type_label": "No",
    "devak": "Devak",
    "kul": "Kul",
    "gotra": "Gotra",
    "navras_name": "Navras",
    "birth_weekday": "Monday",
    "narrative_about_me": "Short profile introduction.",
    "profile_photo": null,
    "partner_preferences": null
  }
}
```

Already exists response: HTTP 409

```json
{
  "success": false,
  "message": "Profile already exists"
}
```

### GET `/api/v1/matrimony-profile`

Requires bearer token. Returns the logged-in user's profile.

Success response: HTTP 200

```json
{
  "success": true,
  "profile": {
    "id": 10,
    "user_id": 1,
    "full_name": "Candidate Name",
    "gender_id": 1,
    "date_of_birth": "1998-04-15",
    "birth_time": "10:30",
    "birth_city_id": 456,
    "birth_place_text": "Pune",
    "birth_place_label": "Pune, Maharashtra",
    "highest_education": "B.E.",
    "location_id": 123,
    "caste_id": 5,
    "gender": "male",
    "country_id": 1,
    "state_id": 2,
    "district_id": 3,
    "taluka_id": 4,
    "mother_tongue_id": 1,
    "mother_tongue_label": "Marathi",
    "marital_status_id": 1,
    "marital_status_label": "Never Married",
    "has_children": false,
    "height_cm": 168,
    "weight_kg": 58,
    "complexion_id": 1,
    "complexion_label": "Fair",
    "blood_group_id": 1,
    "blood_group_label": "A+",
    "physical_build_id": 1,
    "physical_build_label": "Average",
    "spectacles_lens": "contact_lens",
    "physical_condition": "none",
    "diet_id": 1,
    "diet_label": "Vegetarian",
    "smoking_status_id": 1,
    "smoking_status_label": "Non-smoker",
    "drinking_status_id": 1,
    "drinking_status_label": "Non-drinker",
    "profile_photo": null,
    "partner_preferences": null
  }
}
```

Not found response: HTTP 404

```json
{
  "success": false,
  "message": "Profile not found"
}
```

### PUT `/api/v1/matrimony-profile`

Requires bearer token. Updates submitted fields only.

Request:

```json
{
  "full_name": "Updated Candidate Name",
  "gender_id": 1,
  "date_of_birth": "1998-04-15",
  "birth_time": "10:30",
  "birth_city_id": 456,
  "birth_place_text": "Pune",
  "caste": "Maratha",
  "highest_education": "MCA",
  "education_slots": "[{\"t\":\"d\",\"id\":1}]",
  "location_id": 123,
  "address_line": "Optional address line",
  "mother_tongue_id": 1,
  "marital_status_id": 1,
  "has_children": false,
  "height_cm": 168,
  "weight_kg": 58,
  "complexion_id": 1,
  "blood_group_id": 1,
  "physical_build_id": 1,
  "spectacles_lens": "contact_lens",
  "physical_condition": "none",
  "diet_id": 1,
  "smoking_status_id": 1,
  "drinking_status_id": 1,
  "occupation_master_id": 10,
  "company_name": "Navri Tech",
  "work_location_text": "Pune, Maharashtra",
  "family_type_id": 1,
  "property_details": "Own house",
  "rashi_id": 1,
  "narrative_about_me": "Short profile introduction."
}
```

Rules:

- `full_name`: sometimes required string, max 255
- `gender_id`: sometimes required, must exist as an active `master_genders.id`; required when the existing profile has no governed gender
- `date_of_birth`: sometimes required date
- `birth_time`: nullable string, max 20
- `birth_city_id`: nullable, must exist in `addresses.id`
- `birth_place_text`: nullable string, max 255
- `caste`: sometimes required string, max 255
- `highest_education`: sometimes required string, max 255
- `education_slots`: nullable JSON string, max 8192; selected degree IDs must exist in `master_education.id`; when supplied, Laravel resolves it into `highest_education`
- `location_id`: sometimes required, must exist in `addresses.id`
- `address_line`: nullable string, max 255
- `mother_tongue_id`: nullable, must exist as an active `master_mother_tongues.id`
- `marital_status_id`: nullable, must exist as an active `master_marital_statuses.id`
- `has_children`: nullable boolean
- `height_cm`: nullable integer, min 50, max 250
- `weight_kg`: nullable integer, min 20, max 250
- `complexion_id`: nullable, must exist as an active `master_complexions.id`
- `blood_group_id`: nullable, must exist as an active `master_blood_groups.id`
- `physical_build_id`: nullable, must exist as an active `master_physical_builds.id`
- `spectacles_lens`: nullable, one of `no`, `spectacles`, `contact_lens`, `both`
- `physical_condition`: nullable, one of `none`, `physically_challenged`, `hearing_condition`, `vision_condition`, `other`, `prefer_not_to_say`
- `diet_id`: nullable, must exist as an active `master_diets.id`
- `smoking_status_id`: nullable, must exist as an active `master_smoking_statuses.id`
- `drinking_status_id`: nullable, must exist as an active `master_drinking_statuses.id`
- `occupation_master_id`: nullable, must exist in `master_occupations.id`
- `occupation_custom_id`: nullable, must exist in `master_occupation_custom.id` for the logged-in user; cannot be sent together with `occupation_master_id`
- `company_name`: nullable string, max 255
- `work_location_text`: nullable string, max 255
- Family, property, horoscope, and `narrative_about_me` rules are the same as `POST /api/v1/matrimony-profile`.
- Parent/sibling/relative contact fields and partner expectations are not accepted by this mobile update contract.

Success response: HTTP 200

```json
{
  "success": true,
  "message": "Matrimony profile updated",
  "profile": {
    "id": 10,
    "user_id": 1,
    "full_name": "Updated Candidate Name",
    "gender_id": 1,
    "date_of_birth": "1998-04-15",
    "birth_time": "10:30",
    "birth_city_id": 456,
    "birth_place_text": "Pune",
    "birth_place_label": "Pune, Maharashtra",
    "highest_education": "MCA",
    "location_id": 123,
    "caste_id": 5,
    "gender": "male",
    "country_id": 1,
    "state_id": 2,
    "district_id": 3,
    "taluka_id": 4,
    "mother_tongue_id": 1,
    "mother_tongue_label": "Marathi",
    "marital_status_id": 1,
    "marital_status_label": "Never Married",
    "has_children": false,
    "height_cm": 168,
    "weight_kg": 58,
    "complexion_id": 1,
    "complexion_label": "Fair",
    "blood_group_id": 1,
    "blood_group_label": "A+",
    "physical_build_id": 1,
    "physical_build_label": "Average",
    "spectacles_lens": "contact_lens",
    "physical_condition": "none",
    "diet_id": 1,
    "diet_label": "Vegetarian",
    "smoking_status_id": 1,
    "smoking_status_label": "Non-smoker",
    "drinking_status_id": 1,
    "drinking_status_label": "Non-drinker",
    "occupation_master_id": 10,
    "occupation_master_label": "Software Engineer",
    "occupation_custom_id": null,
    "occupation_custom_label": null,
    "company_name": "Navri Tech",
    "work_location_text": "Pune, Maharashtra",
    "work_location_label": "Pune, Maharashtra",
    "profile_photo": null,
    "partner_preferences": null
  }
}
```

Possible errors:

- HTTP 403: profile lifecycle is not editable
- HTTP 404: profile not found
- HTTP 422: validation error

### POST `/api/v1/matrimony-profile/photo`

Requires bearer token. Multipart upload.

Request:

```http
Content-Type: multipart/form-data
profile_photo=<image file, max 2048 KB>
```

Success response: HTTP 200

```json
{
  "success": true,
  "message": "Profile photo uploaded. Processing will complete shortly.",
  "data": {
    "profile_photo": "<pending_filename_or_path>",
    "status": "processing"
  }
}
```

### GET `/api/v1/matrimony-profiles`

Requires bearer token. Lists visible, non-suspended active profiles. Legacy top-level fields remain present for backward compatibility. Newer clients may use the optional `display.card` and `display.actions` payload for match-card rendering.

Optional query filters:

- `feed` (`new`, `daily`, `my_matches`, `nearby`)
- `caste`
- `country_id`
- `state_id`
- `district_id`
- `taluka_id`
- `location_id`
- `age_from`
- `age_to`

Feed behavior is backend-owned:

- omitted `feed`: legacy latest-order list for backward compatibility
- `feed=new`: discovery list using existing profile rotation; recently opened profiles are suppressed for the configured recent-view window and older viewed profiles resurface after never-viewed profiles
- `feed=daily`: daily stable match rotation from `MatchingService::TAB_DAILY`
- `feed=my_matches`: preference-backed match ordering from `MatchingService::TAB_PERFECT`
- `feed=nearby`: location/proximity match ordering from `MatchingService::TAB_NEAR`

All feeds still use the same mobile discovery eligibility rule: no own profile, no same-gender profile, no hidden/blocked/suspended/showcase/admin candidates, and no phone/email/WhatsApp/contact data in list rows.

Success response: HTTP 200

```json
{
  "success": true,
  "profiles": [
    {
      "id": 10,
      "user_id": 1,
      "full_name": "Candidate Name",
      "gender": "male",
      "date_of_birth": "1998-04-15",
      "caste": null,
      "highest_education": "B.E.",
      "location_id": 123,
      "country_id": 1,
      "state_id": 2,
      "district_id": 3,
      "taluka_id": 4,
      "profile_photo": null,
      "created_at": "2026-06-16T00:00:00.000000Z",
      "updated_at": "2026-06-16T00:00:00.000000Z",
      "display": {
        "card": {
          "name": "Candidate Name",
          "age": 28,
          "age_label": "28 years",
          "height_label": "5' 5\"",
          "community_label": "Hindu, Maratha",
          "education_label": "B.E.",
          "occupation_label": "Software Engineer",
          "location_label": "Pune, Maharashtra",
          "verified": true,
          "premium": false,
          "photo_count": 1,
          "primary_photo_url": "https://navrimilenavryala.com/storage/matrimony_photos/example.jpg",
          "comparison_label": "You & Her",
          "has_astro": true
        },
        "actions": {
          "can_send_interest": true,
          "interest_sent": false,
          "can_report": true,
          "can_shortlist": true,
          "can_hide": true,
          "can_block": true,
          "is_shortlisted": false,
          "is_hidden": false,
          "is_blocked": false
        }
      }
    }
  ]
}
```

The list `display` payload is intentionally lightweight. It does not include contact phone, email, WhatsApp number, contact unlock state, full profile sections, about text, or partner preferences.

### GET `/api/v1/matrimony-profiles/more-sections`

Requires bearer token. Returns gender-aware, real-data sections for the mobile Matches / More Matches discovery UI. This endpoint is read-only and keeps profile rows lightweight by reusing the list-card `display.card` and `display.actions` payload.

Success response: HTTP 200

```json
{
  "success": true,
  "viewer_context": {
    "viewer_gender": "male",
    "target_gender": "female",
    "target_singular_en": "Bride",
    "target_plural_en": "Brides",
    "target_plural_mr": "वधू"
  },
  "sections": [
    {
      "key": "looking_for_me",
      "title_en": "Brides looking for me",
      "title_mr": "माझ्या शोधात असलेल्या वधू",
      "subtitle_en": "Profiles whose preferences may match you",
      "subtitle_mr": "ज्यांच्या पसंतीशी तुमचे स्थळ जुळू शकते",
      "locked": false,
      "requires_upgrade": false,
      "profiles": [
        {
          "id": 20,
          "display": {
            "card": {
              "name": "Candidate Name",
              "age": 28,
              "age_label": "28 years",
              "height_label": "5' 5\"",
              "community_label": "Hindu, Maratha",
              "education_label": "B.E.",
              "occupation_label": "Software Engineer",
              "location_label": "Pune, Maharashtra",
              "verified": true,
              "premium": false,
              "photo_count": 1,
              "primary_photo_url": "https://navrimilenavryala.com/storage/matrimony_photos/example.jpg",
              "comparison_label": "You & Her",
              "has_astro": true
            },
            "actions": {
              "can_send_interest": true,
              "interest_sent": false,
              "can_report": true,
              "can_shortlist": true,
              "can_hide": true,
              "can_block": true,
              "is_shortlisted": false,
              "is_hidden": false,
              "is_blocked": false
            }
          },
          "section_score": 6
        }
      ]
    },
    {
      "key": "recently_viewed",
      "title_en": "Recently viewed Brides",
      "title_mr": "अलीकडे पाहिलेल्या वधू",
      "profiles": []
    },
    {
      "key": "matching_my_preference",
      "title_en": "Brides matching my preference",
      "title_mr": "माझ्या पसंतीशी जुळणाऱ्या वधू",
      "profiles": []
    },
    {
      "key": "nearby",
      "title_en": "Nearby Brides",
      "title_mr": "जवळच्या वधू",
      "subtitle_en": "Profiles closer to your location",
      "subtitle_mr": "तुमच्या ठिकाणाजवळील स्थळे",
      "locked": false,
      "requires_upgrade": false,
      "profiles": []
    },
    {
      "key": "recent_visitors",
      "title_en": "Recent visitors",
      "title_mr": "अलीकडील भेट देणाऱ्या वधू",
      "locked": true,
      "requires_upgrade": true,
      "teaser_count": 12,
      "profiles": [],
      "teasers": [
        {
          "headline": "A girl from Pune",
          "lines": [
            "Pune / Maharashtra",
            "25 years"
          ],
          "viewed_summary": "Viewed your profile recently",
          "photo_url": "https://navrimilenavryala.com/images/placeholders/female-profile.svg",
          "avatar_style": "blur",
          "blur_photo_class": "blur-md scale-110 opacity-90",
          "accent_line": null,
          "match_line": null,
          "interest_hint": "Upgrade to know more"
        }
      ],
      "rows": [
        {
          "mode": "teaser",
          "teaser": {
            "headline": "A girl from Pune",
            "lines": [
              "Pune / Maharashtra",
              "25 years"
            ],
            "viewed_summary": "Viewed your profile recently",
            "photo_url": "https://navrimilenavryala.com/images/placeholders/female-profile.svg",
            "avatar_style": "blur",
            "blur_photo_class": "blur-md scale-110 opacity-90",
            "accent_line": null,
            "match_line": null,
            "interest_hint": "Upgrade to know more"
          }
        }
      ],
      "partial_mode": false,
      "preview_limit": 0,
      "unique_count": 12,
      "overflow_count": 12
    },
    {
      "key": "you_may_like",
      "title_en": "Brides you may like",
      "title_mr": "तुम्हाला आवडू शकणाऱ्या वधू",
      "profiles": []
    }
  ]
}
```

Section order is stable:

1. `looking_for_me`
2. `recently_viewed`
3. `matching_my_preference`
4. `nearby`
5. `recent_visitors`
6. `you_may_like`

Gender labels are derived from the logged-in member profile:

- male viewer → female target labels: `Bride`, `Brides`, `वधू`
- female viewer → male target labels: `Groom`, `Grooms`, `वर`
- unknown viewer gender → neutral target labels: `Profile`, `Profiles`, `स्थळे`

`nearby` is backed by `MatchingService::findMatchesForTab($viewerProfile, MatchingService::TAB_NEAR, 12)`. It returns the same safe lightweight profile rows as the other profile sections:

- male viewer / female target: `Nearby Brides`, `जवळच्या वधू`
- female viewer / male target: `Nearby Grooms`, `जवळचे वर`
- unknown target: `Nearby profiles`, `जवळची स्थळे`

`recent_visitors` follows the existing who-viewed entitlement gate and reuses the Laravel website who-viewed teaser policy.

- Full who-viewed access: `profiles[]` contains normal safe profile rows using the same lightweight list-card `display.card` and `display.actions` shape as other sections. `rows[]` may include ordered `{ "mode": "profile", "profile": { ... } }` rows.
- Locked access: `locked=true`, `requires_upgrade=true`, safe `teaser_count`, `profiles=[]`, and `teasers[]` contains privacy-safe teaser payloads. `rows[]` contains ordered `{ "mode": "teaser", "teaser": { ... } }` rows.
- Partial access: `profiles[]` contains only the allowed full profile rows, `teasers[]` contains the locked overflow teaser rows, and `rows[]` preserves the ordered mixed list with `mode="profile"` or `mode="teaser"`.

Teaser rows are not profile rows and must not be opened as profile-detail records. Locked teaser rows expose only these fields: `headline`, `lines`, `viewed_summary`, `photo_url`, `avatar_style`, `blur_photo_class`, `accent_line`, `match_line`, and `interest_hint`. They must not include `id`, `profile_id`, `viewer_profile_id`, `user_id`, `display`, `actions`, `contact`, phone, email, WhatsApp, contact unlock state, paid contact data, or profile contact data.

All section profile rows intentionally exclude contact phone, email, WhatsApp number, contact unlock state, full profile sections, full about text, and partner preferences.

### GET `/api/v1/matrimony-profiles/{id}`

Requires bearer token. Returns HTTP 404 when the profile is missing, lifecycle-hidden, blocked, or not viewable by the current viewer.

Success response: HTTP 200

```json
{
  "success": true,
  "profile": {
    "id": 10,
    "user_id": 1,
    "full_name": "Candidate Name",
    "date_of_birth": "1998-04-15",
    "highest_education": "B.E.",
    "location_id": 123,
    "caste_id": 5,
    "gender": "male",
    "country_id": 1,
    "state_id": 2,
    "district_id": 3,
    "taluka_id": 4,
    "profile_photo": null,
    "partner_preferences": null
  }
}
```

## Interests

All interest endpoints require a bearer token and a matrimony profile for the logged-in user.

Rule-engine errors return JSON like this when the request expects JSON:

```json
{
  "success": false,
  "allowed": false,
  "code": "ERROR_CODE",
  "message": "Readable message",
  "action": null
}
```

### POST `/api/v1/interests`

Request:

```json
{
  "receiver_profile_id": 20
}
```

Rules:

- `receiver_profile_id`: required, must exist in `matrimony_profiles.id`

Success response: HTTP 200

```json
{
  "success": true,
  "message": "Interest sent successfully.",
  "data": {
    "id": 1,
    "sender_profile_id": 10,
    "receiver_profile_id": 20,
    "status": "pending",
    "priority_score": 0
  }
}
```

Duplicate response: HTTP 409

```json
{
  "success": false,
  "allowed": false,
  "code": "INTEREST_DUPLICATE",
  "message": "Readable message",
  "action": null,
  "data": {
    "id": 1,
    "status": "pending"
  }
}
```

### GET `/api/v1/interests/sent`

Success response: HTTP 200

```json
{
  "success": true,
  "data": {
    "sent": []
  }
}
```

### GET `/api/v1/interests/received`

Success response: HTTP 200

```json
{
  "success": true,
  "data": {
    "received": [],
    "interest_view_limit": 0,
    "interest_view_reset_period": "label",
    "interest_view_window_start": "2026-06-16T00:00:00+00:00"
  }
}
```

### POST `/api/v1/interests/{id}/accept`

Success response: HTTP 200

```json
{
  "success": true,
  "message": "Interest accepted.",
  "data": {
    "id": 1,
    "status": "accepted"
  }
}
```

### POST `/api/v1/interests/{id}/reject`

Success response: HTTP 200

```json
{
  "success": true,
  "message": "Interest rejected.",
  "data": {
    "id": 1,
    "status": "rejected"
  }
}
```

### POST `/api/v1/interests/{id}/withdraw`

Success response: HTTP 200

```json
{
  "success": true,
  "message": "Interest withdrawn successfully."
}
```
