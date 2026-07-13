#!/usr/bin/env python3
"""CLI OCR runner for benchmark automation without keeping HTTP server alive."""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from ocr_engine import extract_text_from_image


def main() -> int:
  parser = argparse.ArgumentParser(description="Run EasyOCR once and print JSON result.")
  parser.add_argument("--image", required=True, help="Absolute path to image file")
  args = parser.parse_args()

  image_path = Path(args.image)
  if not image_path.is_file():
    print(json.dumps({"error": f"image not found: {image_path}"}), file=sys.stderr)
    return 2

  result = extract_text_from_image(str(image_path))
  print(json.dumps(result, ensure_ascii=False))
  return 0


if __name__ == "__main__":
  raise SystemExit(main())
