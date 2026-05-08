from __future__ import annotations

import json
import time
from pathlib import Path
from typing import Any

import config


def _latest_comparison() -> Path | None:
    files = sorted(config.OUTPUT_COMPARISONS_DIR.glob("snapshot_comparison_*.json"), key=lambda p: p.stat().st_mtime, reverse=True)
    return files[0] if files else None


def _classify(row: dict[str, Any], snapshot: dict[str, Any]) -> str:
    field = str(row.get("field") or "")
    dbv = row.get("db")
    apiv = row.get("api")
    if apiv in (None, "") and dbv not in (None, ""):
        return "hidden_field_or_serializer_mismatch"
    if field.endswith("_id") and isinstance(apiv, dict):
        return "transformer_issue"
    if field in {"city", "caste", "education", "occupation"}:
        return "normalization_mismatch"
    api = snapshot.get("api") if isinstance(snapshot.get("api"), dict) else {}
    meta = api.get("meta") if isinstance(api.get("meta"), dict) else {}
    if bool(meta.get("cache_hit")):
        return "stale_cache"
    return "missing_eager_load_or_serializer_mismatch"


def run() -> dict[str, Any]:
    cpath = _latest_comparison()
    if cpath is None:
        out = {"generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), "error": "comparison_not_found"}
        (config.OUTPUT_HEALTH_DIR / "api_drift_root_cause_report.json").write_text(json.dumps(out, indent=2), encoding="utf-8")
        return out
    comparison = json.loads(cpath.read_text(encoding="utf-8"))
    snapshot = {}
    sp = comparison.get("snapshot_path")
    if isinstance(sp, str) and sp:
        p = Path(sp)
        if p.exists():
            snapshot = json.loads(p.read_text(encoding="utf-8"))
    causes: list[dict[str, Any]] = []
    for row in comparison.get("comparisons", []):
        if not isinstance(row, dict):
            continue
        if row.get("comparison_type") != "api_drift":
            continue
        causes.append(
            {
                "field": row.get("field"),
                "root_cause": _classify(row, snapshot),
                "db": row.get("db"),
                "api": row.get("api"),
            }
        )
    out = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "comparison_file": cpath.name,
        "api_drift_count": len(causes),
        "root_cause_breakdown": causes,
    }
    config.OUTPUT_HEALTH_DIR.mkdir(parents=True, exist_ok=True)
    (config.OUTPUT_HEALTH_DIR / "api_drift_root_cause_report.json").write_text(json.dumps(out, indent=2, default=str), encoding="utf-8")
    return out


if __name__ == "__main__":
    print(json.dumps(run(), indent=2, default=str))

