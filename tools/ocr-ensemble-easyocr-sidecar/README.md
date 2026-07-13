# EasyOCR benchmark sidecar (Phase 2 Workstream A)

Benchmark-only service. **Not** wired into production bulk intake.

## Install (Linux server)

**Important:** `install.sh` installs **CPU-only** PyTorch first. A plain `pip install easyocr` alone may pull CUDA torch and get OOM-killed on small VPS hosts.

```bash
cd tools/ocr-ensemble-easyocr-sidecar
PYTHON_BIN=python3.12 bash install.sh
source .venv/bin/activate
python verify_torch.py
```

Expected:

```
cuda_built=False
cuda_available=False
torch_cpu_ok=true
```

### Reinstall after OOM / "Killed"

```bash
cd tools/ocr-ensemble-easyocr-sidecar
source .venv/bin/activate
python -m pip uninstall -y torch torchvision torchaudio
python -m pip install torch torchvision --index-url https://download.pytorch.org/whl/cpu
python -m pip install -r requirements.txt --upgrade
python verify_torch.py
```

Optional lower-memory env vars before OCR:

```bash
export EASYOCR_MAX_IMAGE_SIDE=1400
export EASYOCR_CANVAS_SIZE=1024
export OMP_NUM_THREADS=1
```

First run downloads Hindi OCR models.

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
