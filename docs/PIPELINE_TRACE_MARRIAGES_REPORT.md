# Full Pipeline Trace — profile_marriages Not Inserting

## Instrumentation Added (Temporary Logs Only — No Fixes)

All of the following were added for diagnosis only.

### STEP 1 — Section param
- **Where:** `ProfileWizardController::store()` at start of method.
- **Log:** `\Log::info('DEBUG SECTION PARAM', ['section' => $section]);`
- **Route checked:** `routes/web.php` lines 75–80.

### STEP 2 — buildSectionSnapshot request
- **Where:** `ProfileWizardController::buildSectionSnapshot()` before the switch.
- **Log:** `\Log::info('DEBUG BUILD SECTION', ['section' => $section, 'has_marriages_key_in_request' => ..., 'marriages_payload' => ...]);`

### STEP 3 — Snapshot before mutation
- **Where:** `ProfileWizardController::store()` after `buildSectionSnapshot()`.
- **Log:** `\Log::info('DEBUG SNAPSHOT FULL', $snapshot);` (in addition to existing DEBUG SNAPSHOT).

### STEP 4 — applyManualSnapshot receives marriages
- **Where:** `MutationService::applyManualSnapshot()` at top.
- **Log:** `\Log::info('DEBUG APPLY SNAPSHOT', ['keys' => ..., 'marriages_value' => ...]);`

### STEP 5 — ENTITY_SYNC_ORDER loop
- **Where:** Inside the `foreach (self::ENTITY_SYNC_ORDER as $snapshotKey)` in `applyManualSnapshot()`.
- **Log:** `\Log::info('DEBUG ENTITY LOOP', ['current_section' => ..., 'exists_in_snapshot' => ..., 'value' => ...]);`

### STEP 6 — Before sync call
- **Where:** Immediately before `$this->syncEntityDiff(...)` in the same loop (multi-row path).
- **Log:** `\Log::info('DEBUG CALLING SYNC', ['table' => ..., 'rows' => ...]);`

### STEP 7 — mapSnapshotRowToTable
- **Where:** First line of `MutationService::mapSnapshotRowToTable()`.
- **Log:** `\Log::info('DEBUG MAP CALL', ['table' => $entityType, 'row' => $row]);`

### STEP 8 — Before DB insert
- **Where:** Inside `syncEntityDiff()`, immediately before `DB::table($entityType)->insert($insertData)`.
- **Log:** `\Log::info('DEBUG DB OPERATION', ['table' => $entityType, 'payload' => $insertData]);`

---

## Route Verification (STEP 1)

**File:** `routes/web.php`

- **GET:** `Route::get('/matrimony/profile/wizard/{section}', ...)->where('section', 'basic-info|personal-family|location|property|horoscope|legal|about-preferences|contacts|photo|full');`
- **POST:** `Route::post('/matrimony/profile/wizard/{section}', ...)->where('section', 'basic-info|personal-family|location|property|horoscope|legal|about-preferences|contacts|photo|full');`

**Allowed section values in the constraint:**  
basic-info, personal-family, location, property, horoscope, legal, about-preferences, contacts, photo, full.

**Not in the constraint:** marriages, siblings, relatives, alliance.

So:

- The URL for the marriages section is `/matrimony/profile/wizard/marriages` (not `/wizard/marriages`; the prefix is `/matrimony/profile/wizard`).
- When `section=marriages`, the `->where('section', ...)` constraint **does not** include `marriages`, so the route **does not match**. Laravel will return 404 (or another route may match). So **store() is never called with $section = 'marriages'**.

---

## How to Fill the Report (After One Submit)

1. Submit the marriages section form once (POST to the marriages section).
2. Check `storage/logs/laravel.log` for the DEBUG lines above.
3. Run the Tinker commands in STEP 9 below.
4. Fill the numbered items in the “FINAL REPORT FORMAT” section using the log output and Tinker results.

---

## STEP 9 — Direct DB Test (Run in Tinker)

```php
DB::table('profile_marriages')->get();
```

Then:

```php
\App\Models\ProfileMarriage::create([
    'profile_id' => 22,
    'marital_status_id' => 2,
    'marriage_year' => 2020,
]);
```

Then:

```php
DB::table('profile_marriages')->get();
```

(Use a valid `profile_id` for your DB if 22 does not exist.)

---

## FINAL REPORT FORMAT (Fill After Trace)

1. **Section param value:** _[From DEBUG SECTION PARAM — if present; if 404, you will not see this.]_
2. **Request marriages payload:** _[From DEBUG BUILD SECTION marriages_payload.]_
3. **Snapshot full content:** _[From DEBUG SNAPSHOT FULL.]_
4. **Snapshot keys inside MutationService:** _[From DEBUG APPLY SNAPSHOT keys.]_
5. **ENTITY_SYNC_ORDER iteration output:** _[From DEBUG ENTITY LOOP for each section; note whether marriages appears and exists_in_snapshot/value.]_
6. **Whether syncEntityDiff called for marriages:** _[From DEBUG CALLING SYNC with table=profile_marriages.]_
7. **Whether mapSnapshotRowToTable triggered for profile_marriages:** _[From DEBUG MAP CALL with table=profile_marriages.]_
8. **Whether DB insert attempted:** _[From DEBUG DB OPERATION with table=profile_marriages.]_
9. **Direct DB insert success/failure:** _[From Tinker create() and second get().]_
10. **Final root cause (one sentence):** _[See below.]_

---

## Root Cause (From Code Inspection)

**If POST /matrimony/profile/wizard/marriages returns 404 or does not hit store:**  
The route constraint on the wizard GET and POST routes does not include `marriages` (or `siblings`, `relatives`, `alliance`). So when the user saves the marriages section, the request does not match the wizard store route, `ProfileWizardController@store` is never run with `$section = 'marriages'`, and the pipeline (buildSectionSnapshot, snapshot, applyManualSnapshot, entity sync, syncEntityDiff, mapSnapshotRowToTable, DB insert) never runs for marriages. **Root cause: profile_marriages rows are not inserted because the wizard POST route does not allow section=marriages, so the store action is never invoked for the marriages section.**

If in your environment the route somehow matches (e.g. constraint changed or different URL), then use the log points above to see where the pipeline stops (e.g. empty request marriages, snapshot without marriages, or entity sync not running for marriages).

No code or route changes were made; only inspection and temporary logging.
