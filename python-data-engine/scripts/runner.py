#!/usr/bin/env python3
"""
Python Data Engine — analysis + controlled fixes.

Usage (from repository root or python-data-engine/):
  MODE=analyze python3 python-data-engine/scripts/runner.py
  MODE=fix python3 python-data-engine/scripts/runner.py
"""

from __future__ import annotations

import copy
import hashlib
import json
import argparse
import sys
import time
import traceback
from datetime import datetime, timezone
from pathlib import Path
from modules.address_fix import fix_address_lat_long

SCRIPT_DIR = Path(__file__).resolve().parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

import config  # noqa: E402
import db  # noqa: E402
import logger  # noqa: E402
from modules import (  # noqa: E402
    data_integrity_engine,
    data_lineage_engine,
    data_validator,
    duplicate_detector,
    error_log_analyzer,
    mismatch_detector,
    mr_localization_engine,
    pincode_module,
    profile_engine,
    quality_engine,
    schema_analyzer,
    snapshot_comparison_engine,
    suggestion_engine,
)
from modules.auto_fix import run_recipe_pipeline  # noqa: E402
from modules.explanations import build_admin_report  # noqa: E402
from modules.recovery import run_scheduler_recovery_checks  # noqa: E402
from modules.rollback import execute_rollback, validate_rollback  # noqa: E402
from modules.scoring import build_health_scores  # noqa: E402
from modules.workflows import update_workflow_state  # noqa: E402
from modules.conversion_engine import generate_conversion_insights  # noqa: E402
from modules.coverage import build_full_profile_coverage  # noqa: E402
from modules.latlong_module import lookup_latlong_by_pincode  # noqa: E402
from modules.normalizer import normalize_city  # noqa: E402
from snapshot_consistency_validator import run as run_snapshot_consistency_validator  # noqa: E402
from quarantine_invalid_snapshots import run as run_quarantine_invalid_snapshots  # noqa: E402
from parity_validation import run as run_parity_validation  # noqa: E402
from api_drift_root_cause_engine import run as run_api_drift_root_cause  # noqa: E402
from relation_integrity_validator import run as run_relation_integrity_validator  # noqa: E402
from governance_regression_suite import run as run_governance_regression_suite  # noqa: E402
from bulk_governance_validator import run as run_bulk_governance_validator  # noqa: E402
from governance_timeline_analytics import run as run_governance_timeline_analytics  # noqa: E402
from deterministic_repair_orchestrator import run as run_deterministic_repair  # noqa: E402
from snapshot_diff_explorer import run as run_snapshot_diff_explorer  # noqa: E402
from governance_explainability import run as run_governance_explainability  # noqa: E402
from governance_security_audit import run as run_governance_security_audit  # noqa: E402
from multi_entity_validation import run as run_multi_entity_validation  # noqa: E402
from large_scale_validation import run as run_large_scale_validation  # noqa: E402
from governance.generate_full_field_inventory import run as run_generate_full_field_inventory  # noqa: E402
from governance.runtime_truth_report import run as run_governance_runtime_truth_report  # noqa: E402
from governance.verify_runtime import run_verify_api_parity, run_verify_repeater_diffs  # noqa: E402

_EMPTY_CONVERSION: dict = {
    "conversion_signals": {
        "low_profile_score_users": 0,
        "no_photo_users": 0,
        "high_intent_users": 0,
    },
    "recommended_actions": [],
    "notification_candidates": [],
}


def _ensure_report_defaults(report: dict) -> dict:
    """Strict defaults: integers and empty collections for critical keys."""
    report.setdefault("meta", {})
    meta = report["meta"]
    if not isinstance(meta, dict):
        report["meta"] = {}
        meta = report["meta"]
    meta.setdefault("mode", config.MODE)
    meta.setdefault("database", str(config.DB_DATABASE))
    meta.setdefault("batch_size", int(config.BATCH_SIZE))
    meta.setdefault("engine_version", config.ENGINE_VERSION)
    meta.setdefault("run_mode", config.MODE)
    meta.setdefault("lineage_engine_version", "2.0")
    meta.setdefault("report_schema_version", "1")

    qs = report.get("quality_score")
    report["quality_score"] = int(qs) if qs is not None else 0

    report.setdefault("priority_summary", {"critical": 0, "high": 0, "medium": 0, "low": 0})
    ps = report["priority_summary"]
    if isinstance(ps, dict):
        for k in ("critical", "high", "medium", "low"):
            ps[k] = int(ps.get(k) or 0)

    report.setdefault("suggestions", [])
    report.setdefault("duplicates", [])
    report.setdefault("validation_errors", [])
    report.setdefault("mismatch", [])
    report.setdefault("schema_issues", [])
    report.setdefault("log_errors", [])
    report.setdefault(
        "fix_results",
        {
            "pincode_fixed": 0,
            "latlong_fixed": 0,
            "normalized": 0,
            "skipped": 0,
        },
    )
    fr = report["fix_results"]
    if isinstance(fr, dict):
        for k in ("pincode_fixed", "latlong_fixed", "normalized", "skipped"):
            if k in fr and fr[k] is not None:
                fr[k] = int(fr[k])
    report.setdefault("fixes_applied", [])

    report.setdefault("profile_analysis", [])
    report.setdefault("profile_intelligence", {})
    report.setdefault("conversion_intelligence", copy.deepcopy(_EMPTY_CONVERSION))
    report.setdefault(
        "mr_localization",
        {
            "summary": {"mr_columns_found": 0, "pending_rows_total": 0, "updated_rows": 0},
            "columns": [],
            "fix": {"updated_rows": 0, "updated_by_column": [], "skipped": []},
        },
    )
    report.setdefault(
        "data_integrity",
        {
            "summary": {"health_score": 100},
            "registry_vs_profile": {"missing_columns": []},
            "semantic_groups_triggered": [],
            "implementation": {},
        },
    )
    report.setdefault(
        "data_lineage",
        {
            "summary": {
                "health_score": 100,
                "manifest_errors": 0,
                "wrong_sources": 0,
                "multi_source_conflicts": 0,
                "wizard_public_mismatches": 0,
                "missing_render_risks": 0,
                "fields_audited": 0,
            },
            "manifest_errors": [],
            "wrong_sources": [],
            "multi_source_conflicts": [],
            "wizard_public_mismatches": [],
            "missing_render_risks": [],
            "metrics": {
                "scan_duration_ms": None,
                "memory_peak_kb": None,
                "blade_count_scanned": 0,
                "manifest_field_count": 0,
            },
            "implementation": {},
        },
    )
    dl = report.get("data_lineage")
    if isinstance(dl, dict):
        dl.setdefault("manifest_errors", [])
        dl.setdefault("wrong_sources", [])
        dl.setdefault("multi_source_conflicts", [])
        dl.setdefault("wizard_public_mismatches", [])
        dl.setdefault("missing_render_risks", [])
        dl.setdefault(
            "metrics",
            {
                "scan_duration_ms": None,
                "memory_peak_kb": None,
                "blade_count_scanned": 0,
                "manifest_field_count": 0,
            },
        )
    report.setdefault("anomalies", [])
    report.setdefault("runner_module_errors", [])
    report.setdefault("module_warnings", [])
    if report.get("backup_path") is None:
        report["backup_path"] = None
    return report


def _compute_report_hash_payload(report: dict) -> str:
    """MD5 of canonical JSON (must match Laravel DataEngineService)."""
    r = copy.deepcopy(report)
    if isinstance(r.get("meta"), dict):
        r["meta"].pop("hash", None)
    return json.dumps(r, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


def _apply_report_hash(report: dict) -> None:
    payload = _compute_report_hash_payload(report)
    report.setdefault("meta", {})
    report["meta"]["hash"] = hashlib.md5(payload.encode("utf-8")).hexdigest()


def _read_previous_duplicate_groups(mode: str) -> int | None:
    """Most recent saved report for this mode (on disk before this run writes)."""
    try:
        files = sorted(
            config.OUTPUT_REPORTS_DIR.glob(f"engine_{mode}_*.json"),
            key=lambda p: p.stat().st_mtime,
            reverse=True,
        )
    except OSError:
        return None
    if not files:
        return None
    prev_path = files[0]
    try:
        prev = json.loads(prev_path.read_text(encoding="utf-8"))
        d = prev.get("duplicates")
        if not isinstance(d, list):
            return None
        return int(quality_engine.count_duplicate_groups(d))
    except Exception:
        return None


def _backup_fix_table_json(conn) -> str | None:
    """JSON snapshot of FIX_TABLE rows before fix transaction (additive file on disk)."""
    ft = config.FIX_TABLE
    if not _table_exists(conn, ft):
        return None
    id_col = config.FIX_ID_COL
    if not _column_exists(conn, ft, id_col):
        return None
    logger.ensure_dirs()
    rows: list[dict] = []
    offset = 0
    batch = config.BATCH_SIZE
    while True:
        try:
            chunk = db.fetch_all(
                conn,
                f"SELECT * FROM `{ft}` ORDER BY `{id_col}` ASC LIMIT %s OFFSET %s",
                (batch, offset),
            )
        except Exception:
            return None
        if not chunk:
            break
        rows.extend(chunk)
        offset += len(chunk)

    ts = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
    path = config.OUTPUT_BACKUPS_DIR / f"engine_fix_backup_{ts}.json"
    try:
        path.write_text(json.dumps(rows, indent=2, default=str), encoding="utf-8")
    except OSError:
        return None
    root = config.ENGINE_ROOT.parent
    try:
        rel = path.relative_to(root)
    except ValueError:
        rel = path
    return str(rel).replace("\\", "/")


def _safe_module(errors_out: list[dict], name: str, fn, default, *args, **kwargs):
    try:
        return fn(*args, **kwargs)
    except Exception as exc:
        errors_out.append(
            {
                "module": name,
                "error": str(exc),
                "traceback": traceback.format_exc(),
            }
        )
        return default


def _safe_optional(warnings_out: list[dict], name: str, fn, default, *args, **kwargs):
    """
    Optional / best-effort modules: failure becomes a module_warning, not a fatal runner_module_errors row.
    Keeps analyze resilient when non-core pieces fail.
    """
    try:
        return fn(*args, **kwargs)
    except Exception as exc:
        warnings_out.append({"module": name, "warning": str(exc)})
        return default


def _format_log_errors(ela: dict) -> list[dict]:
    out: list[dict] = []
    if not ela.get("exists"):
        out.append(
            {
                "kind": "warning",
                "message": "Laravel log not found or unreadable",
                "path": ela.get("log_path"),
            }
        )
        return out
    for p in ela.get("top_patterns") or []:
        out.append({"kind": "pattern_count", **p})
    for line in ela.get("sample_lines") or []:
        out.append({"kind": "sample_line", "text": line})
    return out


def _column_exists(conn, table: str, column: str) -> bool:
    rows = db.fetch_all(
        conn,
        """
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = %s AND table_name = %s AND column_name = %s LIMIT 1
        """,
        (config.DB_DATABASE, table, column),
    )
    return len(rows) > 0


def _table_exists(conn, table: str) -> bool:
    rows = db.fetch_all(
        conn,
        """
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = %s AND table_name = %s LIMIT 1
        """,
        (config.DB_DATABASE, table),
    )
    return len(rows) > 0


def _fix_result(
    pincode_fixed: int,
    latlong_fixed: int,
    normalized: int,
    skipped: int,
    **extra: object,
) -> dict:
    out: dict = {
        "pincode_fixed": pincode_fixed,
        "latlong_fixed": latlong_fixed,
        "normalized": normalized,
        "skipped": skipped,
    }
    out.update({k: v for k, v in extra.items() if v is not None})
    return out


def apply_safe_fixes(conn) -> dict:
    """
    UPDATE-only: pincode/lat/lon from CSV, then normalize city text (trim/lowercase/collapse spaces).
    Never DELETE; never merge users.
    """
    pin_skipped: list = []
    norm_skipped: list = []
    errors: list = []

    pincode_fixed = 0
    latlong_fixed = 0
    normalized = 0

    if not config.FIX_ENABLED:
        pin_skipped.append("ENGINE_FIX_ENABLED=false")
        return _fix_result(0, 0, 0, 1, note="ENGINE_FIX_ENABLED=false")

    if config.MODE != "fix":
        pin_skipped.append("not in fix mode")
        return _fix_result(0, 0, 0, 1, note="not in fix mode")

    ft = config.FIX_TABLE
    if not _table_exists(conn, ft):
        errors.append(f"Fix table not found: {ft}")
        return _fix_result(0, 0, 0, 0, errors=errors)

    pin_col = config.FIX_PINCODE_COL
    city_col = config.FIX_CITY_COL
    id_col = config.FIX_ID_COL

    for col in (pin_col, city_col, id_col):
        if not _column_exists(conn, ft, col):
            errors.append(f"Missing column {col} on {ft}")
            return _fix_result(0, 0, 0, 0, errors=errors)

    has_lat = bool(config.FIX_LAT_COL) and _column_exists(conn, ft, config.FIX_LAT_COL)
    has_lon = bool(config.FIX_LON_COL) and _column_exists(conn, ft, config.FIX_LON_COL)

    try:
        pincode_module.load_csv()
    except Exception as exc:  # noqa: BLE001
        errors.append(f"Pincode CSV: {exc}")
        return _fix_result(0, 0, 0, 0, errors=errors)

    session_id, _ = logger.start_fix_session_meta(
        {
            "started_at": datetime.now(timezone.utc).isoformat(),
            "fix_table": ft,
            "mode": "fix",
        }
    )

    sql_select_base = f"""
    SELECT `{id_col}` AS _id, `{pin_col}` AS _pin, `{city_col}` AS _city
    FROM `{ft}`
    WHERE (`{pin_col}` IS NULL OR TRIM(`{pin_col}`) = '')
      AND `{city_col}` IS NOT NULL AND TRIM(`{city_col}`) <> ''
    ORDER BY `{id_col}` ASC
    """

    sets_template = [f"`{pin_col}` = %s"]
    if has_lat:
        sets_template.append(f"`{config.FIX_LAT_COL}` = %s")
    if has_lon:
        sets_template.append(f"`{config.FIX_LON_COL}` = %s")

    update_sql = f"UPDATE `{ft}` SET {', '.join(sets_template)} WHERE `{id_col}` = %s"

    offset = 0
    batch = config.BATCH_SIZE
    while True:
        try:
            sql_select = f"{sql_select_base} LIMIT %s OFFSET %s"
            candidates = db.fetch_all(conn, sql_select, (batch, offset))
        except Exception as exc:  # noqa: BLE001
            errors.append(str(exc))
            skipped = len(pin_skipped) + len(norm_skipped)
            return _fix_result(
                pincode_fixed, latlong_fixed, normalized, skipped, errors=errors
            )

        if not candidates:
            break

        for row in candidates:
            rid = row["_id"]
            city_val = row["_city"]
            info = pincode_module.lookup_pincode_by_city(str(city_val))
            if not info:
                pin_skipped.append(
                    {"id": rid, "reason": "no_csv_match_for_city", "city": str(city_val)}
                )
                continue

            pin = str(info["pincode"]).strip()
            lat_v, lon_v = (None, None)
            if has_lat or has_lon:
                lat_v, lon_v = lookup_latlong_by_pincode(pin)

            exec_params: list = [pin]
            if has_lat:
                exec_params.append(lat_v)
            if has_lon:
                exec_params.append(lon_v)
            exec_params.append(rid)

            change = {
                "table": ft,
                "id": rid,
                "set": {pin_col: pin},
            }
            if has_lat:
                change["set"][config.FIX_LAT_COL] = lat_v
            if has_lon:
                change["set"][config.FIX_LON_COL] = lon_v

            try:
                db.execute(conn, update_sql, tuple(exec_params))
                logger.append_change_log({"action": "update", "change": change}, session_id)
                pincode_fixed += 1
                if has_lat or has_lon:
                    latlong_fixed += 1
            except Exception as exc:  # noqa: BLE001
                err = {"id": rid, "error": str(exc)}
                errors.append(err)
                logger.append_change_log({"action": "failed", **err}, session_id)

        if len(candidates) < batch:
            break
        offset += batch

    sql_norm_base = f"""
    SELECT `{id_col}` AS _id, `{city_col}` AS _city
    FROM `{ft}`
    WHERE `{city_col}` IS NOT NULL AND TRIM(`{city_col}`) <> ''
    ORDER BY `{id_col}` ASC
    """
    update_city_sql = f"UPDATE `{ft}` SET `{city_col}` = %s WHERE `{id_col}` = %s"

    offset_n = 0
    while True:
        try:
            sql_norm = f"{sql_norm_base} LIMIT %s OFFSET %s"
            city_rows = db.fetch_all(conn, sql_norm, (batch, offset_n))
        except Exception as exc:  # noqa: BLE001
            errors.append(str(exc))
            skipped = len(pin_skipped) + len(norm_skipped)
            return _fix_result(
                pincode_fixed, latlong_fixed, normalized, skipped, errors=errors
            )

        if not city_rows:
            break

        for row in city_rows:
            rid = row["_id"]
            raw = row["_city"]
            norm = normalize_city(str(raw))
            if norm == "":
                continue
            if norm == str(raw):
                continue
            try:
                db.execute(conn, update_city_sql, (norm, rid))
                ch = {"table": ft, "id": rid, "set": {city_col: norm}, "kind": "city_normalize"}
                logger.append_change_log({"action": "update", "change": ch}, session_id)
                normalized += 1
            except Exception as exc:  # noqa: BLE001
                errors.append({"id": rid, "error": str(exc)})
                logger.append_change_log({"action": "failed", "id": rid, "error": str(exc)}, session_id)

        if len(city_rows) < batch:
            break
        offset_n += batch

    skipped = len(pin_skipped) + len(norm_skipped)
    return _fix_result(pincode_fixed, latlong_fixed, normalized, skipped, errors=errors)


def main() -> int:
    logger.ensure_dirs()
    start_time = time.perf_counter()
    logger.append_engine_log("runner_start", {"mode": config.MODE, "database": str(config.DB_DATABASE)})

    if config.MODE == "analyze":
        print("DRY RUN — MODE=analyze (no database writes)")
        logger.append_engine_log("mode_info", {"message": "DRY RUN", "mode": "analyze"})
    else:
        print("MODE=fix — writes enabled (UPDATE only; transactional)")
        logger.append_engine_log("mode_info", {"message": "UPDATE-only fix mode", "mode": "fix"})

    report: dict = {}
    runner_module_errors: list[dict] = []
    module_warnings: list[dict] = []

    exit_code = 0
    conn = None
    try:
        conn = db.connect()

        try:
            pincode_module.load_csv()
            csv_meta = {"pincode_csv_rows": int(len(pincode_module.get_dataframe()))}
        except Exception as exc:  # noqa: BLE001
            csv_meta = {"pincode_csv_error": str(exc)}

        prev_dup_groups = _read_previous_duplicate_groups(config.MODE)

        duplicates = _safe_module(runner_module_errors, "duplicate_detector", duplicate_detector.run, [], conn)
        validation_errors = _safe_module(runner_module_errors, "data_validator", data_validator.run, [], conn)
        mismatch = _safe_module(runner_module_errors, "mismatch_detector", mismatch_detector.run, [], conn)
        schema_issues = _safe_module(runner_module_errors, "schema_analyzer", schema_analyzer.run, [], conn)

        address_fix_report = _safe_optional(
            module_warnings,
            "address_fix",
            fix_address_lat_long,
            {},
            conn,
        )
        if isinstance(address_fix_report, dict):
            aw = address_fix_report.get("warning")
            if isinstance(aw, str) and aw.strip():
                if not any(
                    w.get("module") == "address_fix" for w in module_warnings
                ):
                    module_warnings.append({"module": "address_fix", "warning": aw.strip()})
        if runner_module_errors:
            logger.append_engine_log("module_errors", {"count": len(runner_module_errors)}, level="ERROR")

        ela = _safe_module(
            runner_module_errors,
            "error_log_analyzer",
            error_log_analyzer.run,
            {
                "exists": False,
                "suggestions": [],
                "top_patterns": [],
                "sample_lines": [],
                "line_count_analyzed": 0,
                "log_path": str(config.LARAVEL_LOG_PATH),
            },
        )

        val_n = int(quality_engine.count_validation_rows(validation_errors))
        mis_n = int(quality_engine.count_mismatch_rows(mismatch))
        sch_n = int(quality_engine.count_schema_rows(schema_issues))

        quality_score = int(quality_engine.compute_quality_score(duplicates, val_n, mis_n, sch_n))
        priority_summary = quality_engine.compute_priority_summary(
            duplicates, validation_errors, mismatch, schema_issues
        )
        if isinstance(priority_summary, dict):
            priority_summary = {
                "critical": int(priority_summary.get("critical") or 0),
                "high": int(priority_summary.get("high") or 0),
                "medium": int(priority_summary.get("medium") or 0),
                "low": int(priority_summary.get("low") or 0),
            }

        suggestions = _safe_module(
            runner_module_errors,
            "suggestion_engine",
            suggestion_engine.run,
            [],
            conn,
            duplicates,
            validation_errors,
            mismatch,
            schema_issues,
        )
        if not isinstance(suggestions, list):
            suggestions = []
        suggestions.extend(ela.get("suggestions") or [])
        suggestions = suggestion_engine.dedupe_suggestions_by_type(suggestions)

        profile_analysis, profile_intelligence = _safe_module(
            runner_module_errors,
            "profile_engine",
            profile_engine.run,
            ([], profile_engine.summarize([])),
            conn,
            duplicates,
            validation_errors,
            config.PROFILE_ANALYSIS_LIMIT,
        )
        if not isinstance(profile_analysis, list):
            profile_analysis = []
        if not isinstance(profile_intelligence, dict):
            profile_intelligence = profile_engine.summarize([])

        conversion_data = _safe_module(
            runner_module_errors,
            "conversion_engine",
            generate_conversion_insights,
            copy.deepcopy(_EMPTY_CONVERSION),
            profile_intelligence,
            quality_score,
            profile_analysis,
        )
        if not isinstance(conversion_data, dict):
            conversion_data = copy.deepcopy(_EMPTY_CONVERSION)

        mr_localization = _safe_module(
            runner_module_errors,
            "mr_localization_engine.analyze",
            mr_localization_engine.analyze,
            {
                "summary": {"mr_columns_found": 0, "pending_rows_total": 0, "updated_rows": 0},
                "columns": [],
                "fix": {"updated_rows": 0, "updated_by_column": [], "skipped": []},
            },
            conn,
        )
        if not isinstance(mr_localization, dict):
            mr_localization = {
                "summary": {"mr_columns_found": 0, "pending_rows_total": 0, "updated_rows": 0},
                "columns": [],
                "fix": {"updated_rows": 0, "updated_by_column": [], "skipped": []},
            }

        data_integrity = _safe_module(
            runner_module_errors,
            "data_integrity_engine.analyze",
            data_integrity_engine.analyze,
            {
                "summary": {"health_score": 100},
                "registry_vs_profile": {"missing_columns": []},
                "semantic_groups_triggered": [],
                "implementation": {"phase": 1},
            },
            conn,
        )
        if not isinstance(data_integrity, dict):
            data_integrity = {
                "summary": {"health_score": 100},
                "registry_vs_profile": {"missing_columns": []},
                "semantic_groups_triggered": [],
                "implementation": {"phase": 1},
            }

        data_lineage = _safe_module(
            runner_module_errors,
            "data_lineage_engine.analyze",
            data_lineage_engine.analyze,
            {
                "summary": {"health_score": 100},
                "manifest_errors": [],
                "wrong_sources": [],
                "multi_source_conflicts": [],
                "wizard_public_mismatches": [],
                "missing_render_risks": [],
                "implementation": {"phase": 2},
            },
            conn,
        )
        if not isinstance(data_lineage, dict):
            data_lineage = {
                "summary": {"health_score": 100},
                "manifest_errors": [],
                "wrong_sources": [],
                "multi_source_conflicts": [],
                "wizard_public_mismatches": [],
                "missing_render_risks": [],
            }

        log_errors = _format_log_errors(ela if isinstance(ela, dict) else {})

        anomalies: list[str] = []
        curr_dup_groups = int(quality_engine.count_duplicate_groups(duplicates))
        if prev_dup_groups is not None and prev_dup_groups >= 1 and curr_dup_groups > prev_dup_groups * 2:
            anomalies.append("Duplicate count spike detected")

        backup_path: str | None = None
        fix_results: dict
        fixes_applied: list[dict]
        if config.MODE == "fix":
            backup_path = _backup_fix_table_json(conn)
            try:
                conn.begin()
                fix_results = apply_safe_fixes(conn)
                mr_localization = _safe_module(
                    runner_module_errors,
                    "mr_localization_engine.apply_fix",
                    mr_localization_engine.apply_fix,
                    mr_localization,
                    conn,
                    mr_localization,
                )
                pin_f = int(fix_results.get("pincode_fixed") or 0)
                lat_f = int(fix_results.get("latlong_fixed") or 0)
                norm_f = int(fix_results.get("normalized") or 0)
                mr_f = int(((mr_localization.get("fix") or {}).get("updated_rows") or 0)) if isinstance(mr_localization, dict) else 0
                fixes_applied = [
                    {"type": "pincode_filled", "count": pin_f},
                    {"type": "latlong_filled", "count": lat_f},
                    {"type": "city_normalized", "count": norm_f},
                    {"type": "mr_localization_filled", "count": mr_f},
                ]
                total_updates = pin_f + lat_f + norm_f + mr_f
                if total_updates > config.FIX_MAX_UPDATES_PER_RUN:
                    conn.rollback()
                    fix_results = {
                        "pincode_fixed": 0,
                        "latlong_fixed": 0,
                        "normalized": 0,
                        "skipped": int(fix_results.get("skipped") or 0),
                        "aborted": True,
                        "abort_reason": "Too many updates — possible bug",
                        "blocked_totals": {"pincode_fixed": pin_f, "latlong_fixed": lat_f, "normalized": norm_f, "mr_localization_filled": mr_f},
                    }
                    anomalies.append("Fix run aborted: update cap exceeded")
                    logger.append_engine_log(
                        "fix_aborted",
                        {"reason": "update_cap_exceeded", "totals": {"pincode": pin_f, "latlong": lat_f, "normalized": norm_f, "mr_localization": mr_f}},
                        level="ERROR",
                    )
                else:
                    conn.commit()
                    logger.append_engine_log(
                        "fix_committed", {"total_updates": total_updates, "fixes_applied": fixes_applied}
                    )
            except Exception:  # noqa: BLE001
                conn.rollback()
                fix_results = {
                    "pincode_fixed": 0,
                    "latlong_fixed": 0,
                    "normalized": 0,
                    "skipped": 0,
                    "fatal": True,
                    "traceback": traceback.format_exc(),
                }
                exit_code = 1
                raise
        else:
            fix_results = {
                "pincode_fixed": 0,
                "latlong_fixed": 0,
                "normalized": 0,
                "skipped": 0,
                "note": "analyze mode — no fixes applied",
            }
            fixes_applied = []

        gen_at = datetime.now(timezone.utc).isoformat()
        report = {
            "meta": {
                "mode": config.MODE,
                "database": str(config.DB_DATABASE),
                "generated_at": gen_at,
                "batch_size": int(config.BATCH_SIZE),
                "engine_version": config.ENGINE_VERSION,
                "lineage_engine_version": "2.0",
                "report_schema_version": "1",
                "run_mode": config.MODE,
                "timestamp": gen_at,
                **csv_meta,
            },
            "quality_score": quality_score,
            "priority_summary": priority_summary,
            "suggestions": suggestions,
            "profile_analysis": profile_analysis,
            "profile_intelligence": profile_intelligence,
            "conversion_intelligence": conversion_data,
            "mr_localization": mr_localization,
            "data_integrity": data_integrity,
            "data_lineage": data_lineage,
            "duplicates": duplicates,
            "validation_errors": validation_errors,
            "mismatch": mismatch,
            "schema_issues": schema_issues,
            "log_errors": log_errors,
            "fix_results": fix_results,
            "fixes_applied": fixes_applied,
            "anomalies": anomalies,
            "runner_module_errors": runner_module_errors,
            "module_warnings": module_warnings,
            "backup_path": backup_path,
            "address_fix": address_fix_report,
        }

    except Exception as exc:  # noqa: BLE001
        exit_code = 1
        logger.append_engine_log("runner_exception", {"error": str(exc), "traceback": traceback.format_exc()}, level="ERROR")
        report = report if isinstance(report, dict) else {}
        report.setdefault("meta", {})
        report["meta"]["runner_error"] = str(exc)
        report["meta"]["runner_traceback"] = traceback.format_exc()
        report.setdefault("quality_score", 0)
        report.setdefault("priority_summary", {"critical": 0, "high": 0, "medium": 0, "low": 0})
        report.setdefault("suggestions", [])
        report.setdefault("duplicates", [])
        report.setdefault("validation_errors", [])
        report.setdefault("mismatch", [])
        report.setdefault("schema_issues", [])
        report.setdefault("log_errors", [])
        report.setdefault(
            "fix_results",
            {
                "pincode_fixed": 0,
                "latlong_fixed": 0,
                "normalized": 0,
                "skipped": 0,
            },
        )
        report.setdefault("fixes_applied", [])
        report.setdefault("profile_analysis", [])
        report.setdefault("profile_intelligence", {})
        report.setdefault("conversion_intelligence", copy.deepcopy(_EMPTY_CONVERSION))
        report.setdefault(
            "mr_localization",
            {
                "summary": {"mr_columns_found": 0, "pending_rows_total": 0, "updated_rows": 0},
                "columns": [],
                "fix": {"updated_rows": 0, "updated_by_column": [], "skipped": []},
            },
        )
        report.setdefault(
            "data_integrity",
            {
                "summary": {"health_score": 0},
                "registry_vs_profile": {"missing_columns": []},
                "semantic_groups_triggered": [],
            },
        )
        report.setdefault(
            "data_lineage",
            {
                "summary": {
                    "health_score": 0,
                    "manifest_errors": 0,
                    "wrong_sources": 0,
                    "multi_source_conflicts": 0,
                    "wizard_public_mismatches": 0,
                    "missing_render_risks": 0,
                    "fields_audited": 0,
                },
                "manifest_errors": [],
                "wrong_sources": [],
                "multi_source_conflicts": [],
                "wizard_public_mismatches": [],
                "missing_render_risks": [],
            },
        )
        report.setdefault("anomalies", [])
        report.setdefault("runner_module_errors", runner_module_errors)
        report["module_warnings"] = module_warnings
        report.setdefault("backup_path", None)
        print(f"ERROR: {exc}", file=sys.stderr)
    finally:
        if conn is not None:
            conn.close()
        if not isinstance(report, dict):
            report = {}
        report = _ensure_report_defaults(report)
        elapsed = time.perf_counter() - start_time
        now_iso = datetime.now(timezone.utc).isoformat()
        meta = report.setdefault("meta", {})
        # String keeps MD5 stable across PHP/Python JSON float formatting.
        meta["execution_time"] = f"{elapsed:.6f}"
        meta.setdefault("engine_version", config.ENGINE_VERSION)
        meta.setdefault("run_mode", config.MODE)
        meta.setdefault("timestamp", now_iso)
        meta.setdefault("generated_at", now_iso)
        meta.setdefault("batch_size", int(config.BATCH_SIZE))
        meta.setdefault("database", str(config.DB_DATABASE))
        meta.setdefault("mode", config.MODE)
        _apply_report_hash(report)
        out_path = logger.save_report(report, prefix=f"engine_{config.MODE}")
        logger.append_engine_log(
            "runner_complete",
            {
                "mode": config.MODE,
                "exit_code": exit_code,
                "report_path": str(out_path),
                "execution_time": report.get("meta", {}).get("execution_time"),
            },
            level="INFO" if exit_code == 0 else "ERROR",
        )
        print(json.dumps({"report_saved": str(out_path)}, indent=2))

    return exit_code


def run_compare(profile_id: int | None, latest: bool) -> int:
    logger.ensure_dirs()
    logger.append_engine_log(
        "comparison_start",
        {"profile_id": profile_id, "latest": latest},
        level="INFO",
    )
    result = snapshot_comparison_engine.run(profile_id=profile_id, latest=latest)
    out_path = logger.save_comparison(result, prefix="snapshot_comparison")
    logger.append_engine_log(
        "comparison_complete",
        {
            "profile_id": profile_id,
            "latest": latest,
            "health_score": result.get("health_score"),
            "report_path": str(out_path),
        },
        level="INFO",
    )
    print(json.dumps({"comparison_saved": str(out_path)}, indent=2))
    return 0


def _parse_cli_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(add_help=True)
    sub = parser.add_subparsers(dest="command")

    cmp_parser = sub.add_parser("compare")
    cmp_parser.add_argument("--latest", action="store_true", default=False)
    cmp_parser.add_argument("--profile", type=int, default=None)

    cleanup_parser = sub.add_parser("compare-cleanup")
    cleanup_parser.add_argument("--dry-run", action="store_true", default=False)
    cleanup_parser.add_argument("--execute", action="store_true", default=False)
    cleanup_parser.add_argument("--snapshot-max-per-profile", type=int, default=None)
    cleanup_parser.add_argument("--comparison-max-files", type=int, default=None)

    ops_parser = sub.add_parser("ops-dashboard")
    ops_parser.add_argument("--report", type=str, default=None)
    ops_parser.add_argument("--comparison", type=str, default=None)
    ops_parser.add_argument("--state", type=str, default="detected")
    ops_parser.add_argument("--action-id", type=str, default="ops-dashboard")

    fix_parser = sub.add_parser("auto-fix")
    fix_parser.add_argument("--recipe", type=str, required=True)
    fix_parser.add_argument("--execute", action="store_true", default=False)
    fix_parser.add_argument("--preview-limit", type=int, default=25)

    sub.add_parser("self-heal-check")

    rollback_parser = sub.add_parser("rollback-execute")
    rollback_parser.add_argument("--manifest", type=str, required=True)

    quarantine_parser = sub.add_parser("snapshot-quarantine")
    quarantine_parser.add_argument("--dry-run", action="store_true", default=False)
    quarantine_parser.add_argument("--retention-days", type=int, default=30)

    parity_parser = sub.add_parser("parity-validate")
    parity_parser.add_argument("--profile", type=int, default=None)

    sub.add_parser("api-drift-root-cause")
    sub.add_parser("relation-integrity")
    sub.add_parser("governance-regression")

    bulk_parser = sub.add_parser("bulk-governance")
    bulk_parser.add_argument("--limit", type=int, default=120)

    sub.add_parser("governance-timeline")

    repair_parser = sub.add_parser("deterministic-repair")
    repair_parser.add_argument("--recipe", type=str, required=True)
    repair_parser.add_argument("--execute", action="store_true", default=False)
    repair_parser.add_argument("--preview-limit", type=int, default=25)

    diff_parser = sub.add_parser("snapshot-diff-explorer")
    diff_parser.add_argument("--profile", type=int, default=None)

    sub.add_parser("analyze-explainability")
    sub.add_parser("security-audit")
    sub.add_parser("multi-entity-validate")

    scale_parser = sub.add_parser("large-scale-validate")
    scale_parser.add_argument("--limit", type=int, default=1000)
    sub.add_parser("generate-field-inventory")
    sub.add_parser("governance-runtime-truth")
    sub.add_parser("generate-canonical-registry")

    vrd = sub.add_parser("verify-repeater-diffs")
    vrd.add_argument("--profile", type=int, default=None)

    vap = sub.add_parser("verify-api-parity")
    vap.add_argument("--profile", type=int, default=None)

    return parser.parse_args(argv)


def _latest_file_from(directory: Path, pattern: str) -> Path | None:
    files = sorted(directory.glob(pattern), key=lambda p: p.stat().st_mtime, reverse=True) if directory.exists() else []
    return files[0] if files else None


def run_ops_dashboard(report_path: str | None, comparison_path: str | None, action_id: str, state: str) -> int:
    logger.ensure_dirs()
    try:
        from governance.build_canonical_registry import main as _emit_canonical_registry

        _emit_canonical_registry()
    except Exception:
        pass
    rp = Path(report_path) if report_path else _latest_file_from(config.OUTPUT_REPORTS_DIR, "engine_*.json")
    if rp is None or not rp.exists():
        print(json.dumps({"error": "report_not_found"}, indent=2))
        return 1
    cp = Path(comparison_path) if comparison_path else _latest_file_from(config.OUTPUT_COMPARISONS_DIR, "snapshot_comparison_*.json")
    report = json.loads(rp.read_text(encoding="utf-8"))
    comparison = json.loads(cp.read_text(encoding="utf-8")) if cp and cp.exists() else None
    snapshot_payload: dict[str, Any] = {}
    if isinstance(comparison, dict):
        sp = comparison.get("snapshot_path")
        if isinstance(sp, str) and sp != "":
            p = Path(sp)
            if p.exists():
                try:
                    snapshot_payload = json.loads(p.read_text(encoding="utf-8"))
                except Exception:
                    snapshot_payload = {}
    coverage = build_full_profile_coverage(snapshot_payload if isinstance(snapshot_payload, dict) else {}, comparison if isinstance(comparison, dict) else None)
    snapshot_integrity = run_snapshot_consistency_validator()
    admin_report = build_admin_report(report, comparison)
    scoring = build_health_scores(report, comparison, coverage)
    workflow = update_workflow_state(action_id=action_id, state=state, details={"report": str(rp), "comparison": str(cp) if cp else None})
    dashboard_payload = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "issue_summaries": admin_report.get("issues", []),
        "health_cards": scoring,
        "risk_summaries": {
            "overall_platform_health": scoring.get("overall_platform_health"),
            "critical_issue_count": admin_report.get("overview", {}).get("critical_issues", 0),
        },
        "coverage": coverage,
        "snapshot_integrity": snapshot_integrity,
        "pending_actions": [
            {"action_id": workflow["action_id"], "state": workflow["state"]},
        ],
        "suggested_fixes": admin_report.get("recommendations", []),
        "ai_ready": admin_report.get("ai_ready_explanations", {}),
    }
    ts = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
    admin_path = config.OUTPUT_ADMIN_REPORTS_DIR / f"admin_report_{ts}.json"
    dash_path = config.OUTPUT_DASHBOARD_DIR / f"dashboard_payload_{ts}.json"
    admin_path.write_text(json.dumps(admin_report, indent=2, default=str), encoding="utf-8")
    dash_path.write_text(json.dumps(dashboard_payload, indent=2, default=str), encoding="utf-8")
    print(json.dumps({"admin_report": str(admin_path), "dashboard_payload": str(dash_path), "workflow": workflow}, indent=2))
    return 0


if __name__ == "__main__":
    args = _parse_cli_args(sys.argv[1:])
    if args.command == "compare":
        latest = True if args.latest or args.profile is None else False
        raise SystemExit(run_compare(profile_id=args.profile, latest=latest))
    if args.command == "compare-cleanup":
        dry_run = True
        if args.execute:
            dry_run = False
        elif args.dry_run:
            dry_run = True
        result = snapshot_comparison_engine.cleanup_retention(
            snapshot_max_per_profile=args.snapshot_max_per_profile,
            comparison_max_files=args.comparison_max_files,
            dry_run=dry_run,
        )
        print(json.dumps(result, indent=2))
        raise SystemExit(0)
    if args.command == "ops-dashboard":
        raise SystemExit(
            run_ops_dashboard(
                report_path=args.report,
                comparison_path=args.comparison,
                action_id=args.action_id,
                state=args.state,
            )
        )
    if args.command == "auto-fix":
        try:
            with db.connection_ctx() as conn:
                out = run_recipe_pipeline(
                    conn=conn,
                    recipe_name=args.recipe,
                    execute=bool(args.execute),
                    preview_limit=max(1, int(args.preview_limit)),
                )
        except Exception as exc:
            out = {
                "recipe": args.recipe,
                "status": "failed",
                "error": str(exc),
                "dry_run": not bool(args.execute),
            }
        action_state = "validated" if out.get("status") in {"validated", "running"} else out.get("status", "failed")
        if action_state in {"preview_ready"}:
            action_state = "reviewed"
        if action_state not in {"detected", "reviewed", "approved", "running", "validated", "failed", "rolled_back"}:
            action_state = "failed"
        update_workflow_state(f"auto-fix:{args.recipe}", action_state, {"pipeline": out})
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0 if out.get("status") not in {"failed"} else 1)
    if args.command == "self-heal-check":
        out = run_scheduler_recovery_checks()
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0)
    if args.command == "rollback-execute":
        manifest_path = Path(args.manifest)
        if not manifest_path.exists():
            print(json.dumps({"status": "failed", "error": "manifest_not_found"}, indent=2))
            raise SystemExit(1)
        payload = json.loads(manifest_path.read_text(encoding="utf-8"))
        table = str(payload.get("table") or "")
        primary_key = str(payload.get("primary_key") or "id")
        rows = payload.get("rows") if isinstance(payload.get("rows"), list) else []
        with db.connection_ctx() as conn:
            conn.begin()
            try:
                executed = execute_rollback(conn, manifest_path)
                validated = validate_rollback(conn, table, primary_key, rows)
                conn.commit()
            except Exception as exc:
                conn.rollback()
                print(json.dumps({"status": "failed", "error": str(exc)}, indent=2))
                raise SystemExit(1)
        out = {"status": "rolled_back", "execution": executed, "validation": validated}
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0)
    if args.command == "snapshot-quarantine":
        out = run_quarantine_invalid_snapshots(retention_days=max(1, int(args.retention_days)), dry_run=bool(args.dry_run))
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0)
    if args.command == "parity-validate":
        out = run_parity_validation(profile_id=args.profile)
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0)
    if args.command == "api-drift-root-cause":
        out = run_api_drift_root_cause()
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0)
    if args.command == "relation-integrity":
        out = run_relation_integrity_validator()
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0)
    if args.command == "governance-regression":
        out = run_governance_regression_suite()
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0)
    if args.command == "bulk-governance":
        out = run_bulk_governance_validator(limit=args.limit)
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0)
    if args.command == "governance-timeline":
        out = run_governance_timeline_analytics()
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0)
    if args.command == "deterministic-repair":
        out = run_deterministic_repair(recipe=args.recipe, execute=bool(args.execute), preview_limit=max(1, int(args.preview_limit)))
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0 if out.get("status") in {"preview_ready", "completed"} else 1)
    if args.command == "snapshot-diff-explorer":
        out = run_snapshot_diff_explorer(profile_id=args.profile)
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0)
    if args.command == "analyze-explainability":
        out = run_governance_explainability()
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0)
    if args.command == "security-audit":
        out = run_governance_security_audit()
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0 if out.get("all_passed") else 1)
    if args.command == "multi-entity-validate":
        out = run_multi_entity_validation()
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0)
    if args.command == "large-scale-validate":
        out = run_large_scale_validation(limit=max(100, int(args.limit)))
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0)
    if args.command == "generate-field-inventory":
        out = run_generate_full_field_inventory()
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0)
    if args.command == "governance-runtime-truth":
        out = run_governance_runtime_truth_report()
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0)
    if args.command == "generate-canonical-registry":
        from governance.build_canonical_registry import main as _emit_canonical_registry

        p = _emit_canonical_registry()
        print(json.dumps({"canonical_registry": str(p)}, indent=2))
        raise SystemExit(0)
    if args.command == "verify-repeater-diffs":
        out = run_verify_repeater_diffs(profile_id=getattr(args, "profile", None))
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0 if out.get("status") == "ok" else 1)
    if args.command == "verify-api-parity":
        out = run_verify_api_parity(profile_id=getattr(args, "profile", None))
        print(json.dumps(out, indent=2, default=str))
        raise SystemExit(0 if out.get("status") == "ok" else 1)
    raise SystemExit(main())
