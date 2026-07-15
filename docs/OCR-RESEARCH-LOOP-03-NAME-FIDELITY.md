# OCR Research Loop 03 — Name (`full_name`) fidelity

> **Status:** OPEN  
> **Authority:** Blueprint §20 + DOC §19 Product Impact First  
> **Parent goal:** Product OCR Vision (In Progress)  
> **Why this loop:** Dashboard remasure — Name **35%** (baseline 30%); **65% miss** on GT-20 vs DOB already **95%**. Affects every production intake.

---

## 1. Impact gate

> Will this improvement affect thousands of real biodata intakes?

**Yes** — candidate name is present on essentially all biodata; residual DOB watermark (`D (8)`) is narrower.

---

## 2. Method

1. Forensic GT-20 name misses: Mode A (not in raw) vs B (in raw, extract miss).  
2. Rank failure modes.  
3. Implement cheapest production-general fix (label/extract — not invent names).  
4. Remasure dashboard + ledger; accept only measurable uplift.  
5. Commit + push; continue.

---

## 3. Non-goals

- Engine shopping  
- Inventing names not present in raw OCR  
- Overfitting one filename
