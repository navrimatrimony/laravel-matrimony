================================================================================
  NudeNet API — simple start (Windows)
================================================================================

WHAT WAS WRONG BEFORE
---------------------
  main.py used a FAKE answer: always safe=true. That is NOT a path problem.
  Moving the folder only stopped the program if you did not start it again.

STEP 1 — Open PowerShell in THIS folder
-----------------------------------------
  cd E:\LaravelProjects\laravel-matrimony\nudnet-api

STEP 2 — One-time: create virtual environment + install packages
-----------------------------------------------------------------
  python -m venv .venv
  .\.venv\Scripts\Activate.ps1
  pip install -r requirements.txt

  (First install can take several minutes — downloads ML model.)

STEP 3 — Run the server (port 8001 — same as Laravel .env)
-----------------------------------------------------------
  uvicorn main:app --host 127.0.0.1 --port 8001

  Leave this window OPEN while you use the matrimony site.

STEP 4 — Test in browser
--------------------------
  Open: http://127.0.0.1:8001/docs
  Try POST /detect with an image file.

OPTIONAL — Laravel threshold sync (defaults apply if Laravel is down)
-----------------------------------------------------------------------
  $env:MODERATION_LARAVEL_CONFIG_URL="http://127.0.0.1:8000/api/moderation-config"
  $env:MODERATION_CONFIG_REFRESH_SEC="300"
  $env:MODERATION_CONFIG_TRAFFIC_DEBOUNCE_SEC="15"

  GET http://127.0.0.1:8001/config-status — current nsfw/review mins, last_sync, source.

OPTIONAL — stricter / looser nudity threshold (0.0 to 1.0)
------------------------------------------------------------
  $env:NUDENET_THRESHOLD="0.35"
  uvicorn main:app --host 127.0.0.1 --port 8001

================================================================================
