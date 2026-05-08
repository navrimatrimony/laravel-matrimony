from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
ENGINE_ROOT = SCRIPT_DIR.parent
SCRIPTS_DIR = ENGINE_ROOT / "scripts"
if str(SCRIPTS_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_DIR))

from modules import data_lineage_engine as dle


def _run_manifest(manifest_path: Path) -> dict:
    orig_manifest = dle.MANIFEST_PATH
    orig_repo = dle.REPO_ROOT
    try:
        dle.MANIFEST_PATH = manifest_path
        # Keep repo root to project root so relative fixture blade paths resolve.
        dle.REPO_ROOT = Path(__file__).resolve().parents[2]
        # Avoid DB dependency for deterministic fixture runs.
        dle._table_exists = lambda _conn, _table: True
        dle._column_exists = lambda _conn, _table, _column: True
        return dle.analyze(None)
    finally:
        dle.MANIFEST_PATH = orig_manifest
        dle.REPO_ROOT = orig_repo


def _regex_probe(snippet: str) -> dict:
    acc = dle._collect_accessors(snippet)
    coalesce = []
    for m in dle._RE_COALESCE_PU.finditer(snippet):
        coalesce.append({"lhs": f"profile.{m.group(1)}", "rhs": f"user.{m.group(2)}"})
    for m in dle._RE_COALESCE_UP.finditer(snippet):
        coalesce.append({"lhs": f"user.{m.group(1)}", "rhs": f"profile.{m.group(2)}"})
    return {
        "raw": snippet,
        "matches": {
            "profile_attrs": sorted(acc["profile"]),
            "user_attrs": sorted(acc["user"]),
            "coalesce_pairs": coalesce,
        },
        "normalized_tokens": sorted(
            [f"profile.{x}" for x in acc["profile"]] + [f"user.{x}" for x in acc["user"]]
        ),
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--manifest", type=str, default="")
    parser.add_argument("--regex-only", action="store_true")
    args = parser.parse_args()

    if args.regex_only:
        snippet = "\n".join(
            [
                "$profile->height_cm",
                "$user->height",
                "$profile->city ?? $user->city",
                "optional($profile)->education",
                "data_get($profile, 'mother_tongue')",
                "$profile['occupation']",
            ]
        )
        print(json.dumps(_regex_probe(snippet), indent=2))
        return 0

    if not args.manifest:
        raise SystemExit("--manifest is required unless --regex-only is used")

    result = _run_manifest(Path(args.manifest))
    print(json.dumps(result, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
