#!/usr/bin/env python3
"""Loop 31b — Watermark removal focused on correct DOB band (y~18–32%)."""

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

# Corrected DOB region from locate (header is ~0–16%; DOB ~18–28%)
DOB_BAND = (0.18, 0.30)  # y0, y1 full width
DOB_TIGHT = (0.02, 0.20, 0.70, 0.28)  # x0,y0,x1,y1 — date value area


def tess(path: Path, psm: int = 6) -> dict:
  t0 = time.perf_counter()
  r = subprocess.run(
    [TESS, str(path), "stdout", "-l", "mar+eng", f"--psm", str(psm)],
    capture_output=True,
    text=True,
    encoding="utf-8",
    errors="replace",
    check=False,
  )
  r2 = subprocess.run(
    [TESS, str(path), "stdout", "-l", "mar+eng", f"--psm", str(psm), "tsv"],
    capture_output=True,
    text=True,
    encoding="utf-8",
    errors="replace",
    check=False,
  )
  confs = []
  for i, line in enumerate((r2.stdout or "").splitlines()):
    if i == 0:
      continue
    cols = line.split("\t")
    if len(cols) >= 12 and cols[0] == "5" and cols[11].strip():
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
    "has_21": ("२१" in text) or ("21" in text),
    "has_24": ("२४" in text) or ("24" in text),
    "has_2103": bool(
      ("२१/०३" in text)
      or ("२१०३" in text)
      or ("21/03" in text)
      or ("2103" in text)
    ),
    "has_2403": bool(
      ("२४/०३" in text)
      or ("२४०३" in text)
      or ("24/03" in text)
      or ("2403" in text)
    ),
    "has_1999": ("१९९९" in text) or ("1999" in text),
  }


def crop_rel(img, x0, y0, x1, y1):
  h, w = img.shape[:2]
  return img[int(h * y0) : int(h * y1), int(w * x0) : int(w * x1)].copy()


def blue_mask_hsv(bgr):
  hsv = cv2.cvtColor(bgr, cv2.COLOR_BGR2HSV)
  m1 = cv2.inRange(hsv, np.array([85, 25, 30]), np.array([140, 255, 255]))
  m2 = cv2.inRange(hsv, np.array([90, 15, 70]), np.array([135, 255, 255]))
  mask = cv2.bitwise_or(m1, m2)
  k = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (3, 3))
  return cv2.morphologyEx(mask, cv2.MORPH_CLOSE, k, iterations=2)


def blue_mask_rgb(bgr):
  b, g, r = cv2.split(bgr)
  gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)
  mask = ((b.astype(np.int16) - r.astype(np.int16)) > 20) & (
    (b.astype(np.int16) - g.astype(np.int16)) > 12
  )
  mask = mask.astype(np.uint8) * 255
  mask[gray < 55] = 0
  k = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (3, 3))
  return cv2.morphologyEx(mask, cv2.MORPH_CLOSE, k, iterations=1)


def blue_mask_lab(bgr):
  lab = cv2.cvtColor(bgr, cv2.COLOR_BGR2LAB)
  L, a, b = cv2.split(lab)
  chroma = np.abs(a.astype(np.int16) - 128) + np.abs(b.astype(np.int16) - 128)
  mask = ((b < 120) & (L > 35) & (L < 235) & (chroma > 10)).astype(np.uint8) * 255
  k = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (3, 3))
  return cv2.morphologyEx(mask, cv2.MORPH_CLOSE, k, iterations=2)


def black_text_extract(bgr):
  hsv = cv2.cvtColor(bgr, cv2.COLOR_BGR2HSV)
  h, s, v = cv2.split(hsv)
  gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)
  ink = gray < 150
  blue = (h >= 85) & (h <= 140) & (s > 30) & (v > 35) & (v < 230)
  keep = ink & (~blue)
  out = np.full_like(gray, 255)
  out[keep] = gray[keep]
  return cv2.normalize(out, None, 0, 255, cv2.NORM_MINMAX)


def main() -> int:
  if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

  OUT.mkdir(parents=True, exist_ok=True)
  (OUT / "variants2").mkdir(exist_ok=True)
  (OUT / "crops2").mkdir(exist_ok=True)
  (OUT / "boxes2").mkdir(exist_ok=True)

  orig = cv2.imread(str(SRC))
  assert orig is not None
  h, w = orig.shape[:2]

  masks = {
    "hsv": blue_mask_hsv(orig),
    "rgb": blue_mask_rgb(orig),
    "lab": blue_mask_lab(orig),
  }
  combo = cv2.bitwise_or(masks["hsv"], masks["lab"])
  left = masks["hsv"].copy()
  left[:, int(w * 0.40) :] = 0

  def whitefill(im: np.ndarray, mask: np.ndarray) -> np.ndarray:
    o = im.copy()
    o[mask > 0] = (255, 255, 255)
    return o

  variants = {
    "original": orig,
    "hsv_inpaint": cv2.inpaint(orig, masks["hsv"], 3, cv2.INPAINT_TELEA),
    "hsv_whitefill": whitefill(orig, masks["hsv"]),
    "rgb_inpaint": cv2.inpaint(orig, masks["rgb"], 3, cv2.INPAINT_TELEA),
    "lab_inpaint": cv2.inpaint(orig, masks["lab"], 3, cv2.INPAINT_TELEA),
    "hsv_lab_inpaint": cv2.inpaint(orig, combo, 3, cv2.INPAINT_TELEA),
    "hsv_left_inpaint": cv2.inpaint(orig, left, 3, cv2.INPAINT_TELEA),
    "hsv_left_whitefill": whitefill(orig, left),
    "black_text_gray": black_text_extract(orig),
  }

  for name, mask in masks.items():
    cv2.imwrite(str(OUT / "variants2" / f"mask_{name}.png"), mask)
  cv2.imwrite(str(OUT / "variants2" / "mask_combo.png"), combo)
  cv2.imwrite(str(OUT / "variants2" / "mask_hsv_left.png"), left)

  rows = []
  for name, img in variants.items():
    if img.ndim == 2:
      bgr = cv2.cvtColor(img, cv2.COLOR_GRAY2BGR)
      cv2.imwrite(str(OUT / "variants2" / f"{name}.png"), img)
    else:
      bgr = img
      cv2.imwrite(str(OUT / "variants2" / f"{name}.png"), img)

    band = crop_rel(bgr, 0.0, DOB_BAND[0], 1.0, DOB_BAND[1])
    tight = crop_rel(bgr, *DOB_TIGHT)
    band_p = OUT / "crops2" / f"{name}_dob_band.png"
    tight_p = OUT / "crops2" / f"{name}_dob_tight.png"
    x4_p = OUT / "crops2" / f"{name}_dob_tight_x4.png"
    cv2.imwrite(str(band_p), band)
    cv2.imwrite(str(tight_p), tight)
    cv2.imwrite(str(x4_p), cv2.resize(tight, None, fx=4, fy=4, interpolation=cv2.INTER_NEAREST))

    for tname, tpath in (("full", OUT / "variants2" / f"{name}.png"), ("dob_band", band_p), ("dob_tight", tight_p)):
      # full path for gray variants
      if tname == "full" and img.ndim == 2:
        tpath = OUT / "variants2" / f"{name}.png"
      for psm in (6, 4, 11, 7):
        rec = tess(tpath, psm=psm)
        row = {"variant": name, "target": tname, "path": str(tpath), **rec}
        rows.append(row)
        snip = " ".join((rec["raw_text"] or "").split())[:100]
        print(
          f"{name}/{tname} psm={psm} 2103={rec['has_2103']} 2403={rec['has_2403']} "
          f"21={rec['has_21']} 24={rec['has_24']} 1999={rec['has_1999']} "
          f"conf={rec['confidence']} ms={rec['duration_ms']} snip={snip!r}",
          flush=True,
        )

  # Boxes on original vs watermark-removed tight x4
  for name in ("original", "hsv_left_inpaint", "hsv_lab_inpaint", "lab_inpaint", "black_text_gray"):
    p = OUT / "crops2" / f"{name}_dob_tight_x4.png"
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
    im = cv2.imread(str(p))
    for i, line in enumerate((r2.stdout or "").splitlines()):
      if i == 0:
        continue
      cols = line.split("\t")
      if len(cols) < 12 or cols[0] != "5" or not cols[11].strip():
        continue
      left_, top, width, height = map(int, cols[6:10])
      cv2.rectangle(im, (left_, top), (left_ + width, top + height), (0, 0, 255), 2)
      cv2.putText(
        im,
        cols[11][:16],
        (left_, max(14, top - 3)),
        cv2.FONT_HERSHEY_SIMPLEX,
        0.45,
        (0, 140, 0),
        1,
        cv2.LINE_AA,
      )
    cv2.imwrite(str(OUT / "boxes2" / f"{name}_tight_x4_boxes.png"), im)

  repro = [
    r
    for r in rows
    if r["has_2103"]
    and r["has_1999"]
    and not r["has_2403"]
    and not r["has_24"]
  ]
  still24 = [r for r in rows if r["has_2403"] or (r["has_24"] and r["has_1999"])]
  summary = {
    "loop": 31,
    "pass": "dob_band_corrected",
    "dob_band": DOB_BAND,
    "dob_tight": DOB_TIGHT,
    "repro_21_03_1999_count": len(repro),
    "still_24_with_1999_count": len(still24),
    "repro_samples": repro[:15],
    "still24_samples": [
      {
        "variant": r["variant"],
        "target": r["target"],
        "psm": r["psm"],
        "raw_snip": " ".join((r["raw_text"] or "").split())[:120],
        "confidence": r["confidence"],
      }
      for r in still24[:20]
    ],
    "rows": rows,
  }
  outp = OUT / "loop31_watermark_dob_corrected.json"
  outp.write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
  print("WROTE", outp)
  print("REPRO_21_03_1999", len(repro), "STILL24", len(still24))
  return 0


if __name__ == "__main__":
  raise SystemExit(main())
