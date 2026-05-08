"""Timestamped JSON reports and structured change logs."""

from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import config


def _ts_slug() -> str:
    return datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")


def ensure_dirs() -> None:
    config.OUTPUT_REPORTS_DIR.mkdir(parents=True, exist_ok=True)
    config.OUTPUT_LOGS_DIR.mkdir(parents=True, exist_ok=True)
    config.OUTPUT_BACKUPS_DIR.mkdir(parents=True, exist_ok=True)
    config.OUTPUT_COMPARISONS_DIR.mkdir(parents=True, exist_ok=True)
    config.OUTPUT_ADMIN_REPORTS_DIR.mkdir(parents=True, exist_ok=True)
    config.OUTPUT_DASHBOARD_DIR.mkdir(parents=True, exist_ok=True)
    config.OUTPUT_AUDIT_DIR.mkdir(parents=True, exist_ok=True)
    config.OUTPUT_WORKFLOWS_DIR.mkdir(parents=True, exist_ok=True)
    config.OUTPUT_ROLLBACK_DIR.mkdir(parents=True, exist_ok=True)
    config.OUTPUT_HEALTH_DIR.mkdir(parents=True, exist_ok=True)
    config.OUTPUT_COVERAGE_DIR.mkdir(parents=True, exist_ok=True)
    config.OUTPUT_QUARANTINE_DIR.mkdir(parents=True, exist_ok=True)
    config.OUTPUT_REGRESSION_DIR.mkdir(parents=True, exist_ok=True)
    config.OUTPUT_GOVERNANCE_DIR.mkdir(parents=True, exist_ok=True)


def save_report(payload: dict[str, Any], prefix: str = "report") -> Path:
    """Write JSON report under output/reports/."""
    ensure_dirs()
    path = config.OUTPUT_REPORTS_DIR / f"{prefix}_{_ts_slug()}.json"
    path.write_text(json.dumps(payload, indent=2, default=str), encoding="utf-8")
    return path


def save_comparison(payload: dict[str, Any], prefix: str = "comparison") -> Path:
    """Write JSON comparison report under output/comparisons/."""
    ensure_dirs()
    path = config.OUTPUT_COMPARISONS_DIR / f"{prefix}_{_ts_slug()}.json"
    path.write_text(json.dumps(payload, indent=2, default=str), encoding="utf-8")
    return path


def _engine_log_path() -> Path:
    return config.OUTPUT_LOGS_DIR / "engine.log"


def append_engine_log(action: str, details: dict[str, Any] | None = None, level: str = "INFO") -> Path:
    """
    Append JSONL event line to output/logs/engine.log.
    """
    ensure_dirs()
    payload: dict[str, Any] = {
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "level": str(level).upper(),
        "action": action,
    }
    if details:
        payload["details"] = details
    path = _engine_log_path()
    with path.open("a", encoding="utf-8") as fh:
        fh.write(json.dumps(payload, default=str) + "\n")
    return path


def append_change_log(entry: dict[str, Any], session_id: str) -> Path:
    """Append one JSON line per change (fix mode)."""
    ensure_dirs()
    path = config.OUTPUT_LOGS_DIR / f"fix_changes_{session_id}.jsonl"
    line = json.dumps(entry, default=str) + "\n"
    with path.open("a", encoding="utf-8") as fh:
        fh.write(line)
    return path


def start_fix_session_meta(meta: dict[str, Any]) -> tuple[str, Path]:
    session_id = _ts_slug()
    ensure_dirs()
    path = config.OUTPUT_LOGS_DIR / f"fix_session_{session_id}.json"
    path.write_text(json.dumps(meta, indent=2, default=str), encoding="utf-8")
    return session_id, path
