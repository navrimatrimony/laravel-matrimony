from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

try:
    import yaml
except ModuleNotFoundError:  # pragma: no cover
    yaml = None  # type: ignore[assignment]

import config
import db
from modules.rollback.engine import create_manifest, execute_rollback, validate_rollback


def _load_recipe(recipe_name: str) -> dict[str, Any]:
    path = config.RECIPES_DIR / f"{recipe_name}.yaml"
    if not path.exists():
        raise FileNotFoundError(f"Recipe not found: {path}")
    raw = path.read_text(encoding="utf-8")
    if yaml is not None:
        data = yaml.safe_load(raw)
    else:
        data = _simple_yaml_parse(raw)
    if not isinstance(data, dict):
        raise ValueError("Recipe must be a mapping.")
    return data


def _simple_yaml_parse(raw: str) -> dict[str, Any]:
    out: dict[str, Any] = {}
    for line in raw.splitlines():
        txt = line.strip()
        if txt == "" or txt.startswith("#"):
            continue
        if ":" not in txt:
            continue
        k, v = txt.split(":", 1)
        key = k.strip()
        val = v.strip().strip('"').strip("'")
        if val.lower() in {"true", "false"}:
            out[key] = val.lower() == "true"
            continue
        try:
            if "." in val:
                out[key] = float(val)
            else:
                out[key] = int(val)
            continue
        except Exception:
            pass
        out[key] = val
    return out


def _query_rows(conn, sql: str, limit: int | None = None) -> list[dict[str, Any]]:
    final_sql = sql
    params: tuple[Any, ...] = ()
    if limit is not None:
        final_sql = f"{sql} LIMIT %s"
        params = (int(limit),)
    return db.fetch_all(conn, final_sql, params)


def _audit_path(recipe_name: str) -> Path:
    config.OUTPUT_AUDIT_DIR.mkdir(parents=True, exist_ok=True)
    ts = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
    return config.OUTPUT_AUDIT_DIR / f"auto_fix_audit_{recipe_name}_{ts}.json"


def run_recipe_pipeline(conn, recipe_name: str, execute: bool = False, preview_limit: int = 25) -> dict[str, Any]:
    recipe = _load_recipe(recipe_name)
    table = str(recipe.get("table") or "")
    primary_key = str(recipe.get("primary_key") or "id")
    detection_sql = str(recipe.get("detection_sql") or "")
    preview_sql = str(recipe.get("preview_sql") or detection_sql)
    fix_sql = str(recipe.get("fix_sql") or "")
    validation_sql = str(recipe.get("validation_sql") or "")

    if not detection_sql:
        raise ValueError("Recipe missing detection_sql")

    detected = _query_rows(conn, detection_sql)
    preview_rows = _query_rows(conn, preview_sql, limit=preview_limit)
    confidence = float(recipe.get("confidence", 0.7))
    risk = str(recipe.get("risk", "medium"))

    result: dict[str, Any] = {
        "recipe": recipe_name,
        "workflow": "detect->preview->backup->execute->validate->rollback",
        "detected_count": len(detected),
        "preview": {
            "rows": preview_rows,
            "estimated_changes": len(detected),
            "confidence": confidence,
            "risk": risk,
            "rollback_available": bool(table and primary_key),
        },
        "dry_run": not execute,
        "backup_manifest": None,
        "execute_summary": None,
        "validation": None,
        "rollback": None,
        "status": "preview_ready",
    }

    if not execute:
        _audit_path(recipe_name).write_text(json.dumps(result, indent=2, default=str), encoding="utf-8")
        return result

    manifest_path: Path | None = None
    if table and primary_key:
        manifest_path = create_manifest(recipe_name, table, primary_key, detected)
        result["backup_manifest"] = str(manifest_path)

    if not fix_sql:
        result["status"] = "failed"
        result["execute_summary"] = {"error": "Recipe missing fix_sql"}
        _audit_path(recipe_name).write_text(json.dumps(result, indent=2, default=str), encoding="utf-8")
        return result

    conn.begin()
    try:
        affected = db.execute(conn, fix_sql)
        result["execute_summary"] = {"affected_rows": int(affected)}
        if validation_sql:
            validation_rows = _query_rows(conn, validation_sql)
            validation = {
                "remaining_problem_rows": len(validation_rows),
                "passed": len(validation_rows) == 0,
            }
            result["validation"] = validation
            if not validation["passed"]:
                conn.rollback()
                rollback_payload = {"performed": False}
                if manifest_path is not None:
                    conn.begin()
                    rollback_exec = execute_rollback(conn, manifest_path)
                    rollback_check = validate_rollback(conn, table, primary_key, detected)
                    conn.commit()
                    rollback_payload = {
                        "performed": True,
                        "execution": rollback_exec,
                        "validation": rollback_check,
                    }
                result["rollback"] = rollback_payload
                result["status"] = "rolled_back"
                _audit_path(recipe_name).write_text(json.dumps(result, indent=2, default=str), encoding="utf-8")
                return result
        conn.commit()
    except Exception as exc:
        conn.rollback()
        result["status"] = "failed"
        result["execute_summary"] = {"error": str(exc)}
        if manifest_path is not None:
            conn.begin()
            rollback_exec = execute_rollback(conn, manifest_path)
            rollback_check = validate_rollback(conn, table, primary_key, detected)
            conn.commit()
            result["rollback"] = {
                "performed": True,
                "execution": rollback_exec,
                "validation": rollback_check,
            }
            result["status"] = "rolled_back"
        _audit_path(recipe_name).write_text(json.dumps(result, indent=2, default=str), encoding="utf-8")
        return result

    result["status"] = "validated" if validation_sql else "running"
    _audit_path(recipe_name).write_text(json.dumps(result, indent=2, default=str), encoding="utf-8")
    return result

