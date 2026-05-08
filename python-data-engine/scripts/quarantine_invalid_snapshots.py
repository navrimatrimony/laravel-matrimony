from __future__ import annotations

import json
import shutil
import time
from pathlib import Path
from typing import Any

import config
from modules import snapshot_comparison_engine


def run(retention_days: int = 30, dry_run: bool = True) -> dict[str, Any]:
    base = config.SNAPSHOT_BASE_DIR
    quarantine_root = config.OUTPUT_QUARANTINE_DIR / "snapshots"
    quarantine_root.mkdir(parents=True, exist_ok=True)
    moved: list[str] = []
    kept_valid: list[str] = []

    if not base.exists() or not base.is_dir():
        report = {
            "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "status": "snapshot_base_not_found",
            "moved_count": 0,
            "kept_valid_count": 0,
            "dry_run": dry_run,
        }
        (config.OUTPUT_HEALTH_DIR / "invalid_snapshot_quarantine_report.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
        return report

    for folder in sorted(base.glob("*_*")):
        if not folder.is_dir():
            continue
        for snap in sorted(folder.glob("snapshot_*.json"), key=lambda p: p.stat().st_mtime):
            eligible = snapshot_comparison_engine._is_comparison_eligible_snapshot(snap, None)
            if eligible:
                kept_valid.append(str(snap))
                continue
            relative = snap.relative_to(base)
            target = quarantine_root / relative
            if not dry_run:
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.move(str(snap), str(target))
            moved.append(str(snap))

    # Retention cleanup only inside quarantine.
    retention_cutoff = time.time() - (max(1, retention_days) * 86400)
    pruned: list[str] = []
    for snap in sorted(quarantine_root.glob("*_*/snapshot_*.json")):
        if snap.stat().st_mtime >= retention_cutoff:
            continue
        if not dry_run:
            snap.unlink(missing_ok=True)
        pruned.append(str(snap))

    report = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "dry_run": dry_run,
        "retention_days": retention_days,
        "moved_count": len(moved),
        "moved_invalid_snapshots": moved[:500],
        "kept_valid_count": len(kept_valid),
        "pruned_quarantine_count": len(pruned),
        "pruned_quarantine_snapshots": pruned[:500],
    }
    config.OUTPUT_HEALTH_DIR.mkdir(parents=True, exist_ok=True)
    (config.OUTPUT_HEALTH_DIR / "invalid_snapshot_quarantine_report.json").write_text(
        json.dumps(report, indent=2, default=str), encoding="utf-8"
    )
    return report


if __name__ == "__main__":
    print(json.dumps(run(dry_run=False), indent=2, default=str))

