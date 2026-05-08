from __future__ import annotations

import json
import re
import time
from pathlib import Path
from typing import Any

import config


def _normalize(name: str) -> str:
    n = name.strip()
    n = re.sub(r"\[\d+\]", "[]", n)
    n = re.sub(r"\[\{\{[^}]+\}\}\]", "[]", n)
    n = n.replace("->", ".")
    return n


def _scan_wizard_inputs() -> list[str]:
    root = config.ENGINE_ROOT.parent / "resources" / "views" / "matrimony" / "profile" / "wizard"
    if not root.exists():
        return []
    fields: set[str] = set()
    for fp in root.rglob("*.blade.php"):
        text = fp.read_text(encoding="utf-8", errors="ignore")
        for m in re.findall(r'name\s*=\s*"([^"]+)"', text, flags=re.IGNORECASE):
            x = _normalize(m)
            if x != "":
                fields.add(x)
        for m in re.findall(r"wire:model(?:\.defer|\.lazy)?\s*=\s*\"([^\"]+)\"", text, flags=re.IGNORECASE):
            x = _normalize(m)
            if x != "":
                fields.add(x)
    return sorted(fields)


def _latest_snapshot() -> dict[str, Any]:
    files = sorted(config.SNAPSHOT_BASE_DIR.glob("*_*/snapshot_*.json"), key=lambda p: p.stat().st_mtime, reverse=True)
    if not files:
        return {}
    try:
        return json.loads(files[0].read_text(encoding="utf-8"))
    except Exception:
        return {}


def run() -> dict[str, Any]:
    wizard_fields = _scan_wizard_inputs()
    snap = _latest_snapshot()
    db = snap.get("db") if isinstance(snap.get("db"), dict) else {}
    reps = snap.get("repeaters") if isinstance(snap.get("repeaters"), dict) else {}
    rendered = snap.get("rendered") if isinstance(snap.get("rendered"), dict) else {}
    rendered_fields = rendered.get("fields") if isinstance(rendered.get("fields"), dict) else {}
    api = snap.get("api") if isinstance(snap.get("api"), dict) else {}
    api_profile = api.get("profile") if isinstance(api.get("profile"), dict) else {}

    repeater_fields: list[str] = []
    for k, rows in reps.items():
        if not isinstance(rows, list):
            continue
        if rows and isinstance(rows[0], dict):
            for col in rows[0].keys():
                repeater_fields.append(f"{k}[].{col}")
        else:
            repeater_fields.append(f"{k}[]")
    scalar_fields = sorted(str(k) for k in db.keys())
    nested_fields = sorted([f for f in scalar_fields if isinstance(db.get(f), dict)])
    governed = set(scalar_fields) | set(repeater_fields) | set(str(k) for k in rendered_fields.keys())
    unsupported = sorted([f for f in wizard_fields if f not in governed])

    entities = {
        "matrimony_profile": {
            "wizard_fields": len(wizard_fields),
            "db_fields": len(scalar_fields),
            "repeater_fields": len(repeater_fields),
            "api_fields": len(api_profile.keys()),
            "rendered_fields": len(rendered_fields.keys()),
            "governed_fields": len(governed),
            "unsupported_fields": len(unsupported),
        }
    }
    payload: dict[str, Any] = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "total_fields": len(sorted(governed)),
        "scalar_fields": scalar_fields,
        "repeater_fields": sorted(repeater_fields),
        "nested_fields": nested_fields,
        "unsupported_fields": unsupported,
        "field_paths": sorted(governed),
        "entities": entities,
        "runtime_verification": {
            "total_wizard_fields": len(wizard_fields),
            "total_governed_fields": len(governed),
            "unsupported_field_count": len(unsupported),
            "comparison_supported_count": max(0, len(wizard_fields) - len(unsupported)),
        },
    }

    config.OUTPUT_GOVERNANCE_DIR.mkdir(parents=True, exist_ok=True)
    (config.OUTPUT_GOVERNANCE_DIR / "full_field_inventory.json").write_text(json.dumps(payload, indent=2, default=str), encoding="utf-8")
    (config.OUTPUT_GOVERNANCE_DIR / "field_inventory_report.json").write_text(json.dumps(payload, indent=2, default=str), encoding="utf-8")
    return payload


if __name__ == "__main__":
    print(json.dumps(run(), indent=2, default=str))

