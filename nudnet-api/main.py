"""
NudeNet HTTP API for Laravel (multipart field name must be: file).

Hybrid moderation: explicit NSFW → unsafe, high-confidence body signals → manual review,
clean images → safe. Face-only high scores do not trigger review.
"""

from __future__ import annotations

import os
import tempfile
from typing import Any

from fastapi import FastAPI, File, HTTPException, UploadFile

app = FastAPI(title="NudeNet detect", version="3")

# --- Class sets (NudeNet label strings) ---

NSFW_CLASSES: frozenset[str] = frozenset(
    {
        "FEMALE_BREAST_EXPOSED",
        "FEMALE_GENITALIA_EXPOSED",
        "MALE_GENITALIA_EXPOSED",
        "ANUS_EXPOSED",
    }
)

# Suggestive / revealing clothing (not always explicit); bikini & lingerie often score here, not in NSFW_*.
RISKY_CLASSES: frozenset[str] = frozenset(
    {
        "FEMALE_BREAST_COVERED",
        "BUTTOCKS_EXPOSED",
        "BELLY_EXPOSED",
        "FEMALE_GENITALIA_COVERED",
        "MALE_BREAST_EXPOSED",
    }
)

# Armpits fire on many modest blouses/sarees — only queue review when confidence is clearly high.
ARMPITS_REVIEW_MIN: float = 0.52

# High model score on these is common for normal portraits; never use them for the "review" rule.
IGNORE_HIGH_SCORE_FOR_REVIEW: frozenset[str] = frozenset(
    {
        "FACE_FEMALE",
        "FACE_MALE",
    }
)

NSFW_SCORE_MIN: float = 0.4
# Any other body box above this (non-face) → manual review — catches marginal bikini cues below explicit NSFW.
REVIEW_SCORE_MIN: float = 0.53

_detector = None
_LOAD_ERROR: str | None = None


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


def classify_image(detections: list[dict[str, Any]]) -> tuple[str, float]:
    """
    Deterministic 3-way decision.

    1) Any NSFW class with score > NSFW_SCORE_MIN → unsafe
    2) Else, any risky class with score > NSFW_SCORE_MIN → review (cleavage / buttocks cues)
       OR any non-face detection with score > REVIEW_SCORE_MIN → review
    3) Else → safe (including face-only images; FACE_* never drives unsafe/review alone)
    """
    if not detections:
        return "safe", 1.0

    explicit_scores = [
        float(d["score"])
        for d in detections
        if d.get("class") in NSFW_CLASSES and float(d["score"]) > NSFW_SCORE_MIN
    ]
    if explicit_scores:
        return "unsafe", round(max(explicit_scores), 4)

    risky_scores = []
    for d in detections:
        cls = d.get("class")
        sc = float(d["score"])
        if cls in RISKY_CLASSES and sc > NSFW_SCORE_MIN:
            risky_scores.append(sc)
        elif cls == "ARMPITS_EXPOSED" and sc > ARMPITS_REVIEW_MIN:
            risky_scores.append(sc)
    high_body_scores = [
        float(d["score"])
        for d in detections
        if d.get("class") not in IGNORE_HIGH_SCORE_FOR_REVIEW
        and float(d["score"]) > REVIEW_SCORE_MIN
    ]
    if risky_scores or high_body_scores:
        combined = risky_scores + high_body_scores
        return "review", round(max(combined), 4)

    rest = [
        float(d["score"])
        for d in detections
        if d.get("class") not in IGNORE_HIGH_SCORE_FOR_REVIEW
    ]
    if not rest:
        return "safe", 1.0

    peak = max(rest)
    margin = max(0.0, REVIEW_SCORE_MIN - peak)
    conf = round(min(1.0, 0.5 + margin), 4)
    return "safe", conf


@app.on_event("startup")
def load_model() -> None:
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


@app.get("/health")
def health():
    return {"ok": True, "model_loaded": _detector is not None, "error": _LOAD_ERROR}


@app.post("/detect")
async def detect(file: UploadFile = File(...)):
    contents = await file.read()
    if not contents:
        return {
            "status": "review",
            "confidence": 0.0,
            "detections": [],
            "error": "empty_file",
        }

    if _detector is None:
        return {
            "status": "review",
            "confidence": 0.0,
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
    status, confidence = classify_image(detections_out)

    return {
        "status": status,
        "confidence": confidence,
        "detections": detections_out,
    }
