from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
ENGINE_ROOT = ROOT / "python-data-engine"
FIXTURES = ENGINE_ROOT / "tests" / "fixtures" / "data-lineage"
GOLDEN = ENGINE_ROOT / "tests" / "golden" / "data-lineage"
RUNNER = ENGINE_ROOT / "tests" / "lineage_evidence_runner.py"
VALID_REPORT = ENGINE_ROOT / "output" / "reports" / "engine_analyze_20260506_070000.json"
ALLOWED_SEVERITY = {"high", "medium", "low"}


def _run_json(args: list[str]) -> dict:
    proc = subprocess.run(args, capture_output=True, text=True, check=False)
    if proc.returncode != 0:
        raise RuntimeError(f"command_failed: {' '.join(args)}\n{proc.stderr}\n{proc.stdout}")
    return json.loads(proc.stdout)


def _normalize(d: dict) -> dict:
    out = json.loads(json.dumps(d))
    metrics = out.get("metrics")
    if isinstance(metrics, dict):
        metrics["scan_duration_ms"] = 0
        metrics["memory_peak_kb"] = 0
    impl = out.get("implementation")
    if isinstance(impl, dict) and "repo_root" in impl:
        impl["repo_root"] = "<repo_root>"
    return out


def _assert_contract(obj: dict) -> None:
    for key in (
        "summary",
        "manifest_errors",
        "wrong_sources",
        "multi_source_conflicts",
        "wizard_public_mismatches",
        "missing_render_risks",
        "metrics",
    ):
        if key not in obj:
            raise AssertionError(f"missing_key:{key}")
    hs = int(obj["summary"]["health_score"])
    if hs < 0 or hs > 100:
        raise AssertionError("health_score_out_of_bounds")
    for bucket in (
        "manifest_errors",
        "wrong_sources",
        "multi_source_conflicts",
        "wizard_public_mismatches",
        "missing_render_risks",
    ):
        rows = obj.get(bucket, [])
        if isinstance(rows, list):
            for row in rows:
                if isinstance(row, dict) and "severity" in row:
                    if str(row["severity"]) not in ALLOWED_SEVERITY:
                        raise AssertionError(f"invalid_severity:{row['severity']}")
    m = obj.get("metrics", {})
    for mk in ("scan_duration_ms", "memory_peak_kb", "blade_count_scanned", "manifest_field_count"):
        if mk not in m:
            raise AssertionError(f"missing_metric:{mk}")


def main() -> int:
    malformed = _run_json(
        [sys.executable, str(RUNNER), "--manifest", str(FIXTURES / "invalid_malformed.yml")]
    )
    forced = _run_json(
        [sys.executable, str(RUNNER), "--manifest", str(FIXTURES / "detection_forced.yml")]
    )
    forced2 = _run_json(
        [sys.executable, str(RUNNER), "--manifest", str(FIXTURES / "detection_forced.yml")]
    )

    # No crash + contract
    _assert_contract(malformed)
    _assert_contract(forced)

    # Deterministic outputs (ignore volatile timing/memory + absolute repo root)
    if _normalize(forced) != _normalize(forced2):
        raise AssertionError("non_deterministic_forced_fixture_output")

    # Golden checks
    malformed_golden = json.loads((GOLDEN / "malformed-manifest.json").read_text(encoding="utf-8"))
    if not malformed["manifest_errors"] or not str(malformed["manifest_errors"][0].get("detail", "")).startswith(
        malformed_golden["manifest_errors"][0]["detail_prefix"]
    ):
        raise AssertionError("malformed_manifest_golden_mismatch")

    forced_golden = json.loads((GOLDEN / "forced-detections.json").read_text(encoding="utf-8"))
    fs = forced["summary"]
    gs = forced_golden["summary"]
    if {k: int(fs[k]) for k in gs.keys()} != gs:
        raise AssertionError("forced_summary_golden_mismatch")

    # Valid report contract checks
    if not VALID_REPORT.is_file():
        raise AssertionError(f"valid_report_missing:{VALID_REPORT}")
    report = json.loads(VALID_REPORT.read_text(encoding="utf-8"))
    dl = report.get("data_lineage")
    if not isinstance(dl, dict):
        raise AssertionError("valid_report_missing_data_lineage")
    _assert_contract(dl)

    print(
        json.dumps(
            {
                "ok": True,
                "checks": [
                    "json_keys_exist",
                    "severity_values_valid",
                    "scoring_bounded",
                    "no_crashes",
                    "metrics_emitted",
                    "deterministic_outputs",
                ],
            },
            indent=2,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
