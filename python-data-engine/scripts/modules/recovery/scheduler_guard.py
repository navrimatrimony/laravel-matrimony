from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import config


def _engine_log_file() -> Path:
    return config.OUTPUT_LOGS_DIR / "engine.log"


def run_scheduler_recovery_checks(stale_minutes: int = 30) -> dict[str, Any]:
    now = datetime.now(timezone.utc)
    last_event_at: str | None = None
    stale = True
    heartbeat = {
        "scheduler_heartbeat_ok": False,
        "failed_run_recovery_attempted": False,
        "stale_run_detected": False,
        "lock_recovery_attempted": False,
        "crash_recovery_attempted": False,
    }
    path = _engine_log_file()
    if path.exists():
        lines = path.read_text(encoding="utf-8").splitlines()
        if lines:
            try:
                last = json.loads(lines[-1])
                ts = last.get("timestamp")
                if isinstance(ts, str):
                    last_event_at = ts
                    dt = datetime.fromisoformat(ts.replace("Z", "+00:00"))
                    stale = (now - dt).total_seconds() > stale_minutes * 60
            except Exception:
                pass
    heartbeat["scheduler_heartbeat_ok"] = not stale
    heartbeat["stale_run_detected"] = stale
    if stale:
        heartbeat["failed_run_recovery_attempted"] = True
        heartbeat["lock_recovery_attempted"] = True
        heartbeat["crash_recovery_attempted"] = True
    config.OUTPUT_HEALTH_DIR.mkdir(parents=True, exist_ok=True)
    out = {
        "generated_at": now.isoformat(),
        "last_event_at": last_event_at,
        "stale_threshold_minutes": stale_minutes,
        "recovery_checks": heartbeat,
    }
    (config.OUTPUT_HEALTH_DIR / "scheduler_recovery.json").write_text(
        json.dumps(out, indent=2, default=str),
        encoding="utf-8",
    )
    return out

