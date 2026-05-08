"""
Data integrity (PHASE-1): structural checks only — no Blade/AST parsing.

1) CORE field_registry.field_key must exist as a column on matrimony_profiles
   (MutationService writes core keys to profile columns; gaps = high risk).

2) Semantic duplicate groups (config/data_integrity.json): flag tables where
   multiple columns from the same conceptual group exist — architectural debt /
   possible dual sources of truth.

Wizard ↔ public profile parity requires PHASE-2 (view manifest or static scan).
"""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

import pymysql

import config
import db

ENGINE_ROOT = Path(__file__).resolve().parent.parent.parent
INTEGRITY_CONFIG_PATH = ENGINE_ROOT / "config" / "data_integrity.json"


def _table_exists(conn: pymysql.connections.Connection, table: str) -> bool:
    rows = db.fetch_all(
        conn,
        """
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = %s AND table_name = %s LIMIT 1
        """,
        (config.DB_DATABASE, table),
    )
    return len(rows) > 0


def _list_columns(conn: pymysql.connections.Connection, table: str) -> set[str]:
    rows = db.fetch_all(
        conn,
        """
        SELECT column_name AS c
        FROM information_schema.columns
        WHERE table_schema = %s AND table_name = %s
        """,
        (config.DB_DATABASE, table),
    )
    return {str(r["c"]) for r in rows}


def _load_integrity_config() -> dict[str, Any]:
    if not INTEGRITY_CONFIG_PATH.is_file():
        return {
            "semantic_groups": [],
            "matrimony_profiles_system_columns": [],
            "core_field_storage": {},
            "roadmap": [],
        }
    try:
        raw = INTEGRITY_CONFIG_PATH.read_text(encoding="utf-8")
        data = json.loads(raw)
        return data if isinstance(data, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {
            "semantic_groups": [],
            "matrimony_profiles_system_columns": [],
            "core_field_storage": {},
            "roadmap": [],
        }


def _fetch_core_registry_keys(conn: pymysql.connections.Connection) -> list[str]:
    if not _table_exists(conn, "field_registry"):
        return []
    rows = db.fetch_all(
        conn,
        """
        SELECT field_key
        FROM field_registry
        WHERE field_type = 'CORE'
          AND COALESCE(is_archived, 0) = 0
        ORDER BY field_key
        """,
    )
    return [str(r["field_key"]) for r in rows if r.get("field_key")]


def _health_score(missing_for_registry: int, semantic_warnings: int) -> int:
    score = 100
    score -= min(50, missing_for_registry * 5)
    score -= min(30, semantic_warnings * 3)
    return max(0, min(100, score))


def analyze(conn: pymysql.connections.Connection) -> dict[str, Any]:
    cfg = _load_integrity_config()
    profile_table = config.PROFILE_TABLE
    users_table = config.USERS_TABLE

    missing_columns: list[dict[str, Any]] = []
    registry_core_count = 0

    profile_cols: set[str] = set()
    user_cols: set[str] = set()

    if _table_exists(conn, profile_table):
        profile_cols = _list_columns(conn, profile_table)
    if _table_exists(conn, users_table):
        user_cols = _list_columns(conn, users_table)

    core_keys = _fetch_core_registry_keys(conn)
    registry_core_count = len(core_keys)
    storage_cfg = cfg.get("core_field_storage") if isinstance(cfg.get("core_field_storage"), dict) else {}
    resolved_aliases: list[dict[str, Any]] = []
    resolved_virtual: list[dict[str, Any]] = []

    for fk in core_keys:
        meta = storage_cfg.get(fk) if isinstance(storage_cfg.get(fk), dict) else {}
        if bool(meta.get("virtual")):
            resolved_virtual.append(
                {
                    "field_key": fk,
                    "storage_type": str(meta.get("storage_type") or "virtual"),
                    "computed": bool(meta.get("computed")),
                    "json_path": meta.get("json_path"),
                    "detail": str(meta.get("detail") or ""),
                }
            )
            continue

        target_column = str(meta.get("target_column") or fk)
        alias_candidates = [target_column]
        aliases = meta.get("aliases")
        if isinstance(aliases, list):
            for alias in aliases:
                a = str(alias).strip()
                if a and a not in alias_candidates:
                    alias_candidates.append(a)

        existing_column = next((c for c in alias_candidates if c in profile_cols), None)
        if existing_column is not None:
            if existing_column != fk:
                resolved_aliases.append(
                    {
                        "field_key": fk,
                        "storage_type": str(meta.get("storage_type") or "column_alias"),
                        "target_column": target_column,
                        "resolved_column": existing_column,
                    }
                )
            continue

        if fk not in profile_cols:
            missing_columns.append(
                {
                    "field_key": fk,
                    "expected_table": profile_table,
                    "missing_db_column": target_column,
                    "severity": "high",
                    "detail": "CORE registry key has no matching column on matrimony_profiles — MutationService cannot persist this field as a core column.",
                }
            )

    semantic_hits: list[dict[str, Any]] = []
    groups = cfg.get("semantic_groups") if isinstance(cfg.get("semantic_groups"), list) else []
    for grp in groups:
        if not isinstance(grp, dict):
            continue
        gid = str(grp.get("id") or "")
        label = str(grp.get("label") or gid)
        cols = grp.get("columns") if isinstance(grp.get("columns"), list) else []
        tables = grp.get("tables") if isinstance(grp.get("tables"), list) else [profile_table]
        present: list[str] = []
        for tname in tables:
            tname = str(tname).strip()
            if not tname or not _table_exists(conn, tname):
                continue
            tcols = _list_columns(conn, tname)
            for c in cols:
                c = str(c).strip()
                if c and c in tcols:
                    present.append(f"{tname}.{c}")
                    tbl_used = tname
        if len(present) >= 2:
            semantic_hits.append(
                {
                    "group_id": gid,
                    "label": label,
                    "severity": "medium",
                    "columns_defined": present,
                    "note": "Multiple columns in the same semantic group exist — ensure only one is canonical for writes/display.",
                }
            )

    sys_cols = cfg.get("matrimony_profiles_system_columns")
    system_set = set(sys_cols) if isinstance(sys_cols, list) else set()

    orphan_candidates: list[str] = []
    if profile_cols and core_keys:
        reg_set = set(core_keys)
        for col in sorted(profile_cols):
            if col in reg_set or col in system_set:
                continue
            if col.endswith("_id") or col.endswith("_at"):
                continue
            orphan_candidates.append(col)

    score = _health_score(len(missing_columns), len(semantic_hits))

    return {
        "summary": {
            "health_score": score,
            "registry_core_keys": registry_core_count,
            "missing_columns_for_core_registry": len(missing_columns),
            "resolved_alias_columns": len(resolved_aliases),
            "virtual_core_fields": len(resolved_virtual),
            "semantic_duplicate_group_warnings": len(semantic_hits),
            "profile_table_columns_observed": len(profile_cols),
            "orphan_profile_columns_sample": orphan_candidates[:40],
            "orphan_profile_columns_total_estimate": len(orphan_candidates),
        },
        "registry_vs_profile": {
            "missing_columns": missing_columns,
            "resolved_aliases": resolved_aliases,
            "resolved_virtual": resolved_virtual,
        },
        "semantic_groups_triggered": semantic_hits,
        "implementation": {
            "phase": 1,
            "scope": "database_schema + field_registry + semantic_groups config",
            "not_in_scope_yet": [
                "Onboarding/intake → DB request-field mapping (needs Laravel route/controller manifest)",
                "Wizard blade vs DB read source (needs view manifest or AST)",
                "Wizard save vs public profile display (needs shared display contract + sampling)",
            ],
        },
        "roadmap": cfg.get("roadmap") if isinstance(cfg.get("roadmap"), list) else [],
    }
