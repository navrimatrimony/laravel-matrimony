from __future__ import annotations

import json
import time
from pathlib import Path
from typing import Any

import config


def _read(path: Path) -> dict[str, Any]:
    if not path.exists():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def run() -> dict[str, Any]:
    inv = _read(config.OUTPUT_GOVERNANCE_DIR / "full_field_inventory.json")
    snap = _read(config.OUTPUT_HEALTH_DIR / "snapshot_integrity_report.json")
    coverage = _read(config.OUTPUT_COVERAGE_DIR / "full_profile_coverage.json")
    ui_surface = {
        "admin_data_engine_index": (config.ENGINE_ROOT.parent / "resources" / "views" / "admin" / "data-engine" / "index.blade.php").exists(),
        "admin_profile_governance": (config.ENGINE_ROOT.parent / "resources" / "views" / "admin" / "governance" / "profile.blade.php").exists(),
    }
    repeater_report = {
        "repeater_mismatches": _read(config.OUTPUT_COVERAGE_DIR / "repeater_mismatches.json"),
        "repeater_fields_count": int((inv.get("entities") or {}).get("matrimony_profile", {}).get("repeater_fields", 0) if isinstance(inv.get("entities"), dict) else 0),
    }
    profile_issue_summary = {
        "latest_comparison_exists": any(config.OUTPUT_COMPARISONS_DIR.glob("snapshot_comparison_*.json")),
        "latest_dashboard_exists": any(config.OUTPUT_DASHBOARD_DIR.glob("dashboard_payload_*.json")),
    }
    out: dict[str, Any] = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "runtime_truth": {
            "inventory_generated": bool(inv),
            "snapshot_integrity_generated": bool(snap),
            "coverage_generated": bool(coverage),
        },
        "inventory_summary": inv.get("runtime_verification", {}),
        "coverage_summary": coverage.get("totals", {}),
    }
    config.OUTPUT_GOVERNANCE_DIR.mkdir(parents=True, exist_ok=True)
    (config.OUTPUT_GOVERNANCE_DIR / "governance_runtime_truth_report.json").write_text(json.dumps(out, indent=2, default=str), encoding="utf-8")
    (config.OUTPUT_GOVERNANCE_DIR / "snapshot_integrity_report.json").write_text(json.dumps(snap, indent=2, default=str), encoding="utf-8")
    (config.OUTPUT_GOVERNANCE_DIR / "repeater_coverage_report.json").write_text(json.dumps(repeater_report, indent=2, default=str), encoding="utf-8")
    (config.OUTPUT_GOVERNANCE_DIR / "governance_ui_surface_report.json").write_text(json.dumps(ui_surface, indent=2, default=str), encoding="utf-8")
    (config.OUTPUT_GOVERNANCE_DIR / "profile_issue_summary_report.json").write_text(json.dumps(profile_issue_summary, indent=2, default=str), encoding="utf-8")
    return out


if __name__ == "__main__":
    print(json.dumps(run(), indent=2, default=str))

