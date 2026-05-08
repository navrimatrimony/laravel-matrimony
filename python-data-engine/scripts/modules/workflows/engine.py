from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import config

VALID_STATES = {
    "detected",
    "reviewed",
    "approved",
    "running",
    "validated",
    "failed",
    "rolled_back",
}


def _workflow_file() -> Path:
    config.OUTPUT_WORKFLOWS_DIR.mkdir(parents=True, exist_ok=True)
    return config.OUTPUT_WORKFLOWS_DIR / "workflow_state.json"


def _load() -> dict[str, Any]:
    path = _workflow_file()
    if not path.exists():
        return {"schema_version": "1", "actions": []}
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return {"schema_version": "1", "actions": []}


def update_workflow_state(action_id: str, state: str, details: dict[str, Any] | None = None) -> dict[str, Any]:
    if state not in VALID_STATES:
        raise ValueError(f"Invalid workflow state: {state}")
    payload = _load()
    actions = payload.get("actions")
    if not isinstance(actions, list):
        actions = []
        payload["actions"] = actions
    row = {
        "action_id": action_id,
        "state": state,
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "details": details or {},
    }
    actions.append(row)
    _workflow_file().write_text(json.dumps(payload, indent=2, default=str), encoding="utf-8")
    return row

