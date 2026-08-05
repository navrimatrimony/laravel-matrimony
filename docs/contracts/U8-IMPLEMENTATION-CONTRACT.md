# U8 Implementation Contract — Customer told a meeting awaits them

**Unit:** U8  
**Authority:** `docs/MARKETPLACE-MASTER-EXECUTION-SSOT.md` §U8  
**Schema:** none  

## Runtime truths referenced by U8

| RT | Validation |
|---|---|
| **RT-4** | One Notification class + one `PushTypeRegistry` row; `SafeNotifier` swallows failures. |
| **RT-5** | Database channel only; no `notifications` queue worker. |
| **RT-11** | Fire where `visit_completion_marked` / `ACTION_VISIT_COMPLETION_MARKED` is already logged. |
| **RT-13** | Class name `SuchakMeetingCompletionMarkedNotification`. |
| **RT-14** | Registry `default_push => true`; apps = member. |

## Behaviour

1. After a successful `markSuchakCompleted()` transition (completion was pending → marked), notify the customer-side user resolved via `customerSideMatrimonyProfileId()` → profile `user_id`.
2. Idempotent: second `markSuchakCompleted` throws before notify (existing guard).
3. Payload: visit id + scheduled date (enough for U9 discoverability). No contacts.

## Tests

- Complete → customer notified once.
- Double-complete → once (second call refused).

## Out of scope

- U9a list endpoint / Flutter UI
- Suchak-facing copy of this alert

## Rollback

`git revert <sha>`
