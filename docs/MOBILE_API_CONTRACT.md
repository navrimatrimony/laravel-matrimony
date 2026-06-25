# Mobile API Contract

Laravel project: `laravel-matrimony`

Base path: `/api/v1`

Use these headers for authenticated JSON calls:

```http
Accept: application/json
Authorization: Bearer <sanctum_token>
```

## Auth

### OTP-first mobile account flow

Phase 1 mobile onboarding uses SMS/mobile OTP first. `users.mobile` is the canonical normalized 10-digit mobile field; there is no separate `mobile_normalized` field in Phase 1. Email and password are optional after OTP verification. Do not create fake email addresses.

WhatsApp alerts are only an opt-in preference in this phase. OTP transport is SMS/mobile OTP, and verifying this flow must not set `whatsapp_verified_at`.

OTP challenges are DB-backed. The backend stores only an OTP hash, never the raw OTP. Local/testing/dev diagnostic responses may include `debug_otp`; production clients must not depend on that key.

### POST `/api/v1/auth/mobile-otp/send`

Creates an SMS OTP challenge for login or registration. The response must not reveal whether the mobile number already belongs to an account.

Request:

```json
{
  "mobile": "9876543210",
  "locale": "mr",
  "channel": "sms",
  "purpose": "login_or_register",
  "terms_accepted": true,
  "privacy_accepted": true,
  "terms_version": "2026-06-24",
  "privacy_version": "2026-06-24",
  "whatsapp_alerts_opt_in": true
}
```

Rules:

- `mobile`: required Indian mobile number; normalized to 10 digits and stored on `users.mobile` only after successful OTP verify.
- `locale`: nullable, supported values `mr`, `en`.
- `channel`: nullable, only `sms` is accepted in Phase 1.
- `purpose`: nullable, only `login_or_register` is accepted in Phase 1.
- `terms_accepted` and `privacy_accepted`: required accepted values.
- `terms_version` and `privacy_version`: required strings, max 64.
- `whatsapp_alerts_opt_in`: nullable boolean; stored as notification preference after successful OTP verify.

Success response: HTTP 200

```json
{
  "success": true,
  "challenge_id": "uuid",
  "expires_in": 600,
  "resend_after": 60,
  "delivery_channel": "sms",
  "message": "OTP sent"
}
```

Cooldown/rate limit response: HTTP 429

```json
{
  "success": false,
  "message": "Please wait before requesting another OTP.",
  "resend_after": 42
}
```

If no SMS provider is configured outside local/testing/dev mode, the endpoint returns HTTP 503.

### POST `/api/v1/auth/mobile-otp/verify`

Verifies the OTP challenge. Existing users with the same normalized mobile are logged in. New mobile numbers create an OTP account shell with `users.mobile`, `mobile_verified_at`, nullable `name`, nullable `email`, and nullable `password`.

Request:

```json
{
  "challenge_id": "uuid",
  "mobile": "9876543210",
  "otp": "123456"
}
```

Rules:

- `challenge_id`: required challenge id returned by send.
- `mobile`: required; must normalize to the same mobile as the challenge.
- `otp`: required 6-digit string.

Success response: HTTP 200

```json
{
  "success": true,
  "token": "<plain_text_sanctum_token>",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "creator_name": null,
    "mobile": "9876543210",
    "mobile_verified_at": "2026-06-24T10:00:00.000000Z",
    "email": null,
    "email_verified_at": null,
    "preferred_locale": "mr"
  },
  "account_state": {
    "is_new_account": true,
    "has_profile": false,
    "next_action": "account_details"
  }
}
```

`account_state.next_action` values:

- `account_details`: creator/account name is still missing.
- `start_onboarding`: account shell is complete and no matrimony profile exists yet.
- `resume_onboarding`: user already has a matrimony profile.

Invalid/expired OTP response: HTTP 422. Too many OTP attempts response: HTTP 429.

On successful verify, the accepted terms/privacy versions are persisted in `user_consents`.

### PATCH `/api/v1/account/details`

Requires bearer token. Completes or updates account-shell details after OTP verification. This endpoint writes account/user fields only; it must not create or mutate matrimony profile data.

Request:

```json
{
  "creator_name": "User Name",
  "email": "user@example.com",
  "locale": "mr",
  "password": "Password value accepted by Laravel password defaults",
  "password_confirmation": "Password value accepted by Laravel password defaults",
  "whatsapp_alerts_opt_in": true
}
```

Rules:

- `creator_name`: required string, max 255; stored in `users.name`.
- `email`: nullable email, max 255. If omitted, existing email is preserved. Email conflict is checked only when email is provided.
- `locale`: nullable, supported values `mr`, `en`; stored in `users.preferred_locale`.
- `password`: nullable, confirmed, Laravel `Rules\Password::defaults()`.
- `whatsapp_alerts_opt_in`: nullable boolean notification preference. This does not verify WhatsApp and does not set `whatsapp_verified_at`.

Success response: HTTP 200

```json
{
  "success": true,
  "user": {
    "id": 1,
    "creator_name": "User Name",
    "mobile": "9876543210",
    "mobile_verified_at": "2026-06-24T10:00:00.000000Z",
    "email": "user@example.com",
    "email_verified_at": null,
    "preferred_locale": "mr"
  },
  "account_state": {
    "is_new_account": false,
    "has_profile": false,
    "next_action": "start_onboarding"
  }
}
```

Email conflict response: HTTP 409.

## Smart Onboarding Phase 2

Phase 2 is backend-only onboarding draft/resume + status/checklist + governed profile save-step skeleton. Flutter must not decide activation/searchability from local rules; use these backend responses.

Rules:

- One account can have only one candidate matrimony profile. Existing profile means resume/edit that profile; never create a duplicate.
- `mobile_onboarding_drafts` stores server-side resume metadata only. It is not the authoritative storage for structured matrimony entities.
- Profile create/update from onboarding must use `MutationService::createDraftProfileForUser()` and `MutationService::applyManualSnapshot()`.
- Email missing or unverified is optional and non-blocking.
- `profile_status` is an API alias for the current profile lifecycle state.
- `is_searchable` is computed by the API. It is not stored in `matrimony_profiles` in Phase 2.
- Phase 2 accepts single-value `mother_tongue_id` on `basic_info` only. It does not accept horoscope, astrology, family type, biodata/OCR, or partner preference auto-generation fields.
- Direct arbitrary education/occupation text is not accepted by onboarding `profile/save-step`; use backend-supported master IDs/options.

Computed `is_searchable` is true only when all of these are true:

- account mobile is verified
- profile exists
- profile lifecycle permits public visibility
- backend required-field policy is complete
- selected residence location is an active final location node
- profile photo is uploaded and approved
- no pending governance conflict / suspended / archived blocker exists

### POST `/api/v1/onboarding/start`

Requires bearer token. Starts or resumes server onboarding draft. Does not create a matrimony profile unless one already exists.

Request:

```json
{
  "profile_for_whom": "self"
}
```

Allowed `profile_for_whom`: `self`, `son`, `daughter`, `brother`, `sister`, `relative`, `friend`.

The exact mobile value is stored in draft data. Existing `users.registering_for` is mapped for backward compatibility:

- `self` -> `self`
- `son` / `daughter` -> `parent_guardian`
- `brother` / `sister` -> `sibling`
- `relative` -> `relative`
- `friend` -> `friend`

Success response: HTTP 200

```json
{
  "success": true,
  "draft_id": 1,
  "profile_id": null,
  "has_existing_profile": false,
  "last_completed_step": "profile_for_whom",
  "current_step": "basic_info",
  "next_step": "basic_info",
  "account_state": {},
  "activation_checklist": [],
  "profile_status": null,
  "is_searchable": false
}
```

### GET `/api/v1/onboarding/status`

Requires bearer token. Returns combined account, draft, profile summary, checklist, and computed next step.

Success response: HTTP 200

```json
{
  "success": true,
  "account": {
    "id": 1,
    "creator_name": "User Name",
    "mobile": "9876543210",
    "mobile_verified_at": "2026-06-24T10:00:00.000000Z",
    "mobile_verified": true,
    "creator_name_present": true,
    "email": null,
    "email_present": false,
    "email_verified_at": null,
    "email_verified": false,
    "preferred_locale": "mr"
  },
  "draft": {
    "id": 1,
    "current_step": "basic_info",
    "last_completed_step": "profile_for_whom",
    "completed_steps": ["account", "profile_for_whom"],
    "data": {}
  },
  "profile": null,
  "has_profile": false,
  "has_existing_profile": false,
  "profile_status": null,
  "is_searchable": false,
  "next_step": "basic_info",
  "account_state": {},
  "activation_checklist": []
}
```

### GET `/api/v1/onboarding/draft`

Requires bearer token. Finds or creates the server draft, and attaches an existing profile when present. It must not create a duplicate profile.

Response shape:

```json
{
  "success": true,
  "draft": {
    "id": 1,
    "current_step": "basic_info",
    "last_completed_step": "profile_for_whom",
    "completed_steps": ["account", "profile_for_whom"],
    "data": {}
  },
  "profile": null,
  "activation_checklist": []
}
```

### PATCH `/api/v1/onboarding/draft/{step}`

Requires bearer token. Saves server draft data and advances resume metadata.

Allowed `{step}`: `profile_for_whom`, `basic_info`, `religion_caste`, `location`, `education`, `career`, `lifestyle`, `family`, `photo`.

Request:

```json
{
  "data": {}
}
```

Server-side dependent clear rules:

- religion changes -> clears caste/sub-caste draft values and related same-caste/sub-caste strictness
- caste changes -> clears sub-caste draft value and sub-caste strictness
- never-married marital status -> clears child draft values
- working-with changes -> clears draft occupation/working-as values

Phase 5C draft alignment:

- `religion_caste` accepts exact strictness enum keys:
  - `religion_strictness`: `open`, `preferred`, `required`
  - `caste_strictness`: `open`, `preferred`, `required`
  - `sub_caste_strictness`: `open`, `preferred`, `required`
- Backward-compatible booleans `same_religion_required`, `same_caste_required`, `same_sub_caste_required`, `same_religion_expected`, and `same_caste_expected` are normalized internally. Boolean `true` becomes `required`; boolean `false`/`null` becomes `open`.
- `location` draft may store a pending location request without making it profile-valid:
  - `pending_location_request_id`
  - `pending_location_label`
  - `pending_location_status`: `pending`
  - `pending_location_type`: `village`, `city`, or `suburb`
- Pending location draft data must not be saved as `matrimony_profiles.location_id`. Only an approved active final node may be profile-saved as `location_id`.
- `family` draft accepts optional `brothers_count` and `sisters_count`. These are draft-only in Phase 5C because legacy profile columns are deprecated in favor of the Siblings engine.

### POST `/api/v1/onboarding/profile/save-step`

Requires bearer token. Persists a supported onboarding step to the actual matrimony profile through the governed mutation path.

Supported Phase 2 steps: `profile_for_whom`, `basic_info`, `religion_caste`, `location`, `education`, `career`, `lifestyle`, `family`.

Request:

```json
{
  "step": "basic_info",
  "data": {
    "full_name": "Candidate Name"
  }
}
```

Behavior:

- If the user already has a profile, update that profile.
- If the user has no profile and the step is a profile data step, create a draft profile with `MutationService::createDraftProfileForUser()`.
- Apply writable profile data with `MutationService::applyManualSnapshot()`.
- Save the same step into `mobile_onboarding_drafts` for resume.
- Location profile save accepts only active final `addresses` nodes where `hierarchy=village` and `tag` is `city`, `suburban`, or `rural`.
- Family profile save persists safe parent fields such as father/mother names and occupation IDs through `MutationService`; `brothers_count` and `sisters_count` remain draft-only.
- `basic_info` may send `mother_tongue_id` as a single active `master_mother_tongues.id`. Do not send horoscope, astrology, family type, biodata/OCR, partner preference, or arbitrary custom education/occupation text in Phase 2.

Success response:

```json
{
  "success": true,
  "profile": {
    "id": 1,
    "profile_status": "draft",
    "lifecycle_state": "draft",
    "is_searchable": false,
    "photo_uploaded": false,
    "photo_approved": false,
    "location_valid": false
  },
  "draft": {},
  "activation_checklist": [],
  "next_step": "location"
}
```

### GET `/api/v1/onboarding/activation-checklist`

Requires bearer token. Returns backend-owned activation/searchability checklist.

Response:

```json
{
  "success": true,
  "profile_status": "draft",
  "is_searchable": false,
  "items": [
    {"key":"mobile_verified","label":"Mobile verified","complete":true,"blocking":true,"status":"complete","message":"Mobile verified"},
    {"key":"account_details_complete","label":"Account details complete","complete":true,"blocking":true,"status":"complete","message":"Creator name added"},
    {"key":"email_added_optional","label":"Email added","complete":false,"blocking":false,"status":"optional","message":"Email is optional"},
    {"key":"required_fields_complete","label":"Required fields complete","complete":false,"blocking":true,"status":"missing","message":"Required profile fields are missing"},
    {"key":"location_valid","label":"Location valid","complete":false,"blocking":true,"status":"missing","message":"Add an approved final location"},
    {"key":"photo_uploaded","label":"Photo uploaded","complete":false,"blocking":true,"status":"missing","message":"Upload profile photo"},
    {"key":"photo_approved","label":"Photo approved","complete":false,"blocking":true,"status":"missing","message":"Upload a photo for approval"},
    {"key":"governance_clear","label":"Governance clear","complete":true,"blocking":true,"status":"complete","message":"No pending governance conflict"},
    {"key":"profile_active","label":"Profile active","complete":false,"blocking":false,"status":"draft","message":"Profile is not active yet"},
    {"key":"profile_searchable","label":"Profile searchable","complete":false,"blocking":false,"status":"not_searchable","message":"Profile is not searchable yet"}
  ]
}
```

If the user has only a pending location request in onboarding draft, `location_valid` remains incomplete and blocking with `status=pending`, and `is_searchable=false`.

## Smart Onboarding Phase 3

Phase 3 is Laravel-backend-only support for mobile SmartPickerPanel lookups, pending master/location suggestions, and partner preference auto-draft generation. Except public read-only bootstrap, endpoints below require Sanctum auth and are intended for users who already passed the OTP-first account flow.

Non-goals:

- no Flutter SmartPicker implementation in this phase
- no biodata/OCR
- single-value mother tongue is allowed in registration onboarding as `basic_info.mother_tongue_id`; no horoscope, astrology, or family type
- no stored `matrimony_profiles.is_searchable` column
- no long partner preference onboarding form

Common lookup request query:

- `q`: nullable string
- `page`: nullable integer, default `1`
- `limit`: nullable integer, default `20`, max `50`
- `locale`: nullable string; falls back to authenticated user `preferred_locale`, then `en`
- `include_popular`: nullable boolean, default `true`

Common lookup response:

```json
{
  "success": true,
  "locale": "mr",
  "results": [],
  "popular": [],
  "pagination": {
    "page": 1,
    "limit": 20,
    "has_more": false,
    "total": null
  }
}
```

Option item shape:

```json
{
  "id": 1,
  "key": "optional-key",
  "label": "Resolved localized label",
  "translation_missing": false,
  "popular": false,
  "meta": {}
}
```

Localization rules:

- API resolves `label` for requested locale.
- Marathi missing translations fall back to English/base label.
- `translation_missing=true` when requested Marathi fallback uses English/base label.
- Flutter can render `label` directly and should not need to choose between `name_en/name_mr`.

### GET `/api/v1/onboarding/lookups/bootstrap`

Returns small registration config: `profile_for_whom`, gender options, marital statuses, children rules, height options, lifestyle lookups, age policy, and onboarding steps.

`profile_for_whom` values are: `self`, `son`, `daughter`, `brother`, `sister`, `relative`, `friend`. Each row includes `gender_mode` (`male`, `female`, or `ask`).

Bootstrap intentionally excludes horoscope, astrology, and family type. It includes read-only `mother_tongues` so the pre-OTP profile-for-whom screen can collect one active master value.

### GET `/api/v1/onboarding/lookups/religions`

Returns active religion options.

### GET `/api/v1/onboarding/lookups/castes`

Requires `religion_id`. Returns active castes under that religion only.

### GET `/api/v1/onboarding/lookups/sub-castes`

Requires `caste_id`. Returns approved active sub-castes under that caste only.

### GET `/api/v1/onboarding/lookups/locations`

Query:

- `q`: string, minimum practical search length is 2
- `preferred_state_id`: nullable state row id from `addresses`
- `type`: nullable `village`, `city`, or `suburb`

Location item shape:

```json
{
  "id": 123,
  "location_id": 123,
  "label": "Tasgaon, Tasgaon, Sangli 416312",
  "display_hierarchy": "Tasgaon, Tasgaon, Sangli 416312",
  "type": "village",
  "tag": "rural",
  "is_final_node": true,
  "status": "approved",
  "pincode": "416312",
  "state_id": 1,
  "district_id": 2,
  "taluka_id": 3,
  "city_id": null,
  "parent": {
    "state": {"id": 1, "label": "Maharashtra"},
    "district": {"id": 2, "label": "Sangli"},
    "taluka": {"id": 3, "label": "Tasgaon"},
    "city": null
  }
}
```

Display follows Laravel hierarchy:

- Rural: village, taluka, district, state, pincode
- City: city + district/state
- Suburb/area: suburb/area + parent city + district/state

Selectable final nodes are active `addresses` rows with `hierarchy=village` and `tag` in `city`, `suburban`, or `rural`. State/district/taluka rows are not final profile-save locations.

### POST `/api/v1/onboarding/location-suggestions`

Creates a pending location request only. It never inserts an approved master location.

Request:

```json
{
  "type": "village",
  "name": "Location name",
  "state_id": 1,
  "district_id": 2,
  "taluka_id": 3,
  "city_id": null,
  "pincode": "416312",
  "notes": "optional"
}
```

Hierarchy validation:

- `state_id` and `district_id` are required.
- `taluka_id` is required for `village`.
- `city_id` is required for `suburb`.
- Requests stay pending and cannot make a profile searchable.
- Flutter should save the returned pending request into `PATCH /api/v1/onboarding/draft/location` using `pending_location_request_id`, `pending_location_label`, `pending_location_status`, and `pending_location_type`.
- Pending request resume/status is returned in draft data and as `pending_location` on onboarding status/checklist responses.

### GET `/api/v1/onboarding/lookups/education`

Returns backend-driven education degree objects. `category_id` is optional. Category labels come from `master_education_categories`; Flutter must not hardcode categories.

Item `meta` includes:

```json
{
  "category_id": 5,
  "category_label": "Engineering",
  "level_rank": 4000,
  "level_rank_source": "category_sort_order",
  "requires_specialization": false,
  "requires_college": false
}
```

If a real `level_rank` column exists in a deployment it can be used; otherwise Phase 3 derives deterministic rank from category/degree sort order.

### POST `/api/v1/onboarding/education-suggestions`

Creates a pending master suggestion only. It does not create an approved `master_education` row and does not allow profile save-step to persist arbitrary education text.

### GET `/api/v1/onboarding/lookups/working-with`

Returns active `working_with_types` options.

### GET `/api/v1/onboarding/lookups/occupations`

Query:

- `working_with_id`: nullable; filters occupation categories linked through `legacy_working_with_type_id`
- `category_id`: nullable
- common lookup query params

Item `meta` includes `category_id`, `category_label`, `working_with_id`, and `working_with_label`. Category labels come from `master_occupation_categories`; Flutter must not hardcode categories.

### POST `/api/v1/onboarding/occupation-suggestions`

Creates a pending master suggestion only. It does not create an approved `master_occupations` row and does not let profile save-step persist arbitrary occupation text as master data.

### GET `/api/v1/onboarding/lookups/income-options`

Returns the backend-supported income engine contract for personal and family income:

```json
{
  "success": true,
  "currency": "INR",
  "currency_id": 1,
  "currency_symbol": "₹",
  "periods": [
    {"key": "monthly", "label": "Monthly"},
    {"key": "annual", "label": "Annual"}
  ],
  "value_types": [
    {"key": "exact", "label": "Exact"},
    {"key": "approximate", "label": "Approximate income"},
    {"key": "range", "label": "Range"},
    {"key": "undisclosed", "label": "Undisclosed"}
  ],
  "ranges": [],
  "privacy_default": "private"
}
```

### GET `/api/v1/onboarding/lookups/diet`
### GET `/api/v1/onboarding/lookups/smoking`
### GET `/api/v1/onboarding/lookups/drinking`

Separate lightweight lifestyle lookup endpoints. The same data is also included in bootstrap.

### GET `/api/v1/onboarding/preferences/auto-draft/preview`

Requires an existing profile. Generates a read-only partner preference draft from profile data and onboarding draft toggles. Does not persist.

Response:

```json
{
  "success": true,
  "profile_id": 1,
  "source": "auto_from_registration",
  "can_persist": true,
  "missing_fields": [],
  "strictness": {
    "religion": "required",
    "caste": "preferred"
  },
  "preference_strictness": {
    "religion": "must_match",
    "caste": "preferred"
  },
  "preferences": {}
}
```

Strictness rules:

- `strictness` preserves registration enum values: `required`, `preferred`, or `open`.
- `preference_strictness` exposes backend matching semantics. `required` maps to `must_match`, `preferred` maps to `preferred`, and `open` maps to `open`.
- `must_match` is used only when the user explicitly selected `required` for religion, caste, or sub-caste.
- Inferred age, height, location, education, occupation, income, marital status, and diet defaults are `preferred` or `open`.
- Existing schema has no persisted `preferred_gender` or `preferred_sub_caste` column; those are represented only in metadata/strictness when relevant.

### POST `/api/v1/onboarding/preferences/auto-draft`

Persists the generated preferences through `MutationService::applyManualSnapshot()` using the existing governed `preferences` snapshot path. Also writes `partner_preference_metadata`.

Request:

```json
{
  "force_regenerate": false
}
```

Overwrite policy:

- no existing preferences: generate and persist
- existing auto-generated preferences: require `force_regenerate=true` to rebuild
- existing manual/user-edited preferences: return HTTP 409 and do not overwrite

Metadata:

- `source = auto_from_registration`
- `generated_from = onboarding`
- `strictness_json` stores exact registration enum values (`required`, `preferred`, `open`) for religion, caste, and sub-caste when present
- generated preferences remain editable later through existing preference edit mechanisms

Partner preference missing does not block activation/searchability in Phase 3.

### GET `/api/v1/onboarding/preferences/auto-draft/status`

Returns whether an auto-draft exists for the authenticated user's profile.

`GET /api/v1/onboarding/status` also includes a non-blocking `preferences` summary with the same source/generated status.

### POST `/api/v1/register`

Legacy password registration endpoint kept for backward compatibility. New mobile onboarding should use the OTP-first flow above.

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
- `currencies`: active `master_income_currencies` rows for income-engine currency selection

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
  ],
  "currencies": [
    {
      "id": 1,
      "key": "INR",
      "code": "INR",
      "symbol": "₹",
      "label": "₹ INR",
      "label_en": "₹ INR",
      "label_mr": null,
      "is_default": true
    }
  ]
}
```

### GET `/api/v1/profile/marital-lifestyle-options`

Requires bearer token. Returns read-only governed options for APK Edit All Marital + Lifestyle fields.

Sources:

- `marital_statuses`: `master_marital_statuses` via `App\Models\MasterMaritalStatus`
- `child_living_with`: active `master_child_living_with` rows for children repeater `child_living_with_id`
- `diets`: `master_diets` via `App\Models\MasterDiet`
- `smoking_statuses`: `master_smoking_statuses` via `App\Models\MasterSmokingStatus`
- `drinking_statuses`: `master_drinking_statuses` via `App\Models\MasterDrinkingStatus`

Success response: HTTP 200

```json
{
  "marital_statuses": [
    { "id": 1, "key": "never_married", "label": "Never Married", "label_en": "Never Married", "label_mr": null }
  ],
  "child_living_with": [
    { "id": 1, "key": "with_parent", "label": "With parent", "label_en": "With parent", "label_mr": null }
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
- `currencies`: active `master_income_currencies` rows for family income-engine currency selection
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
- `horoscope_rules`: `HoroscopeRuleService::getRulesForFrontend()` dependency metadata used by the website horoscope engine
- `rashi_ashtakoota`: `HoroscopeRuleService::getRashiAshtakootaForFrontend()` rashi-based Varna/Vashya/Rashi Lord metadata used by the website horoscope engine

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
  "currencies": [
    { "id": 1, "key": "INR", "code": "INR", "symbol": "₹", "label": "₹ INR", "label_en": "₹ INR", "label_mr": null, "is_default": true }
  ],
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
  ],
  "horoscope_rules": {
    "rashi_rules": [
      { "nakshatra_id": 1, "charan": 1, "rashi_id": 1 }
    ],
    "nakshatra_attributes": [
      { "nakshatra_id": 1, "gan_id": 1, "nadi_id": 1, "yoni_id": 1 }
    ],
    "distinct_rashi_ids_by_nakshatra": {
      "1": [1]
    },
    "nakshatra_ids_by_rashi": {
      "1": [1]
    }
  },
  "rashi_ashtakoota": {
    "1": {
      "varna_id": 1,
      "vashya_id": 1,
      "rashi_lord_id": 1,
      "varna": "Brahmin",
      "vashya": "Chatushpada",
      "rashi_lord": "Mars"
    }
  }
}
```

### GET `/api/v1/profile/partner-preference-options`

Requires bearer token. Returns read-only governed options for APK Edit All simple Partner Preferences / Expectations fields. These options reuse existing website wizard sources and preserve source order.

Sources:

- `marriage_type_preferences`: `master_marriage_type_preferences` via `App\Models\MasterMarriageTypePreference`, ordered by `sort_order`, then `id`
- `marital_statuses`: `master_marital_statuses`, ordered by website partner-preference source order (`label`, then `id`)
- `diets`: `master_diets` via `App\Models\MasterDiet`, ordered by `sort_order`, then `id`
- `religions`: `master_religions`, ordered by website source order (`label`, then `id`)
- `castes`: `master_castes`, ordered by website source order (`label`, then `id`), includes `religion_id`
- `education_degrees`: `master_education` via `App\Models\EducationDegree`, ordered by `sort_order`, then `code`
- `occupation_categories`: `master_occupation_categories`, ordered by `sort_order`, then `name`
- `occupations`: `master_occupations`, ordered by `sort_order`, then `name`
- `partner_profile_with_children`: website enum options `no`, `yes_if_live_separate`, `yes`
- `preferred_profile_managed_by`: website enum options `any`, `self`, `parent_guardian`, `sibling`, `relative`, `friend`, `other`

Success response: HTTP 200

```json
{
  "marriage_type_preferences": [
    { "id": 1, "key": "arranged", "label": "Arranged", "label_en": "Arranged", "label_mr": null }
  ],
  "marital_statuses": [
    { "id": 2, "key": "divorced", "label": "Divorced", "label_en": "Divorced", "label_mr": null }
  ],
  "diets": [
    { "id": 1, "key": "vegetarian", "label": "Vegetarian", "label_en": "Vegetarian", "label_mr": null }
  ],
  "religions": [
    { "id": 4, "key": "hindu", "label": "Hindu", "label_en": "Hindu", "label_mr": "हिंदू" }
  ],
  "castes": [
    { "id": 412, "religion_id": 4, "key": "maratha", "label": "Maratha", "label_en": "Maratha", "label_mr": "मराठा" }
  ],
  "education_degrees": [
    { "id": 1, "code": "B.A.", "label": "B.A.", "label_en": "B.A.", "label_mr": null, "full_form": "Bachelor of Arts", "category_id": 1 }
  ],
  "occupation_categories": [
    { "id": 1, "label": "Technology", "label_en": "Technology", "label_mr": null, "legacy_working_with_type_id": null }
  ],
  "occupations": [
    { "id": 10, "label": "Software Engineer", "label_en": "Software Engineer", "label_mr": null, "category_id": 1 }
  ],
  "partner_profile_with_children": [
    { "key": "no", "label": "No", "label_en": "No", "label_mr": "नाही" },
    { "key": "yes_if_live_separate", "label": "Yes, if living separately", "label_en": "Yes, if living separately", "label_mr": null },
    { "key": "yes", "label": "Yes", "label_en": "Yes", "label_mr": null }
  ],
  "preferred_profile_managed_by": [
    { "key": "", "label": "Any", "label_en": "Any", "label_mr": null },
    { "key": "self", "label": "Self", "label_en": "Self", "label_mr": null }
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
  "self_addresses": [
    {
      "address_type_key": "current",
      "address_line": "Flat 10, Pune",
      "location_id": 123
    },
    {
      "address_type_key": "native",
      "address_line": "Native house",
      "location_id": 124
    }
  ],
  "mother_tongue_id": 1,
  "marital_status_id": 1,
  "has_children": false,
  "marriages": [],
  "children": [],
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
  "father_contact_1": "9876543210",
  "father_contact_2": "+91 98765 43211",
  "mother_name": "Mother Name",
  "mother_occupation_master_id": 11,
  "mother_extra_info": "Homemaker",
  "mother_contact_1": "9876543212",
  "mother_contact_2": null,
  "parents_addresses": [
    {
      "address_type_key": "permanent",
      "address_line": "Parents home",
      "location_id": 125
    }
  ],
  "family_type_id": 1,
  "family_status": "middle_class",
  "family_values": "traditional",
  "has_siblings": true,
  "siblings": [
    {
      "relation_type": "brother",
      "name": "Brother Name",
      "marital_status": "unmarried",
      "occupation": "Engineer",
      "occupation_master_id": null,
      "occupation_custom_id": null,
      "city_id": null,
      "address_line": "Pune",
      "notes": "Elder sibling",
      "sort_order": 0
    }
  ],
  "relatives": [
    {
      "relation_type": "paternal_uncle",
      "name": "Uncle Name",
      "occupation": "Engineer",
      "occupation_master_id": null,
      "occupation_custom_id": null,
      "city_id": null,
      "state_id": null,
      "district_id": null,
      "taluka_id": null,
      "address_line": "Pune",
      "notes": "Paternal side"
    }
  ],
  "alliance_networks": [
    {
      "surname": "Jadhav",
      "city_id": 123,
      "state_id": 2,
      "district_id": 3,
      "taluka_id": 4,
      "notes": "Pune network"
    }
  ],
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
- `self_addresses`: nullable array, max 10 rows. Rows map to governed snapshot key `addresses` with `address_scope=self`.
- `self_addresses.*.id`: nullable integer existing `profile_addresses.id`
- `self_addresses.*.address_type_key`: nullable string, one of `current`, `permanent`, `native`, `work`, `other`; omitted rows default to `current`
- `self_addresses.*.address_type_id`: nullable active `master_address_types.id`; prefer `address_type_key` for mobile
- `self_addresses.*.address_line`: nullable string, max 255
- `self_addresses.*.location_id` or `self_addresses.*.city_id`: nullable, must exist in `addresses.id`
- `self_addresses.*.contact_number`, `self_addresses.*.contact_number_2`, `self_addresses.*.contact_number_3`, `self_addresses.*.phone_number`, `self_addresses.*.mobile_number`, and `self_addresses.*.primary_contact_number` are not accepted.
- The `current` self address is the structured SSOT for current residence. Legacy scalar `location_id` and `address_line` are still accepted for backward compatibility and should mirror the current self address when mobile sends structured rows.
- `mother_tongue_id`: nullable, must exist as an active `master_mother_tongues.id`
- `marital_status_id`: nullable, must exist as an active `master_marital_statuses.id`. Supported marital status keys are `never_married`, `divorced`, `annulled`, `separated`, and `widowed`.
- `has_children`: nullable boolean. It is effective only for `divorced`, `annulled`, `separated`, and `widowed`; when status is `never_married`, the API forces it to `false`.
- `marriages`: nullable array, but mobile treats it as Laravel `marital_engine` status details, not as a user-facing multi-row repeater. At most one effective row is accepted: the row with the highest submitted `id`, otherwise the last non-empty submitted row. The backend preserves the latest saved marriage row id when possible.
- `marriages.*.marital_status_id`: nullable, must exist as an active `master_marital_statuses.id`; omit it to use the top-level `marital_status_id`.
- `marriages.*.marriage_year`: nullable integer year, min 1901, max current year.
- For `divorced` and `annulled`, mobile accepts `marriage_year`, `divorce_year`, and `divorce_status`; `separation_year` and `spouse_death_year` are ignored/returned as `null`.
- For `separated`, mobile accepts `marriage_year`, `separation_year`, and `divorce_status` as legal status; `divorce_year` and `spouse_death_year` are ignored/returned as `null`.
- For `widowed`, mobile accepts `marriage_year` and `spouse_death_year`; `divorce_year`, `separation_year`, and `divorce_status` are ignored/returned as `null`.
- `marriages.*.divorce_year`, `marriages.*.separation_year`, `marriages.*.spouse_death_year`: nullable integer years, min 1901, max current year; relevant years must be greater than or equal to `marriage_year` when both are present.
- `marriages.*.divorce_status`: nullable, one of `pending`, `finalized`, `mutual`, `contested`
- `marriages.*.remarriage_reason` and `marriages.*.notes` are ignored by mobile because Laravel `marital_engine` does not expose those fields.
- `marriages.*.contact_number`, `marriages.*.contact_number_2`, `marriages.*.contact_number_3`, `marriages.*.phone_number`, and `marriages.*.mobile_number` are not accepted. Sending `marital_status_id` for `never_married` clears marriage detail rows and child rows.
- `children`: nullable array, max 20 rows. Each row may include `id`, `child_name`, `gender`, `age`, `child_living_with_id`, and `sort_order`.
- `children.*.gender`: required when the child row has any other data; one of `male`, `female`, `other`, `prefer_not_say`
- `children.*.age`: required when the child row has any other data; integer, min 1, max 120
- `children.*.child_living_with_id`: nullable, must exist as an active `master_child_living_with.id`
- `children.*.child_name`: nullable string, max 255
- `children.*.sort_order`: nullable integer, min 0
- `children.*.contact_number`, `children.*.contact_number_2`, `children.*.contact_number_3`, `children.*.phone_number`, and `children.*.mobile_number` are not accepted. Children are effective only for `divorced`, `annulled`, `separated`, or `widowed` with `has_children=true`; otherwise submitted children are ignored/cleared.
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
- `father_contact_1`, `father_contact_2`, `mother_contact_1`, `mother_contact_2`: nullable phone strings, max 20 chars, digits/`+`/spaces/`-`/parentheses only. Returned only in authenticated owner's own profile payload.
- `father_contact_3`, `mother_contact_3`: same rule, only if the deployment still has those DB columns.
- `parent_contact_max_slots`: integer capability returned only in authenticated owner's own profile payload. Value is `2` unless a supported parent contact 3 column exists, then `3`.
- `father_contact_whatsapp_*` and `mother_contact_whatsapp_*` are not accepted by the mobile API.
- `parents_addresses`: nullable array, max 10 rows. Rows map to governed snapshot key `addresses` with `address_scope=parents`.
- `parents_addresses.*.id`: nullable integer existing `profile_addresses.id`
- `parents_addresses.*.address_type_key`: nullable string, one of `current`, `permanent`, `native`, `work`, `other`; omitted rows default to `permanent`
- `parents_addresses.*.address_type_id`: nullable active `master_address_types.id`; prefer `address_type_key` for mobile
- `parents_addresses.*.address_line`: nullable string, max 255
- `parents_addresses.*.location_id` or `parents_addresses.*.city_id`: nullable, must exist in `addresses.id`
- `parents_addresses.*.contact_number`, `parents_addresses.*.contact_number_2`, `parents_addresses.*.contact_number_3`, `parents_addresses.*.phone_number`, `parents_addresses.*.mobile_number`, and `parents_addresses.*.primary_contact_number` are not accepted.
- `family_type_id`: nullable, must exist as an active `master_family_types.id`
- `family_status`: nullable string, must be one of the website `components.family.status_options` keys
- `family_values`: nullable string, must be one of the website `components.family.values_options` keys
- `has_siblings`: nullable boolean
- `siblings`: nullable array, max 20 rows. Each row may include `id`, `relation_type`, `name`, `marital_status`, `occupation`, `occupation_master_id`, `occupation_custom_id`, `city_id`, `address_line`, `notes`, and `sort_order`.
- `siblings.*.relation_type`: nullable, one of `brother`, `sister`, `brother_wife`, `sister_husband`
- `siblings.*.marital_status`: nullable, one of `unmarried`, `married`
- `siblings.*.occupation_master_id`: nullable, must exist in `master_occupations.id`
- `siblings.*.occupation_custom_id`: nullable, must exist in the logged-in user's `master_occupation_custom.id`; cannot be sent together with `occupation_master_id`
- `siblings.*.city_id`: nullable, must exist in `addresses.id`
- `siblings.*.name`, `siblings.*.occupation`, `siblings.*.address_line`: nullable string, max 255
- `siblings.*.notes`: nullable string, max 1000
- `siblings.*.sort_order`: nullable integer, min 0
- Send `siblings: []` with `has_siblings: false` to clear sibling rows through the same governed replace behavior used by the Laravel wizard. Omitting `siblings` preserves existing sibling rows.
- `relatives`: nullable array, max 20 rows. Each row may include `id`, `relation_type`, `name`, `occupation`, `occupation_master_id`, `occupation_custom_id`, `city_id`, `state_id`, `district_id`, `taluka_id`, `address_line`, and `notes`.
- `relatives.*.relation_type`: nullable but required when the row has other data; one of `paternal_grandfather`, `paternal_grandmother`, `paternal_uncle`, `wife_paternal_uncle`, `paternal_aunt`, `husband_paternal_aunt`, `Cousin`, `maternal_address_ajol`, `maternal_grandfather`, `maternal_grandmother`, `maternal_uncle`, `wife_maternal_uncle`, `maternal_aunt`, `husband_maternal_aunt`, `maternal_cousin`
- `relatives.*.occupation_master_id`: nullable, must exist in `master_occupations.id`
- `relatives.*.occupation_custom_id`: nullable, must exist in the logged-in user's `master_occupation_custom.id`; cannot be sent together with `occupation_master_id`
- `relatives.*.city_id`, `relatives.*.state_id`, `relatives.*.district_id`, `relatives.*.taluka_id`: nullable, must exist in `addresses.id`
- `relatives.*.name`, `relatives.*.occupation`, `relatives.*.address_line`: nullable string, max 255
- `relatives.*.notes`: nullable string, max 1000
- `relatives.*.contact_number`, `relatives.*.contact_number_2`, `relatives.*.contact_number_3`, and `relatives.*.is_primary_contact` are not accepted by the mobile contract. Send `relatives: []` to clear relative rows. Omitting `relatives` preserves existing relative rows.
- `alliance_networks`: nullable array, max 20 rows. Each row may include `id`, `surname`, `city_id`, `state_id`, `district_id`, `taluka_id`, and `notes`.
- `alliance_networks.*.surname`: nullable string, max 255, but required when that row has any location or note data because `profile_alliance_networks.surname` is required.
- `alliance_networks.*.city_id`, `alliance_networks.*.state_id`, `alliance_networks.*.district_id`, `alliance_networks.*.taluka_id`: nullable, must exist in `addresses.id`
- `alliance_networks.*.notes`: nullable string, max 1000
- `alliance_networks.*.contact_number`, `alliance_networks.*.contact_number_2`, `alliance_networks.*.contact_number_3`, `alliance_networks.*.phone_number`, `alliance_networks.*.mobile_number`, and `alliance_networks.*.primary_contact_number` are not accepted by the mobile contract. Send `alliance_networks: []` to clear alliance network rows. Omitting `alliance_networks` preserves existing alliance network rows.
- Own profile GET/PUT responses include `alliance_networks.*.notes` for editing. Other-profile detail responses remove `notes` and any contact-like alliance row keys.
- `other_relatives_text`, `property_details`: nullable string, max 4000
- `rashi_id`, `nakshatra_id`, `gan_id`, `nadi_id`, `yoni_id`, `mangal_dosh_type_id`: nullable active master IDs
- `varna_id`, `vashya_id`, `rashi_lord_id`: nullable active Ashtakoota master IDs
- `charan`: nullable integer, min 1, max 4
- `devak`, `kul`, `gotra`, `navras_name`: nullable string, max 255
- `birth_weekday`: nullable string, must be one of the website weekday dropdown values: `Monday`, `Tuesday`, `Wednesday`, `Thursday`, `Friday`, `Saturday`, `Sunday`
- `narrative_about_me`: nullable string, max 5000
- `preferred_age_min`, `preferred_age_max`: nullable integers, 18-80; min must be less than or equal to max
- `preferred_height_min_cm`, `preferred_height_max_cm`: nullable integers; min must be less than or equal to max
- `preferred_income_min`, `preferred_income_max`: nullable numeric values; min must be less than or equal to max
- `marriage_type_preference_id`: nullable, must exist as an active `master_marriage_type_preferences.id`
- `partner_profile_with_children`: nullable, one of `no`, `yes_if_live_separate`, `yes`
- `preferred_profile_managed_by`: nullable, one of `self`, `parent_guardian`, `sibling`, `relative`, `friend`, `other`
- `willing_to_relocate`: nullable boolean
- `preferred_religion_ids`: nullable array of active `master_religions.id`
- `preferred_caste_ids`: nullable array of active `master_castes.id`; selected castes must belong to selected preferred religions
- `preferred_intercaste`: nullable boolean; saved to `profile_partner_community_flags.interested_in_intercaste`
- `preferred_education_degree_ids`: nullable array of `master_education.id`
- `preferred_occupation_master_ids`: nullable array of `master_occupations.id`
- `preferred_marital_status_ids`: nullable array of active `master_marital_statuses.id`
- `preferred_diet_ids`: nullable array of active `master_diets.id`
- `narrative_expectations`: nullable string, max 5000

Governance note:

- `caste` is accepted as a mobile compatibility input.
- When it resolves against existing master caste data, the governed write stores `caste_id`.
- Raw legacy `caste` text is not a governed write target.
- Matrimony gender source is `matrimony_profiles.gender_id`; `users.gender` is not a runtime fallback for profile matching, visibility, comparison, or display.
- Phase 5B1 + 5D partner expectations listed above flow through the governed partner preference snapshot path, except `preferred_intercaste`, which reuses the existing website community flag service.
- Parent contact numbers are accepted only for the authenticated owner's edit/read payload: `father_contact_1`, `father_contact_2`, `mother_contact_1`, and `mother_contact_2`. `father_contact_3` and `mother_contact_3` are accepted only on deployments where those DB columns still exist. Own profile responses include `parent_contact_max_slots` so mobile can show the correct number of slots. Other-profile detail/list responses must not expose parent contact keys or `parent_contact_max_slots`. WhatsApp/contact-preference flags for parent contact fields are not accepted by the mobile API because they are not stored on `matrimony_profiles`.
- Parent contact values are private. Other-profile detail/list responses and public display sections must not expose them.
- Sibling contact numbers, relative contact numbers, contact unlock/payment fields, and preference repeaters are intentionally not accepted in this mobile contract.
- Sibling and relative contact number columns exist in the Laravel web schema but are not accepted or returned by this mobile contract. Other-profile detail/list responses must not expose sibling or relative contact numbers.
- Relative repeaters are intentionally deferred until a row-preserving, privacy-safe mobile contract is added.

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
    "address_line": "Optional address line",
    "self_addresses": [
      {
        "id": 200,
        "address_scope": "self",
        "address_type_id": 1,
        "address_type_key": "current",
        "address_type_label": "Current",
        "address_line": "Optional address line",
        "location_id": 123,
        "location_label": "Pune, Maharashtra",
        "display": "Pune, Maharashtra"
      }
    ],
    "caste_id": 5,
    "gender": "male",
    "country_id": 1,
    "state_id": 2,
    "district_id": 3,
    "taluka_id": 4,
    "mother_tongue_id": 1,
    "mother_tongue_label": "Marathi",
    "parents_addresses": [
      {
        "id": 201,
        "address_scope": "parents",
        "address_type_id": 2,
        "address_type_key": "permanent",
        "address_type_label": "Permanent",
        "address_line": "Parents home",
        "location_id": 125,
        "location_label": "Satara, Maharashtra",
        "display": "Satara, Maharashtra"
      }
    ],
    "marital_status_id": 2,
    "marital_status_key": "divorced",
    "marital_status_label": "Divorced",
    "has_children": true,
    "marriages": [
      {
        "id": 300,
        "marital_status_id": 2,
        "marital_status_label": "Divorced",
        "marriage_year": 2010,
        "separation_year": null,
        "divorce_year": 2015,
        "spouse_death_year": null,
        "divorce_status": "finalized",
        "divorce_status_label": "Finalized",
        "remarriage_reason": null,
        "notes": null
      }
    ],
    "children": [
      {
        "id": 400,
        "child_name": null,
        "gender": "male",
        "gender_label": "Male",
        "age": 10,
        "child_living_with_id": 1,
        "child_living_with_label": "With parent",
        "sort_order": 0
      }
    ],
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
    "annual_income": 1200000,
    "income_period": "annual",
    "income_value_type": "exact",
    "income_amount": 1200000,
    "income_min_amount": null,
    "income_max_amount": null,
    "income_currency_id": 1,
    "income_currency_label": "₹ INR",
    "income_private": false,
    "income_display_label": "₹12.0 L annually",
    "father_name": "Father Name",
    "father_occupation_master_id": 10,
    "father_occupation_master_label": "Business",
    "father_extra_info": "Runs a business",
    "father_contact_1": "9876543210",
    "father_contact_2": "+91 98765 43211",
    "mother_name": "Mother Name",
    "mother_occupation_master_id": 11,
    "mother_occupation_master_label": "Homemaker",
    "mother_extra_info": "Homemaker",
    "mother_contact_1": "9876543212",
    "mother_contact_2": null,
    "parent_contact_max_slots": 2,
    "family_type_id": 1,
    "family_type_label": "Joint",
    "family_status": "middle_class",
    "family_values": "traditional",
    "family_income": 1500000,
    "family_income_period": "monthly",
    "family_income_value_type": "range",
    "family_income_amount": null,
    "family_income_min_amount": 100000,
    "family_income_max_amount": 150000,
    "family_income_currency_id": 1,
    "family_income_currency_label": "₹ INR",
    "family_income_private": false,
    "family_income_display_label": "₹1.0 L – ₹1.5 L monthly",
    "has_siblings": true,
    "siblings": [
      {
        "id": 100,
        "relation_type": "brother",
        "relation_type_label": "Brother",
        "name": "Brother Name",
        "marital_status": "unmarried",
        "marital_status_label": "Unmarried",
        "occupation": "Engineer",
        "occupation_master_id": null,
        "occupation_master_label": null,
        "occupation_custom_id": null,
        "occupation_custom_label": null,
        "city_id": null,
        "city_label": null,
        "address_line": "Pune",
        "notes": "Elder sibling",
        "sort_order": 0
      }
    ],
    "relatives": [
      {
        "id": 200,
        "relation_type": "paternal_uncle",
        "relation_type_label": "Paternal Uncle",
        "name": "Uncle Name",
        "occupation": "Engineer",
        "occupation_master_id": null,
        "occupation_master_label": null,
        "occupation_custom_id": null,
        "occupation_custom_label": null,
        "city_id": null,
        "city_label": null,
        "state_id": null,
        "district_id": null,
        "taluka_id": null,
        "address_line": "Pune",
        "notes": "Paternal side"
      }
    ],
    "alliance_networks": [
      {
        "id": 250,
        "surname": "Jadhav",
        "city_id": 123,
        "city_label": "Pune",
        "state_id": 2,
        "state_label": "Maharashtra",
        "district_id": 3,
        "district_label": "Pune",
        "taluka_id": 4,
        "taluka_label": "Haveli",
        "notes": "Pune network"
      }
    ],
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
    "narrative_expectations": "Looking for a thoughtful partner.",
    "preferred_age_min": 24,
    "preferred_age_max": 31,
    "preferred_height_min_cm": 150,
    "preferred_height_max_cm": 180,
    "preferred_income_min": 700000,
    "preferred_income_max": 1200000,
    "preferred_income_label": "₹700,000 - ₹1,200,000",
    "marriage_type_preference_id": 1,
    "marriage_type_preference_label": "Arranged",
    "partner_profile_with_children": "yes_if_live_separate",
    "partner_profile_with_children_label": "Yes, if living separately",
    "preferred_profile_managed_by": "parent_guardian",
    "preferred_profile_managed_by_label": "Parent / Guardian",
    "willing_to_relocate": true,
    "preferred_religion_ids": [4],
    "preferred_religion_labels": ["Hindu"],
    "preferred_caste_ids": [412],
    "preferred_caste_labels": ["Maratha"],
    "preferred_intercaste": true,
    "preferred_education_degree_ids": [1],
    "preferred_education_degree_labels": ["B.A."],
    "preferred_occupation_master_ids": [10],
    "preferred_occupation_master_labels": ["Software Engineer"],
    "preferred_marital_status_ids": [2, 5],
    "preferred_marital_status_labels": ["Divorced", "Widowed"],
    "preferred_diet_ids": [1, 3],
    "preferred_diet_labels": ["Vegetarian", "Jain"],
    "profile_photo": null,
    "partner_preferences": null,
    "partner_preference_suggestions": {
      "preferred_age_min": 24,
      "preferred_age_max": 31,
      "preferred_height_min_cm": 150,
      "preferred_height_max_cm": 180,
      "preferred_country_ids": [1],
      "preferred_state_ids": [12, 13],
      "preferred_district_ids": [101, 202],
      "preferred_taluka_ids": [1001, 2002],
      "preferred_location_suggestions": [
        {
          "id": 1001,
          "type": "taluka",
          "label": "Khanapur, Sangli",
          "district_id": 101,
          "state_id": 12,
          "country_id": 1,
          "distance_km": 0,
          "source": "own_taluka"
        },
        {
          "id": 2002,
          "type": "taluka",
          "label": "Athani, Belagavi",
          "district_id": 202,
          "state_id": 13,
          "country_id": 1,
          "distance_km": 18.42,
          "source": "nearby_taluka"
        }
      ],
      "preferred_marital_status_ids": [1],
      "preferred_diet_ids": [1]
    }
  }
}
```

`partner_preference_suggestions` is read-only. It is computed from the existing
profile by `PartnerPreferenceSuggestionService` and is intended only for mobile
Edit All UI defaults. It never writes to `profile_preference_criteria` unless the
user explicitly saves the Partner Preferences section. Location suggestions include
the member's own taluka first, then nearby talukas from the `addresses` latitude /
longitude data using a bounded distance query. Nearby talukas may cross district or
state borders. When taluka rows do not have their own coordinates, the backend may
derive a taluka centroid from child village coordinates inside the bounded search
box. If reliable coordinates are unavailable, the response falls back to the
existing residence district chain and does not use pincode as a fake nearby signal.

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
    "address_line": "Optional address line",
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
    "annual_income": 1200000,
    "income_period": "annual",
    "income_value_type": "exact",
    "income_amount": 1200000,
    "income_min_amount": null,
    "income_max_amount": null,
    "income_currency_id": 1,
    "income_currency_label": "₹ INR",
    "income_private": false,
    "income_display_label": "₹12.0 L annually",
    "family_income": 1500000,
    "family_income_period": "monthly",
    "family_income_value_type": "range",
    "family_income_amount": null,
    "family_income_min_amount": 100000,
    "family_income_max_amount": 150000,
    "family_income_currency_id": 1,
    "family_income_currency_label": "₹ INR",
    "family_income_private": false,
    "family_income_display_label": "₹1.0 L – ₹1.5 L monthly",
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
  "income_period": "annual",
  "income_value_type": "exact",
  "income_amount": 1200000,
  "income_currency_id": 1,
  "income_private": false,
  "family_type_id": 1,
  "family_income_period": "monthly",
  "family_income_value_type": "range",
  "family_income_min_amount": 100000,
  "family_income_max_amount": 150000,
  "family_income_currency_id": 1,
  "family_income_private": false,
  "has_siblings": true,
  "siblings": [
    {
      "id": 100,
      "relation_type": "brother",
      "name": "Brother Name",
      "marital_status": "unmarried",
      "occupation": "Engineer",
      "address_line": "Pune",
      "notes": "Elder sibling",
      "sort_order": 0
    }
  ],
  "relatives": [
    {
      "id": 200,
      "relation_type": "paternal_uncle",
      "name": "Uncle Name",
      "occupation": "Engineer",
      "address_line": "Pune",
      "notes": "Paternal side"
    }
  ],
  "property_details": "Own house",
  "rashi_id": 1,
  "narrative_about_me": "Short profile introduction.",
  "preferred_age_min": 24,
  "preferred_age_max": 31,
  "preferred_height_min_cm": 150,
  "preferred_height_max_cm": 180,
  "preferred_income_min": 700000,
  "preferred_income_max": 1200000,
  "marriage_type_preference_id": 1,
  "partner_profile_with_children": "yes_if_live_separate",
  "preferred_profile_managed_by": "parent_guardian",
  "willing_to_relocate": true,
  "preferred_religion_ids": [4],
  "preferred_caste_ids": [412],
  "preferred_intercaste": true,
  "preferred_education_degree_ids": [1],
  "preferred_occupation_master_ids": [10],
  "preferred_marital_status_ids": [2, 5],
  "preferred_diet_ids": [1, 3],
  "narrative_expectations": "Looking for a thoughtful partner."
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
- `address_line` is returned in the authenticated owner's own profile payload and own Basic Details display section. Other-profile profile/display responses must not expose exact address lines.
- `self_addresses`: same row shape as create. Sending `self_addresses` performs governed sync for `address_scope=self` while preserving existing `parents` address rows by merging them into the outgoing `addresses` snapshot. Omitting `self_addresses` preserves existing self address rows.
- `parents_addresses`: same row shape as create. Sending `parents_addresses` performs governed sync for `address_scope=parents` while preserving existing `self` address rows by merging them into the outgoing `addresses` snapshot. Omitting `parents_addresses` preserves existing parents address rows.
- Own profile GET/PUT responses include `profile.self_addresses`, `profile.parents_addresses`, and `profile.parent_contact_max_slots` for editing. Other-profile detail/list responses must not expose `parents_addresses`, structured self address rows, exact `address_line` values, parent contact keys, or `parent_contact_max_slots`.
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
- Personal income engine keys: `annual_income`, `income_amount`, `income_min_amount`, and `income_max_amount` are nullable numeric values, min 0; `income_period` is one of `annual`, `monthly`, `weekly`, `daily`; `income_value_type` is one of `exact`, `approximate`, `range`, `undisclosed`; `income_currency_id` must exist as an active `master_income_currencies.id`; `income_private` is nullable boolean. `exact`/`approximate` require `income_amount`; `range` requires `income_min_amount` and `income_max_amount`.
- Family, property, horoscope, and `narrative_about_me` rules are the same as `POST /api/v1/matrimony-profile`.
- Family income engine keys mirror personal income: `family_income`, `family_income_amount`, `family_income_min_amount`, `family_income_max_amount`, `family_income_period`, `family_income_value_type`, `family_income_currency_id`, and `family_income_private`.
- Private income flags preserve the values for the owner's edit payload but display sections must not expose exact personal/family income amounts when the corresponding private flag is true.
- Relatives rules mirror `POST /api/v1/matrimony-profile`. Mobile accepts only safe relative row fields and never accepts or returns relative contact numbers.
- Sibling rows follow the same `siblings` shape as create. The mobile update contract does not accept sibling contact fields. Sending `siblings` performs governed row sync for that repeater; omitting `siblings` preserves existing sibling rows.
- Marriage details and child rows follow the same conditional `marital_engine` rules as create. Sending `marital_status_id=never_married` forces `has_children=false` and clears marriage/child rows. For `divorced`, `annulled`, `separated`, and `widowed`, `marriages` is one effective status-detail row, while `children` syncs only when `has_children=true`; use `has_children=false` or `children: []` to clear child rows.
- Alliance network rows follow the same `alliance_networks` shape as create. Sending `alliance_networks` performs governed full row sync for that repeater; omitting `alliance_networks` preserves existing alliance network rows.
- Phase 5B1 + 5D partner preferences and `narrative_expectations` follow the same rules as `POST /api/v1/matrimony-profile`.
- Parent contact fields follow the same owner-only/privacy rules as create. Sibling/relative contact fields and partner preference repeaters are not accepted by this mobile update contract.

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
    "address_line": "Optional address line",
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
    "annual_income": 1200000,
    "income_period": "annual",
    "income_value_type": "exact",
    "income_amount": 1200000,
    "income_min_amount": null,
    "income_max_amount": null,
    "income_currency_id": 1,
    "income_currency_label": "₹ INR",
    "income_private": false,
    "income_display_label": "₹12.0 L annually",
    "family_income": 1500000,
    "family_income_period": "monthly",
    "family_income_value_type": "range",
    "family_income_amount": null,
    "family_income_min_amount": 100000,
    "family_income_max_amount": 150000,
    "family_income_currency_id": 1,
    "family_income_currency_label": "₹ INR",
    "family_income_private": false,
    "family_income_display_label": "₹1.0 L – ₹1.5 L monthly",
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

All feeds still use the same mobile discovery eligibility rule: no own profile, no same-gender profile, no hidden/blocked/suspended/admin candidates, and no phone/email/WhatsApp/contact data in list rows. Active showcase profiles are eligible for mobile discovery with the same gender, lifecycle, block, hide, and visibility rules as regular profiles.

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
