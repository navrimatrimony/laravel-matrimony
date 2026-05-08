from __future__ import annotations

import json
import time
from typing import Any

import db
from modules.auto_fix import run_recipe_pipeline
from modules.rollback import create_manifest


def run(recipe: str, execute: bool = False, preview_limit: int = 25) -> dict[str, Any]:
    with db.connection_ctx() as conn:
        preview = run_recipe_pipeline(conn=conn, recipe_name=recipe, execute=False, preview_limit=preview_limit)
        if not execute:
            return {
                "status": "preview_ready",
                "recipe": recipe,
                "pipeline": ["detect", "simulate", "backup", "repair", "validate", "rollback_if_needed"],
                "preview": preview,
            }

        backup_manifest = create_manifest(
            recipe_name=f"deterministic_{recipe}",
            table=str(((preview.get("preview") or {}).get("table")) or "matrimony_profiles"),
            primary_key=str(((preview.get("preview") or {}).get("primary_key")) or "id"),
            rows=((preview.get("preview") or {}).get("rows") or []),
        )
        executed = run_recipe_pipeline(conn=conn, recipe_name=recipe, execute=True, preview_limit=preview_limit)
        validation = run_recipe_pipeline(conn=conn, recipe_name=recipe, execute=False, preview_limit=preview_limit)
        rollback_recommended = bool((validation.get("preview") or {}).get("estimated_changes", 0))
        return {
            "status": "completed" if not rollback_recommended else "validation_failed",
            "recipe": recipe,
            "backup_manifest": backup_manifest,
            "execute_result": executed,
            "post_validation": validation,
            "rollback_recommended": rollback_recommended,
            "completed_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        }


if __name__ == "__main__":
    print(json.dumps({"error": "use via runner.py deterministic-repair"}, indent=2))

