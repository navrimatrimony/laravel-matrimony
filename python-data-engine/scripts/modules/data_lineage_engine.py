"""
PHASE 2 — Manifest-driven data lineage audit (no AST, no runtime hooks).

Loads config/data_lineage.yml, validates DB columns + blade paths, regex-scans blades for
$profile->field / $user->field / optional() / data_get / ?? chains.

Manifest is source of truth; scan results verify bindings only.
"""

from __future__ import annotations

import re
import time
import tracemalloc
from pathlib import Path
from typing import Any

import pymysql
try:
    import yaml
except ModuleNotFoundError:  # pragma: no cover - runtime environment dependent
    yaml = None  # type: ignore[assignment]

import config
import db

ENGINE_ROOT = Path(__file__).resolve().parent.parent.parent
REPO_ROOT = ENGINE_ROOT.parent
MANIFEST_PATH = ENGINE_ROOT / "config" / "data_lineage.yml"

_RE_PROFILE_ARROW = re.compile(r"\$profile\s*->\s*([a-zA-Z_][a-zA-Z0-9_]*)")
_RE_USER_ARROW = re.compile(r"\$user\s*->\s*([a-zA-Z_][a-zA-Z0-9_]*)")
_RE_OPTIONAL_PROFILE = re.compile(
    r"optional\s*\(\s*\$profile(?:\s*->\s*[a-zA-Z_][a-zA-Z0-9_]*)?\s*\)\s*->\s*([a-zA-Z_][a-zA-Z0-9_]*)",
    re.IGNORECASE,
)
_RE_DATA_GET_PROFILE = re.compile(
    r"data_get\s*\(\s*\$profile\s*,\s*['\"]([a-zA-Z0-9_]+)['\"]",
    re.IGNORECASE,
)
_RE_PROFILE_BRACKET = re.compile(r"\$profile\s*\[\s*['\"]([a-zA-Z_][a-zA-Z0-9_]*)['\"]\s*\]")
_RE_USER_BRACKET = re.compile(r"\$user\s*\[\s*['\"]([a-zA-Z_][a-zA-Z0-9_]*)['\"]\s*\]")
_RE_COALESCE_PU = re.compile(
    r"\$profile\s*->\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\?\?\s*\$user\s*->\s*([a-zA-Z_][a-zA-Z0-9_]*)"
)
_RE_COALESCE_UP = re.compile(
    r"\$user\s*->\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\?\?\s*\$profile\s*->\s*([a-zA-Z_][a-zA-Z0-9_]*)"
)


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


def _load_manifest() -> tuple[dict[str, Any], list[dict[str, Any]]]:
    errors: list[dict[str, Any]] = []
    if yaml is None:
        errors.append({"severity": "high", "detail": "manifest_yaml_unavailable:install_pyyaml"})
        return {}, errors
    if not MANIFEST_PATH.is_file():
        errors.append({"severity": "high", "detail": f"manifest_missing:{MANIFEST_PATH}"})
        return {}, errors
    try:
        raw = MANIFEST_PATH.read_text(encoding="utf-8")
    except OSError as exc:
        errors.append({"severity": "high", "detail": f"manifest_read_error:{exc}"})
        return {}, errors
    try:
        data = yaml.safe_load(raw)
    except yaml.YAMLError as exc:
        errors.append({"severity": "high", "detail": f"manifest_yaml_error:{exc}"})
        return {}, errors
    if not isinstance(data, dict):
        errors.append({"severity": "high", "detail": "manifest_root_must_be_mapping"})
        return {}, errors
    return data, errors


def _parse_binding(spec: str) -> tuple[str | None, str | None]:
    """
    'profile.full_name' -> ('profile', 'full_name')
    'values.height_cm' -> ('values', 'height_cm')
    """
    spec = str(spec).strip()
    if "." not in spec:
        return None, None
    model, _, attr = spec.partition(".")
    return model.strip(), attr.strip()


def _collect_accessors(text: str) -> dict[str, set[str]]:
    """Collect attribute names accessed via profile/user arrows and helpers."""
    out: dict[str, set[str]] = {"profile": set(), "user": set()}
    for m in _RE_PROFILE_ARROW.finditer(text):
        out["profile"].add(m.group(1))
    for m in _RE_USER_ARROW.finditer(text):
        out["user"].add(m.group(1))
    for m in _RE_OPTIONAL_PROFILE.finditer(text):
        out["profile"].add(m.group(1))
    for m in _RE_DATA_GET_PROFILE.finditer(text):
        out["profile"].add(m.group(1))
    for m in _RE_PROFILE_BRACKET.finditer(text):
        out["profile"].add(m.group(1))
    for m in _RE_USER_BRACKET.finditer(text):
        out["user"].add(m.group(1))
    return out


def _read_blade(repo_root: Path, rel: str) -> tuple[str | None, str | None]:
    rel_norm = str(rel).strip().replace("\\", "/")
    p = repo_root / rel_norm
    if not p.is_file():
        return None, f"file_missing:{rel_norm}"
    try:
        return p.read_text(encoding="utf-8", errors="replace"), None
    except OSError as e:
        return None, str(e)

def _severity_penalty(sev: str) -> int:
    s = (sev or "").strip().lower()
    if s == "high":
        return 20
    if s == "medium":
        return 10
    if s == "low":
        return 5
    return 0


def _norm_token(s: str) -> str:
    return re.sub(r"[^a-z0-9_]+", "", (s or "").strip().lower())


def _guess_attr_aliases(field_key: str, canonical_column: str, expected_attr: str) -> list[str]:
    """
    Deterministic light heuristics to catch common naming differences:
    - height_cm -> height
    - religion -> religion_id
    """
    seeds = [_norm_token(expected_attr), _norm_token(canonical_column), _norm_token(field_key)]
    out: list[str] = []
    for x in seeds:
        if not x:
            continue
        if x not in out:
            out.append(x)
        # suffix drops
        for suf in ("_id", "_cm", "_key", "_code"):
            if x.endswith(suf):
                y = x[: -len(suf)]
                if y and y not in out:
                    out.append(y)
        # id add
        if not x.endswith("_id") and (x + "_id") not in out:
            out.append(x + "_id")
    # remove empties
    return [a for a in out if a]


def _binding_resolved(binding: str, text: str) -> bool:
    model, attr = _parse_binding(binding)
    if not attr:
        return False
    if model == "profile":
        acc = _collect_accessors(text)
        return attr in acc["profile"] or f"$profile->{attr}" in text
    if model == "user":
        acc = _collect_accessors(text)
        return attr in acc["user"] or f"$user->{attr}" in text
    if model == "values":
        return (
            f"['{attr}']" in text
            or f'["{attr}"]' in text
            or f"$values['{attr}']" in text
            or f'$values["{attr}"]' in text
            or f"values['{attr}']" in text
        )
    return False


def analyze(conn: pymysql.connections.Connection) -> dict[str, Any]:
    t0 = time.perf_counter()
    tracemalloc.start()
    manifest, load_errors = _load_manifest()
    manifest_errors: list[dict[str, Any]] = []
    manifest_errors.extend(load_errors)
    wrong_sources: list[dict[str, Any]] = []
    multi_source_conflicts: list[dict[str, Any]] = []
    wizard_public_mismatches: list[dict[str, Any]] = []
    missing_render_risks: list[dict[str, Any]] = []
    blades_scanned = 0

    if not manifest or not manifest.get("fields"):
        _, peak = tracemalloc.get_traced_memory()
        tracemalloc.stop()
        fallback_errors = manifest_errors if manifest_errors else [{"severity": "high", "detail": "No data_lineage.yml or empty fields map"}]
        return {
            "summary": {
                "health_score": 80,
                "manifest_errors": len(fallback_errors),
                "wrong_sources": 0,
                "multi_source_conflicts": 0,
                "wizard_public_mismatches": 0,
                "missing_render_risks": 0,
                "fields_audited": 0,
            },
            "manifest_errors": fallback_errors,
            "wrong_sources": [],
            "multi_source_conflicts": [],
            "wizard_public_mismatches": [],
            "missing_render_risks": [],
            "implementation": {"phase": 2, "manifest_path": str(MANIFEST_PATH.relative_to(REPO_ROOT))},
            "metrics": {
                "scan_duration_ms": int(round((time.perf_counter() - t0) * 1000)),
                "memory_peak_kb": int(round(peak / 1024)),
                "blade_count_scanned": 0,
                "manifest_field_count": 0,
            },
        }

    fields_raw = manifest.get("fields")
    if not isinstance(fields_raw, dict):
        manifest_errors.append({"severity": "high", "detail": "Manifest fields must be a mapping"})
        fields_raw = {}

    repo_root = REPO_ROOT

    for field_key, spec in fields_raw.items():
        if not isinstance(spec, dict):
            manifest_errors.append({"field": field_key, "severity": "medium", "detail": "Field spec must be an object"})
            continue

        cs = spec.get("canonical_source") or {}
        table = str(cs.get("table") or "").strip()
        column = str(cs.get("column") or "").strip()
        if not table or not column:
            manifest_errors.append(
                {"field": field_key, "severity": "high", "detail": "canonical_source.table/column required"}
            )
            continue

        if not _table_exists(conn, table):
            manifest_errors.append(
                {
                    "field": field_key,
                    "severity": "high",
                    "detail": f"Table `{table}` does not exist in schema",
                }
            )
        elif not _column_exists(conn, table, column):
            manifest_errors.append(
                {
                    "field": field_key,
                    "severity": "high",
                    "detail": f"Column `{table}.{column}` missing",
                }
            )

        wizard = spec.get("wizard") if isinstance(spec.get("wizard"), dict) else None
        public = spec.get("public_profile") if isinstance(spec.get("public_profile"), dict) else None
        if wizard is None:
            manifest_errors.append({"field": field_key, "severity": "high", "detail": "wizard section missing or invalid"})
            wizard = {}
        if public is None:
            manifest_errors.append(
                {"field": field_key, "severity": "high", "detail": "public_profile section missing or invalid"}
            )
            public = {}

        if not isinstance(wizard.get("blades"), list):
            manifest_errors.append({"field": field_key, "severity": "high", "detail": "wizard.blades must be an array"})
        if not isinstance(public.get("blades"), list):
            manifest_errors.append(
                {"field": field_key, "severity": "high", "detail": "public_profile.blades must be an array"}
            )
        if not isinstance(wizard.get("bindings"), list):
            manifest_errors.append(
                {"field": field_key, "severity": "high", "detail": "wizard.bindings must be an array"}
            )
        if not isinstance(public.get("bindings"), list):
            manifest_errors.append(
                {"field": field_key, "severity": "high", "detail": "public_profile.bindings must be an array"}
            )

        w_blades = wizard.get("blades") if isinstance(wizard.get("blades"), list) else []
        p_blades = public.get("blades") if isinstance(public.get("blades"), list) else []

        wizard_text_parts: list[str] = []
        for rel in w_blades:
            rel = str(rel).strip().replace("\\", "/")
            txt, err = _read_blade(repo_root, rel)
            if err:
                manifest_errors.append({"field": field_key, "blade": rel, "severity": "high", "detail": err})
            elif txt:
                wizard_text_parts.append(txt)
                blades_scanned += 1
        wizard_joined = "\n".join(wizard_text_parts)

        public_text_parts: list[str] = []
        for rel in p_blades:
            rel = str(rel).strip().replace("\\", "/")
            txt, err = _read_blade(repo_root, rel)
            if err:
                manifest_errors.append({"field": field_key, "blade": rel, "severity": "high", "detail": err})
            elif txt:
                public_text_parts.append(txt)
                blades_scanned += 1
        public_joined = "\n".join(public_text_parts)

        w_bind = wizard.get("bindings") if isinstance(wizard.get("bindings"), list) else []
        p_bind = public.get("bindings") if isinstance(public.get("bindings"), list) else []
        w_bind = [str(x).strip() for x in w_bind if str(x).strip() != ""]
        p_bind = [str(x).strip() for x in p_bind if str(x).strip() != ""]

        # C. Wizard/public mismatch (manifest-level binding definitions)
        if w_bind and p_bind:
            w0 = w_bind[0]
            p0 = p_bind[0]
            if w0 != p0:
                wizard_public_mismatches.append(
                    {
                        "field": field_key,
                        "wizard": w0,
                        "public": p0,
                        "severity": "high",
                    }
                )

        # Multi-source ?? chains (manifest blades, regex-only) — B
        def _add_coalesce_hits(layer: str, text: str) -> None:
            for m in _RE_COALESCE_PU.finditer(text):
                multi_source_conflicts.append(
                    {
                        "field": field_key,
                        "sources": [f"profile.{m.group(1)}", f"user.{m.group(2)}"],
                        "severity": "medium",
                        "layer": layer,
                    }
                )
            for m in _RE_COALESCE_UP.finditer(text):
                multi_source_conflicts.append(
                    {
                        "field": field_key,
                        "sources": [f"user.{m.group(1)}", f"profile.{m.group(2)}"],
                        "severity": "medium",
                        "layer": layer,
                    }
                )

        _add_coalesce_hits("wizard", wizard_joined)
        _add_coalesce_hits("public_profile", public_joined)

        # Scan accessors once
        w_acc = _collect_accessors(wizard_joined) if wizard_joined else {"profile": set(), "user": set()}
        p_acc = _collect_accessors(public_joined) if public_joined else {"profile": set(), "user": set()}

        # A. Wrong source (best-effort deterministic): expected binding not found, but an alternative model accessor exists.
        # We target bindings that are explicit in manifest; first binding is the "expected" for this field.
        expected_binding = None
        if w_bind:
            expected_binding = w_bind[0]
        elif p_bind:
            expected_binding = p_bind[0]
        if expected_binding:
            exp_model, exp_attr = _parse_binding(expected_binding)
            exp_attr = exp_attr or ""
            aliases = _guess_attr_aliases(field_key, column, exp_attr)
            if exp_model == "profile":
                # if we don't see expected on profile, but we do see a user alias -> wrong source
                expected_present = False
                if wizard_joined and _binding_resolved(expected_binding, wizard_joined):
                    expected_present = True
                if public_joined and _binding_resolved(expected_binding, public_joined):
                    expected_present = True

                if not expected_present:
                    seen_user = sorted({a for a in (w_acc["user"] | p_acc["user"]) if _norm_token(a) in aliases})
                    if seen_user:
                        wrong_sources.append(
                            {
                                "field": field_key,
                                "expected": expected_binding,
                                "actual": f"user.{seen_user[0]}",
                                "severity": "high",
                            }
                        )

        # D. Missing render risk: manifest defines canonical source, but no expected binding is detected anywhere.
        any_expected_found = False
        for b in (w_bind + p_bind):
            if wizard_joined and _binding_resolved(b, wizard_joined):
                any_expected_found = True
                break
            if public_joined and _binding_resolved(b, public_joined):
                any_expected_found = True
                break
        if not any_expected_found:
            missing_render_risks.append({"field": field_key, "severity": "medium"})

    # Health score (deterministic severity penalties)
    score = 100
    for row in manifest_errors:
        score -= _severity_penalty(str(row.get("severity") or "high"))
    for row in wrong_sources:
        score -= _severity_penalty(str(row.get("severity") or "high"))
    for row in multi_source_conflicts:
        score -= _severity_penalty(str(row.get("severity") or "medium"))
    for row in wizard_public_mismatches:
        score -= _severity_penalty(str(row.get("severity") or "high"))
    for row in missing_render_risks:
        score -= _severity_penalty(str(row.get("severity") or "medium"))
    score = max(0, min(100, score))
    _, peak = tracemalloc.get_traced_memory()
    tracemalloc.stop()

    return {
        "summary": {
            "health_score": score,
            "manifest_errors": len(manifest_errors),
            "wrong_sources": len(wrong_sources),
            "multi_source_conflicts": len(multi_source_conflicts),
            "wizard_public_mismatches": len(wizard_public_mismatches),
            "missing_render_risks": len(missing_render_risks),
            "fields_audited": len(fields_raw),
        },
        "manifest_errors": manifest_errors,
        "wrong_sources": wrong_sources,
        "multi_source_conflicts": multi_source_conflicts,
        "wizard_public_mismatches": wizard_public_mismatches,
        "missing_render_risks": missing_render_risks,
        "implementation": {
            "phase": 2,
            "manifest_path": str(MANIFEST_PATH.relative_to(REPO_ROOT)),
            "repo_root": str(REPO_ROOT),
            "notes": [
                "Bindings use regex over Blade text; dynamic indirect access may false-positive missing_render.",
                "Extend data_lineage.yml as new wizard/public fields stabilize.",
            ],
        },
        "metrics": {
            "scan_duration_ms": int(round((time.perf_counter() - t0) * 1000)),
            "memory_peak_kb": int(round(peak / 1024)),
            "blade_count_scanned": blades_scanned,
            "manifest_field_count": len(fields_raw),
        },
    }
