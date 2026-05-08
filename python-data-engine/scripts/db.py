"""MySQL connections using pymysql.cursors.DictCursor."""

from __future__ import annotations

from contextlib import contextmanager
from typing import Any, Generator, Iterator, Mapping

import pymysql
from pymysql.cursors import DictCursor

import config


def connect(**kwargs: Any) -> pymysql.connections.Connection:
    """Open a new connection with DictCursor as default cursor factory."""
    base = {
        "host": config.DB_HOST,
        "port": config.DB_PORT,
        "user": config.DB_USERNAME,
        "password": config.DB_PASSWORD,
        "database": config.DB_DATABASE,
        "charset": "utf8mb4",
        "cursorclass": DictCursor,
        "autocommit": False,
    }
    base.update(kwargs)
    return pymysql.connect(**base)


@contextmanager
def connection_ctx(**kwargs: Any) -> Generator[pymysql.connections.Connection, None, None]:
    conn = connect(**kwargs)
    try:
        yield conn
    finally:
        conn.close()


def fetch_all(conn: pymysql.connections.Connection, sql: str, params: Any = None) -> list[dict]:
    with conn.cursor() as cur:
        cur.execute(sql, params or ())
        rows = cur.fetchall()
    return list(rows)


def execute(conn: pymysql.connections.Connection, sql: str, params: Any = None) -> int:
    with conn.cursor() as cur:
        affected = cur.execute(sql, params or ())
    return int(affected)


def executemany(conn: pymysql.connections.Connection, sql: str, seq_of_params: Iterator) -> int:
    with conn.cursor() as cur:
        affected = cur.executemany(sql, list(seq_of_params))
    return int(affected)
