#!/usr/bin/env python3
"""Shared EasyOCR engine wrapper for benchmark sidecar + CLI."""

from __future__ import annotations

import time
from functools import lru_cache
from typing import Any

import easyocr


@lru_cache(maxsize=1)
def get_reader() -> easyocr.Reader:
  # Hindi/Devanagari biodata + occasional English labels.
  return easyocr.Reader(["hi", "en"], gpu=False)


def _bbox_sort_key(block: tuple[Any, ...]) -> tuple[float, float]:
  bbox = block[0]
  if not isinstance(bbox, (list, tuple)) or not bbox:
    return (0.0, 0.0)

  first = bbox[0]
  if isinstance(first, (list, tuple)) and len(first) >= 2:
    return (float(first[1]), float(first[0]))

  return (0.0, 0.0)


def extract_text_from_image(image_path: str) -> dict[str, Any]:
  started = time.perf_counter()
  reader = get_reader()
  blocks = reader.readtext(image_path, detail=1, paragraph=False)
  lines: list[str] = []

  for _bbox, text, confidence in sorted(blocks, key=_bbox_sort_key):
    if not isinstance(text, str):
      continue
    stripped = text.strip()
    if stripped == "":
      continue
    lines.append(stripped)

  duration_ms = int(round((time.perf_counter() - started) * 1000))

  return {
    "text": "\n".join(lines),
    "duration_ms": duration_ms,
    "engine": "easyocr_v1",
    "engine_meta": {
      "languages": ["hi", "en"],
      "line_count": len(lines),
      "gpu": False,
      "avg_confidence": round(
        sum(float(block[2]) for block in blocks if len(block) > 2) / len(blocks),
        4,
      ) if blocks else None,
    },
  }
