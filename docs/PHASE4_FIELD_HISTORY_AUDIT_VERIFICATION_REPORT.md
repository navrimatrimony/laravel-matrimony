# Phase-4 Field History & Audit System (Day-6) — Verification Report

Inspection only. No suggestions. No refactoring.

---

## 1) Code paths that update MatrimonyProfile WITHOUT creating FieldValueHistory

### 1.1 MutationService (by design — Phase-5 contract)

**File:** `app/Services/MutationService.php`  
**Method:** `applyApprovedIntake()` (and private helpers that call `$profile->save()` / entity updates)

**Snippet:** Mutation path writes only to `profile_change_history`; no `FieldValueHistoryService::record()`.

```php
// Docblock in MutationService:
// Writes ONLY to profile_change_history (no field_value_history).

// CORE apply (Step 5) — no FieldValueHistoryService call:
$this->setProfileAttribute($profile, $fieldKey, $newVal);
$this->writeProfileChangeHistory($profile->id, 'matrimony_profile', $profile->id, $fieldKey, $oldVal, $newVal);
// ...
$profile->save();

// setLifecycleState() — only profile_change_history:
$this->writeProfileChangeHistory($profile->id, 'matrimony_profile', $profile->id, 'lifecycle_state', $current, $targetState);
$profile->lifecycle_state = $targetState;
$profile->save();
```

**Conclusion:** MatrimonyProfile is updated (CORE fields, lifecycle_state) without any FieldValueHistory record when apply runs through MutationService.

---

### 1.2 InterestController — contact_visible_to update

**File:** `app/Http/Controllers/InterestController.php`  
**Method:** `accept()` (approximate line 224)

**Snippet:**

```php
if ($senderProfile && $receiverProfile->contact_unlock_mode === 'after_interest_accepted') {
    $whitelist = $receiverProfile->contact_visible_to ?? [];
    // ...
    if (!in_array($senderProfile->id, $whitelist, true)) {
        $whitelist[] = $senderProfile->id;
        $receiverProfile->update(['contact_visible_to' => $whitelist]);
    }
}
```

**Conclusion:** `MatrimonyProfile` is updated (`contact_visible_to`) with no `FieldValueHistoryService::record()` call.

---

### 1.3 AdminController — metadata-only update (extended-only edit)

**File:** `app/Http/Controllers/AdminController.php`  
**Method:** `updateProfile()` (around lines 894–900)

**Snippet:** When only extended keys changed (no CORE in `$editedFields`), profile is updated with edit metadata only. No FieldValueHistory is recorded for these columns.

```php
} elseif (!empty($changedExtendedKeys)) {
    $profile->update([
        'edited_by' => $admin->id,
        'edited_at' => now(),
        'edit_reason' => $request->input('edit_reason'),
        'edited_source' => 'admin',
    ]);
}
```

**Conclusion:** `MatrimonyProfile` is updated (`edited_by`, `edited_at`, `edit_reason`, `edited_source`) without FieldValueHistory. (CORE field history is recorded in the `$editedFields` branch above; this branch is metadata only.)

---

### 1.4 Console command (test/proof)

**File:** `app/Console/Commands/Day11CompletenessProof.php`  
**Methods:** Proof logic that calls `$profile->save()` (lines 58, 69). History **is** recorded (lines 56, 67) for the caste changes in that command. So this path does record history for the CORE change; the `save()` itself does not bypass history because the command explicitly calls `FieldValueHistoryService::record()` before saving.

**Conclusion:** No additional “without history” path; listed for completeness.

---

**Summary for §1:**  
- **MutationService:** Updates profile (CORE + lifecycle) without FieldValueHistory (by design).  
- **InterestController::accept():** Updates `contact_visible_to` without FieldValueHistory.  
- **AdminController::updateProfile():** Updates edit metadata (edited_by, edited_at, etc.) without FieldValueHistory when only extended keys change.

---

## 2) Where FieldValueHistory is created

### 2.1 USER profile edit

**File:** `app/Http/Controllers/MatrimonyProfileController.php`

- **store() (create/update):** For each changed CORE field, before `$existingProfile->update($profileData)`:

```php
if ((string) $oldVal !== (string) $newVal) {
    FieldValueHistoryService::record($existingProfile->id, $fieldKey, 'CORE', $oldVal, $newVal, FieldValueHistoryService::CHANGED_BY_USER);
}
$existingProfile->update($profileData);
```

- **update() (edit form):** Same pattern; history for each changed CORE field, then `$user->matrimonyProfile->update($updateData)` (lines 428–443).
- **storePhoto():** History for profile_photo, photo_approved, photo_rejected_at, photo_rejection_reason (lines 534–545), then `$user->matrimonyProfile->update([...])` (547–553).

---

### 2.2 ADMIN profile edit

**File:** `app/Http/Controllers/AdminController.php`

- **updateProfile():** For each CORE field in `$editedFields`, before `$profile->update($updateData)` (846–866):

```php
foreach ($editedFields as $fieldKey) {
    $oldVal = ($originalData[$fieldKey] ?? '') === '' ? null : (string) ($originalData[$fieldKey] ?? null);
    $newVal = isset($updateData[$fieldKey]) ? ($updateData[$fieldKey] === '' ? null : (string) $updateData[$fieldKey]) : null;
    \App\Services\FieldValueHistoryService::record(
        $profile->id, $fieldKey, 'CORE', $oldVal, $newVal,
        \App\Services\FieldValueHistoryService::CHANGED_BY_ADMIN
    );
}
$profile->update($updateData);
```

- **suspendProfile():** Record for `is_suspended` (159), then `$profile->update(['is_suspended' => true])` (161).
- **unsuspendProfile():** Record for `is_suspended` (188), then `$profile->update(['is_suspended' => false])` (190).
- **approveImage():** Records for photo_approved, photo_rejected_at, photo_rejection_reason (246, 249, 252), then `$profile->update([...])` (254–257).
- **rejectImage():** Records (280, 282, 283), then `$profile->update([...])` (284–287).
- **overrideVisibility():** Records for visibility_override, visibility_override_reason (316, 319), then `$profile->update([...])` (321–324).

---

### 2.3 Conflict resolution apply

**File:** `app/Services/ConflictResolutionService.php`  
**Method:** `applyResolution()` (CORE branch, lines 98–120)

**Snippet:**

```php
$oldValue = static::getCurrentFieldValue($profile, $fieldKey, $fieldType);
$changedBy = ($resolver->is_admin ?? false)
    ? FieldValueHistoryService::CHANGED_BY_ADMIN
    : FieldValueHistoryService::CHANGED_BY_USER;

FieldValueHistoryService::record(
    $profile->id, $fieldKey, 'CORE', $oldValue, $newValue, $changedBy
);
// ...
$profile->update($updateData);
```

**Conclusion:** FieldValueHistory is created for the resolved CORE field before `$profile->update()`.

---

### 2.4 Lifecycle change

**File:** `app/Services/ProfileLifecycleService.php`  
**Method:** `transitionTo()` (lines 43–46)

**Snippet:**

```php
$changedBy = ($actor->is_admin ?? false) ? FieldValueHistoryService::CHANGED_BY_ADMIN : FieldValueHistoryService::CHANGED_BY_USER;
FieldValueHistoryService::record($profile->id, 'lifecycle_state', 'CORE', $current, $targetState, $changedBy);
$profile->lifecycle_state = $targetState;
$profile->save();
```

**Conclusion:** FieldValueHistory is created for `lifecycle_state` before updating the profile.

---

### 2.5 Field unlock + overwrite (admin CORE edit)

Same as **§2.2 ADMIN profile edit.** Admin update (including after unlock) records history per edited CORE field in the `$editedFields` loop, then applies lock. No separate “unlock + overwrite” path; it goes through `updateProfile()` and the same history loop.

---

### 2.6 Extended fields (USER/ADMIN)

**File:** `app/Services/ExtendedFieldService.php`  
**Method:** `saveValuesForProfile()` (lines 94–109)

**Snippet:** On update (value changed), record EXTENDED history then save the extended row:

```php
if ($row->exists && (string) $oldValue !== (string) $newValue) {
    $changedBy = ($actor && ($actor->is_admin ?? false))
        ? FieldValueHistoryService::CHANGED_BY_ADMIN
        : FieldValueHistoryService::CHANGED_BY_USER;
    FieldValueHistoryService::record(
        $profile->id, $field_key, 'EXTENDED', $oldValue, $newValue, $changedBy
    );
}
$row->field_value = $normalized;
$row->save();
```

**Conclusion:** FieldValueHistory is created for changed EXTENDED fields before saving.

---

### 2.7 API profile update and photo

**File:** `app/Http/Controllers/Api/MatrimonyProfileApiController.php`

- **update():** For each `$changedFields` CORE field (198), then `$profile->update($updateData)` (202).
- **uploadPhoto():** Records for profile_photo, photo_approved, photo_rejected_at, photo_rejection_reason (262–272), then `$profile->update([...])` (275–280).

---

### 2.8 Demo profile

**File:** `app/Http/Controllers/Admin/DemoProfileController.php`  
History recorded (e.g. 77, 145) with `FieldValueHistoryService::CHANGED_BY_SYSTEM` before profile/field updates.

---

## 3) Raw DB::table('field_value_history')->delete() or update()

**Search:** `DB::table('field_value_history')`, `->delete()`, `->update()` on that table.

**Result:** No matches. No raw delete or update on `field_value_history` in the project.

---

## 4) MatrimonyProfile model — mass assignment and bypass

- **Mass assignment:** Model uses `$fillable` (array of allowed attributes). No `$guarded = []` that would allow all. Standard mass assignment only.
- **fill()->save() without history:** No occurrence of `fill(...)->save()` or similar in `app/Models/MatrimonyProfile.php`. No `updateQuietly()` in the model.
- **updateQuietly():** Not used in `MatrimonyProfile.php`.

**Conclusion:** MatrimonyProfile does not use mass assignment or fill()->save() or updateQuietly() to bypass history inside the model. History is (or is not) recorded at the call site (controllers/services).

---

## 5) cascadeOnDelete and profile deletion

### 5.1 Create migration

**File:** `database/migrations/2026_02_03_000000_create_field_value_history_table.php`

**Snippet:**

```php
Schema::create('field_value_history', function (Blueprint $table) {
    $table->id();
    $table->foreignId('profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
    // ...
});
```

So initially the FK was `cascadeOnDelete()`.

### 5.2 Update migration (current state)

**File:** `database/migrations/2026_02_07_000000_update_field_value_history_profile_fk_to_set_null.php`

**Snippet:**

```php
$table->dropForeign(['profile_id']);
// ... make profile_id nullable ...
Schema::table('field_value_history', function (Blueprint $table) {
    $table->foreign('profile_id')->references('id')->on('matrimony_profiles')->nullOnDelete();
});
```

**Conclusion:** After migrations, the FK is **nullOnDelete**. When a `matrimony_profiles` row is deleted, the database sets `profile_id` to NULL on related `field_value_history` rows; it does **not** delete those rows. So profile deletion does **not** remove history rows; they are preserved with `profile_id = null`.

---

## 6) UI routes for history

- **`/profile/history`:** Does **not** exist. No route defined in `routes/web.php` or `routes/api.php` for `/profile/history`.
- **`/admin/profiles/{id}/history`:** Does **not** exist. No dedicated route for profile history.

**Where history is shown:**  
- **Admin:** `GET /profiles/{id}` → `AdminController::showProfile()` (route name `profiles.show`). This method loads `FieldValueHistoryService::getHistoryForProfile($profile)` and passes `fieldHistory` to the view `admin.profiles.show`. So history is read-only data on the profile show page, not a separate URL.

**Controller method:**  
- `AdminController::showProfile()` — loads history, passes to view. No mutation; read-only.

**User-facing profile history route:**  
- There is no dedicated `/profile/history` (or similar) for the logged-in user’s own profile history in the routes inspected.

---

## 7) Historical records ever overwritten

**FieldValueHistory model** (`app/Models/FieldValueHistory.php`):

```php
public function save(array $options = []): bool
{
    if ($this->exists) {
        throw new \RuntimeException(
            'FieldValueHistory records are append-only.'
        );
    }
    return parent::save($options);
}
```

**Conclusion:** Existing rows cannot be updated or overwritten via the model: `save()` throws when `$this->exists` is true. Only new records can be saved. Historical records are never overwritten by this model.

---

**End of report.**
