"""
Analyze column null ratios using information_schema + per-column counts.

Flags columns where NULL fraction exceeds ENGINE_SCHEMA_NULL_THRESHOLD (default 0.9).
"""

from __future__ import annotations

import pymysql

import config
import db


def _suggest_missing_indexes(
    conn: pymysql.connections.Connection, table: str, indexed_lower: set[str]
) -> list[dict]:
    """
    Heuristic: common filter / join columns should be indexed.
    Uses USERS_TABLE phone/email when table matches.
    """
    suggestions: list[dict] = []
    if table != config.USERS_TABLE:
        return suggestions

    candidates: list[tuple[str, str]] = [
        (config.PHONE_COL, "phone/mobile"),
        (config.EMAIL_COL, "email"),
    ]
    cols_present = set(_list_columns(conn, table))

    for col, label in candidates:
        if not col or col not in cols_present:
            continue
        if col.lower() in indexed_lower:
            continue
        suggestions.append(
            {
                "table": table,
                "column": col,
                "kind": "missing_index",
                "suggestion": f"Add index on column `{col}` ({label} lookups)",
            }
        )

    return suggestions


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


def _columns_with_any_index(conn: pymysql.connections.Connection, table: str) -> set[str]:
    rows = db.fetch_all(
        conn,
        """
        SELECT DISTINCT column_name AS c
        FROM information_schema.statistics
        WHERE table_schema = %s AND table_name = %s
        """,
        (config.DB_DATABASE, table),
    )
    return {str(r["c"]).lower() for r in rows}


def _list_columns(conn: pymysql.connections.Connection, table: str) -> list[str]:
    rows = db.fetch_all(
        conn,
        """
        SELECT column_name AS c
        FROM information_schema.columns
        WHERE table_schema = %s AND table_name = %s
        ORDER BY ordinal_position
        """,
        (config.DB_DATABASE, table),
    )
    return [str(r["c"]) for r in rows]


def run(conn: pymysql.connections.Connection) -> list[dict]:
    issues: list[dict] = []

    for table in config.SCHEMA_TABLES:
        if not _table_exists(conn, table):
            issues.append(
                {
                    "table": table,
                    "warning": "table_not_found",
                }
            )
            continue

        indexed_lower = _columns_with_any_index(conn, table)
        issues.extend(_suggest_missing_indexes(conn, table, indexed_lower))

        cols = _list_columns(conn, table)
        total_rows = db.fetch_all(conn, f"SELECT COUNT(*) AS c FROM `{table}`")[0]["c"]
        total = int(total_rows)
        if total == 0:
            issues.append({"table": table, "warning": "empty_table"})
            continue

        for col in cols:
            # COUNT(*) - COUNT(col) counts rows where col IS NULL (MySQL)
            row = db.fetch_all(
                conn,
                f"SELECT COUNT(*) AS total, SUM(`{col}` IS NULL) AS nulls FROM `{table}`",
            )[0]
            nulls = int(row["nulls"] or 0)
            ratio = nulls / total if total else 0.0
            if ratio > config.NULL_THRESHOLD:
                issues.append(
                    {
                        "table": table,
                        "column": col,
                        "null_count": nulls,
                        "row_count": total,
                        "null_ratio": round(ratio, 4),
                    }
                )

    return issues
