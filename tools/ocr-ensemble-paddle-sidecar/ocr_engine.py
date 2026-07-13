#!/usr/bin/env python3
"""Shared PaddleOCR engine wrapper for benchmark sidecar + CLI."""

from __future__ import annotations

import logging
import time
from functools import lru_cache
from typing import Any

from paddleocr import PaddleOCR

# PaddleOCR 3.x no longer accepts show_log on PaddleOCR(); quiet PaddleX init noise.
logging.getLogger("paddlex").setLevel(logging.ERROR)


@lru_cache(maxsize=1)
def get_ocr() -> PaddleOCR:
  return PaddleOCR(
    lang="hi",
    use_doc_orientation_classify=False,
    use_doc_unwarping=False,
    use_textline_orientation=True,
  )


def _extract_lines_from_v3_result(result_obj: Any) -> list[str]:
  lines: list[str] = []
  payload = getattr(result_obj, "json", None)
  if callable(payload):
    try:
      payload = payload()
    except TypeError:
      payload = None

  if not isinstance(payload, dict):
    return lines

  res = payload.get("res", payload)
  if not isinstance(res, dict):
    return lines

  rec_texts = res.get("rec_texts")
  if not isinstance(rec_texts, list):
    return lines

  for text in rec_texts:
    if isinstance(text, str):
      stripped = text.strip()
      if stripped:
        lines.append(stripped)

  return lines


def _extract_lines_from_v2_result(result: Any) -> list[str]:
  lines: list[str] = []
  if not isinstance(result, list):
    return lines

  for block in result:
    if not isinstance(block, list):
      continue
    for item in block:
      if not item or len(item) < 2:
        continue
      text_cell = item[1]
      text = text_cell[0] if isinstance(text_cell, (list, tuple)) else None
      if isinstance(text, str):
        stripped = text.strip()
        if stripped:
          lines.append(stripped)

  return lines


def extract_text_from_image(image_path: str) -> dict[str, Any]:
  started = time.perf_counter()
  ocr = get_ocr()
  raw_result = ocr.predict(image_path)
  lines: list[str] = []

  if isinstance(raw_result, list):
    for result_obj in raw_result:
      v3_lines = _extract_lines_from_v3_result(result_obj)
      if v3_lines:
        lines.extend(v3_lines)
      else:
        lines.extend(_extract_lines_from_v2_result(result_obj))

  duration_ms = int(round((time.perf_counter() - started) * 1000))

  return {
    "text": "\n".join(lines),
    "duration_ms": duration_ms,
    "engine": "paddleocr_v1",
    "engine_meta": {
      "lang": "hi",
      "line_count": len(lines),
      "paddleocr_api": "predict",
    },
  }
