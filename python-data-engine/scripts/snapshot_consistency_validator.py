from __future__ import annotations

import json
import time
from pathlib import Path
from typing import Any

import config


def _validate_snapshot_file(path: Path, expected_entity: str | None = None) -> dict[str, Any]:
    issues: list[str] = []
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return {"path": str(path), "valid": False, "issues": ["invalid_json"]}
    if not isinstance(payload, dict):
        return {"path": str(path), "valid": False, "issues": ["invalid_payload"]}

    if payload.get("entity_type") is None:
        issues.append("missing_entity_type")
    if expected_entity and payload.get("entity_type") != expected_entity:
        issues.append("mismatched_entity_type")
    if payload.get("render_capture_completed") is not True:
        issues.append("render_capture_not_completed")
    if payload.get("comparison_eligible") is not True:
        issues.append("comparison_eligible_false")
    rendered = payload.get("rendered")
    if not isinstance(rendered, dict):
        issues.append("missing_rendered_payload")
    else:
        fields = rendered.get("fields")
        if not isinstance(fields, dict) or fields == {}:
            issues.append("empty_extraction")
    if payload.get("schema_version") != "matrimony_profile_v2":
        issues.append("stale_schema")

    return {"path": str(path), "valid": issues == [], "issues": issues}


def run() -> dict[str, Any]:
    base = config.SNAPSHOT_BASE_DIR
    if not base.exists():
        report = {"generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), "valid_snapshots": 0, "invalid_snapshots": 0, "files": [], "orphan_snapshot_directories": []}
        (config.OUTPUT_HEALTH_DIR / "snapshot_integrity_report.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
        return report

    files = sorted(base.glob("*_*/snapshot_*.json"), key=lambda p: p.stat().st_mtime, reverse=True)
    rows = [_validate_snapshot_file(fp) for fp in files]
    valid = sum(1 for r in rows if r.get("valid") is True)
    invalid = sum(1 for r in rows if r.get("valid") is not True)
    orphan_dirs = []
    for d in sorted(base.glob("*")):
        if not d.is_dir():
            continue
        if "_" not in d.name:
            orphan_dirs.append(str(d))
            continue
        if list(d.glob("snapshot_*.json")) == []:
            orphan_dirs.append(str(d))
    report = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "valid_snapshots": valid,
        "invalid_snapshots": invalid,
        "files": rows[:200],
        "orphan_snapshot_directories": orphan_dirs,
    }
    config.OUTPUT_HEALTH_DIR.mkdir(parents=True, exist_ok=True)
    (config.OUTPUT_HEALTH_DIR / "snapshot_integrity_report.json").write_text(
        json.dumps(report, indent=2, default=str), encoding="utf-8"
    )
    return report


if __name__ == "__main__":
    print(json.dumps(run(), indent=2, default=str))

