#!/usr/bin/env python3
"""Shared PaddleOCR engine wrapper for benchmark sidecar + CLI."""

from __future__ import annotations

import time
from functools import lru_cache
from typing import Any

from paddleocr import PaddleOCR


@lru_cache(maxsize=1)
def get_ocr() -> PaddleOCR:
  return PaddleOCR(
    use_angle_cls=True,
    lang="hi",
    show_log=False,
  )


def extract_text_from_image(image_path: str) -> dict[str, Any]:
  started = time.perf_counter()
  ocr = get_ocr()
  result = ocr.ocr(image_path, cls=True)
  lines: list[str] = []

  if result:
    for block in result:
      if not block:
        continue
      for item in block:
        if not item or len(item) < 2:
          continue
        text = item[1][0] if isinstance(item[1], (list, tuple)) else None
        if isinstance(text, str) and text.strip() != "":
          lines.append(text.strip())

  duration_ms = int(round((time.perf_counter() - started) * 1000))

  return {
    "text": "\n".join(lines),
    "duration_ms": duration_ms,
    "engine": "paddleocr_v1",
    "engine_meta": {
      "lang": "hi",
      "line_count": len(lines),
    },
  }
