# U12 Implementation Contract — Publisher told a proposal arrived

**Unit:** U12  
**Authority:** `docs/MARKETPLACE-MASTER-EXECUTION-SSOT.md` §U12  
**Schema:** none  

## Runtime truths referenced by U12

| RT | Validation |
|---|---|
| **RT-4** | One Notification + registry row; `SafeNotifier`. |
| **RT-5** | Database channel only. |
| **RT-11** | Fire where `marketplace_proposal_received` is already logged. |
| **RT-13** | `MarketplaceProposalReceivedNotification`. |
| **RT-14** | `default_push => true`; apps = suchak. |

## Behaviour

1. After `ACTION_MARKETPLACE_PROPOSAL_RECEIVED` is recorded in propose flow, notify the **publishing** Suchak's user.
2. Proposer is never notified of their own action.
3. Payload: challenge id + proposing Suchak display name (no candidate contacts).

## Tests

- Publisher notified once · proposer not notified.

## Rollback

`git revert <sha>`
