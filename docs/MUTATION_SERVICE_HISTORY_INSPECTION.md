# MutationService — Phase-4 / Day-6 history inspection

Inspection only. No suggestions. No fixes.

---

## 1) All places where profile is updated, saved, lifecycle changed, or CORE mutated

### Location 1 — Duplicate path: lifecycle_state change on existing profile

**Where:** Inside `applyApprovedIntake()`, duplicate block (lines 134–135).

**Exact code:**

```php
$this->setLifecycleState($existingProfile, 'conflict_pending');
```

**What happens:** `setLifecycleState()` (lines 322–338) sets `lifecycle_state` and calls `$profile->save()`.

- **`$profile->update()`:** Not used (only `$profile->save()` inside `setLifecycleState`).
- **`$profile->save()`:** Yes (inside `setLifecycleState`, line 338).
- **lifecycle_state changed:** Yes (to `conflict_pending`).
- **CORE field mutated:** No (only lifecycle).
- **FieldValueHistoryService::record() called?** No.
- **profile_change_history written?** Yes — inside `setLifecycleState()` via `$this->writeProfileChangeHistory(..., 'lifecycle_state', $current, $targetState)` (lines 328–335).

---

### Location 2 — Step 2: New profile create (draft)

**Where:** Inside `applyApprovedIntake()`, Step 2 “Profile existence”, else branch (lines 156–162).

**Exact code:**

```php
$profile = new MatrimonyProfile();
$profile->user_id = $intake->uploaded_by;
$profile->full_name = $proposedCore['full_name'] ?? 'Draft';
$profile->lifecycle_state = 'draft';
$profile->save();
$intake->matrimony_profile_id = $profile->id;
$intake->save();
```

- **`$profile->update()`:** Not used.
- **`$profile->save()`:** Yes (line 161).
- **lifecycle_state changed:** Yes (set to `draft` on create).
- **CORE field mutated:** Yes (`full_name` set; `lifecycle_state` set).
- **FieldValueHistoryService::record() called?** No.
- **profile_change_history written?** No. No `writeProfileChangeHistory()` call for this create.

---

### Location 3 — Step 5: CORE field apply

**Where:** Inside `applyApprovedIntake()`, Step 5 loop (lines 216–241).

**Exact code (per changed CORE field):**

```php
$this->setProfileAttribute($profile, $fieldKey, $newVal);
$this->writeProfileChangeHistory(
    $profile->id,
    'matrimony_profile',
    $profile->id,
    $fieldKey,
    $oldVal,
    $newVal
);
```
**Then after the loop (line 240):**

```php
$profile->save();
```

- **`$profile->update()`:** Not used.
- **`$profile->save()`:** Yes (line 240, once after all CORE changes).
- **lifecycle_state changed:** No (in this block).
- **CORE field mutated:** Yes (each CORE key in snapshot that is not in conflict set and not locked, via `setProfileAttribute`).
- **FieldValueHistoryService::record() called?** No.
- **profile_change_history written?** Yes — one row per changed CORE field via `writeProfileChangeHistory()` (lines 234–241).

---

### Location 4 — Step 9: Lifecycle transition (conflict_pending or active)

**Where:** Inside `applyApprovedIntake()`, Step 9 (lines 273–281).

**Exact code:**

```php
if ($hasConflicts) {
    $this->setLifecycleState($profile, 'conflict_pending');
} else {
    $current = $profile->lifecycle_state ?? 'active';
    if (!in_array($current, self::NO_AUTO_ACTIVATE_STATES, true)) {
        $this->setLifecycleState($profile, 'active');
    }
}
```

**What happens:** `setLifecycleState()` (lines 322–338) writes profile_change_history for `lifecycle_state`, sets `$profile->lifecycle_state`, then `$profile->save()`.

- **`$profile->update()`:** Not used.
- **`$profile->save()`:** Yes (inside `setLifecycleState`, line 338).
- **lifecycle_state changed:** Yes (`conflict_pending` or `active`).
- **CORE field mutated:** No (only lifecycle).
- **FieldValueHistoryService::record() called?** No.
- **profile_change_history written?** Yes — inside `setLifecycleState()` (lines 328–335).

---

### Location 5 — setLifecycleState() (private helper)

**Where:** Lines 322–339.

**Exact code:**

```php
private function setLifecycleState(MatrimonyProfile $profile, string $targetState): void
{
    $current = $profile->lifecycle_state ?? 'active';
    if ($current === $targetState) {
        return;
    }
    $this->writeProfileChangeHistory(
        $profile->id,
        'matrimony_profile',
        $profile->id,
        'lifecycle_state',
        $current,
        $targetState
    );
    $profile->lifecycle_state = $targetState;
    $profile->save();
}
```

- **`$profile->update()`:** Not used.
- **`$profile->save()`:** Yes (line 338).
- **lifecycle_state changed:** Yes.
- **CORE field mutated:** No (only lifecycle_state).
- **FieldValueHistoryService::record() called?** No.
- **profile_change_history written?** Yes (lines 328–335).

---

### Location 6 — setProfileAttribute() (CORE field mutation only; no save here)

**Where:** Lines 357–364. Called from Step 5 loop.

**Exact code:**

```php
private function setProfileAttribute(MatrimonyProfile $profile, string $fieldKey, $value): void
{
    if ($fieldKey === 'location') {
        return;
    }
    $profile->setAttribute($fieldKey, $value);
}
```

- **`$profile->update()`:** Not used.
- **`$profile->save()`:** Not called here (save is at line 240 after the loop).
- **lifecycle_state changed:** No.
- **CORE field mutated:** Yes (attribute set on model).
- **FieldValueHistoryService::record() called?** No.
- **profile_change_history written?** Yes — caller (Step 5 loop) calls `writeProfileChangeHistory()` for each changed field before/around this.

---

### Summary table

| Location | $profile->update() | $profile->save() | lifecycle changed | CORE mutated | FieldValueHistoryService::record() | profile_change_history |
|----------|--------------------|-------------------|-------------------|--------------|------------------------------------|-------------------------|
| 1 Duplicate path setLifecycleState | No | Yes (in helper) | Yes | No | No | Yes |
| 2 Step 2 new profile | No | Yes | Yes (draft) | Yes (full_name) | No | No |
| 3 Step 5 CORE apply | No | Yes | No | Yes | No | Yes |
| 4 Step 9 lifecycle | No | Yes (in helper) | Yes | No | No | Yes |
| 5 setLifecycleState() | No | Yes | Yes | No | No | Yes |
| 6 setProfileAttribute() | No | No | No | Yes | No | Yes (by caller) |

**Note:** MutationService does not use `$profile->update()` anywhere. All profile persistence is via `$profile->save()`.

---

## 2) Intentionally bypassing FieldValueHistory?

**Yes.** The class docblock states it explicitly:

**File:** `app/Services/MutationService.php`  
**Lines 14–18:**

```php
/**
 * Phase-5: Safe Mutation Pipeline.
 * Compliant with PHASE-5_MUTATION_EXECUTION_CONTRACT.md.
 * Writes ONLY to profile_change_history (no field_value_history).
 * Duplicate detection before profile creation; FieldRegistry-driven CORE; snapshot key routing.
 */
```

**Private method comment** at `writeProfileChangeHistory()` (lines 357–359):

```php
/**
 * Mutation path: write ONLY to profile_change_history (no field_value_history).
 */
private function writeProfileChangeHistory(
```

**Private method comment** at `setLifecycleState()` (lines 319–321):

```php
/**
 * Set lifecycle_state and write ONLY to profile_change_history.
 */
private function setLifecycleState(MatrimonyProfile $profile, string $targetState): void
```

So MutationService is intentionally not calling FieldValueHistoryService and not writing to field_value_history; it documents writing only to profile_change_history.

---

## 3) Phase-5 unified history: replace or both active?

**Contract (PHASE-5_MUTATION_EXECUTION_CONTRACT.md):**

- **§4 "UNIFIED HISTORY WRITE POLICY":**  
  - "**Single history store for mutation:** `profile_change_history` only. No write to `field_value_history` for mutation-originated changes."  
  - "**Rules:** … No mutation path write to field_value_history."  
  - "User/admin-initiated edits (profile edit screen, admin override) **continue to use FieldValueHistoryService / field_value_history** per existing governance; they are **out of scope** of this contract."

So in the contract:

- **Mutation path (MutationService):** Only `profile_change_history`. `field_value_history` is not used for mutation-originated changes.
- **User/admin edits:** Still use FieldValueHistoryService / `field_value_history`; they are explicitly out of scope of the mutation contract.

**Conclusion:** Phase-5 “unified” history means a single store **for the mutation path** (profile_change_history only). It does **not** replace field_value_history globally. Both are active: mutation path → profile_change_history only; user/admin profile edits → field_value_history (and optionally audit) as before.

---

**End of inspection.**
