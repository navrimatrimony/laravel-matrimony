#!/usr/bin/env python3
"""PaddleOCR HTTP sidecar for OCR Ensemble Phase 2 benchmark."""

from __future__ import annotations

import base64
import tempfile
from pathlib import Path

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

from ocr_engine import extract_text_from_image

app = FastAPI(title="OCR Ensemble Paddle Sidecar", version="1.0.0")


class OcrRequest(BaseModel):
  image_path: str | None = Field(default=None, description="Absolute path readable by sidecar process")
  image_base64: str | None = Field(default=None, description="Base64-encoded image bytes")
  preprocessing_version: str | None = None
  language_hint: str | None = None


@app.get("/health")
def health() -> dict[str, str]:
  return {"status": "ok", "engine": "paddleocr_v1"}


@app.post("/ocr")
def ocr(request: OcrRequest) -> dict:
  temp_path: Path | None = None

  try:
    if request.image_path:
      image_path = Path(request.image_path)
      if not image_path.is_file():
        raise HTTPException(status_code=400, detail=f"image_path not found: {request.image_path}")
      return extract_text_from_image(str(image_path))

    if request.image_base64:
      raw = base64.b64decode(request.image_base64, validate=True)
      with tempfile.NamedTemporaryFile(delete=False, suffix=".png") as handle:
        handle.write(raw)
        temp_path = Path(handle.name)
      return extract_text_from_image(str(temp_path))

    raise HTTPException(status_code=400, detail="image_path or image_base64 is required")
  except HTTPException:
    raise
  except Exception as exc:  # noqa: BLE001 - sidecar must return structured failure upstream
    raise HTTPException(status_code=500, detail=str(exc)) from exc
  finally:
    if temp_path is not None and temp_path.exists():
      temp_path.unlink(missing_ok=True)
