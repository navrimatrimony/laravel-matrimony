from __future__ import annotations

import json
import time
from pathlib import Path
from typing import Any

import config


ENTITY_FILES = ["customer.yml", "order.yml", "matrimony_profiles.yml", "lead.yml", "payment.yml", "subscription.yml"]


def run() -> dict[str, Any]:
    cfg = config.ENGINE_ROOT / "config" / "entities"
    rows = []
    for name in ENTITY_FILES:
        p = cfg / name
        rows.append({"entity_config": name, "present": p.exists()})
    out: dict[str, Any] = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "multi_entity_reuse": rows,
        "note": "Missing config files can be added without changing governance core architecture.",
    }
    (config.OUTPUT_HEALTH_DIR / "multi_entity_validation_report.json").write_text(json.dumps(out, indent=2), encoding="utf-8")
    return out

