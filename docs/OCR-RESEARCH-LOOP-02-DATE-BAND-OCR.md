# OCR Research Loop 02 — Date-band / region OCR (Mode A residuals)

> **Status:** OPEN  
> **Authority:** Blueprint §20 + DOC §17 (no premature plateau)  
> **Parent goal:** Product OCR Vision — Raw OCR text fidelity (In Progress)  
> **Targets (ranked):** `D (8).jpeg` then `28.pdf`

---

## 1. Problem

Full-page multipass still misreads DOB digits on hard pages:

| File | Typical raw near DOB | Truth |
|------|----------------------|-------|
| `D (8).jpeg` | `जन्म :२४०३/१९९९` | `1999-03-21` |
| `28.pdf` | `24 फिट 1991` (no month/day calendar) | `1991-03-24` |

Parser invent rejected in Loop 01. Need **better raw** from a tighter region or stronger raster — production-general if uplift holds on more than one layout class.

---

## 2. Method

1. Offline: crop horizontal band around birth-label rows; re-OCR with multipass.  
2. If raw gains a correct/parseable date → design production heuristic (label-proximity band, not GT hardcode).  
3. Bench GT-20 DOB; accept only measurable uplift without regressing resolved cases.  
4. If crop fails: try alternate (higher PDF DPI only for weak PDFs, contrast-limited band, PSM 7/8 on crop) — each logged accept/reject.  
5. Plateau only after multiple approaches (DOC §17).

---

## 3. Explicit non-goals

- New OCR engine shopping  
- Inventing DOB digits in normalizer  
- Declaring Product Goal complete when this loop closes
