# matrimony_profiles.contact_number & profile_contacts — Full Usage Map

Factual usage only. No architecture suggestions.

---

## 1) Where matrimony_profiles.contact_number is used

| File | Line(s) | Usage |
|------|---------|--------|
| **app/Models/MatrimonyProfile.php** | 122 | In `$fillable`: `'contact_number'` (mass assignment). |
| **app/Http/Controllers/MatrimonyProfileController.php** | 284 | Validation rule: `'contact_number' => 'nullable|string|max:20'`. |
| **app/Http/Controllers/MatrimonyProfileController.php** | 370–371 | Read from request; add to `$updateData['contact_number']` (write path). |
| **app/Services/ConflictDetectionService.php** | 194 | Read: `return $primary ?? $profile->getAttribute('contact_number');` (fallback when primary not in profile_contacts). |
| **app/Services/DuplicateDetectionService.php** | 127 | Query: `DB::table('matrimony_profiles')->where('contact_number', $phone)->value('id');` (fallback after profile_contacts). |
| **app/Services/DuplicateDetectionService.php** | 153 | Query: `DB::table('matrimony_profiles')->where('contact_number', $phone)->pluck('id');` in `profileIdsWithPrimaryPhone()`. |
| **app/Services/MutationService.php** | 465 | Read: `return $primary ?? $profile->getAttribute('contact_number');` in `getCurrentCoreValue()` for `primary_contact_number`. |
| **resources/views/matrimony/profile/edit.blade.php** | 66–67 | Form: `value="{{ old('contact_number', $matrimonyProfile->contact_number ?? '') }}"` and error for `contact_number`. |
| **resources/views/matrimony/profile/show.blade.php** | 404–405, 410 | Display: `@if ($matrimonyProfile->contact_number)` and `{{ $matrimonyProfile->contact_number }}`. |
| **database/migrations/2026_02_10_083633_add_women_safety_columns_to_matrimony_profiles_table.php** | 20, 51 | Schema: add column `contact_number` (string 20, nullable); down() drops it. |

Compiled Blade (derived from views above): **storage/framework/views/33e51beba817db07c1dde0b696ccd036.php** lines 409–410, 416 (reads `$matrimonyProfile->contact_number`).

---

## 2) contact_number by area

### Controllers

| File | Line(s) | Usage |
|------|---------|--------|
| **app/Http/Controllers/MatrimonyProfileController.php** | 284 | Validation: `contact_number` rule. |
| **app/Http/Controllers/MatrimonyProfileController.php** | 370–371 | If `$request->has('contact_number')`: `$updateData['contact_number'] = $request->contact_number ?: null;` (later passed to `$user->matrimonyProfile->update($updateData)`). |

No other controllers reference `contact_number`. API controllers: no matches for `contact_number` in **app/Http/Controllers/Api/**.

### Blade views

| File | Line(s) | Usage |
|------|---------|--------|
| **resources/views/matrimony/profile/edit.blade.php** | 66–67 | Input name `contact_number`, value from `$matrimonyProfile->contact_number`. |
| **resources/views/matrimony/profile/show.blade.php** | 404–405, 410 | Conditional display and echo of `$matrimonyProfile->contact_number`. |

No admin Blade views reference `contact_number` (grep in **resources/views/admin/**: no matches).

### API controllers

No references to `contact_number` or `profile_contacts` in **app/Http/Controllers/Api/** (MatrimonyProfileApiController, etc.).

### Duplicate detection logic

| File | Line(s) | Usage |
|------|---------|--------|
| **app/Services/DuplicateDetectionService.php** | 127 | `DB::table('matrimony_profiles')->where('contact_number', $phone)->value('id');` in `findProfileIdByPrimaryPhone()` (fallback when profile_contacts has no primary match). |
| **app/Services/DuplicateDetectionService.php** | 153 | `DB::table('matrimony_profiles')->where('contact_number', $phone)->pluck('id');` in `profileIdsWithPrimaryPhone()` (merge with profile_contacts result). |

Duplicate detection uses both **profile_contacts** (phone_number, is_primary) and **matrimony_profiles.contact_number** (fallback).

### Unlock logic

No references to `contact_number` in unlock-related services (no matches in **app/Services/*Unlock***).  
**InterestController** (lines 218, 224) uses **contact_visible_to** (whitelist) and `$receiverProfile->update(['contact_visible_to' => $whitelist])` — not `contact_number`.

---

## 3) Exact file paths and line numbers (contact_number)

| Path | Line(s) |
|------|---------|
| app/Models/MatrimonyProfile.php | 122 |
| app/Http/Controllers/MatrimonyProfileController.php | 284, 370, 371 |
| app/Services/ConflictDetectionService.php | 29 (constant `primary_contact_number`), 189, 194 |
| app/Services/DuplicateDetectionService.php | 50 (comment), 94, 95 (snapshot key `primary_contact_number`), 127, 153 |
| app/Services/MutationService.php | 370 (ConflictRecord field_name), 460, 465 |
| resources/views/matrimony/profile/edit.blade.php | 66, 67 |
| resources/views/matrimony/profile/show.blade.php | 404, 405, 410 |
| database/migrations/2026_02_10_083633_add_women_safety_columns_to_matrimony_profiles_table.php | 20, 51 |

---

## 4) Is profile_contacts used for reading contact data?

**Yes.** Exact usages:

| File | Line(s) | Usage |
|------|---------|--------|
| **app/Services/DuplicateDetectionService.php** | 119–121 | `DB::table('profile_contacts')->where('phone_number', $phone)->where('is_primary', true)->value('profile_id');` in `findProfileIdByPrimaryPhone()`. |
| **app/Services/DuplicateDetectionService.php** | 148–151 | `DB::table('profile_contacts')->where('phone_number', $phone)->where('is_primary', true)->pluck('profile_id');` in `profileIdsWithPrimaryPhone()`. |
| **app/Services/ConflictDetectionService.php** | 190–182 | `DB::table('profile_contacts')->where('profile_id', $profile->id)->where('is_primary', true)->value('phone_number');` for current primary (then fallback to `$profile->getAttribute('contact_number')`). |
| **app/Services/MutationService.php** | 27 | Snapshot key mapping: `'contacts' => 'profile_contacts'`. |
| **app/Services/MutationService.php** | 350 | `DB::table('profile_contacts')->where('profile_id', $profile->id)->get();` in `syncContactsFromSnapshot()`. |
| **app/Services/MutationService.php** | 380 | `$this->syncEntityDiff($profile, 'profile_contacts', $proposed);` (sync/write). |
| **app/Services/MutationService.php** | 461–463 | `DB::table('profile_contacts')->where('profile_id', $profile->id)->where('is_primary', true)->value('phone_number');` in `getCurrentCoreValue()` for `primary_contact_number` (then fallback to `contact_number`). |

So **profile_contacts** is used for: duplicate detection (primary phone lookup), conflict detection (current primary value), mutation contact sync (read + write), and CORE primary_contact_number current value (with fallback to matrimony_profiles.contact_number).

---

## 5) Writes to matrimony_profiles.contact_number

**Yes.** Single application write path:

| File | Line(s) | Write |
|------|---------|--------|
| **app/Http/Controllers/MatrimonyProfileController.php** | 370–371, 443 | `if ($request->has('contact_number')) { $updateData['contact_number'] = $request->contact_number ?: null; }` then `$user->matrimonyProfile->update($updateData);` (line 443). |

So the **user profile edit** flow (MatrimonyProfileController::update) writes `contact_number` to `matrimony_profiles` from the edit form.

**MutationService** does **not** write to `matrimony_profiles.contact_number`. It syncs **contacts** to the **profile_contacts** table (snapshot key `contacts` → `profile_contacts`); it does not set the legacy `contact_number` column on `matrimony_profiles`.

---

## Summary

- **matrimony_profiles.contact_number:** Read in duplicate detection (fallback), conflict detection (fallback), MutationService getCurrentCoreValue (fallback), edit form and show views; **written** only in MatrimonyProfileController profile update.
- **profile_contacts:** Read (and written) in duplicate detection, conflict detection, and MutationService (contact sync + primary phone for CORE); not referenced in API controllers or unlock logic for `contact_number`.
- **Writes to contact_number:** Only MatrimonyProfileController (user edit) → `matrimony_profiles.contact_number`.
