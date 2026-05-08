"""
Data quality score + priority tiers from detector outputs (pure logic).
"""

from __future__ import annotations

def count_duplicate_groups(duplicates: list[dict]) -> int:
    n = 0
    for row in duplicates:
        if not isinstance(row, dict):
            continue
        if row.get("warning") or row.get("skipped") or row.get("error"):
            continue
        if row.get("type") in ("phone", "email"):
            n += 1
    return n


def count_duplicate_records(duplicates: list) -> int:
    """Total rows involved in phone/email duplicate groups (not just group count)."""
    total = 0
    for row in duplicates:
        if not isinstance(row, dict):
            continue
        if row.get("warning") or row.get("skipped") or row.get("error"):
            continue
        if row.get("type") not in ("phone", "email"):
            continue
        members = row.get("rows")
        if isinstance(members, list):
            total += len(members)
    return total


def count_validation_rows(validation_errors: list[dict]) -> int:
    """Rows with a concrete validation rule (excludes module-level warnings)."""
    return sum(1 for r in validation_errors if isinstance(r, dict) and r.get("rule"))


def count_mismatch_rows(mismatch: list[dict]) -> int:
    n = 0
    for row in mismatch:
        if not isinstance(row, dict):
            continue
        if row.get("warning") or row.get("skipped"):
            continue
        n += 1
    return n


def count_schema_rows(schema_issues: list[dict]) -> int:
    """Null-heavy columns only; index suggestions do not reduce quality score."""
    return sum(
        1
        for r in schema_issues
        if isinstance(r, dict)
        and "column" in r
        and r.get("kind") != "missing_index"
    )


def compute_quality_score(
    duplicates: list,
    validation_n: int,
    mismatch_n: int,
    schema_n: int,
) -> int:
    total_dup_records = count_duplicate_records(duplicates)
    duplicate_penalty = total_dup_records * 1.5
    score = 100.0 - duplicate_penalty - (validation_n * 1) - (mismatch_n * 2) - (schema_n * 1)
    score = max(0.0, min(100.0, score))
    return int(round(score))


def compute_priority_summary(
    duplicates: list[dict],
    validation_errors: list[dict],
    mismatch: list[dict],
    schema_issues: list[dict],
) -> dict[str, int]:
    """
    CRITICAL: duplicate groups, empty phone
    HIGH: mismatch rows, invalid phone (non-empty wrong length)
    MEDIUM: missing name / other missing fields
    LOW: schema null-heavy columns
    """
    critical = count_duplicate_groups(duplicates)
    high_mismatch = count_mismatch_rows(mismatch)
    medium = 0
    high_phone = 0
    critical_phone_empty = 0

    for row in validation_errors:
        if not isinstance(row, dict) or row.get("warning"):
            continue
        rule = row.get("rule")
        msg = str(row.get("message") or "")
        if rule == "missing_name":
            medium += 1
        elif rule == "invalid_phone":
            if "empty" in msg.lower() or "Phone is empty" in msg:
                critical_phone_empty += 1
            else:
                high_phone += 1

    critical += critical_phone_empty
    high = high_mismatch + high_phone
    low = count_schema_rows(schema_issues)

    return {
        "critical": critical,
        "high": high,
        "medium": medium,
        "low": low,
    }
