# U4 Implementation Contract — Throttle member OTP send

**Unit:** U4  
**Authority:** `docs/MARKETPLACE-MASTER-EXECUTION-SSOT.md` §U4  
**Schema:** none  

## Runtime truths referenced by U4

None. U4 cites no RT/MRT. No RT validation required.

## Behaviour

Mirror Suchak's existing `throttle:10,1` pattern (`routes/api/suchak.php` auth group):

- Attach Laravel's built-in `throttle:10,1` middleware to `POST /api/v1/auth/mobile-otp/send` only.
- Do not throttle `/auth/mobile-otp/verify` in this unit.
- Reuse framework middleware only — no custom rate-limiter class, no duplicated counters.

## Tests

- Within one minute / same client: 10th `POST .../send` succeeds (not 429); 11th returns 429.
- Existing `MobileOtpAccountApiTest` suite remains green (regression).

## Out of scope

- Suchak OTP / email OTP / password routes
- U5+ (production OTP fail-closed)
- Schema, new architecture, Master SSOT edits

## Rollback

`git revert <sha>`
