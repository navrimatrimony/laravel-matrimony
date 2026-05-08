"""
Per-profile completeness, missing fields, suggestions, match readiness (logic-only).

Designed for future WhatsApp / notification hooks via structured notification_candidates.
"""

from __future__ import annotations

import pymysql

import config
import db

POINTS_PER_FIELD = 10
FIELD_KEYS = [
    "name",
    "gender",
    "date_of_birth",
    "religion",
    "caste",
    "city",
    "pincode",
    "education",
    "occupation",
    "profile_photo",
]


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


def _column_names(conn: pymysql.connections.Connection, table: str) -> set[str]:
    rows = db.fetch_all(
        conn,
        """
        SELECT column_name AS c FROM information_schema.columns
        WHERE table_schema = %s AND table_name = %s
        """,
        (config.DB_DATABASE, table),
    )
    return {str(r["c"]) for r in rows}


def _filled(val: object) -> bool:
    if val is None:
        return False
    if isinstance(val, bool):
        return val
    if isinstance(val, int):
        return val != 0
    if isinstance(val, float):
        return abs(val) > 1e-9
    s = str(val).strip()
    if not s:
        return False
    low = s.lower()
    return low not in ("null", "none", "n/a", "na", "-")


def collect_critical_user_ids(duplicates: list, validation_errors: list) -> set[int]:
    """Same notion as data-quality CRITICAL: duplicate membership + empty phone."""
    ids: set[int] = set()
    for e in validation_errors:
        if not isinstance(e, dict) or not e.get("rule"):
            continue
        if e.get("rule") != "invalid_phone":
            continue
        msg = str(e.get("message") or "").lower()
        if "empty" in msg or "phone is empty" in msg:
            uid = e.get("user_id")
            if uid is not None:
                try:
                    ids.add(int(uid))
                except (TypeError, ValueError):
                    pass
    for g in duplicates:
        if not isinstance(g, dict) or g.get("type") not in ("phone", "email"):
            continue
        for row in g.get("rows") or []:
            if not isinstance(row, dict):
                continue
            uid = row.get("user_id")
            if uid is not None:
                try:
                    ids.add(int(uid))
                except (TypeError, ValueError):
                    pass
    return ids


def _row_field_presence(row: dict, cols: set[str]) -> dict[str, bool]:
    """Map logical FIELD_KEYS → filled or not using available columns."""
    r = row

    def pk(k: str) -> object:
        return r.get(k)

    name_ok = _filled(pk("full_name")) if "full_name" in cols else False

    gender_ok = False
    if "gender_id" in cols and _filled(pk("gender_id")):
        gender_ok = True
    elif "gender" in cols and _filled(pk("gender")):
        gender_ok = True

    dob_ok = _filled(pk("date_of_birth")) if "date_of_birth" in cols else False

    rel_ok = False
    if "religion_id" in cols and _filled(pk("religion_id")):
        rel_ok = True

    caste_ok = False
    if "caste_id" in cols and _filled(pk("caste_id")):
        caste_ok = True
    elif "caste" in cols and _filled(pk("caste")):
        caste_ok = True

    city_ok = False
    for c in ("location_id", "city_id", "native_city_id", "location"):
        if c in cols and _filled(pk(c)):
            city_ok = True
            break

    pin_ok = _filled(pk("pincode_resolved"))

    edu_ok = False
    for c in ("education_degree_id", "education_text", "education", "highest_education"):
        if c in cols and _filled(pk(c)):
            edu_ok = True
            break

    occ_ok = False
    for c in ("occupation_master_id", "occupation_title", "occupation_custom_id", "occupation"):
        if c in cols and _filled(pk(c)):
            occ_ok = True
            break

    photo_ok = _filled(pk("profile_photo")) if "profile_photo" in cols else False

    return {
        "name": name_ok,
        "gender": gender_ok,
        "date_of_birth": dob_ok,
        "religion": rel_ok,
        "caste": caste_ok,
        "city": city_ok,
        "pincode": pin_ok,
        "education": edu_ok,
        "occupation": occ_ok,
        "profile_photo": photo_ok,
    }


def _improvement_suggestions(presence: dict[str, bool]) -> list[str]:
    texts: list[str] = []
    if not presence.get("profile_photo"):
        texts.append("Upload profile photo to increase visibility")
    if not presence.get("education"):
        texts.append("Add education to improve match quality")
    if not presence.get("pincode"):
        texts.append("Add pincode for better location matching")
    if not presence.get("name"):
        texts.append("Complete display name on your profile")
    if not presence.get("gender"):
        texts.append("Add gender for accurate matching")
    if not presence.get("date_of_birth"):
        texts.append("Add date of birth for age-based matching")
    if not presence.get("religion"):
        texts.append("Add religion preference context")
    if not presence.get("caste"):
        texts.append("Add community / caste where applicable")
    if not presence.get("city"):
        texts.append("Set your location for geo matching")
    if not presence.get("occupation"):
        texts.append("Add occupation for better compatibility signals")
    return texts


def _missing_field_keys(presence: dict[str, bool]) -> list[str]:
    return [k for k in FIELD_KEYS if not presence.get(k)]


def _notification_channels_hint(missing: list[str]) -> list[str]:
    """Future: map to WhatsApp template keys / Laravel notification classes."""
    hints: list[str] = []
    if "profile_photo" in missing:
        hints.append("whatsapp_nudge_profile_photo")
    if "education" in missing:
        hints.append("whatsapp_nudge_education")
    if "pincode" in missing:
        hints.append("whatsapp_nudge_pincode")
    if "name" in missing or "gender" in missing or "date_of_birth" in missing:
        hints.append("whatsapp_nudge_core_fields")
    return hints


def build_profile_query(conn: pymysql.connections.Connection) -> tuple[str, bool]:
    """Returns SQL and whether deleted_at filter applies."""
    t = config.PROFILE_TABLE
    cols = _column_names(conn, t)
    parts: list[str] = ["mp.`id` AS profile_id", "mp.`user_id` AS user_id"]
    select_aliases = [
        "full_name",
        "gender_id",
        "gender",
        "date_of_birth",
        "religion_id",
        "caste_id",
        "caste",
        "location_id",
        "city_id",
        "native_city_id",
        "location",
        "education_degree_id",
        "education_text",
        "education",
        "highest_education",
        "occupation_master_id",
        "occupation_title",
        "occupation_custom_id",
        "occupation",
        "profile_photo",
    ]
    for c in select_aliases:
        if c in cols:
            parts.append(f"mp.`{c}` AS `{c}`")

    pin_sql = ""
    if _table_exists(conn, config.PROFILE_ADDRESSES_TABLE):
        pa_cols = _column_names(conn, config.PROFILE_ADDRESSES_TABLE)
        if "profile_id" in pa_cols and "pin_code" in pa_cols:
            pat = config.PROFILE_ADDRESSES_TABLE
            pin_sql = (
                f", (SELECT pa.`pin_code` FROM `{pat}` pa WHERE pa.`profile_id` = mp.`id` "
                "AND pa.`pin_code` IS NOT NULL AND TRIM(pa.`pin_code`) <> '' "
                "ORDER BY pa.`id` ASC LIMIT 1) AS pincode_resolved"
            )

    sql = f"SELECT {', '.join(parts)}{pin_sql} FROM `{t}` mp "
    if "deleted_at" in cols:
        sql += "WHERE mp.`deleted_at` IS NULL "
    else:
        sql += "WHERE 1=1 "
    sql += "ORDER BY mp.`user_id` ASC LIMIT %s"
    return sql, "deleted_at" in cols


def summarize(analysis: list[dict]) -> dict:
    if not analysis:
        return {
            "average_completeness_score": None,
            "sample_size": 0,
            "top_incomplete": [],
            "suggestion_counts": {},
            "missing_field_counts": {},
            "notification_candidates": [],
        }

    scores = [int(p["completeness_score"]) for p in analysis]
    avg = round(sum(scores) / len(scores), 2)

    sorted_rows = sorted(analysis, key=lambda x: int(x["completeness_score"]))
    top_inc = []
    for p in sorted_rows[:10]:
        top_inc.append(
            {
                "user_id": p["user_id"],
                "profile_id": p.get("profile_id"),
                "completeness_score": p["completeness_score"],
                "missing_fields": p["missing_fields"],
            }
        )

    suggestion_counts: dict[str, int] = {}
    for p in analysis:
        for s in p.get("improvement_suggestions") or []:
            suggestion_counts[s] = suggestion_counts.get(s, 0) + 1

    missing_counts: dict[str, int] = {}
    for p in analysis:
        for m in p.get("missing_fields") or []:
            missing_counts[m] = missing_counts.get(m, 0) + 1

    notification_candidates: list[dict] = []
    for p in analysis:
        if p.get("match_ready"):
            continue
        notification_candidates.append(
            {
                "user_id": p["user_id"],
                "profile_id": p.get("profile_id"),
                "completeness_score": p["completeness_score"],
                "missing_fields": p.get("missing_fields") or [],
                "improvement_suggestions": (p.get("improvement_suggestions") or [])[:5],
                "channels_hint": _notification_channels_hint(p.get("missing_fields") or []),
            }
        )
    notification_candidates = notification_candidates[:50]

    return {
        "average_completeness_score": avg,
        "sample_size": len(analysis),
        "top_incomplete": top_inc,
        "suggestion_counts": suggestion_counts,
        "missing_field_counts": missing_counts,
        "notification_candidates": notification_candidates,
    }


def run(
    conn: pymysql.connections.Connection,
    duplicates: list,
    validation_errors: list,
    limit: int | None = None,
) -> tuple[list[dict], dict]:
    limit = limit if limit is not None else config.PROFILE_ANALYSIS_LIMIT
    critical = collect_critical_user_ids(duplicates, validation_errors)

    if not _table_exists(conn, config.PROFILE_TABLE):
        return (
            [
                {
                    "module": "profile_engine",
                    "warning": f"Table {config.PROFILE_TABLE!r} not found.",
                }
            ],
            summarize([]),
        )

    sql, _has_deleted = build_profile_query(conn)
    try:
        rows = db.fetch_all(conn, sql, (limit,))
    except Exception as exc:  # noqa: BLE001
        return (
            [{"module": "profile_engine", "error": str(exc)}],
            summarize([]),
        )

    base_cols = _column_names(conn, config.PROFILE_TABLE)

    out: list[dict] = []
    for row in rows:
        uid = row.get("user_id")
        pid = row.get("profile_id")
        presence = _row_field_presence(row, base_cols | set(row.keys()))
        filled_n = sum(1 for k in FIELD_KEYS if presence.get(k))
        score = min(100, filled_n * POINTS_PER_FIELD)
        missing = _missing_field_keys(presence)
        suggestions = _improvement_suggestions(presence)

        try:
            uid_int = int(uid) if uid is not None else None
        except (TypeError, ValueError):
            uid_int = None

        m_ready = score > 70 and (uid_int is None or uid_int not in critical)

        out.append(
            {
                "user_id": uid_int,
                "profile_id": int(pid) if pid is not None else None,
                "completeness_score": score,
                "missing_fields": missing,
                "improvement_suggestions": suggestions,
                "match_ready": bool(m_ready),
            }
        )

    return out, summarize(out)
