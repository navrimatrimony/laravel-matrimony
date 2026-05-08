from __future__ import annotations

import json
import time
from pathlib import Path
from typing import Any

import config


def _norm(v: Any) -> str | None:
    if v is None:
        return None
    s = str(v).strip().lower()
    return s if s != "" else None


def _latest_snapshot(profile_id: int | None = None) -> Path | None:
    candidates = sorted(config.SNAPSHOT_BASE_DIR.glob("*_*/snapshot_*.json"), key=lambda p: p.stat().st_mtime, reverse=True)
    if profile_id is None:
        return candidates[0] if candidates else None
    for c in candidates:
        try:
            payload = json.loads(c.read_text(encoding="utf-8"))
        except Exception:
            continue
        if int(payload.get("entity_id") or payload.get("profile_id") or 0) == int(profile_id):
            return c
    return None


def run(profile_id: int | None = None) -> dict[str, Any]:
    snap = _latest_snapshot(profile_id)
    if snap is None:
        result = {"generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), "error": "snapshot_not_found"}
        (config.OUTPUT_HEALTH_DIR / "parity_validation_report.json").write_text(json.dumps(result, indent=2), encoding="utf-8")
        return result
    payload = json.loads(snap.read_text(encoding="utf-8"))
    dbv = payload.get("db") if isinstance(payload.get("db"), dict) else {}
    api = payload.get("api") if isinstance(payload.get("api"), dict) else {}
    api_profile = api.get("profile") if isinstance(api.get("profile"), dict) else {}
    rendered = payload.get("rendered") if isinstance(payload.get("rendered"), dict) else {}
    fields = rendered.get("fields") if isinstance(rendered.get("fields"), dict) else {}
    pub = fields.get("public_profile") if isinstance(fields.get("public_profile"), dict) else {}
    wiz = fields.get("wizard") if isinstance(fields.get("wizard"), dict) else {}
    repeaters = payload.get("repeaters") if isinstance(payload.get("repeaters"), dict) else {}

    scalar_rows: list[dict[str, Any]] = []
    scalar_mismatch = 0
    for k, db_value in dbv.items():
        api_v = api_profile.get(k)
        wiz_v = (wiz.get(k) or {}).get("raw_rendered") if isinstance(wiz.get(k), dict) else None
        pub_v = (pub.get(k) or {}).get("raw_rendered") if isinstance(pub.get(k), dict) else None
        values = [_norm(db_value), _norm(api_v), _norm(wiz_v), _norm(pub_v)]
        non_empty = [v for v in values if v is not None]
        parity = len(set(non_empty)) <= 1 if non_empty else True
        if not parity:
            scalar_mismatch += 1
        scalar_rows.append({"field": k, "parity": parity, "db": db_value, "api": api_v, "wizard": wiz_v, "public_profile": pub_v})

    repeater_rows: list[dict[str, Any]] = []
    for rep, rows in repeaters.items():
        if not isinstance(rows, list):
            continue
        repeater_rows.append(
            {
                "repeater": rep,
                "row_count": len(rows),
                "has_row_identity": all(isinstance(r, dict) and ("id" in r or "uuid" in r) for r in rows),
                "has_nested_arrays": any(isinstance(r, dict) and any(isinstance(v, list) for v in r.values()) for r in rows),
            }
        )

    result = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "snapshot_path": str(snap),
        "summary": {
            "scalar_total": len(scalar_rows),
            "scalar_parity_failures": scalar_mismatch,
            "repeater_groups": len(repeater_rows),
        },
        "scalar_parity": scalar_rows[:500],
        "repeater_parity": repeater_rows,
    }
    config.OUTPUT_HEALTH_DIR.mkdir(parents=True, exist_ok=True)
    (config.OUTPUT_HEALTH_DIR / "parity_validation_report.json").write_text(json.dumps(result, indent=2, default=str), encoding="utf-8")
    return result


if __name__ == "__main__":
    print(json.dumps(run(), indent=2, default=str))

