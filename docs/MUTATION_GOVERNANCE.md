# Phase-5 Point 6: Mutation governance

All profile **data** writes go through `MutationService::applyManualSnapshot` (or approved intake path). No direct `$profile->update([...])` or `MatrimonyProfile::where()->update()` for profile data fields.

## Verified paths (use MutationService)

| Endpoint / action | Controller | Method | Notes |
|------------------|------------|--------|--------|
| POST wizard save | ProfileWizardController | store() | buildSectionSnapshot → applyManualSnapshot |
| POST update-full | MatrimonyProfileController | updateFull() | ManualSnapshotBuilderService → applyManualSnapshot |
| POST upload-photo | MatrimonyProfileController | storePhoto() | snapshot core.profile_photo → applyManualSnapshot |
| Admin sub-caste merge | SubCasteAdminController | merge() | Per-profile snapshot core.sub_caste_id → applyManualSnapshot(..., 'admin') |

## Exceptions (allowed; not profile “data”)

- **ProfileLifecycleService**: updates `lifecycle_state` only (lifecycle logic; do not alter per directive).
- **MutationService** internal: `$profile->save()` after applying core/entities (single write authority).
- **Console / test**: e.g. Day11CompletenessProof may touch profile for proof; not a user-facing write path.

## Redirects (no write)

- `matrimony.profile.store` → redirect to wizard (no DB write).
- `matrimony.profile.edit` / `matrimony.profile.edit-full` → redirect to wizard section `full`.
