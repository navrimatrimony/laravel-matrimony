# Showcase photo pool (SSOT)

Showcase profiles created via **Admin → Bulk create** assign `profile_photo` from a strict on-disk library. This document is the single source of truth for paths, matching, and admin tooling.

## Scope

| In scope | Out of scope |
|----------|----------------|
| Bulk showcase create (`ShowcaseProfileFactory`) | Single-profile create UI |
| `eng/` folders under `public/uploads/matrimony_photos/` | Caste or district in photo paths |
| Admin **Showcase photo pool** (upload / browse / matrix) | Member-uploaded photos |
| Auto-engine uses same factory photo logic | Homepage / marketing images |

## Folder layout (strict — no `any`)

```
uploads/matrimony_photos/eng/{gender}/{religion_key}/{marital_key}/{age_bucket}/file.jpg
```

| Segment | Values |
|---------|--------|
| `gender` | `male`, `female` |
| `religion_key` | `master_religions.key` (slug, e.g. `hindu`, `muslim`) |
| `marital_key` | `master_marital_statuses.key` (e.g. `never_married`, `divorced`) |
| `age_bucket` | `18-24`, `25-30`, `31-35`, `36-45`, `46-plus` (from profile DOB) |

**No** `any/any/any`, **no** `eng/{gender}`-only fallbacks.

## Code map

| Piece | Location |
|-------|----------|
| Photo resolution | `ShowcaseProfileDefaultsService::resolveShowcasePhotoForAttributes()` |
| Admin policy (skip vs create without photo) | `ShowcasePhotoPoolSettings` → `admin_settings.showcase_photo_pool_policy` |
| Pool upload / matrix | `ShowcasePhotoPoolService`, `ShowcasePhotoPoolController` |
| Bulk outcomes | `ShowcaseBulkCreateReport`, `ShowcaseProfileCreateResult` |

## Admin policy (`showcase_photo_pool_policy`)

| Setting | Options |
|---------|---------|
| Missing exact folder / incomplete category | Create without photo **or** skip profile |
| Folder exists but all unused images used | Same |
| `allow_reuse_when_bucket_exhausted` | Reuse an already-used file in the **same** bucket only |

Configure under **Auto-showcase settings → Admin bulk → Photo pool policy**.

## Admin workflows

1. **Upload** — Showcase engine → **Photo pool** → pick gender, religion, marital, age → upload 1–20 images.
2. **Coverage** — Matrix lists every `eng/…` bucket on disk with total / unused counts.
3. **Bulk create** — **Bulk profiles**; check pool health banner and post-run warnings.

## Operations

- Religion/marital **keys in DB must match folder names** on disk.
- Legacy `eng/*/any/any/any` folders are **not** used after strict matching.
- For reliable bulk runs, keep **≥2 unused** images per bucket you expect random autofill to hit.
- When all unused images in a bucket are consumed, behaviour follows **pool exhausted** policy (or reuse if enabled).

## Tests

- `ShowcaseProfilePhotoSelectionTest` — strict matching, factory, bulk summary
- `ShowcasePhotoPoolTest` — upload, delete, matrix
- `ShowcaseBulkCreateReportTest` — outcome counts and grouped warnings
