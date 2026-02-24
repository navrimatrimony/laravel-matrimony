# MutationService applyApprovedIntake — Execution Stop Analysis

## 1) Early exit paths and stopping conditions

### Pre-transaction (before `DB::transaction`)

| Location | Condition | Effect |
|----------|-----------|--------|
| L67–68 | `empty($intake->approval_snapshot_json)` | **Throw** → caller may catch; no transaction. |
| L69–70 | `$intake->approved_by_user !== true` | **Throw** → no transaction. |
| L71–72 | `$intake->intake_status === 'applied'` | **Throw** → no transaction. (Your intake is `"approved"`, so this does **not** fire.) |
| L74–75 | `$intake->intake_locked === true` | **Throw** → no transaction. |
| **L80–82** | **`$version === null \|\| !in_array($version, [1], true)`** | **Throw** → no transaction. **Strict `in_array(..., true)` fails when `$version` is string `"1"` and supported list is `[1]` (int).** |

### Inside transaction

| Location | Condition | Effect |
|----------|-----------|--------|
| L109–131 | `$duplicateResult->isDuplicate === true` | **Return** from closure. Sets `$intake->matrimony_profile_id = $existingProfileId`, saves intake, exits. If this ran with `existingProfileId !== null`, refresh would show that profile id. **If you still see `matrimony_profile_id = null` after refresh, this path either did not run or ran with `existingProfileId === null` (then intake is saved with null).** |

No other `return` inside the transaction. All other exits are **throws** (rollback).

### Exceptions inside transaction (cause full rollback)

Any uncaught exception inside the closure rolls back the transaction: no profile, no intake update. Possible sources:

- **Step 2:** `User::find($intake->uploaded_by)` → null → **throw** "Intake uploaded_by user not found."
- **Step 2:** `MatrimonyProfile::where(...)->lockForUpdate()->first()` → null (invalid `matrimony_profile_id`) → **throw** "Intake references non-existent profile."
- **Step 3:** `ConflictDetectionService::detectResult()` → can use `ExtendedFieldService::getValuesForProfile($profile)`, which queries `profile_extended_fields`. **Missing table or bad query → exception → rollback.**
- **Step 3/4:** `FieldRegistry::where(...)`, `ProfileFieldLockService::isLocked()`, `ConflictRecord::create()` → missing tables (`field_registry`, `profile_field_locks`, `conflict_records`) → exception → rollback.
- **Step 6:** `syncContactsFromSnapshot()` → `profile_contacts` missing or insert failure → exception → rollback.

There is **no** code that swallows exceptions inside `applyApprovedIntake`. If something throws, the transaction rolls back and the exception propagates unless a **caller** catches it (e.g. controller returning null).

---

## 2) Verification

### Duplicate detection returning duplicate?

- If duplicate path ran with **non-null** `existingProfileId`: intake would be saved with that id → you would see it on refresh. So either duplicate is **not** being returned, or it is returned with **null** `existingProfileId` (current code still saves intake with null and returns).
- `DuplicateDetectionService` only returns `isDuplicate === true` when it has an `existingProfileId`; it never returns duplicate with null id. So **incorrect duplicate with null is unlikely** unless there is another caller or a different code path.

### ConflictDetectionService creating conflict before draft?

- Conflict detection runs **after** profile existence (Step 3). For a **new** profile we create it in Step 2, then call `ConflictDetectionService::detectResult($profile, ...)`. With the draft rule, identity-critical diffs on draft do **not** create conflict records. So ConflictDetectionService is **not** “creating conflict before draft” in a way that would prevent profile creation; the profile already exists at that point. If an **exception** is thrown inside `detectResult` (e.g. missing table), that would rollback the whole transaction and **prevent** intake finalization and profile visibility.

### Transaction rolled back silently?

- Laravel does **not** swallow exceptions in `DB::transaction()`. On exception, transaction is rolled back and the exception is rethrown. So “silent” rollback only in the sense that the **caller** might catch the exception and not rethrow (e.g. return null). Then the method appears to “return null” and nothing is persisted.

### Guard when `parsed_json` is null?

- **None.** `applyApprovedIntake` uses only `approval_snapshot_json`. It never reads or checks `parsed_json`. So **parsed_json = null does not block execution.**

---

## 3) Debug instrumentation added

Temporary `Log::info()` calls were added at:

1. **Start of method** — `MutationService::applyApprovedIntake START` with `intakeId`.
2. **After snapshot version check** — `after snapshot version check` with `version`.
3. **After duplicate detection** — `after duplicate detection` with `isDuplicate`, `duplicateType`, `existingProfileId`.
4. **Before profile existence** — `before profile existence step`.
5. **Before lifecycle transition** — `before lifecycle transition` with `profileId`.
6. **Before intake finalization** — `before intake finalization` with `hasConflicts`.

**How to use:** Run `applyApprovedIntake($intakeId)` once and check `storage/logs/laravel.log`. The **last** of these messages that appears is right before where execution stops (either the next line throws or the duplicate `return` was taken).

- If you see **START** but **not** “after snapshot version check” → exit at **L80–82** (version check throw).
- If you see “after snapshot version check” but **not** “after duplicate detection” → exception inside **duplicate detection** (e.g. DB/table issue).
- If you see “after duplicate detection” with `isDuplicate: true` but **not** “before profile existence step” → duplicate path **return** at L131 (intake saved with `existingProfileId`; if that was null, intake ends up with null).
- If you see “before profile existence step” but **not** “before lifecycle transition” → exception between Step 2 and Step 9 (profile creation, conflict detection, CORE apply, contact sync, or entity sync).
- If you see “before lifecycle transition” but **not** “before intake finalization” → exception in Step 9 (lifecycle transition).
- If you see “before intake finalization” → exception in Step 10 (intake save) or after.

---

## 4) Exact code location and suggested minimal fix

### Most likely: exit before transaction (version check)

**Exact location:** **Lines 80–82** in `app/Services/MutationService.php`:

```php
if ($version === null || !in_array($version, self::SUPPORTED_SNAPSHOT_VERSIONS, true)) {
    throw new \RuntimeException('Unsupported or missing snapshot_schema_version. Supported: [1].');
}
```

**Why this can stop execution:**

- `$version` is taken from `$intake->snapshot_schema_version ?? $snapshot['snapshot_schema_version'] ?? null`.
- If the value is stored or decoded as **string** `"1"` (e.g. from JSON or a string column), then `in_array("1", [1], true)` is **false** because of strict comparison (`"1" !== 1`).
- The method **throws** before `DB::transaction()` is ever called, so no profile is created and no intake update is committed. If the caller catches the exception and returns null, you observe “returns null” and no DB changes.

**Suggested minimal fix (version check only):**

Normalize `$version` to an integer before the check so both integer `1` and string `"1"` are accepted:

```php
$version = $intake->snapshot_schema_version ?? $snapshot['snapshot_schema_version'] ?? null;
$version = $version !== null ? (int) $version : null;
if ($version === null || !in_array($version, self::SUPPORTED_SNAPSHOT_VERSIONS, true)) {
    throw new \RuntimeException('Unsupported or missing snapshot_schema_version. Supported: [1].');
}
```

No other refactor required for this fix.

---

### If logs show execution inside the transaction

If the **last** log line is “after duplicate detection” or “before profile existence step” or later, then the stop is **inside** the transaction due to an **exception** (or the duplicate return). In that case:

- **Duplicate return:** Last log = “after duplicate detection” with `isDuplicate: true`. Then intake was saved with `existingProfileId` (possibly null). No code change for “fix” until you confirm why duplicate is true or why id is null.
- **Exception:** Check the exception message and stack trace in the same log (Laravel logs the exception). The stack trace gives the **exact line** (e.g. missing table, null reference, or constraint violation). Fix that specific cause (e.g. migration, or null check for `uploaded_by` user).

Run with the new logs once and use the “last log line” + exception (if any) to confirm which of the above applies.

---

## 5) Follow-up (after version normalization) — execution still exits before profile

### Exact execution stopping point

Without an exception visible in tinker, execution can stop before profile creation in two ways:

1. **Duplicate path with `existingProfileId === null`** — Old code set `$intake->matrimony_profile_id = $existingProfileId`, save, return. If null, intake saved with null; no profile; method returns. **Stopping point:** Inside transaction, duplicate block, then return.
2. **Exception inside transaction (rollback)** — Any throw rolls back; no intake update. If caller catches and does not rethrow, no exception visible. **Stopping point:** The line that throws. Check logs for `EXCEPTION inside/around transaction`.

### Is duplicate detection firing?

- **DuplicateDetectionService** only returns `isDuplicate === true` when a find* method returns a non-null profile id (always passed as `existingProfileId`). It does **not** return duplicate with null id under current code.
- If intake stays unchanged and no exception is shown, the likely explanation is an **exception** (rollback) that is caught. Use **BEFORE / AFTER DB::transaction** and **EXCEPTION** log lines to confirm.

### Does detectFromSnapshot treat uploaded_by=1 as duplicate of itself?

- **No.** SAME_USER is returned only when: (1) snapshot has a primary phone, (2) `findProfileIdByPrimaryPhone` returns an existing profile id, and (3) that profile's `user_id === uploaded_by`. So "this phone is already on another profile of this user." If user 1 has **no** existing profile, the find returns null and SAME_USER is not returned. First-time intake for user 1 is **not** treated as duplicate of themselves.

### DuplicateResult for Scenario-1 (first-time intake, user 1, no existing profile)

- **Result:** `DuplicateResult::notDuplicate()` → `isDuplicate` = **false**, `duplicateType` = **''**, `existingProfileId` = **null**, `reason` = **'No duplicate detected.'**
- So the duplicate path is **not** taken; execution continues to profile existence and creation.

### Minimal fix applied

- **Guard:** Duplicate block now runs only when `$duplicateResult->isDuplicate === true` **and** `$duplicateResult->existingProfileId !== null`. If duplicate were ever returned with null id, we fall through and create a profile instead of saving intake with null and returning.
- **Exception logging:** try/catch around `DB::transaction()` logs any Throwable (message, file, line) then rethrows. Check `storage/logs/laravel.log` for `EXCEPTION inside/around transaction`.

### Debug logging added (this round)

- Entry; version value and **version_type** (gettype); full **DuplicateResult** (isDuplicate, duplicateType, existingProfileId, reason).
- **BEFORE DB::transaction**; **AFTER DB::transaction**; **DUPLICATE PATH — right before return** (when duplicate path taken); **EXCEPTION inside/around transaction** (when something throws).
