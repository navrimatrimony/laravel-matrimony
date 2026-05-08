"""
Marathi localization engine for *_mr columns.

Analyze:
- Finds all tables/columns ending with _mr
- Reports pending rows where *_mr is empty but base column has data

Fix:
- Fills *_mr from base column using offline translation helper
- Falls back to source text copy if no dictionary mapping exists
"""

from __future__ import annotations

import re
from typing import Any

import pymysql

import config
import db
from modules.translation_module import translate_text

_ID_RX = re.compile(r"^[a-zA-Z_][a-zA-Z0-9_]*$")


def _safe_ident(name: str) -> str:
    if not _ID_RX.match(name):
        raise ValueError(f"Unsafe SQL identifier: {name!r}")
    return name


def _tables_with_mr_columns(conn: pymysql.connections.Connection) -> list[dict[str, Any]]:
    rows = db.fetch_all(
        conn,
        """
        SELECT table_name AS t, column_name AS c
        FROM information_schema.columns
        WHERE table_schema = %s
          AND column_name LIKE %s
        ORDER BY table_name, column_name
        """,
        (config.DB_DATABASE, r"%\_mr"),
    )
    out: list[dict[str, Any]] = []
    for r in rows:
        table = str(r.get("t") or "")
        col_mr = str(r.get("c") or "")
        if not table or not col_mr.endswith("_mr"):
            continue
        base = col_mr[:-3]
        out.append({"table": table, "mr_column": col_mr, "base_column": base})
    return out


def _column_exists(conn: pymysql.connections.Connection, table: str, col: str) -> bool:
    rows = db.fetch_all(
        conn,
        """
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = %s AND table_name = %s AND column_name = %s
        LIMIT 1
        """,
        (config.DB_DATABASE, table, col),
    )
    return len(rows) > 0


def _id_column(conn: pymysql.connections.Connection, table: str) -> str | None:
    for candidate in ("id", "profile_id", "user_id"):
        if _column_exists(conn, table, candidate):
            return candidate
    return None


def _row_counts(conn: pymysql.connections.Connection, table: str, base_col: str, mr_col: str) -> dict[str, int]:
    """Rows where base text exists: expected; mr filled; still pending."""
    t = _safe_ident(table)
    b = _safe_ident(base_col)
    m = _safe_ident(mr_col)
    row = db.fetch_all(
        conn,
        f"""
        SELECT
          SUM(CASE WHEN `{b}` IS NOT NULL AND TRIM(`{b}`) <> '' THEN 1 ELSE 0 END) AS expected_rows,
          SUM(CASE WHEN `{b}` IS NOT NULL AND TRIM(`{b}`) <> ''
                    AND `{m}` IS NOT NULL AND TRIM(`{m}`) <> '' THEN 1 ELSE 0 END) AS filled_rows,
          SUM(CASE WHEN `{b}` IS NOT NULL AND TRIM(`{b}`) <> ''
                    AND (`{m}` IS NULL OR TRIM(`{m}`) = '') THEN 1 ELSE 0 END) AS pending_rows
        FROM `{t}`
        """,
    )[0]
    return {
        "expected_rows": int(row.get("expected_rows") or 0),
        "filled_rows": int(row.get("filled_rows") or 0),
        "pending_rows": int(row.get("pending_rows") or 0),
    }


def analyze(conn: pymysql.connections.Connection) -> dict[str, Any]:
    details: list[dict[str, Any]] = []
    total_pending = 0
    total_expected = 0
    total_filled = 0
    total_columns = 0

    for row in _tables_with_mr_columns(conn):
        table = row["table"]
        mr_col = row["mr_column"]
        base_col = row["base_column"]
        if not _column_exists(conn, table, base_col):
            continue
        total_columns += 1
        counts = _row_counts(conn, table, base_col, mr_col)
        pending = counts["pending_rows"]
        expected = counts["expected_rows"]
        filled = counts["filled_rows"]
        total_pending += pending
        total_expected += expected
        total_filled += filled
        details.append(
            {
                "table": table,
                "base_column": base_col,
                "mr_column": mr_col,
                "expected_rows": expected,
                "filled_rows": filled,
                "pending_rows": pending,
            }
        )

    details.sort(key=lambda x: int(x["pending_rows"]), reverse=True)
    return {
        "summary": {
            "mr_columns_found": total_columns,
            "pending_rows_total": total_pending,
            "expected_rows_total": total_expected,
            "filled_rows_total": total_filled,
        },
        "columns": details,
        "fix": {
            "updated_rows": 0,
            "updated_by_column": [],
            "skipped": [],
        },
    }


def apply_fix(conn: pymysql.connections.Connection, report: dict[str, Any]) -> dict[str, Any]:
    columns = report.get("columns")
    if not isinstance(columns, list):
        return report

    max_updates = int(getattr(config, "FIX_MAX_UPDATES_PER_RUN", 5000))
    updated_total = 0
    updated_by_column: list[dict[str, Any]] = []
    skipped: list[dict[str, Any]] = []

    for c in columns:
        if not isinstance(c, dict):
            continue
        table = str(c.get("table") or "")
        base_col = str(c.get("base_column") or "")
        mr_col = str(c.get("mr_column") or "")
        if not table or not base_col or not mr_col:
            continue
        if not _column_exists(conn, table, base_col) or not _column_exists(conn, table, mr_col):
            skipped.append({"table": table, "mr_column": mr_col, "reason": "column_missing"})
            continue

        id_col = _id_column(conn, table)
        if id_col is None:
            skipped.append({"table": table, "mr_column": mr_col, "reason": "no_id_column"})
            continue

        t = _safe_ident(table)
        b = _safe_ident(base_col)
        m = _safe_ident(mr_col)
        i = _safe_ident(id_col)
        offset = 0
        batch = int(getattr(config, "BATCH_SIZE", 1000))
        local_updates = 0

        while True:
            if updated_total >= max_updates:
                skipped.append(
                    {
                        "table": table,
                        "mr_column": mr_col,
                        "reason": "global_update_cap_reached",
                        "cap": max_updates,
                    }
                )
                break
            rows = db.fetch_all(
                conn,
                f"""
                SELECT `{i}` AS _id, `{b}` AS _base, `{m}` AS _mr
                FROM `{t}`
                WHERE (`{m}` IS NULL OR TRIM(`{m}`) = '')
                  AND `{b}` IS NOT NULL AND TRIM(`{b}`) <> ''
                ORDER BY `{i}` ASC
                LIMIT %s OFFSET %s
                """,
                (batch, offset),
            )
            if not rows:
                break

            for r in rows:
                if updated_total >= max_updates:
                    break
                rid = r.get("_id")
                base_val = r.get("_base")
                if rid is None or base_val is None:
                    continue
                mr_val = translate_text(base_val, source_lang="en", target_lang="mr")
                if not mr_val:
                    mr_val = str(base_val).strip()
                db.execute(
                    conn,
                    f"UPDATE `{t}` SET `{m}` = %s WHERE `{i}` = %s",
                    (mr_val, rid),
                )
                local_updates += 1
                updated_total += 1

            if len(rows) < batch or updated_total >= max_updates:
                break
            offset += batch

        updated_by_column.append(
            {
                "table": table,
                "mr_column": mr_col,
                "base_column": base_col,
                "updated_rows": local_updates,
            }
        )

    report["fix"] = {
        "updated_rows": updated_total,
        "updated_by_column": updated_by_column,
        "skipped": skipped,
    }
    if isinstance(report.get("summary"), dict):
        report["summary"]["updated_rows"] = updated_total
    return report

