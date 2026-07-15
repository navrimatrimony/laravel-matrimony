#!/usr/bin/env python3
"""Benchmark-only DocTR OCR CLI (Sprint 2). Not production-wired."""

from __future__ import annotations

import argparse
import json
import sys
import time
from functools import lru_cache
from pathlib import Path

from doctr.io import DocumentFile
from doctr.models import ocr_predictor


@lru_cache(maxsize=1)
def get_predictor():
  # pretrained=True downloads weights on first run
  return ocr_predictor(pretrained=True)


def extract_text_from_image(image_path: str) -> dict:
  started = time.perf_counter()
  doc = DocumentFile.from_images(image_path)
  model = get_predictor()
  result = model(doc)
  lines: list[str] = []
  # result.export() -> pages -> blocks -> lines -> words
  exported = result.export()
  for page in exported.get("pages", []):
    for block in page.get("blocks", []):
      for line in block.get("lines", []):
        words = [w.get("value", "") for w in line.get("words", []) if isinstance(w, dict)]
        text = " ".join(w for w in words if w).strip()
        if text:
          lines.append(text)
  text = "\n".join(lines)
  duration_ms = int((time.perf_counter() - started) * 1000)
  return {
    "engine": "doctr_v1",
    "text": text,
    "line_count": len(lines),
    "duration_ms": duration_ms,
    "engine_meta": {"backend": "python-doctr"},
  }


def main() -> int:
  if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")
  if hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8")

  parser = argparse.ArgumentParser(description="Run DocTR once and print JSON result.")
  parser.add_argument("--image", required=True, help="Absolute path to image file")
  args = parser.parse_args()
  image_path = Path(args.image)
  if not image_path.is_file():
    print(json.dumps({"error": f"image not found: {image_path}"}), file=sys.stderr)
    return 2
  try:
    # Keep model-download chatter off stdout so the CLI emits one JSON object only.
    _stdout = sys.stdout
    sys.stdout = sys.stderr
    try:
      result = extract_text_from_image(str(image_path.resolve()))
    finally:
      sys.stdout = _stdout
  except Exception as exc:  # noqa: BLE001
    print(json.dumps({"error": str(exc)}), file=sys.stderr)
    return 1
  print(json.dumps(result, ensure_ascii=False))
  return 0


if __name__ == "__main__":
  raise SystemExit(main())
