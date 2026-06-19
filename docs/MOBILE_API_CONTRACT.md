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
  "gender": "male",
  "email": "user@example.com",
  "password": "Password value accepted by Laravel password defaults"
}
```

Rules:

- `name`: required string, max 255
- `gender`: required, `male` or `female`
- `email`: required lowercase email, unique in `users.email`, max 255
- `password`: required, Laravel `Rules\Password::defaults()`

Success response: HTTP 200

```json
{
  "success": true,
  "message": "Registration successful",
  "token": "<plain_text_sanctum_token>",
  "user": {
    "id": 1,
    "name": "User Name",
    "email": "user@example.com",
    "gender": "male"
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

## Matrimony Profile

All profile mutation goes through the governed mutation layer. The mobile create path creates a draft profile with `MutationService::createDraftProfileForUser()` and applies submitted profile data with `MutationService::applyManualSnapshot()`.

### POST `/api/v1/matrimony-profile`

Requires bearer token. Creates the logged-in user's matrimony profile. Returns HTTP 409 if a profile already exists.

Request:

```json
{
  "full_name": "Candidate Name",
  "date_of_birth": "1998-04-15",
  "caste": "Maratha",
  "highest_education": "B.E.",
  "location_id": 123
}
```

Rules:

- `full_name`: required string, max 255
- `date_of_birth`: required date
- `caste`: required string, max 255
- `highest_education`: required string, max 255
- `location_id`: required, must exist in `addresses.id`

Governance note:

- `caste` is accepted as a mobile compatibility input.
- When it resolves against existing master caste data, the governed write stores `caste_id`.
- Raw legacy `caste` text is not a governed write target.

Success response: HTTP 200

```json
{
  "success": true,
  "message": "Matrimony profile created",
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
  "date_of_birth": "1998-04-15",
  "caste": "Maratha",
  "highest_education": "MCA",
  "location_id": 123,
  "address_line": "Optional address line"
}
```

Rules:

- `full_name`: sometimes required string, max 255
- `date_of_birth`: sometimes required date
- `caste`: sometimes required string, max 255
- `highest_education`: sometimes required string, max 255
- `location_id`: sometimes required, must exist in `addresses.id`
- `address_line`: nullable string, max 255

Success response: HTTP 200

```json
{
  "success": true,
  "message": "Matrimony profile updated",
  "profile": {
    "id": 10,
    "user_id": 1,
    "full_name": "Updated Candidate Name",
    "date_of_birth": "1998-04-15",
    "highest_education": "MCA",
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

- `caste`
- `country_id`
- `state_id`
- `district_id`
- `taluka_id`
- `location_id`
- `age_from`
- `age_to`

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
