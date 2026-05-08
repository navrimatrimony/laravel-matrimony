"""
Latitude / longitude from the same offline pincode CSV (no HTTP calls).
"""

from __future__ import annotations

from . import pincode_module


def lookup_latlong_by_pincode(pincode: str) -> tuple[str | None, str | None]:
    info = pincode_module.get_by_pincode(pincode)
    if not info:
        return None, None
    lat = info.get("lat")
    lon = info.get("lon")
    if lat is None or lon is None:
        return None, None
    return str(lat), str(lon)


def lookup_latlong_by_city(city: str) -> tuple[str | None, str | None]:
    info = pincode_module.get_by_city(city)
    if not info:
        return None, None
    lat = info.get("lat")
    lon = info.get("lon")
    if lat is None or lon is None:
        return None, None
    return str(lat), str(lon)
