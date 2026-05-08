from __future__ import annotations

import json
import time
from pathlib import Path
from typing import Any

import config


def _latest_two(profile_id: int | None = None) -> tuple[Path | None, Path | None]:
    files = sorted(config.SNAPSHOT_BASE_DIR.glob("*_*/snapshot_*.json"), key=lambda p: p.stat().st_mtime, reverse=True)
    if profile_id is not None:
        filtered = []
        for f in files:
            try:
                p = json.loads(f.read_text(encoding="utf-8"))
            except Exception:
                continue
            if int(p.get("entity_id") or p.get("profile_id") or 0) == int(profile_id):
                filtered.append(f)
        files = filtered
    return (files[0], files[1]) if len(files) > 1 else (files[0], None) if files else (None, None)


def run(profile_id: int | None = None) -> dict[str, Any]:
    newer, older = _latest_two(profile_id)
    if newer is None or older is None:
        out = {
            "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "status": "insufficient_snapshots",
            "newer_snapshot": str(newer) if newer is not None else None,
            "older_snapshot": None,
            "db_diffs": [],
            "relation_diffs": [],
        }
        (config.OUTPUT_HEALTH_DIR / "snapshot_diff_explorer.json").write_text(json.dumps(out, indent=2, default=str), encoding="utf-8")
        return out
    new_p = json.loads(newer.read_text(encoding="utf-8"))
    old_p = json.loads(older.read_text(encoding="utf-8"))
    db_new = new_p.get("db") if isinstance(new_p.get("db"), dict) else {}
    db_old = old_p.get("db") if isinstance(old_p.get("db"), dict) else {}
    changed = []
    for k in sorted(set(db_new.keys()) | set(db_old.keys())):
        if db_new.get(k) != db_old.get(k):
            changed.append({"field": k, "older": db_old.get(k), "newer": db_new.get(k)})
    reps_new = new_p.get("repeaters") if isinstance(new_p.get("repeaters"), dict) else {}
    reps_old = old_p.get("repeaters") if isinstance(old_p.get("repeaters"), dict) else {}
    rel_changes = []
    for rk in sorted(set(reps_new.keys()) | set(reps_old.keys())):
        n = reps_new.get(rk) if isinstance(reps_new.get(rk), list) else []
        o = reps_old.get(rk) if isinstance(reps_old.get(rk), list) else []
        if len(n) != len(o):
            rel_changes.append({"repeater": rk, "older_count": len(o), "newer_count": len(n)})
    out = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "newer_snapshot": str(newer),
        "older_snapshot": str(older),
        "db_diffs": changed[:500],
        "relation_diffs": rel_changes,
    }
    (config.OUTPUT_HEALTH_DIR / "snapshot_diff_explorer.json").write_text(json.dumps(out, indent=2, default=str), encoding="utf-8")
    return out

