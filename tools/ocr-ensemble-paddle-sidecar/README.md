# PaddleOCR benchmark sidecar (Phase 2 Stage B)

Benchmark-only service. **Not** wired into production bulk intake until go/no-go.

## Requirements

- Python **3.10–3.12** (PaddlePaddle wheels are not available for 3.14+ on all platforms)
- Linux recommended (production server path)

## Install (Linux server)

Pinned versions (see `requirements.txt`):

| Package | Version | Notes |
|---------|---------|-------|
| Python | 3.10–3.12 | Server currently uses 3.12 |
| paddleocr | 3.7.0 | Requires PaddlePaddle >= 3.0 |
| paddlepaddle | **3.2.2** | Avoid 3.3.x CPU oneDNN/PIR crash |

```bash
cd tools/ocr-ensemble-paddle-sidecar
PYTHON_BIN=python3.12 bash install.sh
source .venv/bin/activate
```

First run downloads Hindi/Devanagari OCR models (~100MB).

### Reinstall after compatibility fix

If OCR fails with `ConvertPirAttribute2RuntimeAttribute` / `onednn_instruction.cc`:

```bash
cd tools/ocr-ensemble-paddle-sidecar
source .venv/bin/activate
python -m pip install "paddlepaddle==3.2.2" \
  -i https://www.paddlepaddle.org.cn/packages/stable/cpu/
python -m pip install -r requirements.txt --upgrade
python -c "import paddle, paddleocr; print(paddle.__version__, paddleocr.__version__)"
```

## Verify standalone OCR (before Laravel)

```bash
source .venv/bin/activate
python -c "import paddle, paddleocr; print('python ok', paddle.__version__, paddleocr.__version__)"
python run_ocr.py --image /absolute/path/to/one/biodata/image.jpg
```

Expected: JSON with `"text": "..."` and `"line_count" > 0` for a readable biodata image.

## Run HTTP sidecar

```bash
source .venv/bin/activate
uvicorn server:app --host 127.0.0.1 --port 18080
```

Health check:

```bash
curl http://127.0.0.1:18080/health
```

## CLI (single image)

```bash
python run_ocr.py --image /absolute/path/to/preprocessed.png
```

## Laravel benchmark

Set in `.env`:

```env
OCR_ENSEMBLE_PADDLE_SIDECAR_URL=http://127.0.0.1:18080
```

Then:

```bash
php artisan ocr-ensemble:benchmark-run 43 --engine=paddleocr_v1 --stage=B --baseline=68.75
```

This command:

1. Preprocesses each Batch image with the same Phase 1 preset (`photo_capture`)
2. Sends the preprocessed image to PaddleOCR
3. Scores OCR text through the **frozen** benchmark field extractor
4. Writes the same JSON + CSV report format as `ocr-ensemble:benchmark-score`
5. Prints uplift vs the Phase 1 baseline (68.75%)

## Notes

- Production `intake_ocr_ensemble_enabled` flag stays **OFF**
- Parser and production OCR pipeline are untouched
- Benchmark extractor is frozen for engine comparison
