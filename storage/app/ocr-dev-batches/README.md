# OCR Dev Batches (local inbox)

Canonical path owned by the agent (DOC §13.7):

```text
storage/app/ocr-dev-batches/Batch-001
storage/app/ocr-dev-batches/Batch-002
…
```

- Drop **production-like** Marathi biodata here when the agent asks.
- Images/PDFs are gitignored (PII). Do not invent alternate folder names.
- Sprint sizes and ground-truth counts are agent-owned (DOC §13.6).

## Batch-001 (Sprint 2)

| Field | Value |
|-------|-------|
| Target count | 50 files |
| Formats | PDF, JPG, PNG |
| Mix | Old scans, mobile photos, poor light, rotated, varied layouts |
| Ground truth | Not required at drop; agent will request a subset later |
