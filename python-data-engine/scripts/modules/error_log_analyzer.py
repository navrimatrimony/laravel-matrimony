"""
Read Laravel log file and summarize common error patterns.
"""

from __future__ import annotations

import re
from collections import Counter
from pathlib import Path

import config


def _tail_lines(path: Path, max_lines: int = 20_000) -> list[str]:
    if not path.is_file():
        return []
    # Read last N lines without loading entire huge file into memory
    try:
        with path.open("rb") as fh:
            fh.seek(0, 2)
            size = fh.tell()
            block = 8192
            data = b""
            lines_found = 0
            while size > 0 and lines_found <= max_lines:
                read_size = min(block, size)
                size -= read_size
                fh.seek(size)
                data = fh.read(read_size) + data
                lines_found = data.count(b"\n")
            text = data.decode("utf-8", errors="replace")
    except OSError:
        return []

    all_lines = text.splitlines()
    return all_lines[-max_lines:]


def run() -> dict:
    path = config.LARAVEL_LOG_PATH
    lines = _tail_lines(path)

    if not lines:
        return {
            "log_path": str(path),
            "exists": path.is_file(),
            "line_count_analyzed": 0,
            "top_patterns": [],
            "sample_lines": [],
            "suggestions": [],
        }

    pattern_hits: Counter[str] = Counter()
    samples: list[str] = []

    patterns = [
        (r"SQLSTATE\[[^\]]+\]", "sqlstate"),
        (r"production\.ERROR:", "laravel_error_marker"),
        (r"local\.ERROR:", "laravel_error_marker"),
        (r"exception\s+'[^']+'", "quoted_exception"),
        (r"Uncaught \w+Exception", "uncaught_exception"),
        (r"Stack trace:", "stack_trace"),
        (r"Fatal error", "fatal_error"),
        (r"Allowed memory size", "memory_limit"),
        (r"Maximum execution time", "timeout"),
    ]

    for line in lines:
        lower = line
        for regex, label in patterns:
            if re.search(regex, lower, re.IGNORECASE):
                pattern_hits[label] += 1
        if ".ERROR:" in line or "ERROR:" in line:
            pattern_hits["error_token"] += 1
            if len(samples) < 50:
                samples.append(line[:500])

    top = [{"pattern": k, "count": v} for k, v in pattern_hits.most_common(20)]

    unknown_col_hits = sum(1 for line in lines if "Unknown column" in line)
    sqlstate_hits = sum(1 for line in lines if "SQLSTATE" in line)

    suggestions: list[dict] = []
    if unknown_col_hits > 0:
        suggestions.append(
            {
                "type": "schema_error",
                "count": unknown_col_hits,
                "suggestion": "Column missing in DB. Check migrations.",
            }
        )
    if sqlstate_hits > 0:
        suggestions.append(
            {
                "type": "query_error",
                "count": sqlstate_hits,
                "suggestion": "Check SQL queries or null constraints.",
            }
        )

    return {
        "log_path": str(path),
        "exists": True,
        "line_count_analyzed": len(lines),
        "top_patterns": top,
        "sample_lines": samples,
        "suggestions": suggestions,
    }
