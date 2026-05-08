from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any

import config


def _load_canonical_registry_file() -> dict[str, Any]:
    p = config.ENGINE_ROOT / "config" / "governance" / "canonical_field_registry.json"
    if not p.is_file():
        return {"meta": {}, "entries": []}
    try:
        raw = json.loads(p.read_text(encoding="utf-8"))
    except Exception:
        return {"meta": {}, "entries": []}
    return raw if isinstance(raw, dict) else {"meta": {}, "entries": []}


CRITICAL_SECTIONS = {"basic-info", "horoscope", "relatives", "property", "education-career", "about-preferences"}

RELATION_KEY_MAP = {
    "siblings": "profile_siblings",
    "children": "profile_children",
    "education_history": "profile_education",
    "career_history": "profile_career",
    "relatives": "profile_relatives",
    "property_assets": "profile_property_assets",
    "contacts": "profile_contacts",
}

SECTION_HINTS = [
    "basic-info",
    "physical",
    "education-career",
    "family-details",
    "siblings",
    "relatives",
    "alliance",
    "property",
    "horoscope",
    "about-preferences",
]


def _extract_input_names(text: str) -> list[str]:
    names = re.findall(r'name\s*=\s*"([^"]+)"', text, flags=re.IGNORECASE)
    names.extend(re.findall(r"x-[a-zA-Z0-9\-\._:]+[^>]*\sname\s*=\s*\"([^\"]+)\"", text, flags=re.IGNORECASE))
    names.extend(re.findall(r"wire:model(?:\.defer|\.lazy)?\s*=\s*\"([^\"]+)\"", text, flags=re.IGNORECASE))
    out: list[str] = []
    seen: set[str] = set()
    for n in names:
        x = _normalize_field_name(n.strip())
        if x == "" or x in seen:
            continue
        seen.add(x)
        out.append(x)
    return out


def _field_type(name: str) -> str:
    if "[]" in name:
        return "repeater_array"
    if re.search(r"\[[a-zA-Z0-9_]+\]", name):
        return "nested_array"
    if "select" in name or name.endswith("_id"):
        return "relation_select"
    return "scalar"


def _section_for_path(path: Path) -> str:
    p = path.as_posix().lower()
    for key in SECTION_HINTS:
        if key in p:
            return key
    stem = path.stem.lower()
    for key in SECTION_HINTS:
        if key in stem:
            return key
    if "preference" in p or "alliance" in p:
        return "about-preferences"
    if "contact" in p:
        return "basic-info"
    return "unknown"


def _normalize_field_name(name: str) -> str:
    n = name.strip()
    if n == "":
        return ""
    n = n.replace(".0.", "[].").replace(".1.", "[].")
    n = re.sub(r"\.\d+\.", "[].", n)
    n = re.sub(r"\.\d+$", "[]", n)
    n = n.replace(".", "[]") if "." in n and "[" not in n else n
    n = n.replace("[]][]", "[][]")
    if any(x in n for x in ("{{", "}}", "namePrefix", "__INDEX__", "__NAME__")):
        n = re.sub(r"\[\{\{[^}]+\}\}\]", "[]", n)
        n = n.replace("$idx", "[]").replace("$index", "[]").replace("$i", "[]")
    n = re.sub(r"\[\d+\]", "[]", n)
    n = re.sub(r"\[\{\{[^}]+\}\}\]", "[]", n)
    n = re.sub(r"\[[a-zA-Z_][a-zA-Z0-9_]*\]", "[]", n) if n.count("[") > 1 else n
    return n


def _storage_source(field_path: str) -> tuple[str, str | None]:
    if field_path.startswith("siblings"):
        return ("repeaters.siblings", RELATION_KEY_MAP["siblings"])
    if field_path.startswith("children"):
        return ("repeaters.children", RELATION_KEY_MAP["children"])
    if field_path.startswith("education_history"):
        return ("repeaters.education_history", RELATION_KEY_MAP["education_history"])
    if field_path.startswith("career_history"):
        return ("repeaters.career_history", RELATION_KEY_MAP["career_history"])
    if field_path.startswith("relatives"):
        return ("repeaters.relatives", RELATION_KEY_MAP["relatives"])
    if field_path.startswith("property_assets"):
        return ("repeaters.property_assets", RELATION_KEY_MAP["property_assets"])
    if field_path.startswith("contacts"):
        return ("repeaters.contacts", RELATION_KEY_MAP["contacts"])
    if field_path.startswith("partner_preferences"):
        return ("db.partner_preferences", "profile_preference_criteria")
    return ("db", None)


def _is_audited(field_path: str, snapshot: dict[str, Any]) -> bool:
    field_path = _normalize_field_name(field_path)
    if field_path == "":
        return False
    db = snapshot.get("db") if isinstance(snapshot.get("db"), dict) else {}
    reps = snapshot.get("repeaters") if isinstance(snapshot.get("repeaters"), dict) else {}
    scalar_key = field_path.split("[")[0]
    if scalar_key in db:
        return True
    for rep_key, rows in reps.items():
        if field_path.startswith(f"{rep_key}["):
            return True
    return False


def _comparison_supported(field_type: str, field_path: str, snapshot: dict[str, Any]) -> str:
    reps = snapshot.get("repeaters") if isinstance(snapshot.get("repeaters"), dict) else {}
    if field_type in {"repeater_array", "nested_array"}:
        for k in reps.keys():
            if field_path.startswith(k):
                return "full"
        return "unsupported"
    return "full"


def _build_inventory(snapshot: dict[str, Any]) -> list[dict[str, Any]]:
    views_root = config.ENGINE_ROOT.parent / "resources" / "views" / "matrimony" / "profile" / "wizard"
    files = sorted(views_root.rglob("*.blade.php")) if views_root.exists() else []
    inventory: list[dict[str, Any]] = []
    for fp in files:
        text = fp.read_text(encoding="utf-8", errors="ignore")
        section = _section_for_path(fp)
        for name in _extract_input_names(text):
            ftype = _field_type(name)
            storage, relation = _storage_source(name)
            audited = _is_audited(name, snapshot)
            cmp_state = _comparison_supported(ftype, name, snapshot)
            inventory.append(
                {
                    "field_path": name,
                    "section": section,
                    "field_type": ftype,
                    "storage_source": storage,
                    "relation_table": relation,
                    "audited_status": bool(audited),
                    "comparison_support_status": cmp_state,
                }
            )
    db = snapshot.get("db") if isinstance(snapshot.get("db"), dict) else {}
    for key in db.keys():
        section = "basic-info"
        if key in {"nakshatra", "rashi", "mangal_dosh"}:
            section = "horoscope"
        elif key in {"family_type"}:
            section = "family-details"
        elif key in {"partner_preferences"}:
            section = "about-preferences"
        storage, relation = _storage_source(str(key))
        inventory.append(
            {
                "field_path": str(key),
                "section": section,
                "field_type": "scalar" if key != "partner_preferences" else "nested_array",
                "storage_source": storage,
                "relation_table": relation,
                "audited_status": True,
                "comparison_support_status": "full",
            }
        )
    reps = snapshot.get("repeaters") if isinstance(snapshot.get("repeaters"), dict) else {}
    for rep_key, rows in reps.items():
        storage, relation = _storage_source(str(rep_key))
        section = "unknown"
        if rep_key in {"siblings", "children"}:
            section = "family-details"
        elif rep_key in {"education_history", "career_history"}:
            section = "education-career"
        elif rep_key == "relatives":
            section = "relatives"
        elif rep_key == "property_assets":
            section = "property"
        elif rep_key == "contacts":
            section = "basic-info"
        row = rows[0] if isinstance(rows, list) and rows else {}
        if isinstance(row, dict):
            for col in row.keys():
                inventory.append(
                    {
                        "field_path": f"{rep_key}[].{col}",
                        "section": section,
                        "field_type": "repeater_array",
                        "storage_source": storage,
                        "relation_table": relation,
                        "audited_status": True,
                        "comparison_support_status": "full",
                    }
                )
    deduped: list[dict[str, Any]] = []
    seen_keys: set[str] = set()
    for row in inventory:
        key = f"{row.get('section')}::{row.get('field_path')}"
        if key in seen_keys:
            continue
        seen_keys.add(key)
        deduped.append(row)
    return deduped


def _auto_governance_alerts(inventory: list[dict[str, Any]]) -> dict[str, Any]:
    baseline_path = config.OUTPUT_COVERAGE_DIR / "field_inventory_baseline.json"
    current_fields = sorted({str(r.get("field_path") or "") for r in inventory if str(r.get("field_path") or "") != ""})
    baseline_fields: list[str] = []
    if baseline_path.exists():
        try:
            payload = json.loads(baseline_path.read_text(encoding="utf-8"))
            baseline_fields = [str(x) for x in payload.get("fields", []) if str(x) != ""]
        except Exception:
            baseline_fields = []
    else:
        baseline_path.write_text(json.dumps({"fields": current_fields}, indent=2), encoding="utf-8")
    new_fields = sorted(set(current_fields) - set(baseline_fields))
    return {
        "baseline_exists": bool(baseline_fields),
        "new_fields_detected": len(new_fields),
        "new_fields": new_fields[:200],
    }


def _section_coverage(inventory: list[dict[str, Any]]) -> list[dict[str, Any]]:
    grouped: dict[str, dict[str, int]] = {}
    for row in inventory:
        s = str(row.get("section") or "unknown")
        g = grouped.setdefault(s, {"detected": 0, "audited": 0})
        g["detected"] += 1
        if bool(row.get("audited_status")):
            g["audited"] += 1
    out: list[dict[str, Any]] = []
    for section, stats in sorted(grouped.items(), key=lambda x: x[0]):
        detected = int(stats["detected"])
        audited = int(stats["audited"])
        pct = round((audited / detected) * 100, 2) if detected > 0 else 0.0
        out.append({"section": section, "audited": audited, "detected": detected, "coverage_percent": pct})
    return out


def _silent_data_loss(snapshot: dict[str, Any]) -> list[dict[str, Any]]:
    alerts: list[dict[str, Any]] = []
    dbv = snapshot.get("db") if isinstance(snapshot.get("db"), dict) else {}
    api_profile = {}
    api = snapshot.get("api")
    if isinstance(api, dict) and isinstance(api.get("profile"), dict):
        api_profile = api.get("profile")  # type: ignore[assignment]
    rendered = snapshot.get("rendered") if isinstance(snapshot.get("rendered"), dict) else {}
    rf = rendered.get("fields") if isinstance(rendered.get("fields"), dict) else {}
    layered = rendered.get("fields_by_source") if isinstance(rendered.get("fields_by_source"), dict) else {}
    pub = rf.get("public_profile") if isinstance(rf.get("public_profile"), dict) else {}
    if pub == {} and isinstance(layered.get("public_profile"), dict):
        pub = layered.get("public_profile")  # type: ignore[assignment]
    for k, v in dbv.items():
        if v is None or str(v).strip() == "":
            continue
        if k not in api_profile:
            alerts.append({"type": "saved_in_db_missing_in_api", "field": k})
        rv = pub.get(k) if isinstance(pub, dict) else None
        raw = rv.get("raw_rendered") if isinstance(rv, dict) else rv
        if raw in (None, "") and isinstance(rf.get(k), (str, int, float)):
            raw = rf.get(k)
        if raw in (None, ""):
            alerts.append({"type": "api_present_public_missing", "field": k})
    reps = snapshot.get("repeaters") if isinstance(snapshot.get("repeaters"), dict) else {}
    for rep_key, rows in reps.items():
        if isinstance(rows, list) and len(rows) == 0:
            alerts.append({"type": "repeater_rows_silently_dropped", "field": rep_key})
    return alerts


def _completeness(snapshot: dict[str, Any]) -> dict[str, Any]:
    dbv = snapshot.get("db") if isinstance(snapshot.get("db"), dict) else {}
    reps = snapshot.get("repeaters") if isinstance(snapshot.get("repeaters"), dict) else {}
    scalar_total = len(dbv)
    scalar_filled = sum(1 for v in dbv.values() if v not in (None, "", []))
    relation_total = len(reps)
    relation_filled = sum(1 for v in reps.values() if isinstance(v, list) and len(v) > 0)
    horoscope_keys = ["nakshatra", "rashi", "mangal_dosh"]
    horoscope_filled = sum(1 for k in horoscope_keys if dbv.get(k) not in (None, ""))
    pref = dbv.get("partner_preferences")
    pref_dict = pref if isinstance(pref, dict) else {}
    pref_filled = sum(1 for v in pref_dict.values() if v not in (None, "", []))
    pref_total = max(1, len(pref_dict))
    return {
        "actual_profile_completeness": round((scalar_filled / max(1, scalar_total)) * 100, 2),
        "relation_completeness": round((relation_filled / max(1, relation_total)) * 100, 2),
        "family_completeness": round((1 if len(reps.get("siblings", [])) > 0 or len(reps.get("children", [])) > 0 else 0) * 100, 2),
        "horoscope_completeness": round((horoscope_filled / len(horoscope_keys)) * 100, 2),
        "partner_preference_completeness": round((pref_filled / pref_total) * 100, 2),
    }


def build_full_profile_coverage(snapshot: dict[str, Any], comparison: dict[str, Any] | None) -> dict[str, Any]:
    inventory = _build_inventory(snapshot)
    auto_governance = _auto_governance_alerts(inventory)
    total = len(inventory)
    audited = sum(1 for i in inventory if bool(i.get("audited_status")))
    unsupported = [i for i in inventory if str(i.get("comparison_support_status")) != "full"]
    repeaters = [i for i in inventory if str(i.get("field_type")) == "repeater_array"]
    unsupported_repeaters = [i for i in repeaters if str(i.get("comparison_support_status")) != "full"]
    unsupported_rel = [i for i in inventory if i.get("relation_table") and str(i.get("comparison_support_status")) != "full"]
    section_cov = _section_coverage(inventory)
    silent = _silent_data_loss(snapshot)
    completeness = _completeness(snapshot)
    coverage_score = round((audited / max(1, total)) * 100, 2)
    if int(auto_governance.get("new_fields_detected") or 0) > 0:
        coverage_score = max(0.0, round(coverage_score - min(15.0, float(auto_governance["new_fields_detected"]) * 0.5), 2))
    reg = _load_canonical_registry_file()
    reg_meta = reg.get("meta") if isinstance(reg.get("meta"), dict) else {}
    governed_logical = int(reg_meta.get("governed_logical_field_count") or 0)
    comparison_supported_reg = int(reg_meta.get("comparison_supported_count") or 0)
    rpt = {
        "coverage_score": coverage_score,
        "coverage_label": f"{audited} / {total} fields audited",
        "totals": {
            "total_detected_fields": total,
            "audited_fields": audited,
            "unaudited_fields": max(0, total - audited),
            "partial_support": len(unsupported),
            "unsupported_repeaters": len(unsupported_repeaters),
            "unsupported_relations": len(unsupported_rel),
            "canonical_governed_logical_field_count": governed_logical,
            "canonical_comparison_supported_count": comparison_supported_reg,
        },
        "section_coverage": section_cov,
        "unsupported_fields": unsupported[:300],
        "silent_data_loss_alerts": silent,
        "completeness_governance": completeness,
        "repeater_mismatches": (comparison or {}).get("repeater_mismatches", []),
        "auto_field_governance": auto_governance,
        "canonical_registry": {
            "path": str(config.ENGINE_ROOT / "config" / "governance" / "canonical_field_registry.json"),
            "meta": reg_meta,
            "note": "Dashboard totals above are inventory-derived; canonical_* keys reconcile with comparison_truth / registry SSOT.",
        },
    }

    config.OUTPUT_COVERAGE_DIR.mkdir(parents=True, exist_ok=True)
    (config.OUTPUT_COVERAGE_DIR / "full_profile_field_inventory.json").write_text(
        json.dumps({"inventory": inventory}, indent=2, default=str), encoding="utf-8"
    )
    (config.OUTPUT_COVERAGE_DIR / "full_profile_coverage.json").write_text(
        json.dumps(rpt, indent=2, default=str), encoding="utf-8"
    )
    (config.OUTPUT_COVERAGE_DIR / "repeater_mismatches.json").write_text(
        json.dumps({"repeater_mismatches": (comparison or {}).get("repeater_mismatches", [])}, indent=2, default=str),
        encoding="utf-8",
    )
    (config.OUTPUT_COVERAGE_DIR / "silent_data_loss.json").write_text(
        json.dumps({"alerts": silent}, indent=2, default=str), encoding="utf-8"
    )
    (config.OUTPUT_COVERAGE_DIR / "field_governance_map.json").write_text(
        json.dumps(
            {
                "field_governance_map": [
                    {
                        "wizard_field": row.get("field_path"),
                        "db_source": row.get("storage_source"),
                        "api_source": "api.profile.<field_alias>",
                        "public_profile_source": "rendered.fields.public_profile",
                        "comparison_support_status": row.get("comparison_support_status"),
                    }
                    for row in inventory
                ]
            },
            indent=2,
            default=str,
        ),
        encoding="utf-8",
    )
    return rpt

