# OCR Research Loop 04 — Mobile (`primary_contact_number`)

> **Status:** OPEN  
> **Authority:** Blueprint §20 + DOC §19 Impact First + §21 Continue  
> **Why:** GT-20 mobile **55.6%** (flat since Sprint 2) — worse structured accuracy than Name (65%); every intake needs contact.

## Impact gate

> Affects thousands of intakes? **Yes.**

## Method

1. Forensic mobile misses: digits in raw (B) vs absent (A).  
2. Production-general extract fix (no invent digits).  
3. Remasure critical + dashboard; accept only uplift.  
4. Commit + push; **continue next loop automatically** (§21).
