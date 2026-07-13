# EasyOCR benchmark sidecar (Phase 2 Workstream A)

Benchmark-only service. **Not** wired into production bulk intake.

## Install (Linux server)

```bash
cd tools/ocr-ensemble-easyocr-sidecar
PYTHON_BIN=python3.12 bash install.sh
source .venv/bin/activate
```

First run downloads Hindi/English OCR models.

## Verify standalone OCR

```bash
source .venv/bin/activate
python run_ocr.py --image /absolute/path/to/biodata/image.jpg
```

## Run HTTP sidecar

```bash
source .venv/bin/activate
uvicorn server:app --host 127.0.0.1 --port 18081
```

Health check:

```bash
curl http://127.0.0.1:18081/health
```

## Laravel benchmark

Set `.env`:

```env
OCR_ENSEMBLE_EASYOCR_SIDECAR_URL=http://127.0.0.1:18081
OCR_ENSEMBLE_EASYOCR_PYTHON=/home/navri/htdocs/navrimilenavryala.com/tools/ocr-ensemble-easyocr-sidecar/.venv/bin/python
```

Run:

```bash
php artisan ocr-ensemble:benchmark-run 43 --engine=easyocr_v1 --stage=B --baseline=68.75
```

Go threshold on this batch: **73.75%** critical accuracy (+5pp vs 68.75% baseline).

## Notes

- Uses the same frozen benchmark field extractor and scorer as Tesseract baseline.
- Production OCR pipeline and parser remain untouched.
- `intake_ocr_ensemble_enabled` stays **OFF**.
