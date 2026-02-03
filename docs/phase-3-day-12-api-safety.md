# Phase-3 Day-12 — API Safety Boundaries: CORE vs EXTENDED Profile Fields

**Document type:** Documentation deliverable (Phase-3 Day-12)  
**Source:** PHASE-3_SSOT.md — Day 12  
**Scope:** Documentation only. No code changes. No API contract changes. No Flutter parity change.

---

## 1. CORE vs EXTENDED field definitions

### CORE fields

- **Definition:** Fixed schema fields stored as columns on the profile table.
- **Characteristics:**
  - Fixed set of keys; defined in code and migrations.
  - Used by search, filters, completeness, and core app flows.
  - Cannot be created or deleted at runtime by admin.
- **Examples:** `full_name`, `gender`, `date_of_birth`, `marital_status`, `education`, `location`, `caste`, `height_cm`, `profile_photo`.

### EXTENDED fields

- **Definition:** Admin-definable fields stored as key-value data (separate from CORE).
- **Characteristics:**
  - Dynamic set; defined in the field registry at runtime.
  - Not used by search algorithms or completeness formula.
  - Can be created, reordered, enabled/disabled by admin without code change.
- **Examples:** Contextual or optional attributes (e.g. preferences, family notes) as defined in the registry.

---

## 2. How CORE fields are exposed in APIs

- **Access:** CORE fields are exposed as **fixed keys** in profile API responses.
- **Behavior:**
  - Response structure includes a stable set of keys (e.g. `id`, `full_name`, `date_of_birth`, `caste`, `education`, `location`, `profile_photo`, `created_at`, `updated_at`, and related fields such as `gender` where applicable).
  - Clients may rely on these keys being present in profile payloads.
  - Values may be `null` or empty if not filled; clients must handle null/empty.
- **Summary:** CORE fields are **fixed**, **direct**, and **schema-bound** in the API.

---

## 3. How EXTENDED fields are dynamic and optional

- **Access:** EXTENDED field values are **not** part of the current profile API response contract. If the API later adds an optional EXTENDED block, it will be dynamic and keyed by field name.
- **Behavior:**
  - The set of EXTENDED fields is defined in the registry and can change (e.g. per environment or over time).
  - Any future EXTENDED payload must be treated as **optional** and **dynamic**.
  - Clients must **not** assume any specific EXTENDED field key exists.
- **Summary:** EXTENDED fields are **dynamic** and **optional**; they may be empty or absent in any response.

---

## 4. Graceful handling when EXTENDED fields are missing

- **Apps must NOT assume EXTENDED fields exist.** If the API provides an EXTENDED block (e.g. an `extended` object or array), it may be missing entirely for some or all responses.
- **Apps must NOT assume any specific EXTENDED field key exists.** The set of keys can differ by environment or change over time.
- **Apps MUST function correctly when EXTENDED data is missing:** e.g. no `extended` key, empty object, or empty array must not cause errors or broken flows.
- **Apps MUST NOT require EXTENDED fields for core flows** (profile view, list, search, interests). CORE fields alone are sufficient for those flows.
- **Summary:** All clients must implement **graceful handling** for missing or empty EXTENDED data; core functionality must work with CORE-only data.

---

## 5. Backward compatibility guarantees

- **Current behavior:** Profile API responses contain **only CORE fields**. No EXTENDED fields are returned in the current contract. This document does not change that.
- **If EXTENDED is added to the API later:**
  - It must be **additive only** (e.g. an optional `extended` or equivalent member in the profile payload).
  - Existing clients that ignore EXTENDED must continue to work without change.
  - New clients that read EXTENDED must treat them as optional and handle missing/empty gracefully.
- **No breaking changes:** Existing API contracts (e.g. v1) are unchanged. No new required fields, no removal of existing CORE keys, no change to the current response structure for existing endpoints.

---

## 6. Explicit statements (Day-12 lock)

- **No breaking API changes.** This deliverable is documentation only; it does not introduce any API change.
- **No new API endpoints.** Day-12 does not add endpoints.
- **No modification of existing API contracts.** Current request/response shapes are unchanged.
- **No Flutter parity change.** Flutter/Web app parity is not altered by this documentation.
- **API structure supports EXTENDED fields without breaking changes.** This is documented as a boundary: any future EXTENDED exposure must remain optional and backward compatible.

---

## 7. Summary table

| Aspect              | CORE fields                         | EXTENDED fields                          |
|---------------------|-------------------------------------|------------------------------------------|
| In API (current)    | Fixed keys; always present          | Not exposed in profile API               |
| Client assumption   | Keys exist; values may be null      | Must NOT assume present or any key set   |
| If added later      | N/A                                 | Optional; app must work when absent      |
| Graceful handling   | Handle null/empty values            | Handle missing/empty EXTENDED block       |

---

*This document satisfies the Phase-3 Day-12 documentation deliverable per PHASE-3_SSOT.md. No code, API, or parity changes are introduced.*
