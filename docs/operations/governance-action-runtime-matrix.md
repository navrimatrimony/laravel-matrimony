# Governance Action Runtime Matrix

Date: 2026-05-07  
Profile: `#207`  
Page: `/admin/data-engine/profiles/207`

## Execution Matrix

| Action | Runtime result | Visibility check | Notes |
|---|---|---|---|
| Rebuild snapshot (`rebuild_snapshot`) | PASS | Success panel visible: "Snapshot rebuilt successfully" with profile/artifact details | Action executed after login session; result card rendered in-page. |
| Re-run comparison (`rerun_comparison`) | PASS | Success text visible: "comparison completed" / comparison status updated | Result message appeared in in-page result area. |
| Check API parity (`validate_api_parity`) | PASS | Success panel visible: "API check passed" / "API parity check passed for the latest snapshot." | Action shows clear admin-facing message. |
| Refresh coverage summary (`refresh_coverage`) | PASS | Success panel visible: "Coverage summary refreshed" with profile details | Coverage/dashboard refresh message rendered. |
| Re-run section check (`rerun_repeater_diff`) | PASS | Success text visible: "Repeater and layer check finished" | Includes repeater checks in result description. |

## Failure-State Visibility (Verified)

Observed one runtime failure state during action attempts:

- Error message shown in-page: `CSRF token mismatch.`
- This confirms failure is not silent and is visible to admin users.
- After page refresh (new CSRF token), actions resumed successfully.

## Loading/Console Observations

- Action clicks disable/processing behavior is visible through button disabled state and refreshed result panel updates.
- No functional JavaScript errors observed in console during tested flows.
- Console output only includes Vite connection logs and Cursor browser dialog warning.

## Evidence Pointers

Browser snapshots captured during execution include (non-exhaustive):

- `snapshot-2026-05-07T11-26-00-868Z-2t2jb7.log` (snapshot rebuild success state)
- `snapshot-2026-05-07T11-26-44-067Z-yamdxu.log` (comparison rerun flow)
- `snapshot-2026-05-07T11-26-58-555Z-m9y2tg.log` (API parity action flow)
- `snapshot-2026-05-07T11-27-09-713Z-smxnoc.log` (coverage refresh flow)
- `snapshot-2026-05-07T11-27-25-863Z-zg0xxp.log` (section/repeater re-check flow)

