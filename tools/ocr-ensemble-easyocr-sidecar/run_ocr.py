#!/usr/bin/env python3
"""CLI OCR runner for benchmark automation without keeping HTTP server alive."""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

# Set thread limits before heavy imports in ocr_engine.
os.environ.setdefault("OMP_NUM_THREADS", "1")
os.environ.setdefault("MKL_NUM_THREADS", "1")
os.environ.setdefault("OPENBLAS_NUM_THREADS", "1")

from ocr_engine import extract_text_from_image


def main() -> int:
  parser = argparse.ArgumentParser(description="Run EasyOCR once and print JSON result.")
  parser.add_argument("--image", required=True, help="Absolute path to image file")
  args = parser.parse_args()

  image_path = Path(args.image)
  if not image_path.is_file():
    print(json.dumps({"error": f"image not found: {image_path}"}), file=sys.stderr)
    return 2

  try:
    result = extract_text_from_image(str(image_path))
  except Exception as exc:  # noqa: BLE001 - surface failure as JSON for ops
    print(json.dumps({"error": str(exc)}), file=sys.stderr)
    return 1

  print(json.dumps(result, ensure_ascii=False))
  return 0


if __name__ == "__main__":
  raise SystemExit(main())
