"""
Validate common user fields: name present, phone length (10 digits).
"""

from __future__ import annotations

import re

import pymysql

import config
import db


def _digits_only(s: str | None) -> str:
    if s is None:
        return ""
    return re.sub(r"\D+", "", str(s))


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


def run(conn: pymysql.connections.Connection) -> list[dict]:
    t = config.USERS_TABLE
    errors: list[dict] = []

    if not _table_exists(conn, t):
        return [
            {
                "module": "data_validator",
                "warning": f"Table {t!r} not found; skipped.",
            }
        ]

    id_col = config.USER_ID_COL
    name_col = config.NAME_COL
    phone_col = config.PHONE_COL

    required_cols = [id_col]
    if not all(_column_exists(conn, t, c) for c in required_cols):
        return [{"module": "data_validator", "error": "Missing required columns on users table."}]

    select_cols = [id_col]
    if _column_exists(conn, t, name_col):
        select_cols.append(name_col)
    if _column_exists(conn, t, phone_col):
        select_cols.append(phone_col)

    select_sql = f"SELECT {', '.join(select_cols)} FROM `{t}` ORDER BY `{id_col}` ASC"

    offset = 0
    batch = config.BATCH_SIZE
    while True:
        sql = f"{select_sql} LIMIT %s OFFSET %s"
        rows = db.fetch_all(conn, sql, (batch, offset))
        if not rows:
            break
        offset += len(rows)

        for row in rows:
            uid = row.get(id_col)

            if name_col in row:
                name_val = row.get(name_col)
                if name_val is None or str(name_val).strip() == "":
                    errors.append(
                        {
                            "rule": "missing_name",
                            "user_id": uid,
                            "message": "Name is null or empty",
                        }
                    )

            if phone_col in row:
                raw = row.get(phone_col)
                if raw is None or str(raw).strip() == "":
                    errors.append(
                        {
                            "rule": "invalid_phone",
                            "user_id": uid,
                            "message": "Phone is empty",
                        }
                    )
                else:
                    d = _digits_only(str(raw))
                    if len(d) != 10:
                        errors.append(
                            {
                                "rule": "invalid_phone",
                                "user_id": uid,
                                "message": f"Phone must be 10 digits after stripping non-digits; got {len(d)}",
                                "raw": raw,
                            }
                        )

    return errors
