# OCR Ensemble — Product Decisions Log

> **Parent:** Blueprint v1.0 + v1.1 Addendum  
> **Date:** 2026-07-12  
> **Status:** Approved by product owner — **no implementation yet**

---

## 1. Phase 1 scope — Bulk only ✅ FINAL

| Decision | Value |
|----------|-------|
| First rollout surface | **Admin Bulk Intake only** |
| Single-intake admin upload | **Later** (not Phase 1) |
| Reason | Simpler, lower risk |

---

## 2. Ground truth priority — Admin first, Sarvam second ✅ FINAL

**सोप्या भाषेत:** खरी माहिती कोणाची मानायची?

```
1st priority → Admin ने तपासून लिहिलेले (human verified)
2nd priority → Sarvam output (जेव्हा admin ने अजून verify केले नाही)
```

| Use case | Rule |
|----------|------|
| Golden dataset (10+50 images) | Admin verified wins; Sarvam helps fill draft only |
| Production parse | Ensemble + Sarvam judge; **admin correct-candidate is final truth** |
| Sarvam wrong | System must **show admin** what failed — not hide |

**महत्त्वाचे:** `approval_snapshot_json` (admin save) हेच अंतिम सत्य — हे कायम.

---

## 3. Ground truth dataset — 10 NEW biodata (not #735–737) ✅ FINAL

| Decision | Value |
|----------|-------|
| #735, #736, #737 | **Same one biodata**, three OCR methods — useful as **OCR comparison lab**, **not** three separate ground-truth seeds |
| Dataset size | **10 new biodata images** (10 different people/forms) |
| Manual CSV fill | **NOT required upfront** |
| How truth is built | Upload → OCRs run → Sarvam as reference → admin fixes wrong fields on `correct-candidate` → **admin save = truth** |

**सोप्या भाषेत:** Excel मध्ये हाताने भरण्याऐवजी — upload करा, system OCR करेल, Sarvam बरोबर compare दाखवेल, admin फक्त चुकीची fields fix करेल. ती fix केलेली माहितीच ground truth.

| Priority | Source |
|----------|--------|
| 1st | Admin corrected fields (`approval_snapshot_json`) |
| 2nd | Sarvam (reference until admin fixes) |
| 3rd | Other OCR engines |

---

## 4. Admin correction UI ✅ FINAL (no duplicate work)

**Already exists on `correct-candidate`:**

- Left: zoomable biodata image (`bulk-image-zoom` toolbar)
- Right: edit form

**Do NOT rebuild zoom.** Phase 5 only adds: **OCR comparison table** (Field | Tesseract | 2nd OCR | Sarvam | Final | Reason) + ensure **16 agreed fields** visible/editable on form.

---

## 5. Bulk upload — एक photo की अनेक? (स्पष्टीकरण)

**सोप्या भाषेत:**

| Action | काय होते |
|--------|----------|
| Admin **एक batch** मध्ये **अनेक photos** निवडू शकतो | होय — `files[]` multiple |
| **प्रत्येक photo** = **एका माणसाचे biodata** | होय — सामान्यतः 1 image = 1 intake |
| Admin **एकाच वेळी १ photo** upload करतो | होय — तेही चालते (batch मध्ये 1 file) |
| **10 ground truth** साठी | **10 वेगळ्या biodata images** — 10 वेळा upload नको; एक batch मध्ये 10 files किंवा 10 separate batches — दोन्ही OK |

**उत्तर:** Admin **प्रत्येक वेळी एक किंवा अनेक** photos एकाच batch form वरून upload करतो. Ground truth साठी **10 वेगळ्या biodata** images लागतात — **10 वेळा system वापरायला नको**, फक्त 10 images collect + verify करा.

---

## 6. Production vs local testing ✅ FINAL

| Decision | Value |
|----------|-------|
| Current state | Production server = **testing only**, no live customers |
| Testing location | **Server OK** — avoids local vs server conflict |
| Developer workflow | Code in `E:\LaravelProjects\laravel-matrimony` → git push → server pull |
| Feature flag | Default `false` until Phase 1 tested on server |
| Live customers | Not yet — safe to test on `navrimilenavryala.com` server |

**जेव्हा implement सुरू:** staging-style testing on **same production server** with flag on for test batches only.

---

## 7. Implementation — NOT started ✅ CONFIRMED

| Item | Status |
|------|--------|
| Blueprint + contracts + review + checklist + test plan | ✅ Done |
| Product decisions (this doc) | ✅ Done |
| Phase 1 code | ❌ **Waiting for explicit “Phase 1 सुरू कर”** |

---

## 8. Updated priority stack (final truth chain)

```
Image
  ↓
OCR Ensemble (Tesseract + optional 2nd engine + Sarvam judge)
  ↓
parsed_json (machine)
  ↓
Admin correct-candidate (zoom image + form)  ← HIGH PRIORITY UX
  ↓
approval_snapshot_json  ← FINAL TRUTH for profile
```

Sarvam = helper + judge, **never** replaces admin review.

---

## Document history

| Date | Change |
|------|--------|
| 2026-07-12 | Product owner decisions: bulk-only P1, admin>saravam truth, 10 images, zoom UI, server test |
| 2026-07-13 | Fix: #735–737 = one biodata 3 OCR paths; 10 NEW biodata; no manual CSV — admin correction builds truth; zoom already exists |
