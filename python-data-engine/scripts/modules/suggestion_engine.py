"""
Rule-based fix suggestions (counts + messages). No AI.
"""

from __future__ import annotations

from typing import Any

import pymysql

import config
import db
from modules import quality_engine


def dedupe_suggestions_by_type(suggestions: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Keep first suggestion per `type` key."""
    seen: set[Any] = set()
    unique: list[dict[str, Any]] = []
    for s in suggestions:
        if not isinstance(s, dict):
            continue
        key = s.get("type")
        if key not in seen:
            seen.add(key)
            unique.append(s)
    return unique


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


def _column_exists(conn: pymysql.connections.Connection, table: str, column: str) -> bool:
    rows = db.fetch_all(
        conn,
        """
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = %s AND table_name = %s AND column_name = %s LIMIT 1
        """,
        (config.DB_DATABASE, table, column),
    )
    return len(rows) > 0


def count_missing_pincode_candidates(conn: pymysql.connections.Connection) -> int:
    """Rows eligible for pincode backfill (same criteria as fix mode pincode fill)."""
    ft = config.FIX_TABLE
    if not _table_exists(conn, ft):
        return 0
    pin_col = config.FIX_PINCODE_COL
    city_col = config.FIX_CITY_COL
    if not _column_exists(conn, ft, pin_col) or not _column_exists(conn, ft, city_col):
        return 0
    sql = f"""
    SELECT COUNT(*) AS c FROM `{ft}`
    WHERE (`{pin_col}` IS NULL OR TRIM(`{pin_col}`) = '')
      AND `{city_col}` IS NOT NULL AND TRIM(`{city_col}`) <> ''
    """
    try:
        row = db.fetch_all(conn, sql)[0]
        return int(row.get("c", 0))
    except Exception:
        return 0


def run(
    conn: pymysql.connections.Connection,
    duplicates: list,
    validation_errors: list,
    mismatch: list,
    schema_issues: list,
) -> list[dict[str, Any]]:
    suggestions: list[dict[str, Any]] = []

    dup_n = quality_engine.count_duplicate_groups(duplicates)
    if dup_n > 0:
        suggestions.append(
            {
                "type": "duplicate_phone_email",
                "count": dup_n,
                "suggestion": "Merge duplicate users or keep latest",
            }
        )

    missing_pin = count_missing_pincode_candidates(conn)
    if missing_pin > 0:
        suggestions.append(
            {
                "type": "missing_pincode",
                "count": missing_pin,
                "suggestion": "Fill pincode using city mapping",
            }
        )

    mm_n = quality_engine.count_mismatch_rows(mismatch)
    if mm_n > 0:
        suggestions.append(
            {
                "type": "city_mismatch",
                "count": mm_n,
                "suggestion": "Sync city fields across tables",
            }
        )

    schema_n = quality_engine.count_schema_rows(schema_issues)
    if schema_n > 0:
        suggestions.append(
            {
                "type": "sparse_columns",
                "count": schema_n,
                "suggestion": "Review columns with very high NULL rates; improve intake or defaults.",
            }
        )

    val_module_warning = any(
        isinstance(r, dict) and r.get("module") == "data_validator" and r.get("warning")
        for r in validation_errors
    )

    invalid_phone_n = sum(
        1
        for r in validation_errors
        if isinstance(r, dict)
        and r.get("rule") == "invalid_phone"
        and "empty" not in str(r.get("message") or "").lower()
    )
    if invalid_phone_n > 0:
        suggestions.append(
            {
                "type": "invalid_phone_format",
                "count": invalid_phone_n,
                "suggestion": "Normalize mobile numbers to 10 digits at registration or edit.",
            }
        )

    missing_name_n = sum(
        1 for r in validation_errors if isinstance(r, dict) and r.get("rule") == "missing_name"
    )
    if missing_name_n > 0:
        suggestions.append(
            {
                "type": "missing_name",
                "count": missing_name_n,
                "suggestion": "Require name completion during onboarding.",
            }
        )

    if val_module_warning:
        suggestions.append(
            {
                "type": "validation_skipped",
                "count": 1,
                "suggestion": "Users table or columns missing — fix schema before trusting validation.",
            }
        )

    return dedupe_suggestions_by_type(suggestions)
