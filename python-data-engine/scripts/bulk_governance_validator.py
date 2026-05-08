from __future__ import annotations

import json
import time
from typing import Any

import config
import db
from modules import snapshot_comparison_engine


def run(limit: int = 120) -> dict[str, Any]:
    with db.connection_ctx() as conn:
        rows = db.fetch_all(
            conn,
            f"SELECT id FROM `{config.PROFILE_TABLE}` ORDER BY id DESC LIMIT %s",
            (max(10, min(500, int(limit))),),
        )
    profile_ids = [int(r.get("id")) for r in rows if r.get("id") is not None]
    summary: list[dict[str, Any]] = []
    healthy = 0
    unreliable = 0
    for pid in profile_ids:
        cmp = snapshot_comparison_engine.run(profile_id=pid, latest=True)
        rel = cmp.get("reliability") if isinstance(cmp.get("reliability"), dict) else {}
        is_reliable = rel.get("reliability_status") == "ok"
        if is_reliable:
            healthy += 1
        else:
            unreliable += 1
        summary.append(
            {
                "profile_id": pid,
                "health_score": int(cmp.get("health_score") or 0),
                "reliability_status": rel.get("reliability_status", "comparison reliability insufficient"),
                "mismatch_count": int((cmp.get("summary") or {}).get("mismatch_count") or 0),
                "error": cmp.get("error"),
            }
        )
    out: dict[str, Any] = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "profiles_scanned": len(summary),
        "reliable_profiles": healthy,
        "unreliable_profiles": unreliable,
        "rows": summary[:500],
    }
    config.OUTPUT_HEALTH_DIR.mkdir(parents=True, exist_ok=True)
    (config.OUTPUT_HEALTH_DIR / "bulk_governance_summary.json").write_text(json.dumps(out, indent=2, default=str), encoding="utf-8")
    return out


if __name__ == "__main__":
    print(json.dumps(run(), indent=2, default=str))

