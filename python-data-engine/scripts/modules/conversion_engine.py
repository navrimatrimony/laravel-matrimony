"""
Conversion intelligence: turn profile + quality signals into actions (no outbound sends).

notification_candidates use {user_id, type} for future WhatsApp / in-app hooks.
"""

from __future__ import annotations

from typing import Any


def generate_conversion_insights(
    _profile_intelligence: dict[str, Any] | None,
    quality_score: int | None,
    profile_analysis: list | None,
) -> dict[str, Any]:
    """Uses per-row profile_analysis counts; aggregates passed separately for future enrichment."""

    analysis = [p for p in (profile_analysis or []) if isinstance(p, dict)]
    rows = [
        p
        for p in analysis
        if p.get("user_id") is not None
        and "completeness_score" in p
        and p.get("module") is None
    ]

    low_profile_score_users = sum(1 for p in rows if int(p.get("completeness_score") or 0) < 50)
    no_photo_users = sum(1 for p in rows if "profile_photo" in (p.get("missing_fields") or []))
    high_intent_users = sum(1 for p in rows if int(p.get("completeness_score") or 0) > 80)

    qs: int | None = int(quality_score) if quality_score is not None else None

    recommended_actions: list[dict[str, str]] = []
    if no_photo_users > 0:
        recommended_actions.append(
            {
                "type": "profile_improvement",
                "action": "Send notification to upload photo",
            }
        )
    if low_profile_score_users > 0:
        recommended_actions.append(
            {
                "type": "profile_improvement",
                "action": "Prompt users with low completeness to finish core profile fields",
            }
        )
    if qs is not None and qs > 80:
        recommended_actions.append(
            {
                "type": "upgrade_prompt",
                "action": "Show premium plan banner",
            }
        )
    elif high_intent_users > 0:
        recommended_actions.append(
            {
                "type": "upgrade_prompt",
                "action": "Surface premium features to highly complete profiles",
            }
        )

    notification_candidates: list[dict[str, Any]] = []
    seen_nc: set[tuple[int, str]] = set()
    for p in rows:
        uid = p.get("user_id")
        if uid is None:
            continue
        try:
            uid_i = int(uid)
        except (TypeError, ValueError):
            continue
        mf = p.get("missing_fields") or []
        if "profile_photo" in mf:
            key = (uid_i, "upload_photo")
            if key not in seen_nc:
                seen_nc.add(key)
                notification_candidates.append({"user_id": uid_i, "type": "upload_photo"})
        elif int(p.get("completeness_score") or 0) < 50:
            key = (uid_i, "complete_profile")
            if key not in seen_nc:
                seen_nc.add(key)
                notification_candidates.append({"user_id": uid_i, "type": "complete_profile"})

    notification_candidates = notification_candidates[:100]

    return {
        "conversion_signals": {
            "low_profile_score_users": low_profile_score_users,
            "no_photo_users": no_photo_users,
            "high_intent_users": high_intent_users,
        },
        "recommended_actions": recommended_actions,
        "notification_candidates": notification_candidates,
    }
