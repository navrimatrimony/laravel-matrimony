"""
Address lat/lng enrichment from bundled pincode CSV.

CSV is loaded lazily on first use — no disk IO at import time.
"""

from __future__ import annotations

import logging
from pathlib import Path

import pandas as pd

from config import MODE

_LOG = logging.getLogger(__name__)

_CSV_PATH = Path(__file__).resolve().parents[2] / "data" / "india_pincode.csv"

_df: pd.DataFrame | None = None
_df_attempted: bool = False


def _load_pincode_frame() -> pd.DataFrame:
    """Single lazy load per process; returns empty DataFrame on any failure."""
    global _df, _df_attempted
    if _df_attempted:
        return _df if _df is not None else pd.DataFrame()
    _df_attempted = True
    try:
        if not _CSV_PATH.is_file():
            _LOG.warning("address_fix: pincode CSV missing at %s", _CSV_PATH)
            _df = pd.DataFrame()
            return _df
        raw = pd.read_csv(_CSV_PATH)
        raw.columns = [str(c).strip().lower() for c in raw.columns]
        if "pincode" not in raw.columns:
            _LOG.warning("address_fix: CSV has no pincode column; columns=%s", list(raw.columns))
            _df = pd.DataFrame()
            return _df
        if "lat" not in raw.columns and "latitude" in raw.columns:
            raw["lat"] = raw["latitude"]
        if "lon" not in raw.columns and "longitude" in raw.columns:
            raw["lon"] = raw["longitude"]
        try:
            raw["pincode"] = pd.to_numeric(raw["pincode"], errors="coerce").astype("Int64")
        except Exception:  # noqa: BLE001
            raw["pincode"] = raw["pincode"]
        _df = raw
        return _df
    except Exception as exc:  # noqa: BLE001
        _LOG.warning("address_fix: failed to read CSV %s: %s", _CSV_PATH, exc)
        _df = pd.DataFrame()
        return _df


def get_location(pincode):
    df = _load_pincode_frame()
    if df.empty:
        return (None, None)
    try:
        pc = int(str(pincode).strip())
    except (TypeError, ValueError):
        return (None, None)
    try:
        col = df["pincode"]
        row = df[col == pc]
        if row.empty:
            return (None, None)
        lat = row.iloc[0].get("lat")
        lon = row.iloc[0].get("lon")
        if lat is None or lon is None or (isinstance(lat, float) and pd.isna(lat)):
            return (None, None)
        return (float(lat), float(lon))
    except Exception:  # noqa: BLE001
        return (None, None)


def fix_address_lat_long(conn):
    df = _load_pincode_frame()
    warning_msg: str | None = None
    if df.empty:
        warning_msg = (
            f"Pincode CSV unavailable or invalid at {_CSV_PATH} — address lat/lng lookup disabled for this run."
        )

    updates = []
    skipped = []
    rows: list = []

    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, pincode, lat, lng
            FROM addresses
            WHERE (lat IS NULL OR lng IS NULL)
            AND pincode IS NOT NULL
            AND pincode != ''
        """
        )

        rows = cur.fetchall()

        for row in rows:
            pincode = row["pincode"]

            try:
                lat, lng = get_location(pincode)

                if lat and lng:
                    updates.append(
                        {
                            "id": row["id"],
                            "pincode": pincode,
                            "lat": lat,
                            "lng": lng,
                        }
                    )

                    if MODE == "fix":
                        cur.execute(
                            """
                            UPDATE addresses
                            SET lat=%s, lng=%s
                            WHERE id=%s
                            """,
                            (lat, lng, row["id"]),
                        )

                else:
                    skipped.append(
                        {
                            "id": row["id"],
                            "reason": "pincode not found in CSV",
                        }
                    )

            except Exception as e:
                skipped.append(
                    {
                        "id": row["id"],
                        "reason": str(e),
                    }
                )

    if MODE == "fix":
        conn.commit()

    out: dict = {
        "total_checked": len(rows),
        "updated": len(updates),
        "skipped": skipped[:20],
        "sample_updates": updates[:10],
    }
    if warning_msg:
        out["warning"] = warning_msg
    return out
