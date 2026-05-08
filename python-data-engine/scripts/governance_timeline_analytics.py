from __future__ import annotations

import json
import time
from collections import defaultdict
from pathlib import Path
from typing import Any

import config


def run() -> dict[str, Any]:
    files = sorted(config.OUTPUT_COMPARISONS_DIR.glob("snapshot_comparison_*.json"), key=lambda p: p.stat().st_mtime)
    by_field: dict[str, list[str]] = defaultdict(list)
    module_pressure: dict[str, int] = defaultdict(int)
    for fp in files:
        try:
            payload = json.loads(fp.read_text(encoding="utf-8"))
        except Exception:
            continue
        ts = str(payload.get("generated_at") or time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(fp.stat().st_mtime)))
        for row in payload.get("comparisons", []):
            if not isinstance(row, dict) or row.get("status") != "fail":
                continue
            field = str(row.get("field") or "unknown")
            by_field[field].append(ts)
            ctype = str(row.get("comparison_type") or "unknown")
            module_pressure[ctype] += 1
    timeline_rows = []
    for field, stamps in by_field.items():
        timeline_rows.append(
            {
                "field": field,
                "first_seen": min(stamps),
                "last_seen": max(stamps),
                "occurrences": len(stamps),
            }
        )
    timeline_rows.sort(key=lambda r: r["occurrences"], reverse=True)
    out: dict[str, Any] = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "drift_timeline": timeline_rows[:300],
        "worsening_modules": sorted(
            [{"module": k, "failures": v} for k, v in module_pressure.items()],
            key=lambda x: x["failures"],
            reverse=True,
        ),
        "deployment_correlation_note": "Use generated_at timeline against deploy logs for exact correlation.",
    }
    config.OUTPUT_HEALTH_DIR.mkdir(parents=True, exist_ok=True)
    (config.OUTPUT_HEALTH_DIR / "governance_timeline_analytics.json").write_text(json.dumps(out, indent=2), encoding="utf-8")
    return out


if __name__ == "__main__":
    print(json.dumps(run(), indent=2))

