#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

PYTHON_BIN="${PYTHON_BIN:-python3.12}"
if ! command -v "$PYTHON_BIN" >/dev/null 2>&1; then
  PYTHON_BIN="${PYTHON_BIN_FALLBACK:-python3}"
fi

if [ ! -d .venv ]; then
  "$PYTHON_BIN" -m venv .venv
fi

source .venv/bin/activate
python -m pip install --upgrade pip
python -m pip install -r requirements.txt

python - <<'PY'
import sys

import easyocr

print(f"python={sys.version.split()[0]}")
print(f"easyocr={easyocr.__version__}")
PY

echo "EasyOCR sidecar installed."
echo "Verify OCR: python run_ocr.py --image /absolute/path/to/image.png"
echo "Start sidecar: source .venv/bin/activate && uvicorn server:app --host 127.0.0.1 --port 18081"
