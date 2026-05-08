"""Phase-6J runtime verification helpers (artifacts under output/health)."""

from __future__ import annotations

import json
import sys
import time
from pathlib import Path
from typing import Any

SCRIPTS_ROOT = Path(__file__).resolve().parent.parent
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import config  # noqa: E402


def _latest_comparison() -> Path | None:
    base = config.OUTPUT_COMPARISONS_DIR
    if not base.exists():
        return None
    files = sorted(base.glob("snapshot_comparison_*.json"), key=lambda p: p.stat().st_mtime, reverse=True)
    return files[0] if files else None


def _latest_snapshot_for_profile(profile_id: int | None) -> Path | None:
    if not config.SNAPSHOT_BASE_DIR.exists():
        return None
    cands = sorted(config.SNAPSHOT_BASE_DIR.glob("*_*/snapshot_*.json"), key=lambda p: p.stat().st_mtime, reverse=True)
    for p in cands:
        if profile_id is None:
            return p
        try:
            data = json.loads(p.read_text(encoding="utf-8"))
        except Exception:
            continue
        if int(data.get("entity_id") or data.get("profile_id") or 0) == int(profile_id):
            return p
    return cands[0] if cands else None


def run_verify_repeater_diffs(profile_id: int | None = None) -> dict[str, Any]:
    cmp_path = _latest_comparison()
    out: dict[str, Any] = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "comparison_path": str(cmp_path) if cmp_path else None,
        "repeater_field_diff_count": 0,
        "snapshot_repeater_governance_present": False,
        "status": "failed",
    }
    if cmp_path is None or not cmp_path.is_file():
        out["error"] = "comparison_not_found"
        _write_health("verify_repeater_diffs", out)
        return out
    cmp_data = json.loads(cmp_path.read_text(encoding="utf-8"))
    diffs = cmp_data.get("repeater_field_diffs")
    out["repeater_field_diff_count"] = len(diffs) if isinstance(diffs, list) else 0
    sp = cmp_data.get("snapshot_path")
    if isinstance(sp, str) and sp != "":
        snap = Path(sp)
        if snap.is_file():
            snap_data = json.loads(snap.read_text(encoding="utf-8"))
            rg = snap_data.get("repeater_governance")
            out["snapshot_repeater_governance_present"] = isinstance(rg, dict) and isinstance(rg.get("runtime_proof"), dict)
    out["status"] = "ok" if out["snapshot_repeater_governance_present"] else "incomplete"
    _write_health("verify_repeater_diffs", out)
    return out


def run_verify_api_parity(profile_id: int | None = None) -> dict[str, Any]:
    from governance.canonical_registry_spec import api_alias_map

    snap_path = _latest_snapshot_for_profile(profile_id)
    reg_path = config.ENGINE_ROOT / "config" / "governance" / "canonical_field_registry.json"
    out: dict[str, Any] = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "snapshot_path": str(snap_path) if snap_path else None,
        "missing_api_keys": [],
        "status": "failed",
    }
    if snap_path is None or not snap_path.is_file():
        out["error"] = "snapshot_not_found"
        _write_health("verify_api_parity", out)
        return out
    if not reg_path.is_file():
        out["error"] = "canonical_registry_not_found"
        _write_health("verify_api_parity", out)
        return out
    snap = json.loads(snap_path.read_text(encoding="utf-8"))
    reg = json.loads(reg_path.read_text(encoding="utf-8"))
    api = snap.get("api") if isinstance(snap.get("api"), dict) else {}
    profile = api.get("profile") if isinstance(api.get("profile"), dict) else {}
    aliases = api_alias_map()
    missing = []
    for e in reg.get("entries") or []:
        if not isinstance(e, dict) or not e.get("governed") or not e.get("api_supported") or e.get("repeater"):
            continue
        field = str(e.get("field") or "")
        if field == "":
            continue
        keys = aliases.get(field, [field])
        if not any(k in profile for k in keys):
            missing.append({"field": field, "expected_any_of": keys})
    out["missing_api_keys"] = missing
    out["status"] = "ok" if missing == [] else "failed"
    _write_health("verify_api_parity", out)
    return out


def _write_health(name: str, payload: dict[str, Any]) -> None:
    config.OUTPUT_HEALTH_DIR.mkdir(parents=True, exist_ok=True)
    ts = time.strftime("%Y%m%d_%H%M%S", time.gmtime())
    p = config.OUTPUT_HEALTH_DIR / f"{name}_{ts}.json"
    p.write_text(json.dumps(payload, indent=2, default=str), encoding="utf-8")
