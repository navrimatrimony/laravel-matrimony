from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import config
import db


def _validate_identifier(name: str) -> str:
    if not name.replace("_", "").isalnum():
        raise ValueError(f"Invalid identifier: {name}")
    return name


def create_manifest(recipe_name: str, table: str, primary_key: str, rows: list[dict[str, Any]]) -> Path:
    config.OUTPUT_ROLLBACK_DIR.mkdir(parents=True, exist_ok=True)
    ts = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
    path = config.OUTPUT_ROLLBACK_DIR / f"rollback_manifest_{recipe_name}_{ts}.json"
    payload = {
        "schema_version": "1",
        "recipe": recipe_name,
        "table": table,
        "primary_key": primary_key,
        "created_at": datetime.now(timezone.utc).isoformat(),
        "rows": rows,
    }
    path.write_text(json.dumps(payload, indent=2, default=str), encoding="utf-8")
    return path


def execute_rollback(conn, manifest_path: Path) -> dict[str, Any]:
    payload = json.loads(manifest_path.read_text(encoding="utf-8"))
    table = _validate_identifier(str(payload["table"]))
    primary_key = _validate_identifier(str(payload["primary_key"]))
    rows = payload.get("rows") if isinstance(payload.get("rows"), list) else []

    restored = 0
    skipped = 0
    for row in rows:
        if not isinstance(row, dict):
            skipped += 1
            continue
        if primary_key not in row:
            skipped += 1
            continue
        rid = row[primary_key]
        cols = [k for k in row.keys() if k != primary_key]
        if not cols:
            skipped += 1
            continue
        set_sql = ", ".join([f"`{_validate_identifier(c)}`=%s" for c in cols])
        sql = f"UPDATE `{table}` SET {set_sql} WHERE `{primary_key}`=%s"
        params = [row[c] for c in cols] + [rid]
        db.execute(conn, sql, tuple(params))
        restored += 1

    return {"restored_rows": restored, "skipped_rows": skipped, "manifest": str(manifest_path)}


def validate_rollback(conn, table: str, primary_key: str, rows: list[dict[str, Any]]) -> dict[str, Any]:
    table = _validate_identifier(table)
    primary_key = _validate_identifier(primary_key)
    mismatches = 0
    checked = 0
    for row in rows:
        if not isinstance(row, dict) or primary_key not in row:
            continue
        rid = row[primary_key]
        res = db.fetch_all(conn, f"SELECT * FROM `{table}` WHERE `{primary_key}`=%s LIMIT 1", (rid,))
        if not res:
            mismatches += 1
            continue
        checked += 1
    return {"validated_rows": checked, "mismatch_rows": mismatches}

