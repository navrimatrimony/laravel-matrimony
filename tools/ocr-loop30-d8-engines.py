#!/usr/bin/env python3
"""Loop 30 — Multi-engine OCR on D(8) DOB crops (EasyOCR / Paddle / DocTR).

Records RAW text, confidence, duration. No invent / no voting.

Usage:
  C:\\eov\\Scripts\\python.exe tools/ocr-loop30-d8-engines.py
"""

from __future__ import annotations

import json
import os
import subprocess
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "storage" / "app" / "private" / "ocr-temp" / "d8-loop30"
CROPS = OUT / "crops"
GLYPH = OUT / "glyph"
BOXES = OUT / "boxes"
ORIG = ROOT / "storage" / "app" / "ocr-dev-batches" / "Batch-001" / "D (8).jpeg"

EOV = Path(r"C:\eov\Scripts\python.exe")
POV = Path(r"C:\pov\Scripts\python.exe")
DOV = Path(r"C:\dov\Scripts\python.exe")

EASYOCR_DIR = ROOT / "tools" / "ocr-ensemble-easyocr-sidecar"
PADDLE_DIR = ROOT / "tools" / "ocr-ensemble-paddle-sidecar"
DOCTR_DIR = ROOT / "tools" / "ocr-ensemble-doctr-sidecar"


def run_cli(python: Path, cwd: Path, script: str, image: Path) -> dict:
  if not python.is_file():
    return {"error": f"python missing: {python}"}
  if not image.is_file():
    return {"error": f"image missing: {image}"}
  started = time.perf_counter()
  try:
    proc = subprocess.run(
      [str(python), script, "--image", str(image.resolve())],
      cwd=str(cwd),
      capture_output=True,
      text=True,
      encoding="utf-8",
      errors="replace",
      timeout=600,
      check=False,
    )
  except Exception as exc:  # noqa: BLE001
    return {"error": str(exc), "duration_ms": int((time.perf_counter() - started) * 1000)}

  wall_ms = int((time.perf_counter() - started) * 1000)
  stdout = (proc.stdout or "").strip()
  stderr = (proc.stderr or "").strip()
  if proc.returncode != 0:
    return {
      "error": stderr or stdout or f"exit {proc.returncode}",
      "duration_ms": wall_ms,
      "returncode": proc.returncode,
    }
  try:
    payload = json.loads(stdout)
  except json.JSONDecodeError:
    return {"error": "non-json stdout", "stdout": stdout[:500], "stderr": stderr[:500], "duration_ms": wall_ms}
  if isinstance(payload, dict) and "duration_ms" not in payload:
    payload["duration_ms"] = wall_ms
  payload["wall_ms"] = wall_ms
  return payload


def easyocr_detail_boxes(image: Path, out_png: Path) -> dict:
  """Run EasyOCR with detail boxes + draw overlays (glyph investigation)."""
  sys.path.insert(0, str(EASYOCR_DIR))
  os.environ.setdefault("OMP_NUM_THREADS", "1")
  try:
    import easyocr  # type: ignore
    from PIL import Image, ImageDraw  # type: ignore
  except Exception as exc:  # noqa: BLE001
    return {"error": f"easyocr import failed: {exc}"}

  started = time.perf_counter()
  reader = easyocr.Reader(["hi"], gpu=False, quantize=True, verbose=False)
  blocks = reader.readtext(str(image.resolve()), detail=1, paragraph=False)
  duration_ms = int((time.perf_counter() - started) * 1000)

  lines = []
  confs = []
  drawn = []
  img = Image.open(image).convert("RGB")
  draw = ImageDraw.Draw(img)
  for bbox, text, conf in blocks:
    t = (text or "").strip()
    if not t:
      continue
    lines.append(t)
    if isinstance(conf, (int, float)):
      confs.append(float(conf))
    pts = [(float(p[0]), float(p[1])) for p in bbox]
    draw.polygon(pts, outline=(255, 0, 0))
    drawn.append({"text": t, "conf": conf, "bbox": pts})

  BOXES.mkdir(parents=True, exist_ok=True)
  img.save(out_png)

  return {
    "engine": "easyocr_detail",
    "raw_text": "\n".join(lines),
    "confidence": round(sum(confs) / len(confs), 4) if confs else None,
    "duration_ms": duration_ms,
    "blocks": drawn,
    "boxes_path": str(out_png),
  }


def main() -> int:
  if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

  OUT.mkdir(parents=True, exist_ok=True)
  tight = CROPS / "dob_line_tight.png"
  targets = []
  if tight.is_file():
    targets.append(("dob_line_tight", tight))
  else:
    print("WARN: tight crop missing; run php tools/ocr-loop30-d8-exhaustive.php first", file=sys.stderr)

  for name in [
    "full_page.png",
    "dob_line.png",
    "date_token.png",
    "day_digits.png",
    "month_digits.png",
    "year_only.png",
  ]:
    p = OUT / "seg" / name
    if p.is_file():
      targets.append((f"seg_{name.replace('.png', '')}", p))

  for z in (2, 4, 8):
    p = GLYPH / f"dob_line_x{z}.png"
    if p.is_file():
      targets.append((f"glyph_x{z}", p))

  if ORIG.is_file():
    targets.insert(0, ("original_full", ORIG))

  results: dict = {"loop": 30, "A_engines": [], "B_easyocr_boxes": None, "F_ensemble": []}

  engines = [
    ("easyocr", EOV, EASYOCR_DIR, "run_ocr.py"),
    ("paddle", POV, PADDLE_DIR, "run_ocr.py"),
    ("doctr", DOV, DOCTR_DIR, "run_ocr.py"),
  ]

  # Prefer tight crop for primary A; also original full
  primary = [t for t in targets if t[0] in ("dob_line_tight", "original_full", "seg_dob_line", "seg_date_token", "seg_day_digits")]
  if not primary:
    primary = targets[:1]

  for label, path in primary:
    for eng_name, py, cwd, script in engines:
      print(f"RUN {eng_name} on {label} ...", flush=True)
      payload = run_cli(py, cwd, script, path)
      row = {
        "engine": eng_name,
        "target": label,
        "path": str(path),
        "raw_text": payload.get("text") if isinstance(payload, dict) else None,
        "confidence": None,
        "duration_ms": payload.get("duration_ms") if isinstance(payload, dict) else None,
        "wall_ms": payload.get("wall_ms") if isinstance(payload, dict) else None,
        "error": payload.get("error") if isinstance(payload, dict) else None,
        "engine_meta": payload.get("engine_meta") if isinstance(payload, dict) else None,
      }
      if isinstance(payload, dict):
        meta = payload.get("engine_meta") or {}
        if isinstance(meta, dict) and meta.get("avg_confidence") is not None:
          row["confidence"] = meta.get("avg_confidence")
        # paddle may expose rec_scores in future; keep null if absent
      results["A_engines"].append(row)
      snip = (row["raw_text"] or row["error"] or "")[:120]
      print(f"  -> conf={row['confidence']} ms={row['duration_ms']} snip={snip!r}", flush=True)

  # Glyph boxes via EasyOCR on tight + x4
  if tight.is_file() and EOV.is_file():
    # Must run under eov python — re-exec detail helper via subprocess inline
    detail_script = OUT / "_easyocr_boxes_once.py"
    detail_script.write_text(
      f"""
import json, sys
sys.path.insert(0, r"{EASYOCR_DIR.as_posix()}")
from pathlib import Path
import easyocr
from PIL import Image, ImageDraw
import time
image = Path(sys.argv[1])
out_png = Path(sys.argv[2])
started = time.perf_counter()
reader = easyocr.Reader(["hi"], gpu=False, quantize=True, verbose=False)
blocks = reader.readtext(str(image), detail=1, paragraph=False)
img = Image.open(image).convert("RGB")
draw = ImageDraw.Draw(img)
drawn = []
lines = []
confs = []
for bbox, text, conf in blocks:
  t = (text or "").strip()
  if not t:
    continue
  lines.append(t)
  if isinstance(conf, (int, float)):
    confs.append(float(conf))
  pts = [(float(p[0]), float(p[1])) for p in bbox]
  draw.line(pts + [pts[0]], fill=(255, 0, 0), width=2)
  # also mark each corner
  for p in pts:
    r = 2
    draw.ellipse((p[0]-r, p[1]-r, p[0]+r, p[1]+r), outline=(0, 255, 0))
  drawn.append({{"text": t, "conf": float(conf) if isinstance(conf, (int, float)) else None, "bbox": pts}})
img.save(out_png)
print(json.dumps({{
  "engine": "easyocr_detail",
  "raw_text": "\\n".join(lines),
  "confidence": round(sum(confs)/len(confs), 4) if confs else None,
  "duration_ms": int((time.perf_counter()-started)*1000),
  "blocks": drawn,
  "boxes_path": str(out_png),
}}, ensure_ascii=False))
""",
      encoding="utf-8",
    )
    for label, img_path, out_name in [
      ("tight", tight, "easyocr_tight_boxes.png"),
      ("x4", GLYPH / "dob_line_x4.png", "easyocr_x4_boxes.png"),
      ("day_x4", GLYPH / "day_digits_x4.png", "easyocr_day_x4_boxes.png"),
    ]:
      if not img_path.is_file():
        continue
      out_png = BOXES / out_name
      print(f"BOXES easyocr {label} ...", flush=True)
      proc = subprocess.run(
        [str(EOV), str(detail_script), str(img_path), str(out_png)],
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        timeout=600,
        check=False,
      )
      if proc.returncode == 0 and proc.stdout.strip():
        try:
          payload = json.loads(proc.stdout.strip().splitlines()[-1])
          results.setdefault("B_easyocr_boxes_list", []).append({"target": label, **payload})
        except json.JSONDecodeError:
          results.setdefault("B_easyocr_boxes_list", []).append({"target": label, "error": proc.stdout[:300]})
      else:
        results.setdefault("B_easyocr_boxes_list", []).append(
          {"target": label, "error": (proc.stderr or proc.stdout or "")[:500]}
        )

  # Ensemble summary on tight crop only
  tight_rows = [r for r in results["A_engines"] if r.get("target") == "dob_line_tight"]
  for r in tight_rows:
    text = r.get("raw_text") or ""
    day24 = ("२४" in text) or ("24" in text)
    day21 = ("२१" in text) or ("21" in text)
    results["F_ensemble"].append(
      {
        "engine": r.get("engine"),
        "raw_text": text,
        "confidence": r.get("confidence"),
        "reads_24": day24,
        "reads_21": day21,
        "error": r.get("error"),
      }
    )

  out_json = OUT / "loop30_evidence_engines.json"
  out_json.write_text(json.dumps(results, ensure_ascii=False, indent=2), encoding="utf-8")
  print(f"WROTE {out_json}")
  return 0


if __name__ == "__main__":
  raise SystemExit(main())
