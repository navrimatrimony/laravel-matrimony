"""
Offline India pincode lookup using bundled CSV (no external APIs).
"""

from __future__ import annotations

import csv
from pathlib import Path

import config

try:
    import pandas as pd  # type: ignore
except Exception:  # noqa: BLE001
    pd = None


_rows: list[dict] | None = None
_pincode_index: dict[str, dict] = {}
_city_index: dict[str, dict] = {}


def _normalize_city(name: str | None) -> str:
    if name is None:
        return ""
    return " ".join(str(name).strip().lower().split())


def _apply_column_aliases(row: dict[str, str]) -> dict[str, str]:
    """Normalize common dataset variants to canonical keys."""
    # Common Indian pincode datasets often use district/statename instead of city/state.
    if ("city" not in row or row.get("city", "") == "") and row.get("district", "") != "":
        row["city"] = row["district"]
    if ("state" not in row or row.get("state", "") == "") and row.get("statename", "") != "":
        row["state"] = row["statename"]
    if "lat" not in row and row.get("latitude", "") != "":
        row["lat"] = row["latitude"]
    if "lon" not in row and row.get("longitude", "") != "":
        row["lon"] = row["longitude"]
    return row


def load_csv(path: Path | None = None) -> list[dict]:
    global _rows, _pincode_index, _city_index
    p = path or config.PINCODE_CSV
    if not p.is_file():
        raise FileNotFoundError(f"Pincode CSV not found: {p}")
    rows: list[dict] = []
    if pd is not None:
        frame = pd.read_csv(p, dtype=str).fillna("")
        frame.columns = [str(c).lower().strip() for c in frame.columns]
        if "city" not in frame.columns and "district" in frame.columns:
            frame["city"] = frame["district"]
        if "state" not in frame.columns and "statename" in frame.columns:
            frame["state"] = frame["statename"]
        if "lat" not in frame.columns and "latitude" in frame.columns:
            frame["lat"] = frame["latitude"]
        if "lon" not in frame.columns and "longitude" in frame.columns:
            frame["lon"] = frame["longitude"]
        required = {"pincode", "city", "state"}
        missing = required - set(frame.columns)
        if missing:
            raise ValueError(f"CSV missing columns {missing}; found {list(frame.columns)}")
        for raw in frame.to_dict(orient="records"):
            row = {str(k).lower().strip(): str(v).strip() for k, v in raw.items()}
            row = _apply_column_aliases(row)
            row["city_norm"] = _normalize_city(row.get("city"))
            rows.append(row)
    else:
        with p.open("r", encoding="utf-8", newline="") as fh:
            reader = csv.DictReader(fh)
            if reader.fieldnames is None:
                raise ValueError("Pincode CSV has no header row")
            normalized = [str(c).lower().strip() for c in reader.fieldnames]
            required = {"pincode", "city", "state"}
            missing = required - set(normalized)
            if missing:
                raise ValueError(f"CSV missing columns {missing}; found {reader.fieldnames}")
            for raw in reader:
                row = {str(k).lower().strip(): (str(v).strip() if v is not None else "") for k, v in raw.items()}
                row = _apply_column_aliases(row)
                row["city_norm"] = _normalize_city(row.get("city"))
                rows.append(row)
    pin_idx: dict[str, dict] = {}
    city_idx: dict[str, dict] = {}
    for row in rows:
        pin = str(row.get("pincode") or "").strip()
        city_norm = row.get("city_norm", "")
        if pin and pin not in pin_idx:
            pin_idx[pin] = row
        if city_norm and city_norm not in city_idx:
            city_idx[city_norm] = row
    _rows = rows
    _pincode_index = pin_idx
    _city_index = city_idx
    return _rows


def get_dataframe() -> list[dict]:
    if _rows is None:
        return load_csv()
    return _rows


def lookup_by_pincode(pincode: str) -> dict | None:
    """Return first matching row as dict (city, state) for a pincode string."""
    get_dataframe()
    key = str(pincode).strip()
    if not key:
        return None
    row = _pincode_index.get(key)
    if not row:
        return None
    return {
        "pincode": str(row.get("pincode") or "").strip(),
        "city": str(row.get("city") or "").strip(),
        "state": str(row.get("state") or "").strip(),
        "lat": row.get("lat"),
        "lon": row.get("lon"),
    }


def lookup_pincode_by_city(city: str) -> dict | None:
    """First CSV row whose normalized city matches."""
    get_dataframe()
    norm = _normalize_city(city)
    if not norm:
        return None
    row = _city_index.get(norm)
    if not row:
        return None
    out = {
        "pincode": str(row.get("pincode") or "").strip(),
        "city": str(row.get("city") or "").strip(),
        "state": str(row.get("state") or "").strip(),
    }
    if row.get("lat", "") != "":
        out["lat"] = row["lat"]
    if row.get("lon", "") != "":
        out["lon"] = row["lon"]
    return out


def get_by_pincode(pincode: str) -> dict | None:
    return lookup_by_pincode(pincode)


def get_by_city(city: str) -> dict | None:
    return lookup_pincode_by_city(city)
