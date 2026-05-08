from __future__ import annotations

from typing import Any


def _clamp(v: int) -> int:
    return max(0, min(100, int(v)))


def build_health_scores(
    report: dict[str, Any],
    comparison: dict[str, Any] | None = None,
    coverage: dict[str, Any] | None = None,
) -> dict[str, Any]:
    duplicate_penalty = len(report.get("duplicates") or []) * 2
    validation_penalty = len(report.get("validation_errors") or []) * 1
    mismatch_penalty = len(report.get("mismatch") or []) * 2
    schema_penalty = len(report.get("schema_issues") or []) * 2
    module_scores = {
        "duplicates": _clamp(100 - duplicate_penalty),
        "validation": _clamp(100 - validation_penalty),
        "mismatch": _clamp(100 - mismatch_penalty),
        "schema": _clamp(100 - schema_penalty),
    }
    if isinstance(comparison, dict):
        module_scores["comparison"] = _clamp(int(comparison.get("health_score") or 0))
    coverage_score = int((coverage or {}).get("coverage_score") or 0)
    module_scores["coverage"] = _clamp(coverage_score)
    unsupported_penalty = int((coverage or {}).get("totals", {}).get("partial_support", 0)) if isinstance((coverage or {}).get("totals"), dict) else 0
    critical_sections = (coverage or {}).get("section_coverage") if isinstance((coverage or {}), dict) else []
    critical_gap_penalty = 0
    if isinstance(critical_sections, list):
        for row in critical_sections:
            if not isinstance(row, dict):
                continue
            section = str(row.get("section") or "")
            cov = float(row.get("coverage_percent") or 0)
            if section in {"basic-info", "horoscope", "relatives", "property"} and cov < 50:
                critical_gap_penalty += 8
    weighted = [
        ("duplicates", 0.30),
        ("validation", 0.20),
        ("mismatch", 0.25),
        ("schema", 0.15),
        ("comparison", 0.10),
        ("coverage", 0.20),
    ]
    total = 0.0
    for key, w in weighted:
        total += float(module_scores.get(key, 100)) * w
    overall = _clamp(round(total) - unsupported_penalty - critical_gap_penalty)
    if coverage_score < 80:
        overall = min(overall, 89)
    if coverage_score < 60:
        overall = min(overall, 79)
    comparison_rel = comparison.get("reliability") if isinstance(comparison, dict) and isinstance(comparison.get("reliability"), dict) else {}
    extraction_rel = int(comparison_rel.get("extraction_completeness") or 0)
    snapshot_rel = int(comparison_rel.get("snapshot_confidence") or 0)
    data_correctness = _clamp(round((module_scores.get("duplicates", 100) + module_scores.get("validation", 100) + module_scores.get("mismatch", 100) + module_scores.get("schema", 100)) / 4))
    return {
        "module_health_score": module_scores,
        "overall_platform_health": overall,
        "reliability_scores": {
            "data_correctness_score": data_correctness,
            "coverage_score": coverage_score,
            "extraction_reliability_score": extraction_rel,
            "snapshot_reliability_score": snapshot_rel,
        },
        "risk_weighting": {k: w for k, w in weighted},
        "coverage_penalties": {
            "unsupported_field_penalty": unsupported_penalty,
            "unaudited_critical_section_penalty": critical_gap_penalty,
            "coverage_score": coverage_score,
        },
        "trend_history": {
            "note": "Trend history is derived from persisted comparison history and repeated runs.",
        },
    }

