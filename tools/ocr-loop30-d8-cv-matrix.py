#!/usr/bin/env python3
"""Loop 30 — OpenCV preprocess + slash-split experiments on D8 DOB crop."""

from __future__ import annotations

import json
import subprocess
import time
from pathlib import Path

import cv2
import numpy as np

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "storage" / "app" / "private" / "ocr-temp" / "d8-loop30"
TIGHT = OUT / "crops" / "dob_line_tight.png"
PREP = OUT / "prep_cv"
TESS = r"C:\Program Files\Tesseract-OCR\tesseract.exe"


def tess(path: Path, psm: int) -> tuple[str, int]:
  t0 = time.perf_counter()
  r = subprocess.run(
    [TESS, str(path), "stdout", "-l", "mar+eng", "--psm", str(psm)],
    capture_output=True,
    text=True,
    encoding="utf-8",
    errors="replace",
    check=False,
  )
  return r.stdout, int((time.perf_counter() - t0) * 1000)


def flags(text: str) -> dict:
  return {
    "has_21": ("२१" in text) or ("21" in text),
    "has_24": ("२४" in text) or ("24" in text),
    "has_28": ("२८" in text) or ("28" in text),
    "has_27": ("२७" in text) or ("27" in text),
    "has_20": ("२०" in text) or ("20" in text),
  }


def main() -> int:
  import sys

  if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

  PREP.mkdir(parents=True, exist_ok=True)
  img = cv2.imread(str(TIGHT))
  if img is None:
    print("missing tight crop")
    return 1
  gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)

  ops: dict[str, np.ndarray] = {
    "cv_gray": gray,
    "cv_otsu": cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)[1],
    "cv_adaptive": cv2.adaptiveThreshold(
      gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 15, 8
    ),
    "cv_clahe": cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(gray),
  }
  ops["cv_morph_open"] = cv2.morphologyEx(
    ops["cv_otsu"], cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2, 2))
  )
  ops["cv_morph_erode"] = cv2.erode(
    ops["cv_otsu"], cv2.getStructuringElement(cv2.MORPH_RECT, (1, 2)), iterations=1
  )
  blur = cv2.GaussianBlur(gray, (0, 0), 1.0)
  ops["cv_unsharp"] = cv2.addWeighted(gray, 1.5, blur, -0.5, 0)

  for scale, interp, tag in [
    (3, cv2.INTER_NEAREST, "nn3"),
    (3, cv2.INTER_LANCZOS4, "lz3"),
    (4, cv2.INTER_NEAREST, "nn4"),
    (4, cv2.INTER_CUBIC, "cu4"),
  ]:
    up = cv2.resize(gray, None, fx=scale, fy=scale, interpolation=interp)
    ops[f"cv_{tag}"] = up
    ops[f"cv_{tag}_otsu"] = cv2.threshold(up, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)[1]
    ops[f"cv_{tag}_clahe"] = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(up)

  # Slash-split hypothesis: erode thin bridges then dilate lightly (general morphology, not D8-hardcode)
  for tag in ("nn3_otsu", "lz3_otsu", "nn4_otsu"):
    base = ops[f"cv_{tag}"]
    # Invert if needed so ink=white for morphology consistency — keep as-is if already binary ink dark
    # Work on ink-as-white
    inv = 255 - base
    ker = cv2.getStructuringElement(cv2.MORPH_RECT, (2, 1))  # horizontal bridge break
    split = cv2.morphologyEx(inv, cv2.MORPH_OPEN, ker, iterations=1)
    split = 255 - split
    ops[f"cv_{tag}_hbridge_open"] = split
    ker2 = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2, 2))
    split2 = 255 - cv2.erode(inv, ker2, iterations=1)
    ops[f"cv_{tag}_erode1"] = split2

  # Super-resolution API present?
  sr_note = {"dnn_superres_api": hasattr(cv2, "dnn_superres"), "model_loaded": False}
  # No bundled EDSR/ESPCN weights in repo — record unavailable
  rows = []
  print("sr_note", sr_note)

  for name, arr in ops.items():
    path = PREP / f"{name}.png"
    cv2.imwrite(str(path), arr)
    for psm in (6, 7, 8):
      text, ms = tess(path, psm)
      f = flags(text)
      row = {"op": name, "psm": psm, "raw_text": text, "duration_ms": ms, **f}
      rows.append(row)
      snip = " ".join(text.split())[:80]
      print(
        f"{name} psm={psm} 21={f['has_21']} 24={f['has_24']} 28={f['has_28']} 27={f['has_27']} snip={snip!r}"
      )

  # Also OCR day_only and date_focus
  for extra in ("glyph/day_only_x8_nn.png", "glyph/date_focus_x4_nn.png"):
    p = OUT / extra
    if not p.is_file():
      continue
    for psm in (6, 7, 8, 10, 13):
      text, ms = tess(p, psm)
      f = flags(text)
      rows.append({"op": extra, "psm": psm, "raw_text": text, "duration_ms": ms, **f})
      snip = " ".join(text.split())[:80]
      print(f"{extra} psm={psm} 21={f['has_21']} 24={f['has_24']} snip={snip!r}")

  clean21 = [r for r in rows if r["has_21"] and not r["has_24"]]
  summary = {
    "sr_note": sr_note,
    "rows": rows,
    "clean_21_without_24_count": len(clean21),
    "clean_21_samples": clean21[:10],
    "any_21": any(r["has_21"] for r in rows),
    "any_24": any(r["has_24"] for r in rows),
  }
  out_json = OUT / "loop30_cv_matrix.json"
  out_json.write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
  print("WROTE", out_json)
  print("CLEAN_21_COUNT", len(clean21))
  return 0


if __name__ == "__main__":
  raise SystemExit(main())
