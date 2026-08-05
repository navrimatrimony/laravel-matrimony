# U3 Implementation Contract — Admin told a dispute party is leaving

**Unit:** U3 (NOTIFY_ONLY)  
**Authority:** `docs/MARKETPLACE-MASTER-EXECUTION-SSOT.md` §U3  
**Schema:** none  

## Runtime truths used (validated, not re-audited)

| RT | Use in U3 |
|----|-----------|
| **RT-4** | One Notification class + one `PushTypeRegistry` row; deliver via existing `SafeNotifier` + `SendPushForDatabaseNotification`. |
| **RT-5** | Channels = `database` only (sync write + best-effort push). Never queue `notifications`. |
| **RT-7** | Fire only on the same U2-A atomic flip: `requestDeletion` when `whereNull(deletion_requested_at)->update(...)` affected exactly 1. No notify on second request / cancel / purge. |
| **RT-13** | Class name: `DisputePartyDeletionRequestedNotification`. |
| **RT-14** | Registry row `default_push => true`. |

MRT-02/04/11 apply as already owned by U2 pattern. RT-6 does **not** apply (receivers are admins, not Suchaks).

## Behaviour

1. After a successful deletion-request flip in `MemberAccountDeletionService::requestDeletion()`:
2. If the member has a `matrimony_profile_id` that appears on any `suchak_disputes` row with `status IN (open, under_review)`:
3. Notify each admin user (`is_admin = true`) **once** with `DisputePartyDeletionRequestedNotification`.
4. Do **not** change dispute status, priority, assignment, or any dispute column (NOTIFY_ONLY).

## Payload (privacy)

- `customer_full_name`
- `event_date` (Y-m-d, Latin digits)
- `open_dispute_count` (int — enough for admin triage; no reason/contacts)

## Out of scope

- Cancel-deletion admin notify (U3 names only U2-A / request flip)
- Changing dispute lifecycle or money levers
- New tables/columns/queues/schedulers
- U4+

## Tests / regression / rollback

- Tests: open/under_review → admins once; resolved/closed → none; dispute row unchanged; second request → no second notify.
- Regression: `SuchakDisputeLifecycleTest` green.
- Rollback: `git revert <sha>`.
