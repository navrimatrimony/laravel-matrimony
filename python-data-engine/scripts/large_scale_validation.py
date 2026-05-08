from __future__ import annotations

import json
import os
import time
from pathlib import Path
from typing import Any

import config
from bulk_governance_validator import run as run_bulk


def _snapshot_size_bytes() -> int:
    total = 0
    base = config.SNAPSHOT_BASE_DIR
    if not base.exists():
        return 0
    for p in base.glob("*_*/snapshot_*.json"):
        try:
            total += p.stat().st_size
        except OSError:
            continue
    return total


def run(limit: int = 1000) -> dict[str, Any]:
    t0 = time.perf_counter()
    before_size = _snapshot_size_bytes()
    bulk = run_bulk(limit=limit)
    after_size = _snapshot_size_bytes()
    out: dict[str, Any] = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "profiles_targeted": limit,
        "profiles_scanned": int(bulk.get("profiles_scanned") or 0),
        "runtime_ms": int(round((time.perf_counter() - t0) * 1000)),
        "memory_note": "Use system profiler for exact RSS in production worker host.",
        "queue_throughput_note": "Track governance queues via governance:queue-health metrics.",
        "snapshot_growth_bytes": max(0, after_size - before_size),
    }
    (config.OUTPUT_HEALTH_DIR / "large_scale_validation_report.json").write_text(json.dumps(out, indent=2), encoding="utf-8")
    return out

