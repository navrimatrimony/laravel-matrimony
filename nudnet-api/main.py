"""
NudeNet HTTP API for Laravel (multipart field name must be: file).

Hybrid moderation: explicit NSFW → unsafe, high-confidence body signals → manual review,
clean images → safe. Face-only high scores do not trigger review.

Thresholds and ignore-classes sync from Laravel GET /api/moderation-config when available;
hardcoded defaults apply when Laravel is unreachable.
"""

from __future__ import annotations

import json
import os
import tempfile
import threading
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from typing import Any

from fastapi import FastAPI, File, HTTPException, UploadFile

app = FastAPI(title="NudeNet detect", version="3")

# --- Hardcoded defaults (used when Laravel is down or returns invalid data) ---

DEFAULT_NSFW_SCORE_MIN: float = 0.4
DEFAULT_REVIEW_SCORE_MIN: float = 0.53
DEFAULT_IGNORE_CLASSES: frozenset[str] = frozenset({"FACE_FEMALE", "FACE_MALE"})

# --- Runtime config (updated by fetch_config / background thread) ---

_CFG_LOCK = threading.Lock()
_NSFW_SCORE_MIN: float = DEFAULT_NSFW_SCORE_MIN
_REVIEW_SCORE_MIN: float = DEFAULT_REVIEW_SCORE_MIN
_IGNORE_HIGH_SCORE_FOR_REVIEW: frozenset[str] = DEFAULT_IGNORE_CLASSES
_LAST_SYNC_WALL: float | None = None
_CONFIG_SOURCE: str = "default"
_APPLIED_VERSION: str | None = None

_FETCH_SCHEDULE_LOCK = threading.Lock()
_LAST_TRAFFIC_FETCH_TRIGGER: float = 0.0

# Laravel JSON endpoint (override in production, e.g. http://laravel-app/api/moderation-config)
MODERATION_LARAVEL_CONFIG_URL: str = os.environ.get(
    "MODERATION_LARAVEL_CONFIG_URL", "http://127.0.0.1/api/moderation-config"
).strip()

CONFIG_REFRESH_INTERVAL_SEC: float = float(os.environ.get("MODERATION_CONFIG_REFRESH_SEC", "300"))
TRAFFIC_FETCH_DEBOUNCE_SEC: float = float(os.environ.get("MODERATION_CONFIG_TRAFFIC_DEBOUNCE_SEC", "15"))

# --- Class sets (NudeNet label strings) ---

NSFW_CLASSES: frozenset[str] = frozenset(
    {
        "FEMALE_BREAST_EXPOSED",
        "FEMALE_GENITALIA_EXPOSED",
        "MALE_GENITALIA_EXPOSED",
        "ANUS_EXPOSED",
    }
)

RISKY_CLASSES: frozenset[str] = frozenset(
    {
        "FEMALE_BREAST_COVERED",
        "BUTTOCKS_EXPOSED",
        "BELLY_EXPOSED",
        "FEMALE_GENITALIA_COVERED",
        "MALE_BREAST_EXPOSED",
    }
)

ARMPITS_REVIEW_MIN: float = 0.52

_detector = None
_LOAD_ERROR: str | None = None


def _safe_float(val: Any, fallback: float) -> float:
    try:
        x = float(val)
    except (TypeError, ValueError):
        return fallback
    if x < 0.0 or x > 1.0:
        return fallback
    return x


def _safe_ignore_frozenset(val: Any, fallback: frozenset[str]) -> frozenset[str]:
    if not isinstance(val, list):
        return fallback
    parts = [str(x).strip() for x in val if str(x).strip()]
    return frozenset(parts)


def fetch_config() -> None:
    """
    Pull JSON from Laravel. On any failure, keep current in-memory values (initially defaults).
    Non-blocking when called from a background thread.
    """
    global _NSFW_SCORE_MIN, _REVIEW_SCORE_MIN, _IGNORE_HIGH_SCORE_FOR_REVIEW
    global _LAST_SYNC_WALL, _CONFIG_SOURCE, _APPLIED_VERSION

    url = MODERATION_LARAVEL_CONFIG_URL
    if not url:
        return

    try:
        req = urllib.request.Request(
            url,
            method="GET",
            headers={"Accept": "application/json"},
        )
        with urllib.request.urlopen(req, timeout=2.5) as resp:
            raw = resp.read()
        data = json.loads(raw.decode("utf-8"))
        if not isinstance(data, dict):
            return

        new_version = str(data.get("version", "") or "").strip() or "v1"
        with _CFG_LOCK:
            base_nsfw = _NSFW_SCORE_MIN
            base_review = _REVIEW_SCORE_MIN
            base_ignore = _IGNORE_HIGH_SCORE_FOR_REVIEW

        nsfw = _safe_float(data.get("nsfw_score_min"), base_nsfw)
        review = _safe_float(data.get("review_score_min"), base_review)
        ignore = _safe_ignore_frozenset(data.get("ignore_classes"), base_ignore)

        # Version change → apply immediately in this fetch (no extra wait for the 5-minute loop).
        with _CFG_LOCK:
            _NSFW_SCORE_MIN = nsfw
            _REVIEW_SCORE_MIN = review
            _IGNORE_HIGH_SCORE_FOR_REVIEW = ignore
            _APPLIED_VERSION = new_version
            _LAST_SYNC_WALL = time.time()
            _CONFIG_SOURCE = "laravel_api"
    except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError, OSError, json.JSONDecodeError, TypeError, ValueError):
        # Keep last good values (or initial defaults). Never crash callers.
        return
    except Exception:
        return


def _config_refresher_loop() -> None:
    while True:
        try:
            fetch_config()
        except Exception:
            pass
        time.sleep(max(30.0, CONFIG_REFRESH_INTERVAL_SEC))


def _schedule_fetch_from_traffic() -> None:
    """Debounced background fetch so admin changes propagate without waiting 5 minutes."""
    global _LAST_TRAFFIC_FETCH_TRIGGER
    now = time.monotonic()
    with _FETCH_SCHEDULE_LOCK:
        if now - _LAST_TRAFFIC_FETCH_TRIGGER < TRAFFIC_FETCH_DEBOUNCE_SEC:
            return
        _LAST_TRAFFIC_FETCH_TRIGGER = now
    threading.Thread(target=fetch_config, daemon=True, name="mod-config-fetch").start()


def _iso_last_sync() -> str | None:
    if _LAST_SYNC_WALL is None:
        return None
    return datetime.fromtimestamp(_LAST_SYNC_WALL, tz=timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _normalize_detections(raw: list[Any]) -> list[dict[str, Any]]:
    out: list[dict[str, Any]] = []
    for d in raw or []:
        if not isinstance(d, dict):
            continue
        label = str(d.get("class") or d.get("label") or "")
        try:
            score = float(d.get("score", 0.0))
        except (TypeError, ValueError):
            score = 0.0
        row: dict[str, Any] = {"class": label, "score": score}
        if "box" in d and isinstance(d["box"], (list, tuple)):
            row["box"] = list(d["box"])
        out.append(row)
    return out


def classify_image(detections: list[dict[str, Any]]) -> tuple[str, float, str]:
    """
    Deterministic 3-way decision using dynamically synced thresholds when available.

    1) Any NSFW class with score > nsfw_min → unsafe
    2) Matrimony strict / context bands (unchanged thresholds)
    3) Face + body mix heuristic → review
    4) Else, any risky class with score > nsfw_min → review
       OR any non-ignored class with score > review_min → review
    5) Else → safe (or review if confidence is low)
    """
    if not detections:
        return "safe", 1.0, "clean_image"

    scores: dict[str, float] = {}
    for d in detections:
        cls = d.get("class")
        if not cls:
            continue
        try:
            sc = float(d.get("score", 0.0))
        except (TypeError, ValueError):
            sc = 0.0
        scores[str(cls)] = max(scores.get(str(cls), 0.0), sc)

    with _CFG_LOCK:
        nsfw_min = _NSFW_SCORE_MIN
        review_min = _REVIEW_SCORE_MIN
        ignore_for_review = _IGNORE_HIGH_SCORE_FOR_REVIEW

    explicit_scores = [
        scores[c]
        for c in NSFW_CLASSES
        if c in scores and scores[c] > nsfw_min
    ]
    if explicit_scores:
        return "unsafe", round(max(explicit_scores), 4), "explicit_nudity"

    # Matrimony strict rules (fixed thresholds)
    bex = scores.get("FEMALE_BREAST_EXPOSED", 0.0)
    if bex > 0.4:
        return "unsafe", round(bex, 4), "explicit_nudity"

    bcc = scores.get("FEMALE_BREAST_COVERED", 0.0)
    if bcc > 0.85:
        return "unsafe", round(bcc, 4), "explicit_nudity"

    belly = scores.get("BELLY_EXPOSED", 0.0)
    if belly > 0.6:
        return "unsafe", round(belly, 4), "explicit_nudity"

    # Matrimony context rules (covered / suggestive → review)
    b_cov = scores.get("BUTTOCKS_COVERED", 0.0)
    if b_cov > 0.4:
        return "review", round(b_cov, 4), "buttocks_suggestive"

    if 0.6 < bcc < 0.85:
        return "review", round(bcc, 4), "breast_covered_suggestive"

    if 0.5 < belly < 0.7:
        return "review", round(belly, 4), "belly_suggestive"

    # Face + body mix (female face present with other signals)
    if "FACE_FEMALE" in scores and len(scores) > 1:
        mx = max(scores.values())
        # ✅ NEW: normal saree logic
        if bcc < 0.6:
            return "safe", 0.8, "face_normal_pose"

        if mx > 0.4:
            return "review", round(mx, 4), "face_body_mix"

    risky_scores: list[float] = []
    for cls in RISKY_CLASSES:
        if cls in scores and scores[cls] > nsfw_min:
            risky_scores.append(scores[cls])
    ap = scores.get("ARMPITS_EXPOSED", 0.0)
    if ap > ARMPITS_REVIEW_MIN:
        risky_scores.append(ap)

    high_body_scores = [
        scores[c]
        for c in scores
        if c not in ignore_for_review and scores[c] > review_min
    ]
    if risky_scores or high_body_scores:
        combined = risky_scores + high_body_scores
        return "review", round(max(combined), 4), "body_signals_review"

    rest = [scores[c] for c in scores if c not in ignore_for_review]
    if not rest:
        return "safe", 1.0, "clean_image"

    peak = max(rest)
    margin = max(0.0, review_min - peak)
    conf = round(min(1.0, 0.5 + margin), 4)
    if conf < 0.6:
        return "review", conf, "low_confidence"
    return "safe", conf, "clean_image"


def _load_model() -> None:
    global _detector, _LOAD_ERROR
    try:
        from nudenet import NudeDetector

        _detector = NudeDetector()
        _LOAD_ERROR = None
        print("NudeDetector loaded OK.")
    except Exception as e:  # noqa: BLE001
        _detector = None
        _LOAD_ERROR = str(e)
        print("WARNING: NudeDetector could not load:", _LOAD_ERROR)


@app.on_event("startup")
def startup() -> None:
    _load_model()
    t = threading.Thread(target=_config_refresher_loop, daemon=True, name="moderation-config-refresh")
    t.start()


@app.get("/health")
def health():
    return {"ok": True, "model_loaded": _detector is not None, "error": _LOAD_ERROR}


@app.get("/config-status")
def config_status():
    with _CFG_LOCK:
        return {
            "nsfw_score_min": _NSFW_SCORE_MIN,
            "review_score_min": _REVIEW_SCORE_MIN,
            "last_sync": _iso_last_sync(),
            "source": _CONFIG_SOURCE,
        }


@app.post("/detect")
async def detect(file: UploadFile = File(...)):
    _schedule_fetch_from_traffic()

    contents = await file.read()
    if not contents:
        return {
            "status": "review",
            "confidence": 0.0,
            "reason": "empty_file",
            "detections": [],
            "error": "empty_file",
        }

    if _detector is None:
        return {
            "status": "review",
            "confidence": 0.0,
            "reason": "model_not_loaded",
            "detections": [],
            "error": "model_not_loaded",
            "detail": _LOAD_ERROR,
        }

    suffix = ".jpg"
    fn = (file.filename or "").lower()
    if fn.endswith(".webp"):
        suffix = ".webp"
    elif fn.endswith(".png"):
        suffix = ".png"
    elif fn.endswith(".gif"):
        suffix = ".gif"

    path = None
    try:
        fd, path = tempfile.mkstemp(suffix=suffix)
        with os.fdopen(fd, "wb") as tmp:
            tmp.write(contents)
        raw = _detector.detect(path)
    except Exception as e:  # noqa: BLE001
        raise HTTPException(status_code=500, detail=str(e)) from e
    finally:
        if path and os.path.isfile(path):
            try:
                os.unlink(path)
            except OSError:
                pass

    detections_out = _normalize_detections(raw if isinstance(raw, list) else [])
    status, confidence, reason = classify_image(detections_out)

    return {
        "status": status,
        "confidence": confidence,
        "reason": reason,
        "detections": detections_out,
    }
