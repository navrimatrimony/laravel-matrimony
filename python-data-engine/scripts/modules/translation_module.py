"""
Offline lightweight translation and normalization helpers.

Purpose:
- Keep engine reusable without external APIs.
- Provide deterministic string cleanup and optional EN<->MR phrase mapping.
"""

from __future__ import annotations

from typing import Any


_EN_TO_MR: dict[str, str] = {
    "name": "नाव",
    "city": "शहर",
    "state": "राज्य",
    "missing": "गहाळ",
    "invalid": "अवैध",
    "phone": "मोबाईल",
    "pincode": "पिनकोड",
    "duplicate": "डुप्लिकेट",
    "error": "त्रुटी",
}

_MR_TO_EN: dict[str, str] = {v: k for k, v in _EN_TO_MR.items()}


def normalize_text(value: Any) -> str:
    """Lower-noise text normalizer used before comparison/translation."""
    if value is None:
        return ""
    text = str(value).strip()
    if text == "":
        return ""
    # Collapse inner whitespace and normalize casing for matching.
    return " ".join(text.split())


def translate_text(text: Any, source_lang: str = "en", target_lang: str = "mr") -> str:
    """
    Offline phrase-level translation helper.
    Falls back to original text when no mapping exists.
    """
    normalized = normalize_text(text)
    if normalized == "":
        return ""

    src = source_lang.lower().strip()
    tgt = target_lang.lower().strip()
    key = normalized.lower()

    if src == "en" and tgt == "mr":
        return _EN_TO_MR.get(key, normalized)
    if src == "mr" and tgt == "en":
        return _MR_TO_EN.get(key, normalized)
    return normalized


def translate_record_fields(
    record: dict[str, Any],
    field_lang_map: dict[str, tuple[str, str]] | None = None,
) -> dict[str, Any]:
    """
    Translate selected record fields.

    field_lang_map format:
      {
          "city": ("en", "mr"),
          "state": ("en", "mr"),
      }
    """
    if not isinstance(record, dict):
        return {}
    if not field_lang_map:
        return dict(record)

    out = dict(record)
    for field, lang_pair in field_lang_map.items():
        if field not in out:
            continue
        src, tgt = lang_pair
        out[field] = translate_text(out[field], source_lang=src, target_lang=tgt)
    return out

