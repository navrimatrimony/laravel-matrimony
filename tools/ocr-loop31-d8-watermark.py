#!/usr/bin/env python3
"""Loop 31 — Blue watermark / color-separation on ORIGINAL D(8).jpeg.

Preserves black biodata text; suppresses blue vertical watermark.
Records RAW Tesseract output (no normalize). Invent forbidden.

Usage: C:\\pov\\Scripts\\python.exe tools/ocr-loop31-d8-watermark.py
"""

from __future__ import annotations

import json
import subprocess
import sys
import time
from pathlib import Path

import cv2
import numpy as np

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "storage" / "app" / "ocr-dev-batches" / "Batch-001" / "D (8).jpeg"
OUT = ROOT / "storage" / "app" / "private" / "ocr-temp" / "d8-loop31"
TESS = r"C:\Program Files\Tesseract-OCR\tesseract.exe"


def tess(path: Path, psm: int = 6, lang: str = "mar+eng") -> dict:
  t0 = time.perf_counter()
  r = subprocess.run(
    [TESS, str(path), "stdout", "-l", lang, "--psm", str(psm)],
    capture_output=True,
    text=True,
    encoding="utf-8",
    errors="replace",
    check=False,
  )
  # confidence via tsv
  t1 = time.perf_counter()
  r2 = subprocess.run(
    [TESS, str(path), "stdout", "-l", lang, "--psm", str(psm), "tsv"],
    capture_output=True,
    text=True,
    encoding="utf-8",
    errors="replace",
    check=False,
  )
  confs: list[float] = []
  for i, line in enumerate((r2.stdout or "").splitlines()):
    if i == 0:
      continue
    cols = line.split("\t")
    if len(cols) < 12:
      continue
    if cols[0] == "5" and cols[11].strip() != "":
      try:
        c = float(cols[10])
      except ValueError:
        continue
      if c >= 0:
        confs.append(c)
  text = r.stdout or ""
  return {
    "raw_text": text,
    "confidence": round(sum(confs) / len(confs), 2) if confs else None,
    "duration_ms": int((time.perf_counter() - t0) * 1000),
    "psm": psm,
    "lang": lang,
    "has_21": ("२१" in text) or ("21" in text),
    "has_24": ("२४" in text) or ("24" in text),
    "has_28": ("२८" in text) or ("28" in text),
    "dobish": _dobish(text),
  }


def _dobish(text: str) -> str | None:
  import re

  m = re.search(r".{0,8}जन्म.{0,50}", text)
  if m:
    return m.group(0).replace("\n", " ")
  m = re.search(r"[२2][०-९0-9][/\.\-]?[०-९0-9]{1,2}[/\.\-]?[१1]?[९9]{0,3}", text)
  return m.group(0) if m else None


def save(path: Path, img: np.ndarray) -> None:
  path.parent.mkdir(parents=True, exist_ok=True)
  cv2.imwrite(str(path), img)


def crop_dob(img: np.ndarray, y0: float = 0.12, y1: float = 0.28) -> np.ndarray:
  h, w = img.shape[:2]
  a = int(h * y0)
  b = max(a + 20, int(h * y1))
  return img[a:b, 0:w].copy()


def crop_dob_tight(img: np.ndarray) -> np.ndarray:
  """Empirical tight band around DOB value (left-mid of page)."""
  h, w = img.shape[:2]
  return img[int(h * 0.145) : int(h * 0.205), int(w * 0.02) : int(w * 0.55)].copy()


def blue_mask_hsv(bgr: np.ndarray) -> np.ndarray:
  hsv = cv2.cvtColor(bgr, cv2.COLOR_BGR2HSV)
  # Broad blue + cyan (watermark often light/mid blue)
  m1 = cv2.inRange(hsv, np.array([85, 30, 30]), np.array([140, 255, 255]))
  m2 = cv2.inRange(hsv, np.array([95, 20, 80]), np.array([130, 255, 255]))
  mask = cv2.bitwise_or(m1, m2)
  # Prefer left vertical strip where watermark lives, but keep full-page mask soft
  h, w = mask.shape
  weight = np.ones((h, w), dtype=np.float32)
  weight[:, int(w * 0.55) :] *= 0.35  # right side less aggressive
  mask_f = (mask.astype(np.float32) * weight).astype(np.uint8)
  _, mask_f = cv2.threshold(mask_f, 20, 255, cv2.THRESH_BINARY)
  kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (3, 3))
  mask_f = cv2.morphologyEx(mask_f, cv2.MORPH_CLOSE, kernel, iterations=2)
  return mask_f


def blue_mask_rgb(bgr: np.ndarray) -> np.ndarray:
  b, g, r = cv2.split(bgr)
  # Blue-dominant pixels: B > R and B > G with margin
  mask = ((b.astype(np.int16) - r.astype(np.int16)) > 25) & ((b.astype(np.int16) - g.astype(np.int16)) > 15)
  mask = mask.astype(np.uint8) * 255
  # Exclude near-black text
  gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)
  mask[gray < 60] = 0
  kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (3, 3))
  return cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel, iterations=1)


def blue_mask_lab(bgr: np.ndarray) -> np.ndarray:
  lab = cv2.cvtColor(bgr, cv2.COLOR_BGR2LAB)
  # L, a, b — blue tends to negative b* channel in OpenCV Lab (centered at 128)
  L, a, b = cv2.split(lab)
  # Lower b* => blue; also require not too dark (watermark ink)
  mask = (b < 118) & (L > 40) & (L < 230)
  # Prefer chroma: distance from neutral a/b
  chroma = np.abs(a.astype(np.int16) - 128) + np.abs(b.astype(np.int16) - 128)
  mask = mask & (chroma > 12)
  mask = mask.astype(np.uint8) * 255
  kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (3, 3))
  return cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel, iterations=2)


def apply_inpaint(bgr: np.ndarray, mask: np.ndarray) -> np.ndarray:
  return cv2.inpaint(bgr, mask, 3, cv2.INPAINT_TELEA)


def apply_white_fill(bgr: np.ndarray, mask: np.ndarray) -> np.ndarray:
  out = bgr.copy()
  out[mask > 0] = (255, 255, 255)
  return out


def black_text_extract(bgr: np.ndarray) -> np.ndarray:
  """Keep dark ink; suppress blue by requiring low saturation OR low value chroma blue."""
  hsv = cv2.cvtColor(bgr, cv2.COLOR_BGR2HSV)
  h, s, v = cv2.split(hsv)
  gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)
  # Candidate ink: dark enough
  ink = gray < 140
  # Blue watermark: high-ish S and hue in blue band even if mid-gray
  blue = ((h >= 85) & (h <= 140) & (s > 35) & (v > 40) & (v < 220))
  keep = ink & (~blue)
  out = np.full_like(gray, 255)
  out[keep] = gray[keep]
  # Strengthen ink
  out = cv2.normalize(out, None, 0, 255, cv2.NORM_MINMAX)
  return out


def red_channel_suppress_blue(bgr: np.ndarray) -> np.ndarray:
  """Red channel often weak on blue ink; enhance black."""
  b, g, r = cv2.split(bgr)
  # Blend: emphasize red (kills blue), keep darkness of gray
  gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)
  mix = cv2.addWeighted(r, 0.75, gray, 0.25, 0)
  return mix


def main() -> int:
  if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

  if not SRC.is_file():
    print("missing", SRC)
    return 1

  OUT.mkdir(parents=True, exist_ok=True)
  (OUT / "variants").mkdir(exist_ok=True)
  (OUT / "crops").mkdir(exist_ok=True)
  (OUT / "boxes").mkdir(exist_ok=True)

  orig = cv2.imread(str(SRC))
  assert orig is not None
  h, w = orig.shape[:2]
  meta = {
    "source": str(SRC),
    "width": w,
    "height": h,
    "bytes": SRC.stat().st_size,
    "sha256": __import__("hashlib").sha256(SRC.read_bytes()).hexdigest(),
  }

  variants: dict[str, np.ndarray] = {"original": orig}
  masks = {
    "hsv": blue_mask_hsv(orig),
    "rgb": blue_mask_rgb(orig),
    "lab": blue_mask_lab(orig),
  }
  for name, mask in masks.items():
    save(OUT / "variants" / f"mask_{name}.png", mask)
    variants[f"{name}_inpaint"] = apply_inpaint(orig, mask)
    variants[f"{name}_whitefill"] = apply_white_fill(orig, mask)

  # Combined HSV+LAB mask
  combo = cv2.bitwise_or(masks["hsv"], masks["lab"])
  save(OUT / "variants" / "mask_hsv_lab.png", combo)
  variants["hsv_lab_inpaint"] = apply_inpaint(orig, combo)
  variants["hsv_lab_whitefill"] = apply_white_fill(orig, combo)

  bt = black_text_extract(orig)
  variants["black_text_gray"] = bt
  variants["red_channel"] = red_channel_suppress_blue(orig)

  # Left-strip only aggressive HSV wipe (watermark column)
  left_mask = masks["hsv"].copy()
  left_mask[:, int(w * 0.42) :] = 0
  save(OUT / "variants" / "mask_hsv_left.png", left_mask)
  variants["hsv_left_inpaint"] = apply_inpaint(orig, left_mask)
  variants["hsv_left_whitefill"] = apply_white_fill(orig, left_mask)

  rows = []
  for name, img in variants.items():
    # Save full variant
    if img.ndim == 2:
      full_path = OUT / "variants" / f"{name}.png"
      save(full_path, img)
      bgr_for_crop = cv2.cvtColor(img, cv2.COLOR_GRAY2BGR)
    else:
      full_path = OUT / "variants" / f"{name}.png"
      save(full_path, img)
      bgr_for_crop = img

    tight = crop_dob_tight(bgr_for_crop)
    tight_path = OUT / "crops" / f"{name}_dob_tight.png"
    save(tight_path, tight)

    # Upscale tight for glyph view
    x4 = cv2.resize(tight, None, fx=4, fy=4, interpolation=cv2.INTER_NEAREST)
    x4_path = OUT / "crops" / f"{name}_dob_tight_x4.png"
    save(x4_path, x4)

    for target_name, target_path in (("full", full_path), ("dob_tight", tight_path)):
      for psm in (6, 4, 11):
        rec = tess(target_path, psm=psm)
        row = {
          "variant": name,
          "target": target_name,
          "path": str(target_path),
          **rec,
        }
        rows.append(row)
        snip = " ".join((rec["raw_text"] or "").split())[:90]
        print(
          f"{name}/{target_name} psm={psm} 21={rec['has_21']} 24={rec['has_24']} "
          f"conf={rec['confidence']} ms={rec['duration_ms']} dobish={rec['dobish']!r} snip={snip!r}",
          flush=True,
        )

  # Glyph boxes on original vs best candidates (draw Tesseract word boxes on x4)
  for name in ("original", "hsv_left_inpaint", "hsv_lab_inpaint", "black_text_gray", "lab_inpaint"):
    p = OUT / "crops" / f"{name}_dob_tight_x4.png"
    if not p.is_file():
      continue
    r2 = subprocess.run(
      [TESS, str(p), "stdout", "-l", "mar+eng", "--psm", "6", "tsv"],
      capture_output=True,
      text=True,
      encoding="utf-8",
      errors="replace",
      check=False,
    )
    img = cv2.imread(str(p))
    if img is None:
      continue
    for i, line in enumerate((r2.stdout or "").splitlines()):
      if i == 0:
        continue
      cols = line.split("\t")
      if len(cols) < 12 or cols[0] != "5" or not cols[11].strip():
        continue
      left, top, width, height = map(int, cols[6:10])
      cv2.rectangle(img, (left, top), (left + width, top + height), (0, 0, 255), 2)
      cv2.putText(img, cols[11][:12], (left, max(12, top - 4)), cv2.FONT_HERSHEY_SIMPLEX, 0.4, (0, 128, 0), 1)
    box_path = OUT / "boxes" / f"{name}_x4_boxes.png"
    save(box_path, img)

  clean21 = [
    r
    for r in rows
    if r["has_21"]
    and not r["has_24"]
    and (("१९९९" in (r["raw_text"] or "")) or ("1999" in (r["raw_text"] or "")))
  ]
  # Also accept explicit 21/03/1999 forms
  repro = []
  for r in rows:
    t = r["raw_text"] or ""
    if (("२१/०३/१९९९" in t) or ("21/03/1999" in t) or ("२१०३/१९९९" in t) or ("२१/०३/1999" in t)) and (
      "२४" not in t and "24" not in t
    ):
      repro.append(r)

  summary = {
    "loop": 31,
    "meta": meta,
    "row_count": len(rows),
    "clean_21_with_1999_no_24": len(clean21),
    "repro_21_03_1999": len(repro),
    "clean21_samples": clean21[:20],
    "repro_samples": repro[:20],
    "any_21": any(r["has_21"] for r in rows),
    "any_24": any(r["has_24"] for r in rows),
    "rows": rows,
  }
  out_json = OUT / "loop31_watermark_evidence.json"
  out_json.write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
  print("WROTE", out_json)
  print("CLEAN21", len(clean21), "REPRO_21_03_1999", len(repro))
  return 0


if __name__ == "__main__":
  raise SystemExit(main())
