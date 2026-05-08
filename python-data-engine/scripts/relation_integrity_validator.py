from __future__ import annotations

import json
import time
from typing import Any

import config
import db


RELATION_TABLES = [
    "profile_siblings",
    "profile_relatives",
    "profile_children",
    "profile_property_assets",
    "profile_contacts",
    "profile_education",
    "profile_career",
]


def _table_exists(conn, table: str) -> bool:
    rows = db.fetch_all(
        conn,
        "SELECT 1 FROM information_schema.tables WHERE table_schema = %s AND table_name = %s LIMIT 1",
        (config.DB_DATABASE, table),
    )
    return len(rows) > 0


def run() -> dict[str, Any]:
    findings: list[dict[str, Any]] = []
    with db.connection_ctx() as conn:
        for table in RELATION_TABLES:
            if not _table_exists(conn, table):
                findings.append({"table": table, "type": "table_missing", "count": 0})
                continue
            rows = db.fetch_all(
                conn,
                f"""
                SELECT COUNT(*) AS c
                FROM `{table}` r
                LEFT JOIN `{config.PROFILE_TABLE}` p ON p.id = r.profile_id
                WHERE r.profile_id IS NOT NULL AND p.id IS NULL
                """,
                (),
            )
            orphan_count = int((rows[0] or {}).get("c") or 0) if rows else 0
            findings.append({"table": table, "type": "orphan_rows", "count": orphan_count})

    total_orphans = sum(int(r.get("count") or 0) for r in findings if r.get("type") == "orphan_rows")
    result = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "total_orphan_rows": total_orphans,
        "relations": findings,
    }
    config.OUTPUT_HEALTH_DIR.mkdir(parents=True, exist_ok=True)
    (config.OUTPUT_HEALTH_DIR / "relation_integrity_report.json").write_text(json.dumps(result, indent=2), encoding="utf-8")
    return result


if __name__ == "__main__":
    print(json.dumps(run(), indent=2))

