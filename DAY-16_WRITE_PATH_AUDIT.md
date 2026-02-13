# Phase-4 Day 16: Global Write Path Mapping — Audit Report

**Objective:** List every location in the codebase where `MatrimonyProfile` or the `matrimony_profiles` table is modified.

**Audit date:** Generated from codebase scan. No code was modified.

---

## 1. Write paths: Controllers

| Controller | Method | Line(s) | Type of write | Notes |
|------------|--------|---------|----------------|-------|
| `App\Http\Controllers\AdminController` | `suspendProfile` | 161 | Model `->update()` | `$profile->update(['is_suspended' => true])` |
| `App\Http\Controllers\AdminController` | `unsuspendProfile` | 190 | Model `->update()` | `$profile->update(['is_suspended' => false])` |
| `App\Http\Controllers\AdminController` | `approveImage` | 254 | Model `->update()` | `$profile->update([...])` photo approval fields |
| `App\Http\Controllers\AdminController` | `rejectImage` | 284 | Model `->update()` | `$profile->update([...])` photo rejection fields |
| `App\Http\Controllers\AdminController` | `overrideVisibility` | 321 | Model `->update()` | `$profile->update([...])` visibility override |
| `App\Http\Controllers\AdminController` | `updateProfile` | 866 | Model `->update()` | `$profile->update($updateData)` admin profile edit |
| `App\Http\Controllers\AdminController` | `updateProfile` | 895 | Model `->update()` | `$profile->update([...])` extended-only edit metadata |
| `App\Http\Controllers\AdminController` | `softDeleteProfile` | 220 | Model `->delete()` | `$profile->delete()` (soft delete) |
| `App\Http\Controllers\MatrimonyProfileController` | `store` | 149 | Model `::create()` | `MatrimonyProfile::create(...)` new profile |
| `App\Http\Controllers\MatrimonyProfileController` | `store` | 176 | Model `->update()` | `$existingProfile->update($profileData)` |
| `App\Http\Controllers\MatrimonyProfileController` | `update` | 421 | Model `->update()` | `$user->matrimonyProfile->update($updateData)` |
| `App\Http\Controllers\MatrimonyProfileController` | `storePhoto` | 526 | Model `->update()` | `$user->matrimonyProfile->update([...])` photo fields |
| `App\Http\Controllers\Admin\DemoProfileController` | `store` | 46 | Model `::create()` | `MatrimonyProfile::create([...])` |
| `App\Http\Controllers\Admin\DemoProfileController` | `bulkStore` | 116 | Model `::create()` | `MatrimonyProfile::create([...])` |
| `App\Http\Controllers\Api\MatrimonyProfileApiController` | `store` | 51 | Model `::create()` | `MatrimonyProfile::create([...])` |
| `App\Http\Controllers\Api\MatrimonyProfileApiController` | `update` | 202 | Model `->update()` | `$profile->update($updateData)` |
| `App\Http\Controllers\Api\MatrimonyProfileApiController` | `uploadPhoto` | 275 | Model `->update()` | `$profile->update([...])` photo fields |
| `App\Http\Controllers\InterestController` | `accept` | 224 | Model `->update()` | `$receiverProfile->update(['contact_visible_to' => $whitelist])` |

---

## 2. Write paths: Services

| Service | Method | Line(s) | Type of write | Notes |
|---------|--------|---------|----------------|-------|
| `App\Services\ConflictResolutionService` | `applyResolutionToProfile` (private, via resolve) | 118 | Model `->update()` | `$profile->update($updateData)` with bypass flag; history recorded before update |
| `App\Services\ProfileLifecycleService` | `transitionTo` | 55 | Model `->update()` | `$profile->update(['lifecycle_state' => $targetState])`; history recorded before update |

---

## 3. Write paths: Console commands

| Class | Method | Line(s) | Type of write | Notes |
|-------|--------|---------|----------------|-------|
| `App\Console\Commands\Day11CompletenessProof` | `handle` | 58, 69 | Model `->save()` | `$profile->save()` after setting caste (proof-of-concept; history recorded before save) |

---

## 4. Write paths: Jobs

| Job | Method | Line(s) | Type of write | Notes |
|-----|--------|---------|----------------|-------|
| `App\Jobs\ProcessDelayedViewBack` | `handle` | — | **None** | Does **not** modify `MatrimonyProfile`. Creates `ProfileView` and sends notification only. |

**Conclusion:** No background job updates `MatrimonyProfile`. No governance bypass via jobs.

---

## 5. Write paths: Migrations (one-time / schema)

| File | Line(s) | Type of write | Notes |
|------|---------|----------------|-------|
| `database/migrations/2026_02_10_083633_add_women_safety_columns_to_matrimony_profiles_table.php` | 29–40 | **Raw SQL** `DB::statement("UPDATE matrimony_profiles ...")` | One-time backfill: sets `profile_visibility_mode`, `contact_unlock_mode`, `safety_defaults_applied` by gender. Schema migration only; not application runtime path. |

**Note:** Migration uses `DB::statement`; file does not import `Illuminate\Support\Facades\DB` (Laravel may resolve facade). All other migrations only use `Schema::table('matrimony_profiles', ...)` for column add/drop (no row updates).

---

## 6. Write paths: Seeders

| Location | MatrimonyProfile write? | Notes |
|----------|-------------------------|-------|
| `database/seeders/*` | **No** | No seeder references `MatrimonyProfile` or `matrimony_profiles` for create/update/delete. Seeders touch `FieldRegistry`, `AdminSetting`, etc. only. |

**Conclusion:** No seeder modifies `MatrimonyProfile` or production profile data logic.

---

## 7. Raw SQL and DB facade (application code)

| Pattern | Result |
|---------|--------|
| `DB::table('matrimony_profiles')` | **No matches** in app code (only in migrations as above). |
| `DB::update(` / `DB::insert(` | **No direct** `DB::update()` or `DB::insert()` calls in app code for `matrimony_profiles`. |

**Conclusion:** All application-level writes to profile data go through the `MatrimonyProfile` model (create/update/save/delete). No raw SQL bypass in app code.

---

## 8. Summary table (all application write paths)

| # | Location | Method / context | Line | Write type |
|---|----------|------------------|------|------------|
| 1 | AdminController | suspendProfile | 161 | update |
| 2 | AdminController | unsuspendProfile | 190 | update |
| 3 | AdminController | approveImage | 254 | update |
| 4 | AdminController | rejectImage | 284 | update |
| 5 | AdminController | overrideVisibility | 321 | update |
| 6 | AdminController | updateProfile | 866 | update |
| 7 | AdminController | updateProfile | 895 | update |
| 8 | AdminController | softDeleteProfile | 220 | delete (soft) |
| 9 | MatrimonyProfileController | store | 149 | create |
| 10 | MatrimonyProfileController | store | 176 | update |
| 11 | MatrimonyProfileController | update | 421 | update |
| 12 | MatrimonyProfileController | storePhoto | 526 | update |
| 13 | Admin\DemoProfileController | store | 46 | create |
| 14 | Admin\DemoProfileController | bulkStore | 116 | create |
| 15 | Api\MatrimonyProfileApiController | store | 51 | create |
| 16 | Api\MatrimonyProfileApiController | update | 202 | update |
| 17 | Api\MatrimonyProfileApiController | uploadPhoto | 275 | update |
| 18 | InterestController | accept | 224 | update |
| 19 | ConflictResolutionService | applyResolutionToProfile | 118 | update (bypass) |
| 20 | ProfileLifecycleService | transitionTo | 55 | update |
| 21 | Day11CompletenessProof | handle | 58, 69 | save |

---

## 9. Confirmations

### 9.1 All writes pass through the model (no raw SQL bypass in app)

- **Yes.** No `DB::table('matrimony_profiles')->update/insert/delete()` in application code.
- The only raw write to `matrimony_profiles` is in migration `2026_02_10_083633` (one-time backfill). All runtime writes use `MatrimonyProfile::create()`, `$profile->update()`, `$profile->save()`, or `$profile->delete()` and therefore go through model events (e.g. governance seal on update/save).

### 9.2 No background job updates without governance

- **Confirmed.** The only job that loads `MatrimonyProfile` is `ProcessDelayedViewBack`; it does not update or save profiles. No other jobs were found that modify `MatrimonyProfile`.

### 9.3 No seeder modifies production profile data logic

- **Confirmed.** No seeder creates, updates, or deletes `MatrimonyProfile` or uses `matrimony_profiles` for data. Seeders only touch other tables (e.g. field registry, admin settings).

---

## 10. Excluded (not MatrimonyProfile writes)

The following were found by broad search but do **not** write to `MatrimonyProfile` or `matrimony_profiles`:

- `ConflictRecord::update`, `Interest::update`, `VerificationTag::update`, `SeriousIntent::update`, `FieldRegistry::update`, `AbuseReport::update`, `User::update`, `BiodataIntake::update`, `AdminCapabilityController` / `TagAssignmentService` (other tables).
- `ExtendedFieldService`: `$row->save()` on `ProfileExtendedField` (different table).
- `ProfileFieldLockService::removeLock`: `DB::table('profile_field_locks')->delete()` (different table).
- `governance_test_v2.php` / `governance_test.php`: test/scratch files; not part of application write path audit.

---

**End of Day-16 Write Path Audit.**
