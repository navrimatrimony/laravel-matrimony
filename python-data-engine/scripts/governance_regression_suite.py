from __future__ import annotations

import json
import time
from pathlib import Path
from typing import Any

import config


def _latest(pattern: str, root: Path) -> Path | None:
    files = sorted(root.glob(pattern), key=lambda p: p.stat().st_mtime, reverse=True)
    return files[0] if files else None


def run() -> dict[str, Any]:
    reg_dir = config.OUTPUT_REGRESSION_DIR
    reg_dir.mkdir(parents=True, exist_ok=True)
    golden_snapshot = reg_dir / "golden_snapshot.json"
    golden_comparison = reg_dir / "golden_comparison.json"
    current_snapshot = None
    current_comparison = _latest("snapshot_comparison_*.json", config.OUTPUT_COMPARISONS_DIR)
    if current_comparison is not None:
        c = json.loads(current_comparison.read_text(encoding="utf-8"))
        sp = c.get("snapshot_path")
        if isinstance(sp, str) and Path(sp).exists():
            current_snapshot = Path(sp)

    if current_snapshot and not golden_snapshot.exists():
        golden_snapshot.write_text(current_snapshot.read_text(encoding="utf-8"), encoding="utf-8")
    if current_comparison and not golden_comparison.exists():
        golden_comparison.write_text(current_comparison.read_text(encoding="utf-8"), encoding="utf-8")

    checks: list[dict[str, Any]] = []
    if golden_comparison.exists() and current_comparison is not None:
        g = json.loads(golden_comparison.read_text(encoding="utf-8"))
        c = json.loads(current_comparison.read_text(encoding="utf-8"))
        checks.append(
            {
                "check": "health_score_non_regression",
                "golden": int(g.get("health_score") or 0),
                "current": int(c.get("health_score") or 0),
                "passed": int(c.get("health_score") or 0) >= max(0, int(g.get("health_score") or 0) - 15),
            }
        )
        checks.append(
            {
                "check": "eligible_snapshot_used",
                "golden": g.get("snapshot_id"),
                "current": c.get("snapshot_id"),
                "passed": c.get("snapshot_id") is not None and c.get("error") is None,
            }
        )
    result = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "checks": checks,
        "all_passed": all(bool(x.get("passed")) for x in checks) if checks else False,
    }
    (reg_dir / "governance_regression_report.json").write_text(json.dumps(result, indent=2, default=str), encoding="utf-8")
    return result


if __name__ == "__main__":
    print(json.dumps(run(), indent=2, default=str))

