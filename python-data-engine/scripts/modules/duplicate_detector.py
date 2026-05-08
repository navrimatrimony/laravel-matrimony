"""
Detect duplicate user records by phone and email.
"""

from __future__ import annotations

import pymysql

import config
import db


def _table_exists(conn: pymysql.connections.Connection, table: str) -> bool:
    q = """
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = %s AND table_name = %s
    LIMIT 1
    """
    rows = db.fetch_all(conn, q, (config.DB_DATABASE, table))
    return len(rows) > 0


def _column_exists(conn: pymysql.connections.Connection, table: str, column: str) -> bool:
    q = """
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = %s AND table_name = %s AND column_name = %s
    LIMIT 1
    """
    rows = db.fetch_all(conn, q, (config.DB_DATABASE, table, column))
    return len(rows) > 0


def run(conn: pymysql.connections.Connection) -> list[dict]:
    t = config.USERS_TABLE
    if not _table_exists(conn, t):
        return [
            {
                "module": "duplicate_detector",
                "warning": f"Table {t!r} not found; skipped.",
            }
        ]

    phone_col = config.PHONE_COL
    email_col = config.EMAIL_COL
    id_col = config.USER_ID_COL

    groups: list[dict] = []

    if _column_exists(conn, t, phone_col):
        sql_phone_dup = f"""
        SELECT TRIM({phone_col}) AS key_value, COUNT(*) AS cnt
        FROM {t}
        WHERE {phone_col} IS NOT NULL AND TRIM({phone_col}) <> ''
        GROUP BY TRIM({phone_col})
        HAVING COUNT(*) > 1
        """
        dup_keys = db.fetch_all(conn, sql_phone_dup)
        for row in dup_keys:
            key = row["key_value"]
            sel = [f"{id_col} AS user_id"]
            if _column_exists(conn, t, phone_col):
                sel.append(f"{phone_col} AS phone")
            if _column_exists(conn, t, email_col):
                sel.append(f"{email_col} AS email")
            members = db.fetch_all(
                conn,
                f"""
                SELECT {", ".join(sel)}
                FROM {t}
                WHERE TRIM({phone_col}) = %s
                """,
                (key,),
            )
            groups.append(
                {
                    "type": "phone",
                    "value": key,
                    "count": int(row["cnt"]),
                    "rows": members,
                }
            )

    if _column_exists(conn, t, email_col):
        sql_email_dup = f"""
        SELECT LOWER(TRIM({email_col})) AS key_value, COUNT(*) AS cnt
        FROM {t}
        WHERE {email_col} IS NOT NULL AND TRIM({email_col}) <> ''
        GROUP BY LOWER(TRIM({email_col}))
        HAVING COUNT(*) > 1
        """
        dup_keys = db.fetch_all(conn, sql_email_dup)
        for row in dup_keys:
            key = row["key_value"]
            sel = [f"{id_col} AS user_id"]
            if _column_exists(conn, t, phone_col):
                sel.append(f"{phone_col} AS phone")
            if _column_exists(conn, t, email_col):
                sel.append(f"{email_col} AS email")
            members = db.fetch_all(
                conn,
                f"""
                SELECT {", ".join(sel)}
                FROM {t}
                WHERE LOWER(TRIM({email_col})) = %s
                """,
                (key,),
            )
            groups.append(
                {
                    "type": "email",
                    "value": key,
                    "count": int(row["cnt"]),
                    "rows": members,
                }
            )

    return groups
