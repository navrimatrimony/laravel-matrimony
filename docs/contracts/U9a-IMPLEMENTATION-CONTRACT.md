# U9a Implementation Contract — Member API: meetings list

**Unit:** U9a  
**Authority:** `docs/MARKETPLACE-MASTER-EXECUTION-SSOT.md` §U9a  
**Schema:** none  

## Runtime truths referenced by U9a

| RT | Validation |
|---|---|
| **RT-9** | Until this exists, nothing may hand the app a visit id. This unit adds `GET /api/v1/suchak-meetings`. |

## Behaviour

1. `GET /api/v1/suchak-meetings` (auth:sanctum) on `MemberSuchakMeetingApiController::index`.
2. Only meetings whose customer side is the caller — same ownership as `customerSideMatrimonyProfileId()` / confirm+dispute guards.
3. Each row: `id`, `visit_status`, `scheduled_for`, `suchak_display_name` (arranging Suchak's `suchak_name`).
4. Unauthenticated → 401. Another member's meetings → empty list (not leaked).

## Tests

- Own meetings visible.
- Another member sees none of them.
- Unauthenticated refused.

## Out of scope

- Flutter UI (U9b)
- Confirm/dispute actions (already exist; Flutter U10/U11)

## Rollback

`git revert <sha>`
