# OCR Research Loop 11 — Loss audit + RAW OCR pivot attempt

> **Status:** COMPLETE  
> **Artifacts:**  
> - Audit: `loss_audit_mode_ab_20260716_103734.json`  
> - Rejected remasure: `product_metrics_gt20_20260716_105725.json` (crit **68.4%**)  
> - Accepted remasure: (pending restore run)

## Loss audit verdict

| Mode | Count |
|------|------:|
| A_raw_ocr | 8 |
| B_extract | 12 |

Naive count still lists extract as larger, but:

- **Religion 4/4 Mode A**
- **Gender 4/6 Mode A**
- Several name “B” rows are OCR garble (not inventable)

→ Largest **fidelity** gap is RAW OCR; parser loops are at diminishing returns for Mode A fields.

## Accepted

- Biodata title on its own line → next-line name (`रेखा शिवदास पाटील`)
- Loss-audit tooling + ledger pivot decision

## Rejected (regression evidence)

| Change | Result |
|--------|--------|
| jpg/webp default → `photo_capture` | PDF/image multipass regressions |
| Extra multipass presets (`noisy_scan`, forced `marathi_printed`) | Critical **78.9% → 68.4%**; DOB **95% → 80%**; PDF DOB **100% → 60%** |
| Multipass score boosts (community/months/exact mobile) | Coupled with above; reverted |

## Knowledge

Global multipass/preset widening without PDF-safe gating destroys English resume + Marathi PDF raster wins. RAW OCR experiments must be **image-cohort gated** or additive variants that cannot win when they wipe calendar/English signals.

## Next (Loop 12)

RAW OCR — **image-only** safer approach (e.g. photo_capture as extra multipass variant already exists; prefer scoring that cannot demote valid slash dates / English resumes). Do not change PDF defaults.
