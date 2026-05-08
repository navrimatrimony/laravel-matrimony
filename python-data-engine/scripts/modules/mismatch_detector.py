"""
Compare city-like columns across two tables (e.g. users vs address rows).

Supports optional ENGINE_MISMATCH_SQL for project-specific joins.
"""

from __future__ import annotations

import pymysql

import config
import db


def _exists(conn: pymysql.connections.Connection, table: str) -> bool:
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


def run(conn: pymysql.connections.Connection) -> list[dict]:
    if not config.MISMATCH_ENABLED:
        return [{"module": "mismatch_detector", "skipped": True, "reason": "ENGINE_MISMATCH_ENABLED=false"}]

    if config.MISMATCH_SQL:
        try:
            rows = db.fetch_all(conn, config.MISMATCH_SQL)
            return list(rows)
        except Exception as exc:  # noqa: BLE001
            return [
                {
                    "module": "mismatch_detector",
                    "error": "Custom ENGINE_MISMATCH_SQL failed",
                    "detail": str(exc),
                }
            ]

    lt, lk, lc = config.LEFT_TABLE, config.LEFT_KEY, config.LEFT_CITY
    rt, rf, rc = config.RIGHT_TABLE, config.RIGHT_FK, config.RIGHT_CITY

    for tbl, col, label in [(lt, lc, "left city"), (rt, rc, "right city"), (rt, rf, "right fk")]:
        if not _exists(conn, tbl):
            return [
                {
                    "module": "mismatch_detector",
                    "warning": f"Table {tbl!r} not found; configure ENGINE_MISMATCH_SQL or add columns.",
                    "hint": "Set ENGINE_MISMATCH_SQL to a join that fits your schema.",
                }
            ]
        if not _column_exists(conn, tbl, col):
            return [
                {
                    "module": "mismatch_detector",
                    "warning": f"Column {col!r} missing on {tbl!r}; skipped generic mismatch.",
                    "hint": "Define ENGINE_MISMATCH_SQL for your project (e.g. profiles vs profile_addresses).",
                }
            ]

    lim = config.MISMATCH_MAX_ROWS
    sql = f"""
    SELECT
      l.{lk} AS left_id,
      l.{lc} AS left_city,
      r.{rc} AS right_city,
      r.{rf} AS right_user_id
    FROM {lt} l
    INNER JOIN {rt} r ON r.{rf} = l.{lk}
    WHERE l.{lc} IS NOT NULL AND TRIM(l.{lc}) <> ''
      AND r.{rc} IS NOT NULL AND TRIM(r.{rc}) <> ''
      AND LOWER(TRIM(l.{lc})) <> LOWER(TRIM(r.{rc}))
    LIMIT {lim}
    """
    try:
        return db.fetch_all(conn, sql)
    except Exception as exc:  # noqa: BLE001
        return [{"module": "mismatch_detector", "error": str(exc)}]
