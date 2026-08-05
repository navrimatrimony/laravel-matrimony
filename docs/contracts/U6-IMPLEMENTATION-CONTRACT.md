# U6 Implementation Contract — Throttle challenge publish + proposal

**Unit:** U6  
**Authority:** `docs/MARKETPLACE-MASTER-EXECUTION-SSOT.md` §U6  
**Schema:** none  

## Runtime truths referenced by U6

None. U6 cites no RT/MRT. No RT validation required.

## Behaviour

Mirror existing Suchak `throttle:10,1` pattern:

- Attach Laravel's built-in `throttle:10,1` to:
  - `POST /api/v1/suchak/marketplace/challenges`
  - `POST /api/v1/suchak/marketplace/challenges/{challenge}/proposals`
- Do not throttle browse, withdraw, show, or my-candidates in this unit.
- Reuse framework middleware only — no custom rate-limiter class.

## Tests

- Authenticated Suchak: 10th publish POST in a minute is not 429; 11th returns 429.
- Authenticated Suchak: 10th proposal POST in a minute is not 429; 11th returns 429.

## Out of scope

- Named limiter prefixes / shared-bucket redesign
- U7+ cancellation / reputation
- Schema, Master SSOT edits

## Rollback

`git revert <sha>`
