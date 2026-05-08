from __future__ import annotations

from typing import Any


def _severity_from_count(count: int) -> str:
    if count >= 100:
        return "critical"
    if count >= 25:
        return "high"
    if count >= 5:
        return "medium"
    return "low"


def _issue(
    issue: str,
    count: int,
    root_cause: str,
    suggested_fix: str,
    auto_fix: bool,
    rollback: bool,
) -> dict[str, Any]:
    sev = _severity_from_count(max(0, int(count)))
    return {
        "issue": issue,
        "severity": sev,
        "impact": f"{count} records potentially affected",
        "affected_count": int(count),
        "root_cause": root_cause,
        "suggested_fix": suggested_fix,
        "auto_fix_available": auto_fix,
        "rollback_available": rollback,
    }


def build_admin_report(report: dict[str, Any], comparison: dict[str, Any] | None = None) -> dict[str, Any]:
    duplicates = report.get("duplicates") if isinstance(report.get("duplicates"), list) else []
    validation_errors = report.get("validation_errors") if isinstance(report.get("validation_errors"), list) else []
    mismatch = report.get("mismatch") if isinstance(report.get("mismatch"), list) else []
    schema_issues = report.get("schema_issues") if isinstance(report.get("schema_issues"), list) else []

    cmp_summary = comparison.get("summary") if isinstance(comparison, dict) and isinstance(comparison.get("summary"), dict) else {}
    mismatch_count = int(cmp_summary.get("mismatch_count") or len(mismatch))
    high_sev = int(cmp_summary.get("high_severity_count") or 0)

    issues = [
        _issue(
            "Duplicate identities",
            len(duplicates),
            "Multiple rows share identity keys (phone/email) across ingestion paths.",
            "Review duplicate groups and apply dedupe recipe in dry-run first.",
            auto_fix=True,
            rollback=True,
        ),
        _issue(
            "Validation errors",
            len(validation_errors),
            "Missing/invalid required fields from source or normalization layers.",
            "Run validation-safe auto-fix recipes; unresolved rows should be queued for admin review.",
            auto_fix=True,
            rollback=True,
        ),
        _issue(
            "Cross-layer mismatches",
            mismatch_count,
            "Canonical DB values differ from API/rendered snapshots.",
            "Run comparison workflow, inspect diffs, then approve targeted fix recipes.",
            auto_fix=True,
            rollback=True,
        ),
        _issue(
            "Schema integrity risks",
            len(schema_issues),
            "High null-ratio / sparse columns / weak indexing coverage.",
            "Apply index and hierarchy repair recipes with pre-fix simulation.",
            auto_fix=True,
            rollback=True,
        ),
        _issue(
            "High severity lineage/comparison",
            high_sev,
            "Multiple high severity discrepancies in deterministic comparison output.",
            "Pause broad fixes, review impacted entities, then run scoped auto-fix plan.",
            auto_fix=False,
            rollback=True,
        ),
    ]

    return {
        "schema_version": "1",
        "report_type": "admin_human_diagnostics",
        "generated_at": report.get("meta", {}).get("generated_at"),
        "overview": {
            "quality_score": int(report.get("quality_score") or 0),
            "total_issues": sum(int(i["affected_count"]) for i in issues),
            "critical_issues": sum(1 for i in issues if i["severity"] == "critical"),
        },
        "issues": issues,
        "recommendations": [
            "Start with dry-run previews for all fixes.",
            "Only execute approved workflows in sequence: detect -> preview -> backup -> execute -> validate.",
            "Use rollback manifests for any failed validation.",
        ],
        "ai_ready_explanations": {
            "explanation_blocks": issues,
            "language_targets": ["en", "mr"],
            "note": "Prepared for future AI explanation services; no AI generation in this layer.",
        },
    }

