# OCR Research Loop 04 — Mobile (`primary_contact_number`)

> **Status:** COMPLETE (partial residual remains)  
> **Authority:** Blueprint §20 + DOC §19 Impact First + §21 Continue  
> **Why:** GT-20 mobile was **55.6%** (flat since Sprint 2).

## Results

| Metric | Before | After |
|--------|-------:|------:|
| Mobile | 55.6% | **66.7%** |
| Critical | 66.3% | **68.4%** |

**Artifact:** `product_metrics_gt20_20260715_194518.json`

## Forensic

- Mode A (digits absent): **0**
- Mode B (digits in raw; wrong/null extract): **8** (pre-fix sample)

## Accepted

- Score a **local snippet** around each phone (not whole megapage line).
- **Left-biased** window so glued `वडील मोबाईल` after `मो.नं.` does not zero the candidate.
- Page boosts only on short lines (<220 chars).
- Orphan unlabeled digit-line penalty.

## Rejected

- Inventing missing digits from relatives’ numbers.
- Preferring father/mother numbers as primary when candidate `मो.नं.` exists.

## Residual (defer / Loop later)

`28.pdf`, `D (8)`, digit-shifted OCR (`8145932593` vs `9881459325`), etc. — Mode B preference or Mode A OCR fidelity; not invent.

## Next

Continuing automatically to **Loop 05 — Religion** (§21).
