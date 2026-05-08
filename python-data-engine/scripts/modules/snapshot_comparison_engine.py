from __future__ import annotations

import json
import re
import sys
import time
import hashlib
from pathlib import Path
from typing import Any

import config
import db

_SCRIPTS = config.ENGINE_ROOT / "scripts"
if str(_SCRIPTS) not in sys.path:
    sys.path.insert(0, str(_SCRIPTS))
try:
    from governance.canonical_registry_spec import api_alias_map as _canonical_api_alias_map
except ImportError:  # pragma: no cover
    _canonical_api_alias_map = None  # type: ignore[misc,assignment]

_REGISTRY_CACHE: dict[str, Any] | None = None


def _registry_json_path() -> Path:
    return config.ENGINE_ROOT / "config" / "governance" / "canonical_field_registry.json"


def _load_canonical_registry() -> dict[str, Any]:
    global _REGISTRY_CACHE
    if _REGISTRY_CACHE is not None:
        return _REGISTRY_CACHE
    p = _registry_json_path()
    if not p.is_file():
        _REGISTRY_CACHE = {"meta": {}, "entries": []}
        return _REGISTRY_CACHE
    try:
        raw = json.loads(p.read_text(encoding="utf-8"))
    except Exception:
        raw = {}
    _REGISTRY_CACHE = raw if isinstance(raw, dict) else {"meta": {}, "entries": []}
    return _REGISTRY_CACHE
try:
    import yaml
except ModuleNotFoundError:  # pragma: no cover - runtime environment dependent
    yaml = None  # type: ignore[assignment]

from modules.semantic_normalization_engine import SemanticNormalizationEngine


def _field_api_aliases() -> dict[str, list[str]]:
    if _canonical_api_alias_map is not None:
        return _canonical_api_alias_map()
    return {
        "full_name": ["full_name"],
        "gender": ["gender", "gender_id"],
        "date_of_birth": ["date_of_birth"],
        "height_cm": ["height_cm", "height"],
        "religion": ["religion", "religion_id"],
        "caste": ["caste", "caste_id"],
        "education": ["education", "highest_education", "qualification"],
        "occupation": ["occupation", "occupation_title"],
        "annual_income": ["annual_income"],
        "city": ["city", "city_id", "location_id"],
        "state": ["state", "state_id"],
        "mother_tongue": ["mother_tongue", "mother_tongue_id"],
        "marital_status": ["marital_status", "marital_status_id"],
        "family_type": ["family_type", "family_type_id"],
        "complexion": ["complexion", "complexion_id"],
        "blood_group": ["blood_group", "blood_group_id"],
        "nakshatra": ["nakshatra", "nakshatra_id"],
        "rashi": ["rashi", "rashi_id"],
        "mangal_dosh": ["mangal_dosh", "mangal_dosh_type_id", "mangal_status_id"],
        "income_range": ["income_range", "income_range_id"],
        "professions": ["profession", "profession_id"],
        "partner_preferences": ["partner_preferences"],
    }
def _safe_str(value: Any) -> str | None:
    if value is None:
        return None
    if isinstance(value, str):
        v = value.strip()
        return v if v != "" else None
    if isinstance(value, (int, float)):
        return str(value)
    return None


def _normalize_whitespace(value: str | None) -> str | None:
    if value is None:
        return None
    return " ".join(value.split()).strip().lower()


def _normalize_numeric(value: str | None) -> str | None:
    if value is None:
        return None
    s = _normalize_whitespace(value)
    if s is None:
        return None
    m = re.fullmatch(r"[-+]?\d+(\.\d+)?", s)
    return s if m else None


def _height_to_cm(value: str | None) -> str | None:
    if value is None:
        return None
    s = _normalize_whitespace(value)
    if s is None:
        return None

    m_cm = re.search(r"(\d{2,3})\s*cm\b", s)
    if m_cm:
        return m_cm.group(1)

    m_num = re.fullmatch(r"\d{2,3}", s)
    if m_num:
        return m_num.group(0)

    m_ft = re.search(r"(\d+)\s*ft\s*(\d+)\s*in", s)
    if m_ft:
        feet = int(m_ft.group(1))
        inch = int(m_ft.group(2))
        total_inches = feet * 12 + inch
        cm = round(total_inches * 2.54)
        return str(cm)

    return None


def _normalize_for_field(field: str, value: Any) -> str | None:
    raw = _safe_str(value)
    if raw is None:
        return None
    if field == "height_cm":
        h = _height_to_cm(raw)
        if h is not None:
            return h
    n = _normalize_numeric(raw)
    if n is not None:
        return n
    return _normalize_whitespace(raw)


def _is_populated(value: Any) -> bool:
    if isinstance(value, dict):
        return value != {}
    if isinstance(value, list):
        return value != []
    s = _safe_str(value)
    return s is not None and s != ""


def _get_api_value(api_payload: dict[str, Any], field: str) -> Any:
    profile = api_payload.get("profile")
    if not isinstance(profile, dict):
        return None
    for key in _field_api_aliases().get(field, [field]):
        if key in profile:
            v = profile.get(key)
            if isinstance(v, dict) and "id" in v:
                return v.get("id")
            return v
    return None


def _governed_scalar_registry_fields() -> list[str]:
    reg = _load_canonical_registry()
    out: list[str] = []
    for e in reg.get("entries") or []:
        if not isinstance(e, dict):
            continue
        if e.get("repeater"):
            continue
        if e.get("governed") is True and e.get("comparison_supported") is True:
            out.append(str(e.get("field") or ""))
    return sorted({f for f in out if f != ""})


def _governed_repeater_registry_names() -> list[str]:
    reg = _load_canonical_registry()
    out: list[str] = []
    for e in reg.get("entries") or []:
        if not isinstance(e, dict):
            continue
        if e.get("repeater") is True and e.get("governed") is True:
            out.append(str(e.get("field") or ""))
    return sorted({f for f in out if f != ""})


def _repeater_row_field_allowlist(name: str) -> list[str] | None:
    reg = _load_canonical_registry()
    for e in reg.get("entries") or []:
        if isinstance(e, dict) and e.get("field") == name and e.get("repeater"):
            cols = e.get("repeater_row_fields")
            if isinstance(cols, list):
                return [str(c) for c in cols]
    return None


def _resolve_fields(snapshot_payload: dict[str, Any]) -> list[str]:
    """Canonical governed scalar keys only (no DB-key inflation, no YAML duplicates)."""
    return _governed_scalar_registry_fields()



def _get_rendered_value(rendered_fields: dict[str, Any], field: str) -> Any:
    if field in rendered_fields:
        val = rendered_fields.get(field)
        if isinstance(val, dict) and "raw_rendered" in val:
            return val.get("raw_rendered"), "flattened"
        if field == "partner_preferences" and isinstance(val, (dict, list)):
            return val, "flattened"
        if not isinstance(val, dict):
            return val, "flattened"
    for layer in ("public_profile", "wizard"):
        lf = rendered_fields.get(layer)
        if isinstance(lf, dict) and field in lf:
            row = lf.get(field)
            if isinstance(row, dict) and "raw_rendered" in row:
                return row.get("raw_rendered"), layer
            if isinstance(row, dict):
                return row.get("raw_rendered"), layer
            return row, layer
    return None, None


def _compare_field(
    field: str,
    db_v: Any,
    api_v: Any,
    rendered_v: Any,
    semantic_engine: SemanticNormalizationEngine | None = None,
) -> dict[str, Any]:
    if field == "partner_preferences":

        def _json_canon(x: Any) -> str | None:
            if x is None:
                return None
            if isinstance(x, str) and x.strip() == "":
                return None
            try:
                parsed = json.loads(x) if isinstance(x, str) else x
                if parsed in (None, {}, []):
                    return None
                return json.dumps(parsed, sort_keys=True, default=str)
            except Exception:
                s = _safe_str(x)
                return s

        db_j = _json_canon(db_v)
        api_j = _json_canon(api_v)
        ren_j = _json_canon(rendered_v)
        out: dict[str, Any] = {
            "field": field,
            "db": db_j,
            "api": api_j,
            "rendered": ren_j,
            "comparison_type": "exact_match",
            "severity": "low",
            "status": "pass",
        }
        if db_j is not None and ren_j is None:
            out["comparison_type"] = "missing_render"
            out["severity"] = "high"
            out["status"] = "fail"
            return out
        if db_j is not None and api_j is None:
            out["comparison_type"] = "api_drift"
            out["severity"] = "medium"
            out["status"] = "fail"
            out["root_cause_hint"] = "hidden_field_or_serializer_mismatch"
            return out
        if db_j is not None and api_j is not None and db_j != api_j:
            out["comparison_type"] = "api_drift"
            out["severity"] = "medium"
            out["status"] = "fail"
            out["root_cause_hint"] = "nested_payload_mismatch"
            return out
        if db_j is not None and ren_j is not None and db_j != ren_j:
            out["comparison_type"] = "cross_layer_inconsistency"
            out["severity"] = "medium"
            out["status"] = "fail"
            return out
        out["comparison_type"] = "exact_match" if db_j == api_j == ren_j else "normalized_match"
        return out

    db_n = _normalize_for_field(field, db_v)
    api_n = _normalize_for_field(field, api_v)
    ren_n = _normalize_for_field(field, rendered_v)

    out = {
        "field": field,
        "db": _safe_str(db_v),
        "api": _safe_str(api_v),
        "rendered": _safe_str(rendered_v),
        "comparison_type": "exact_match",
        "severity": "low",
        "status": "pass",
    }

    if db_n is not None and ren_n is None:
        out["comparison_type"] = "missing_render"
        out["severity"] = "high"
        out["status"] = "fail"
        return out

    if db_n is not None and api_n is None and ren_n is None:
        out["comparison_type"] = "null_propagation"
        out["severity"] = "high"
        out["status"] = "fail"
        return out

    if db_n is not None and api_n is not None and db_n != api_n:
        out["comparison_type"] = "api_drift"
        out["severity"] = "medium"
        out["status"] = "fail"
        out["root_cause_hint"] = _classify_api_drift(field, db_v, api_v)
        return out

    if db_n is not None and ren_n is not None and db_n != ren_n:
        if semantic_engine is not None and semantic_engine.is_semantic_equivalent(field, db_v, api_v, rendered_v):
            out["comparison_type"] = "semantic_equivalent"
            out["severity"] = "low"
            out["status"] = "pass"
            return out
        out["comparison_type"] = "cross_layer_inconsistency"
        out["severity"] = "medium"
        out["status"] = "fail"
        return out

    exact = (
        _safe_str(db_v) is not None
        and _safe_str(api_v) is not None
        and _safe_str(db_v) == _safe_str(api_v)
    )
    out["comparison_type"] = "exact_match" if exact else "normalized_match"
    out["severity"] = "low"
    out["status"] = "pass"
    return out


def _classify_api_drift(field: str, db_v: Any, api_v: Any) -> str:
    if api_v in (None, "") and db_v not in (None, ""):
        return "hidden_field_or_serializer_mismatch"
    if isinstance(api_v, dict):
        return "transformer_issue"
    if field in {"city", "education", "occupation", "caste"}:
        return "normalization_mismatch"
    return "missing_eager_load_or_stale_cache"


def _score(comparisons: list[dict[str, Any]]) -> int:
    score = 100
    for row in comparisons:
        if row.get("suppressed") is True:
            continue
        if row.get("status") != "fail":
            continue
        sev = row.get("severity")
        if sev == "high":
            score -= 20
        elif sev == "medium":
            score -= 10
        else:
            score -= 5
    return max(0, score)


def _canonical_row(row: dict[str, Any]) -> str:
    keys = sorted([k for k in row.keys() if k not in {"id", "created_at", "updated_at", "deleted_at"}])
    parts: list[str] = []
    for k in keys:
        v = row.get(k)
        parts.append(f"{k}={'' if v is None else str(v).strip().lower()}")
    return "|".join(parts)


def _row_signature(row: dict[str, Any]) -> str:
    canonical = _canonical_row(row)
    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()


def _flatten_rendered_scalar_map(rendered_fields: dict[str, Any]) -> dict[str, Any]:
    flat: dict[str, Any] = {}
    if not isinstance(rendered_fields, dict):
        return flat
    for k, v in rendered_fields.items():
        if isinstance(v, dict) and "raw_rendered" in v:
            flat[str(k)] = v.get("raw_rendered")
        elif isinstance(v, (str, int, float, bool)) or v is None:
            flat[str(k)] = v
    return flat


def _repeater_rows_from_rendered_flat(flat: dict[str, Any], rep: str) -> list[dict[str, Any]]:
    prefix = f"repeaters.{rep}."
    grouped: dict[int, dict[str, Any]] = {}
    for path, val in flat.items():
        if not isinstance(path, str) or not path.startswith(prefix):
            continue
        rest = path[len(prefix) :]
        if "." not in rest:
            continue
        idx_s, col = rest.split(".", 1)
        try:
            idx = int(idx_s)
        except ValueError:
            continue
        grouped.setdefault(idx, {})[col] = val
    return [grouped[i] for i in sorted(grouped.keys())]


def _repeater_field_level_diffs(
    payload: dict[str, Any],
    rendered_flat: dict[str, Any],
    api_payload: dict[str, Any],
) -> list[dict[str, Any]]:
    rg = payload.get("repeater_governance")
    if isinstance(rg, dict):
        fd = rg.get("repeater_field_diffs")
        if isinstance(fd, list) and len(fd) > 0:
            return fd
    repeaters = payload.get("repeaters") if isinstance(payload.get("repeaters"), dict) else {}
    out: list[dict[str, Any]] = []
    for rep in _governed_repeater_registry_names():
        db_rows = repeaters.get(rep)
        if not isinstance(db_rows, list) or not db_rows:
            continue
        flat_rows = _repeater_rows_from_rendered_flat(rendered_flat, rep)
        allow = _repeater_row_field_allowlist(rep)
        cols = allow or sorted(
            {k for r in db_rows if isinstance(r, dict) for k in r.keys() if k not in {"created_at", "updated_at", "deleted_at"}}
        )
        by_flat_id: dict[int, dict[str, Any]] = {}
        for fr in flat_rows:
            if isinstance(fr, dict) and fr.get("id") is not None:
                try:
                    by_flat_id[int(fr["id"])] = fr
                except (TypeError, ValueError):
                    pass
        for ri, drow in enumerate(db_rows):
            if not isinstance(drow, dict):
                continue
            rid = drow.get("id")
            frow: dict[str, Any] | None = None
            if rid is not None:
                try:
                    frow = by_flat_id.get(int(rid))
                except (TypeError, ValueError):
                    frow = None
            if frow is None and ri < len(flat_rows) and isinstance(flat_rows[ri], dict):
                frow = flat_rows[ri]
            api_profile = api_payload.get("profile") if isinstance(api_payload.get("profile"), dict) else {}
            api_row = None
            if isinstance(api_profile.get(rep), list) and ri < len(api_profile[rep]):
                api_row = api_profile[rep][ri] if isinstance(api_profile[rep][ri], dict) else None
            for col in cols:
                if col in {"id", "created_at", "updated_at", "deleted_at"}:
                    continue
                dv = drow.get(col)
                fv = frow.get(col) if isinstance(frow, dict) else None
                av = api_row.get(col) if isinstance(api_row, dict) else None
                dn = _normalize_whitespace(_safe_str(dv))
                fn = _normalize_whitespace(_safe_str(fv))
                an = _normalize_whitespace(_safe_str(av))
                if dn == fn == an:
                    continue
                if dn is None and fn is None and an is None:
                    continue
                ctype = "semantic_mismatch" if dn and fn and dn != fn else "value_mismatch"
                sev = "medium" if dn and fn and dn != fn else "low"
                out.append(
                    {
                        "repeater": rep,
                        "row": ri + 1,
                        "field": col,
                        "wizard": dv,
                        "api": av,
                        "normalized": {"wizard": dn, "api": an, "public_render": fn},
                        "comparison_type": ctype,
                        "severity": sev,
                    }
                )
    return out


def _comparison_truth_metadata(
    registry: dict[str, Any],
    comparisons: list[dict[str, Any]],
    skipped_empty: list[str],
    db_values: dict[str, Any],
    api_payload: dict[str, Any],
    rendered_fields: dict[str, Any],
) -> dict[str, Any]:
    entries = [e for e in (registry.get("entries") or []) if isinstance(e, dict)]
    supported = [str(e["field"]) for e in entries if e.get("comparison_supported") is True]
    unsupported = [str(e["field"]) for e in entries if e.get("governed") is True and e.get("comparison_supported") is not True]
    repeater_names = [str(e["field"]) for e in entries if e.get("repeater") is True]
    compared = [str(r["field"]) for r in comparisons if isinstance(r.get("field"), str)]
    api_profile = api_payload.get("profile") if isinstance(api_payload.get("profile"), dict) else {}
    api_missing: list[str] = []
    snapshot_missing: list[str] = []
    for e in entries:
        if not e.get("comparison_supported") or e.get("repeater"):
            continue
        fname = str(e.get("field") or "")
        if fname == "":
            continue
        db_v = db_values.get(fname)
        api_v = _get_api_value(api_payload, fname)
        ren_v, _ = _get_rendered_value(rendered_fields, fname)
        if _is_populated(db_v) and not _is_populated(api_v):
            api_missing.append(fname)
        if _is_populated(db_v) and not _is_populated(ren_v):
            snapshot_missing.append(fname)
    return {
        "supported_fields": sorted(set(supported)),
        "unsupported_fields": sorted(set(unsupported)),
        "ignored_fields": sorted(set(skipped_empty)),
        "compared_fields": compared,
        "repeater_fields": sorted(set(repeater_names)),
        "api_missing_fields": sorted(set(api_missing)),
        "snapshot_missing_fields": sorted(set(snapshot_missing)),
        "canonical_registry_meta": registry.get("meta") if isinstance(registry.get("meta"), dict) else {},
    }


def _compare_repeaters(repeaters: dict[str, Any]) -> list[dict[str, Any]]:
    out: list[dict[str, Any]] = []
    for key in _governed_repeater_registry_names():
        rows = repeaters.get(key)
        if not isinstance(rows, list):
            continue
        if rows == []:
            continue
        canonical = [_canonical_row(r) for r in rows if isinstance(r, dict)]
        signatures = [_row_signature(r) for r in rows if isinstance(r, dict)]
        if len(canonical) != len(rows):
            out.append({"repeater": key, "type": "partial_row_corruption", "severity": "high", "details": {"rows": len(rows), "valid_rows": len(canonical)}})
        sorted_c = sorted(canonical)
        if canonical != sorted_c:
            out.append({"repeater": key, "type": "reordered_rows_tolerated", "severity": "low", "details": {"row_count": len(rows), "row_signatures": signatures[:20]}})
        seen: set[str] = set()
        dup = 0
        for c in canonical:
            if c in seen:
                dup += 1
            seen.add(c)
        if dup > 0:
            out.append({"repeater": key, "type": "row_count_mismatch", "severity": "medium", "details": {"duplicate_rows": dup, "row_count": len(rows), "row_signatures": signatures[:20]}})
        if len(rows) > 0 and len(canonical) == 0:
            out.append({"repeater": key, "type": "silent_deletions", "severity": "high", "details": {"row_count": len(rows)}})
        missing_identity = 0
        for row in rows:
            if not isinstance(row, dict):
                continue
            if row.get("id") in (None, "") and row.get("uuid") in (None, ""):
                missing_identity += 1
        if missing_identity > 0:
            out.append({"repeater": key, "type": "orphan_or_unidentified_rows", "severity": "medium", "details": {"rows_missing_identity": missing_identity, "row_count": len(rows)}})
    return out


def _load_suppressions() -> dict[str, Any]:
    if yaml is None:
        return {"suppressions": []}
    path = config.COMPARISON_SUPPRESSIONS_PATH
    if not path.exists() or not path.is_file():
        return {"suppressions": []}
    try:
        data = yaml.safe_load(path.read_text(encoding="utf-8"))
    except Exception:
        return {"suppressions": []}
    if not isinstance(data, dict):
        return {"suppressions": []}
    rows = data.get("suppressions")
    if not isinstance(rows, list):
        rows = []
    return {"suppressions": rows}


def _apply_suppression(row: dict[str, Any], source_layer: str | None, rules: list[dict[str, Any]]) -> dict[str, Any]:
    out = dict(row)
    out["suppressed"] = False
    out["suppression_reason"] = None
    out["effective_severity"] = out.get("severity")
    out["source_layer"] = source_layer

    for r in rules:
        if not isinstance(r, dict):
            continue
        field = r.get("field")
        ctype = r.get("comparison_type")
        layer = r.get("route_view")

        if field is not None and field != out.get("field"):
            continue
        if ctype is not None and ctype != out.get("comparison_type"):
            continue
        if layer is not None and layer != source_layer:
            continue

        if "severity_override" in r and isinstance(r.get("severity_override"), str):
            out["effective_severity"] = r.get("severity_override")
        if bool(r.get("suppress", True)):
            out["suppressed"] = True
            out["suppression_reason"] = str(r.get("reason") or "suppressed_by_rule")
        return out
    return out


def _find_snapshot(profile_id: int | None, latest: bool) -> Path | None:
    base = config.SNAPSHOT_BASE_DIR
    if not base.exists() or not base.is_dir():
        return None

    candidates: list[Path] = []
    if profile_id is not None:
        dirs = list(base.glob(f"*_{profile_id}"))
        for p_dir in dirs:
            if p_dir.exists() and p_dir.is_dir():
                candidates.extend(sorted(p_dir.glob("snapshot_*.json"), key=lambda p: p.stat().st_mtime, reverse=True))
        candidates = sorted(candidates, key=lambda p: p.stat().st_mtime, reverse=True)
    else:
        candidates = sorted(base.glob("*_*/snapshot_*.json"), key=lambda p: p.stat().st_mtime, reverse=True)

    valid = [p for p in candidates if _is_comparison_eligible_snapshot(p, profile_id)]
    if not valid:
        return None
    if latest:
        return valid[0]
    return valid[-1]


def _is_comparison_eligible_snapshot(path: Path, profile_id: int | None) -> bool:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return False
    if not isinstance(payload, dict):
        return False
    if payload.get("entity_type") != "matrimony_profile":
        return False
    if profile_id is not None and int(payload.get("entity_id") or payload.get("profile_id") or 0) != int(profile_id):
        return False
    if payload.get("schema_version") != "matrimony_profile_v2":
        return False
    if payload.get("render_capture_completed") is not True:
        return False
    if payload.get("comparison_eligible") is not True:
        return False
    if str(payload.get("render_capture_status") or "") != "complete":
        return False
    captured = str(payload.get("captured_at") or "")
    if captured != "":
        try:
            ts = time.strptime(captured[:19], "%Y-%m-%dT%H:%M:%S")
            age_hours = (time.time() - time.mktime(ts)) / 3600
            if age_hours > 72:
                return False
        except Exception:
            return False
    quality = int(payload.get("extraction_quality_score") or 0)
    if quality < 15:
        return False
    src = payload.get("capture_sources_present") if isinstance(payload.get("capture_sources_present"), dict) else {}
    if not bool(src.get("wizard")) or not bool(src.get("public_profile")):
        return False
    return True


def _comparison_files() -> list[Path]:
    base = config.OUTPUT_COMPARISONS_DIR
    if not base.exists() or not base.is_dir():
        return []
    return sorted(base.glob("snapshot_comparison_*.json"), key=lambda p: p.stat().st_mtime, reverse=True)


def _build_trends(current: list[dict[str, Any]]) -> list[dict[str, Any]]:
    files = _comparison_files()
    field_stats: dict[str, dict[str, Any]] = {}
    for fp in files[:200]:
        try:
            payload = json.loads(fp.read_text(encoding="utf-8"))
        except Exception:
            continue
        rows = payload.get("comparisons")
        if not isinstance(rows, list):
            continue
        seen_at = payload.get("generated_at") or time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(fp.stat().st_mtime))
        for r in rows:
            if not isinstance(r, dict):
                continue
            if r.get("status") != "fail":
                continue
            if r.get("suppressed") is True:
                continue
            field = r.get("field")
            if not isinstance(field, str):
                continue
            stat = field_stats.setdefault(
                field,
                {"field": field, "failure_count": 0, "first_seen": seen_at, "last_seen": seen_at},
            )
            stat["failure_count"] += 1
            if str(seen_at) < str(stat["first_seen"]):
                stat["first_seen"] = seen_at
            stat["last_seen"] = seen_at

    out = []
    for field, stat in field_stats.items():
        trend = "persistent" if int(stat["failure_count"]) >= 3 else "intermittent"
        out.append(
            {
                "field": field,
                "failure_count": int(stat["failure_count"]),
                "first_seen": stat["first_seen"],
                "last_seen": stat["last_seen"],
                "trend": trend,
            }
        )
    out.sort(key=lambda r: r["failure_count"], reverse=True)
    return out


def _history_index() -> dict[str, Any]:
    files = _comparison_files()
    latest_scores: list[dict[str, Any]] = []
    recent_failures: list[dict[str, Any]] = []
    for fp in files[:20]:
        try:
            payload = json.loads(fp.read_text(encoding="utf-8"))
        except Exception:
            continue
        latest_scores.append(
            {
                "file": fp.name,
                "health_score": payload.get("health_score"),
                "generated_at": payload.get("generated_at"),
            }
        )
        rows = payload.get("comparisons")
        if isinstance(rows, list):
            for r in rows:
                if isinstance(r, dict) and r.get("status") == "fail" and r.get("suppressed") is not True:
                    recent_failures.append(
                        {
                            "file": fp.name,
                            "field": r.get("field"),
                            "comparison_type": r.get("comparison_type"),
                            "severity": r.get("effective_severity") or r.get("severity"),
                        }
                    )
    trends = _build_trends([])
    persistent = [t for t in trends if t.get("trend") == "persistent"]
    return {
        "latest_scores": latest_scores,
        "recent_failures": recent_failures[:100],
        "persistent_failures": persistent,
    }


def cleanup_retention(
    snapshot_max_per_profile: int | None = None,
    comparison_max_files: int | None = None,
    dry_run: bool = True,
) -> dict[str, Any]:
    snapshot_keep = snapshot_max_per_profile or config.SNAPSHOT_RETENTION_PER_PROFILE
    comparison_keep = comparison_max_files or config.COMPARISON_RETENTION_FILES

    deleted_snapshots: list[str] = []
    deleted_comparisons: list[str] = []

    base = config.SNAPSHOT_BASE_DIR
    if base.exists() and base.is_dir():
        for profile_dir in sorted(base.glob("*_*")):
            if not profile_dir.is_dir():
                continue
            files = sorted(profile_dir.glob("snapshot_*.json"), key=lambda p: p.stat().st_mtime, reverse=True)
            for old in files[snapshot_keep:]:
                if not dry_run:
                    old.unlink(missing_ok=True)
                deleted_snapshots.append(str(old))

    comp_files = _comparison_files()
    for old in comp_files[comparison_keep:]:
        if not dry_run:
            old.unlink(missing_ok=True)
        deleted_comparisons.append(str(old))

    return {
        "dry_run": dry_run,
        "snapshot_max_per_profile": snapshot_keep,
        "comparison_max_files": comparison_keep,
        "snapshots_pruned": len(deleted_snapshots),
        "comparisons_pruned": len(deleted_comparisons),
        "snapshot_files": deleted_snapshots,
        "comparison_files": deleted_comparisons,
    }


def run(profile_id: int | None = None, latest: bool = True) -> dict[str, Any]:
    t0 = time.perf_counter()
    snapshot_path = _find_snapshot(profile_id=profile_id, latest=latest)
    if snapshot_path is None:
        return {
            "snapshot_id": None,
            "health_score": 65,
            "summary": {
                "compared_fields": 0,
                "mismatch_count": 0,
                "high_severity_count": 0,
                "medium_severity_count": 0,
                "pass_count": 0,
            },
            "comparisons": [],
            "reliability": {
                "reliability_status": "comparison reliability insufficient",
                "reliability_score": 0,
                "snapshot_confidence": 0,
                "extraction_completeness": 0,
            },
            "metrics": {
                "compare_duration_ms": int(round((time.perf_counter() - t0) * 1000)),
                "snapshot_load_ms": 0,
            },
            "error": "eligible_snapshot_not_found",
        }

    load_start = time.perf_counter()
    payload = json.loads(snapshot_path.read_text(encoding="utf-8"))
    load_ms = int(round((time.perf_counter() - load_start) * 1000))

    db_values = payload.get("db") if isinstance(payload.get("db"), dict) else {}
    api = payload.get("api") if isinstance(payload.get("api"), dict) else {}
    rendered = payload.get("rendered") if isinstance(payload.get("rendered"), dict) else {}
    repeaters = payload.get("repeaters") if isinstance(payload.get("repeaters"), dict) else {}
    rendered_fields = rendered.get("fields") if isinstance(rendered.get("fields"), dict) else {}
    if rendered_fields == {}:
        rendered_fields = rendered.get("fields_by_source") if isinstance(rendered.get("fields_by_source"), dict) else {}

    rules = _load_suppressions().get("suppressions") or []
    semantic_engine: SemanticNormalizationEngine | None = None
    skipped_empty: list[str] = []
    try:
        with db.connection_ctx() as conn:
            semantic_engine = SemanticNormalizationEngine(conn)
            comparisons = []
            for field in _resolve_fields(payload):
                db_v = db_values.get(field)
                api_v = _get_api_value(api, field)
                rendered_v, source_layer = _get_rendered_value(rendered_fields, field)
                if not _is_populated(db_v) and not _is_populated(api_v) and not _is_populated(rendered_v):
                    skipped_empty.append(field)
                    continue
                row = _compare_field(field, db_v, api_v, rendered_v, semantic_engine=semantic_engine)
                row = _apply_suppression(row, source_layer, rules)
                comparisons.append(row)
    except Exception:
        semantic_engine = None
        comparisons = []
        skipped_empty = []
        for field in _resolve_fields(payload):
            db_v = db_values.get(field)
            api_v = _get_api_value(api, field)
            rendered_v, source_layer = _get_rendered_value(rendered_fields, field)
            if not _is_populated(db_v) and not _is_populated(api_v) and not _is_populated(rendered_v):
                skipped_empty.append(field)
                continue
            row = _compare_field(field, db_v, api_v, rendered_v, semantic_engine=semantic_engine)
            row = _apply_suppression(row, source_layer, rules)
            comparisons.append(row)

    mismatch_count = sum(1 for r in comparisons if r.get("status") == "fail" and r.get("suppressed") is not True)
    high = sum(
        1
        for r in comparisons
        if r.get("status") == "fail" and r.get("suppressed") is not True and (r.get("effective_severity") or r.get("severity")) == "high"
    )
    medium = sum(
        1
        for r in comparisons
        if r.get("status") == "fail" and r.get("suppressed") is not True and (r.get("effective_severity") or r.get("severity")) == "medium"
    )
    passed = sum(1 for r in comparisons if r.get("status") == "pass")
    suppressed = sum(1 for r in comparisons if r.get("suppressed") is True)
    trends = _build_trends(comparisons)
    repeater_mismatches = _compare_repeaters(repeaters)
    rendered_flat = _flatten_rendered_scalar_map(rendered_fields)
    repeater_field_diffs = _repeater_field_level_diffs(payload, rendered_flat, api)
    registry = _load_canonical_registry()
    comparison_truth = _comparison_truth_metadata(
        registry, comparisons, skipped_empty, db_values, api, rendered_fields
    )
    repeater_high = sum(1 for r in repeater_mismatches if r.get("severity") == "high")
    extraction_completeness = int(payload.get("extraction_quality_score") or 0)
    snapshot_confidence = 100 if payload.get("comparison_eligible") is True else 40
    reliability_score = max(0, min(100, int((extraction_completeness * 0.6) + (snapshot_confidence * 0.4))))
    reliability_ok = reliability_score >= 45
    raw_health = max(0, _score(comparisons) - (repeater_high * 15))
    if not reliability_ok:
        health = 65
    else:
        health = raw_health
    if reliability_score < 60:
        health = min(health, 79)
    if reliability_score < 45:
        health = min(health, 69)

    return {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "snapshot_id": snapshot_path.stem,
        "snapshot_path": str(snapshot_path),
        "health_score": health,
        "summary": {
            "compared_fields": len(comparisons),
            "canonical_governed_logical_field_count": int(
                (comparison_truth.get("canonical_registry_meta") or {}).get("governed_logical_field_count") or 0
            ),
            "mismatch_count": mismatch_count,
            "high_severity_count": high,
            "medium_severity_count": medium,
            "pass_count": passed,
            "suppressed_count": suppressed,
        },
        "reliability": {
            "reliability_status": "ok" if reliability_ok else "comparison reliability insufficient",
            "reliability_score": reliability_score,
            "snapshot_confidence": snapshot_confidence,
            "extraction_completeness": extraction_completeness,
        },
        "comparisons": comparisons,
        "comparison_truth": comparison_truth,
        "repeater_field_diffs": repeater_field_diffs,
        "trends": trends,
        "repeater_mismatches": repeater_mismatches,
        "history_index": _history_index(),
        "metrics": {
            "compare_duration_ms": int(round((time.perf_counter() - t0) * 1000)),
            "snapshot_load_ms": load_ms,
        },
    }

