# DocTR OCR — Sprint 2 benchmark only

Not wired to production intake.

## Setup (Windows short path recommended)

```bat
py -3.12 -m venv C:\dov
C:\dov\Scripts\python -m pip install -r requirements.txt
C:\dov\Scripts\python run_ocr.py --image E:\path\to\image.jpg
```

Laravel (optional CLI):

```env
OCR_ENSEMBLE_DOCTR_PYTHON=C:\dov\Scripts\python.exe
OCR_ENSEMBLE_DOCTR_CLI_RUNNER=E:\LaravelProjects\laravel-matrimony\tools\ocr-ensemble-doctr-sidecar\run_ocr.py
```
