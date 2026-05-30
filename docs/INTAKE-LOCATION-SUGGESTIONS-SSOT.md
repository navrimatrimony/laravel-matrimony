# Intake location suggestions SSOT

## Purpose

Lock the biodata → hierarchy location suggestion flow so future edits do not regress birth-place (or other location) behaviour.

## Invariants (do not break)

1. **Display SSOT** — The visible location typeahead shows the member’s saved profile value or what they typed. Parser/biodata text in `parsed_json` must **not** replace it on preview load.
2. **Suggestion-only biodata** — Biodata place text appears only in the indigo strip: `From biodata:` + formatted label + **Apply** (MR: `बायोडेटामधून:` + `लागू करा`).
3. **Apply** — `PATCH /intake/resolve-location/{intake}` updates `approval_snapshot_json`, then preview JS calls `LocationTypeahead.applySelection()` so the visible typeahead matches the applied hierarchy label (same as picking from search).
4. **Approve** — On submit, `mergeApprovalLocationSuggestionsIntoSubmitSnapshot()` merges applied suggestion ids from approval snapshot into the posted snapshot.
5. **Validation** — Resolved ids use `AddressHierarchyRules::existsLocationLeafId()` (city/town/village/suburb leaves), not `existsCityId()` only.
6. **Confident match** — One option when village + taluka + district match (`PlaceIntakeSearchService::confidentMatch()`). No “Search more” UI.
7. **Birth biodata source** — `IntakeLocationSuggestionLayerService::extractBiodataBirthPlace()` uses parsed biodata only; `core.birth_place` on the form is not treated as user text for “should suggest”.

## Code map

| Layer | Class / file |
|--------|----------------|
| Suggestion logic | `App\Services\Intake\IntakeLocationSuggestionLayerService` |
| Field → DOM binding | `App\Services\Intake\IntakeLocationFieldRegistry` |
| Preview display SSOT | `App\Services\Intake\IntakePreviewExistingProfileOverlay::restoreProfileBirthPlaceForPreviewDisplay()` |
| Profile birth display | `App\Services\Intake\IntakePreviewProfileHydrator` (profile `birth_city_id` first) |
| Resolve API | `IntakeController::resolveLocationSuggestion`, `AdminIntakeController::resolveLocationSuggestion` |
| Approve merge | `IntakeController::mergeApprovalLocationSuggestionsIntoSubmitSnapshot()` |
| Preview UI | `resources/views/intake/preview.blade.php` (`resolveInlineTargets`, `bindLocationApplyButtons`) |

## Supported preview fields

- `birth_place` — `data-location-context="birth"`
- `native_place` — `data-location-context="native"`
- `work_location` — `data-location-context="work"`
- `addresses.{n}` — parents address row `parents_addresses[n]` (वडिलांचा/पालक पत्ता)
- `self_addresses.{n}` — self address row in **Your addresses** (`wizardSelfAddresses`; biodata `current` / `permanent` / `native` / `residential`)
- `relatives_parents_family.{n}` / `relatives_maternal_family.{n}` — relation row location typeahead

## Birth place Apply (regression guard)

- Do **not** remove `LocationTypeahead.applySelection()` from `bindLocationApplyButtons` success handler in `preview.blade.php`.
- Unit tests: `IntakeLocationSuggestionLayerServiceTest`, `IntakePreviewExistingProfileOverlayTest`.

## Regression tests (run before merging intake location changes)

```bash
php artisan test tests/Unit/Intake/IntakeLocationSuggestionLayerServiceTest.php
php artisan test tests/Unit/Intake/IntakePreviewExistingProfileOverlayTest.php
php artisan test tests/Unit/Intake/IntakeLocationFieldRegistryTest.php
```

## Checklist for new location fields

1. Register `field_key` + `IntakeLocationFieldRegistry::domAnchor()`.
2. Add candidate + resolve paths in `IntakeLocationSuggestionLayerService`.
3. Extend preview JS anchor resolver (or registry-driven lookup).
4. If profile-backed, extend overlay restore so profile value stays visible until approve.
5. Add a unit test row for the new `field_key`.
