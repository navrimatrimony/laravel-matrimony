#!/usr/bin/env python3
"""Emit config/governance/canonical_field_registry.json (deterministic, Phase-6A)."""

from __future__ import annotations

import json
import sys
import time
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
SCRIPTS_ROOT = SCRIPT_DIR.parent
ENGINE_ROOT = SCRIPTS_ROOT.parent
OUT_PATH = ENGINE_ROOT / "config" / "governance" / "canonical_field_registry.json"

if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))


def main() -> Path:
    from governance.canonical_registry_spec import canonical_entries_for_export

    entries = canonical_entries_for_export()
    governed = [e for e in entries if e.get("governed") is True]
    scalars = [e for e in governed if e.get("repeater") is not True]
    repeaters = [e for e in governed if e.get("repeater") is True]
    cmp_sup = [e for e in governed if e.get("comparison_supported") is True]
    payload = {
        "meta": {
            "schema": "canonical_field_registry_v1",
            "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "governed_logical_field_count": len(governed),
            "governed_scalar_count": len(scalars),
            "governed_repeater_count": len(repeaters),
            "comparison_supported_count": len(cmp_sup),
        },
        "entries": entries,
    }
    OUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    OUT_PATH.write_text(json.dumps(payload, indent=2, sort_keys=False, ensure_ascii=False) + "\n", encoding="utf-8")
    return OUT_PATH


if __name__ == "__main__":
    p = main()
    print(json.dumps({"canonical_registry_written": str(p)}, indent=2))
