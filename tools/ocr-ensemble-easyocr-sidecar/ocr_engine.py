#!/usr/bin/env python3
"""Shared EasyOCR engine wrapper for benchmark sidecar + CLI."""

from __future__ import annotations

import gc
import os
import tempfile
import time
from functools import lru_cache
from pathlib import Path
from typing import Any

# Limit BLAS/thread pools on small VPS hosts to reduce peak RAM.
os.environ.setdefault("OMP_NUM_THREADS", "1")
os.environ.setdefault("MKL_NUM_THREADS", "1")
os.environ.setdefault("OPENBLAS_NUM_THREADS", "1")

from PIL import Image

import easyocr

# Devanagari biodata: Hindi model covers Marathi script; single language halves model RAM.
_LANGUAGES = ["hi"]
_MAX_IMAGE_SIDE = int(os.environ.get("EASYOCR_MAX_IMAGE_SIDE", "1600"))
_CANVAS_SIZE = int(os.environ.get("EASYOCR_CANVAS_SIZE", "1280"))


@lru_cache(maxsize=1)
def get_reader() -> easyocr.Reader:
  return easyocr.Reader(
    _LANGUAGES,
    gpu=False,
    quantize=True,
    verbose=False,
  )


def _bbox_sort_key(block: tuple[Any, ...]) -> tuple[float, float]:
  bbox = block[0]
  if not isinstance(bbox, (list, tuple)) or not bbox:
    return (0.0, 0.0)

  first = bbox[0]
  if isinstance(first, (list, tuple)) and len(first) >= 2:
    return (float(first[1]), float(first[0]))

  return (0.0, 0.0)


def _prepare_image_path(image_path: str) -> tuple[str, bool]:
  """Downscale very large inputs before OCR to avoid OOM on small servers."""
  path = Path(image_path)
  with Image.open(path) as img:
    img = img.convert("RGB")
    width, height = img.size
    longest = max(width, height)

    if longest <= _MAX_IMAGE_SIDE:
      return str(path), False

    scale = _MAX_IMAGE_SIDE / float(longest)
    resized = img.resize(
      (max(1, int(width * scale)), max(1, int(height * scale))),
      Image.Resampling.LANCZOS,
    )
    handle = tempfile.NamedTemporaryFile(delete=False, suffix=".jpg")
    resized.save(handle.name, format="JPEG", quality=92, optimize=True)
    handle.close()
    return handle.name, True


def extract_text_from_image(image_path: str) -> dict[str, Any]:
  started = time.perf_counter()
  prepared_path, is_temp = _prepare_image_path(image_path)

  try:
    reader = get_reader()
    blocks = reader.readtext(
      prepared_path,
      detail=1,
      paragraph=False,
      batch_size=1,
      workers=0,
      canvas_size=_CANVAS_SIZE,
    )
  finally:
    if is_temp:
      Path(prepared_path).unlink(missing_ok=True)
    gc.collect()

  lines: list[str] = []
  confidences: list[float] = []

  for _bbox, text, confidence in sorted(blocks, key=_bbox_sort_key):
    if not isinstance(text, str):
      continue
    stripped = text.strip()
    if stripped == "":
      continue
    lines.append(stripped)
    if isinstance(confidence, (int, float)):
      confidences.append(float(confidence))

  duration_ms = int(round((time.perf_counter() - started) * 1000))

  return {
    "text": "\n".join(lines),
    "duration_ms": duration_ms,
    "engine": "easyocr_v1",
    "engine_meta": {
      "languages": _LANGUAGES,
      "line_count": len(lines),
      "gpu": False,
      "quantize": True,
      "max_image_side": _MAX_IMAGE_SIDE,
      "canvas_size": _CANVAS_SIZE,
      "avg_confidence": round(sum(confidences) / len(confidences), 4) if confidences else None,
    },
  }
