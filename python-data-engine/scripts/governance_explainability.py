from __future__ import annotations

import json
import time
from pathlib import Path
from typing import Any

import config


def run() -> dict[str, Any]:
    files = sorted(config.OUTPUT_COMPARISONS_DIR.glob("snapshot_comparison_*.json"), key=lambda p: p.stat().st_mtime, reverse=True)
    if not files:
        return {"generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), "error": "comparison_not_found"}
    cmp_payload = json.loads(files[0].read_text(encoding="utf-8"))
    rows = []
    for row in cmp_payload.get("comparisons", []):
        if not isinstance(row, dict) or row.get("status") != "fail":
            continue
        rows.append(
            {
                "field": row.get("field"),
                "evidence_chain": {"db": row.get("db"), "api": row.get("api"), "rendered": row.get("rendered")},
                "source_layer": row.get("source_layer"),
                "normalization_path": row.get("comparison_type"),
                "drift_cause": row.get("root_cause_hint", "unknown"),
                "repair_recommendation": "Run deterministic-repair preview and approve if confidence is high.",
            }
        )
    out: dict[str, Any] = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "comparison_file": files[0].name,
        "mismatch_explainability": rows[:500],
    }
    (config.OUTPUT_HEALTH_DIR / "governance_explainability_report.json").write_text(json.dumps(out, indent=2, default=str), encoding="utf-8")
    return out

