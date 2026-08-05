# U7 Implementation Contract — Honest cancellation + fair `cancelled_rate`

**Unit:** U7  
**Authority:** `docs/MARKETPLACE-MASTER-EXECUTION-SSOT.md` §U7  
**Schema:** none  

## Runtime truths referenced by U7

| RT | Validation |
|---|---|
| **RT-8** | `visitCancelActorType()` already returns `ACTOR_ADMIN` / `ACTOR_SUCHAK` and is logged. No new actor-tracking columns. |
| **RT-11** | Cancel continues to write `ACTION_VISIT_CANCELLED` / `EVENT_CANCELLED`; reason+attendance go into existing event `metadata_json`. |

## Behaviour

1. **Cancel writes reason + attendance** into `suchak_visit_confirmation_events.metadata_json` on `EVENT_CANCELLED` (no new columns). Attendance is a required allow-listed string: `none` | `partial` | `both`. Reason remains required and is also mirrored into metadata (alongside existing `event_note`).
2. **Reputation `cancelled_rate` numerator** counts only cancellations whose cancel event has `actor_type = suchak`. Admin-actor cancellations are excluded. Denominator stays `total` (every meeting arranged). Raw `cancelled` count still includes all cancellations.
3. No actor-tracking columns (RT-8).

## Tests

- Cancel records reason + attendance in event metadata; event row remains immutable.
- Admin-cancel does not raise `cancelled_rate` numerator; Suchak-cancel does.

## Out of scope

- Customer-history `attendance_recorded` flag flip
- Actor tracking columns
- U8+ notifications

## Rollback

`git revert <sha>`
