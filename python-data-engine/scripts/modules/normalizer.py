"""
Deterministic text normalization (no external APIs).
"""

from __future__ import annotations


def normalize_city(value: str | None) -> str:
    """Lowercase, trim, collapse internal whitespace."""
    if value is None:
        return ""
    return " ".join(str(value).strip().lower().split())


def normalize_name(value: str | None) -> str:
    """Trim and title-case person names (simple word split)."""
    if value is None:
        return ""
    s = str(value).strip()
    if not s:
        return ""
    parts = s.split()
    return " ".join(p[:1].upper() + p[1:].lower() if p else "" for p in parts)
