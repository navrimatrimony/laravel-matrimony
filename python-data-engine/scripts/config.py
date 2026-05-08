"""
Central configuration for the Python Data Engine.

Loads Laravel-compatible DB_* variables and engine-specific ENGINE_* overrides.
"""

from __future__ import annotations

import os
import re
from pathlib import Path

from dotenv import load_dotenv

ENGINE_ROOT = Path(__file__).resolve().parent.parent

# Prefer repo-root .env (parent of python-data-engine/), then engine-local .env
load_dotenv(ENGINE_ROOT.parent / ".env")
load_dotenv(ENGINE_ROOT / ".env")


def _bool_env(key: str, default: bool = False) -> bool:
    v = os.getenv(key)
    if v is None:
        return default
    return v.strip().lower() in ("1", "true", "yes", "on")


def _validate_sql_identifier(name: str, label: str) -> str:
    if not name or not re.match(r"^[a-zA-Z_][a-zA-Z0-9_]*$", name):
        raise ValueError(f"Invalid SQL identifier for {label}: {name!r}")
    return name


# --- Database (Laravel-style names) ---
DB_HOST = os.getenv("DB_HOST", "127.0.0.1")
DB_PORT = int(os.getenv("DB_PORT", "3306"))
DB_USERNAME = os.getenv("DB_USERNAME") or os.getenv("DB_USER") or "root"
DB_PASSWORD = os.getenv("DB_PASSWORD", "")
DB_DATABASE = os.getenv("DB_DATABASE") or os.getenv("DB_NAME") or "laravel"

# --- Mode ---
MODE_RAW = (os.getenv("MODE") or "analyze").strip().lower()
MODE = MODE_RAW if MODE_RAW in ("analyze", "fix") else "analyze"

# --- Users / duplicate / validation ---
USERS_TABLE = _validate_sql_identifier(os.getenv("ENGINE_USERS_TABLE", "users"), "USERS_TABLE")
USER_ID_COL = _validate_sql_identifier(os.getenv("ENGINE_USER_ID_COLUMN", "id"), "USER_ID_COL")
PHONE_COL = _validate_sql_identifier(os.getenv("ENGINE_USER_PHONE_COLUMN", "mobile"), "PHONE_COL")
EMAIL_COL = _validate_sql_identifier(os.getenv("ENGINE_USER_EMAIL_COLUMN", "email"), "EMAIL_COL")
NAME_COL = _validate_sql_identifier(os.getenv("ENGINE_USER_NAME_COLUMN", "name"), "NAME_COL")

# --- City mismatch (optional custom SQL) ---
MISMATCH_ENABLED = _bool_env("ENGINE_MISMATCH_ENABLED", True)
MISMATCH_SQL = (os.getenv("ENGINE_MISMATCH_SQL") or "").strip()
LEFT_TABLE = _validate_sql_identifier(os.getenv("ENGINE_MISMATCH_LEFT_TABLE", "users"), "LEFT_TABLE")
LEFT_KEY = _validate_sql_identifier(os.getenv("ENGINE_MISMATCH_LEFT_KEY", "id"), "LEFT_KEY")
LEFT_CITY = _validate_sql_identifier(os.getenv("ENGINE_MISMATCH_LEFT_CITY", "city"), "LEFT_CITY")
RIGHT_TABLE = _validate_sql_identifier(
    os.getenv("ENGINE_MISMATCH_RIGHT_TABLE", "addresses"), "RIGHT_TABLE"
)
RIGHT_FK = _validate_sql_identifier(
    os.getenv("ENGINE_MISMATCH_RIGHT_USER_FK", "user_id"), "RIGHT_FK"
)
RIGHT_CITY = _validate_sql_identifier(
    os.getenv("ENGINE_MISMATCH_RIGHT_CITY", "city"), "RIGHT_CITY"
)

# --- Schema analyzer ---
SCHEMA_TABLES = [
    _validate_sql_identifier(t.strip(), f"SCHEMA_TABLES[{i}]")
    for i, t in enumerate((os.getenv("ENGINE_SCHEMA_TABLES") or "users").split(","))
    if t.strip()
]
NULL_THRESHOLD = float(os.getenv("ENGINE_SCHEMA_NULL_THRESHOLD", "0.9"))

# --- Batch / memory limits (full-table scans avoided where possible) ---
BATCH_SIZE = max(100, min(50_000, int(os.getenv("ENGINE_BATCH_SIZE", "1000"))))
MISMATCH_MAX_ROWS = max(100, min(500_000, int(os.getenv("ENGINE_MISMATCH_MAX_ROWS", "5000"))))

# --- Laravel log ---
LARAVEL_LOG_PATH = Path(
    os.getenv(
        "ENGINE_LARAVEL_LOG_PATH",
        str(ENGINE_ROOT.parent / "storage" / "logs" / "laravel.log"),
    )
)

# --- Pincode dataset ---
PINCODE_CSV = Path(
    os.getenv("ENGINE_PINCODE_CSV", str(ENGINE_ROOT / "data" / "india_pincode.csv"))
)

# --- Fix mode target (single table; UPDATE only) ---
FIX_ENABLED = _bool_env("ENGINE_FIX_ENABLED", True)
FIX_TABLE = _validate_sql_identifier(os.getenv("ENGINE_FIX_TABLE", "profile_addresses"), "FIX_TABLE")
FIX_ID_COL = _validate_sql_identifier(os.getenv("ENGINE_FIX_ID_COLUMN", "id"), "FIX_ID_COL")
FIX_PINCODE_COL = _validate_sql_identifier(
    os.getenv("ENGINE_FIX_PINCODE_COLUMN", "pin_code"), "FIX_PINCODE_COL"
)
FIX_CITY_COL = _validate_sql_identifier(
    os.getenv("ENGINE_FIX_CITY_COLUMN", "district"), "FIX_CITY_COL"
)
FIX_LAT_COL = os.getenv("ENGINE_FIX_LAT_COLUMN") or ""
FIX_LON_COL = os.getenv("ENGINE_FIX_LON_COLUMN") or ""
if FIX_LAT_COL:
    FIX_LAT_COL = _validate_sql_identifier(FIX_LAT_COL, "FIX_LAT_COL")
if FIX_LON_COL:
    FIX_LON_COL = _validate_sql_identifier(FIX_LON_COL, "FIX_LON_COL")

OUTPUT_REPORTS_DIR = Path(os.getenv("ENGINE_OUTPUT_REPORTS", str(ENGINE_ROOT / "output" / "reports")))
OUTPUT_LOGS_DIR = Path(os.getenv("ENGINE_OUTPUT_LOGS", str(ENGINE_ROOT / "output" / "logs")))
OUTPUT_BACKUPS_DIR = Path(os.getenv("ENGINE_OUTPUT_BACKUPS", str(ENGINE_ROOT / "output" / "backups")))
OUTPUT_COMPARISONS_DIR = Path(
    os.getenv("ENGINE_OUTPUT_COMPARISONS", str(ENGINE_ROOT / "output" / "comparisons"))
)
OUTPUT_ADMIN_REPORTS_DIR = Path(
    os.getenv("ENGINE_OUTPUT_ADMIN_REPORTS", str(ENGINE_ROOT / "output" / "admin_reports"))
)
OUTPUT_DASHBOARD_DIR = Path(
    os.getenv("ENGINE_OUTPUT_DASHBOARD", str(ENGINE_ROOT / "output" / "dashboard"))
)
OUTPUT_AUDIT_DIR = Path(
    os.getenv("ENGINE_OUTPUT_AUDIT", str(ENGINE_ROOT / "output" / "audit"))
)
OUTPUT_WORKFLOWS_DIR = Path(
    os.getenv("ENGINE_OUTPUT_WORKFLOWS", str(ENGINE_ROOT / "output" / "workflows"))
)
OUTPUT_ROLLBACK_DIR = Path(
    os.getenv("ENGINE_OUTPUT_ROLLBACK", str(ENGINE_ROOT / "output" / "rollback"))
)
OUTPUT_HEALTH_DIR = Path(
    os.getenv("ENGINE_OUTPUT_HEALTH", str(ENGINE_ROOT / "output" / "health"))
)
OUTPUT_COVERAGE_DIR = Path(
    os.getenv("ENGINE_OUTPUT_COVERAGE", str(ENGINE_ROOT / "output" / "coverage"))
)
OUTPUT_QUARANTINE_DIR = Path(
    os.getenv("ENGINE_OUTPUT_QUARANTINE", str(ENGINE_ROOT / "output" / "quarantine"))
)
OUTPUT_REGRESSION_DIR = Path(
    os.getenv("ENGINE_OUTPUT_REGRESSION", str(ENGINE_ROOT / "output" / "regression"))
)
OUTPUT_GOVERNANCE_DIR = Path(
    os.getenv("ENGINE_OUTPUT_GOVERNANCE", str(ENGINE_ROOT / "output" / "governance"))
)
RECIPES_DIR = Path(
    os.getenv("ENGINE_RECIPES_DIR", str(ENGINE_ROOT / "config" / "recipes"))
)
COMPARISON_SUPPRESSIONS_PATH = Path(
    os.getenv(
        "ENGINE_COMPARISON_SUPPRESSIONS",
        str(ENGINE_ROOT / "config" / "comparison_suppressions.yml"),
    )
)
SNAPSHOT_BASE_DIR = Path(
    os.getenv(
        "ENGINE_SNAPSHOT_BASE_DIR",
        str(ENGINE_ROOT.parent / "storage" / "app" / "data-audit" / "snapshots"),
    )
)
SNAPSHOT_RETENTION_PER_PROFILE = max(
    1, min(5000, int(os.getenv("ENGINE_SNAPSHOT_RETENTION_PER_PROFILE", "30")))
)
COMPARISON_RETENTION_FILES = max(
    1, min(5000, int(os.getenv("ENGINE_COMPARISON_RETENTION_FILES", "300")))
)

# --- Release / safety caps ---
ENGINE_VERSION = (os.getenv("ENGINE_VERSION") or "1.0.0").strip()
FIX_MAX_UPDATES_PER_RUN = max(1, min(1_000_000, int(os.getenv("ENGINE_FIX_MAX_UPDATES_PER_RUN", "5000"))))

# --- Profile intelligence (matrimony_profiles + optional joins) ---
PROFILE_TABLE = _validate_sql_identifier(os.getenv("ENGINE_PROFILE_TABLE", "matrimony_profiles"), "PROFILE_TABLE")
PROFILE_ANALYSIS_LIMIT = max(1, min(500, int(os.getenv("ENGINE_PROFILE_ANALYSIS_LIMIT", "100"))))
PROFILE_ADDRESSES_TABLE = _validate_sql_identifier(
    os.getenv("ENGINE_PROFILE_ADDRESSES_TABLE", "profile_addresses"), "PROFILE_ADDRESSES_TABLE"
)
