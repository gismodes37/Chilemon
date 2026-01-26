from __future__ import annotations

import os
from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates

from app.core.status import get_system_status


# ----------------------------------------------------------------------------
# Paths (portable: works anywhere the repo is cloned)
# ----------------------------------------------------------------------------
BASE_DIR = Path(__file__).resolve().parent          # e.g. /opt/chilemon/backend
APP_DIR = BASE_DIR / "app"                         # e.g. /opt/chilemon/backend/app
TEMPLATES_DIR = APP_DIR / "templates"              # e.g. /opt/chilemon/backend/app/templates
STATIC_DIR = APP_DIR / "static"                    # e.g. /opt/chilemon/backend/app/static


# ----------------------------------------------------------------------------
# Reverse-proxy / subpath support
#
# Default: runs at /
# Optional: runs behind Apache at /chilemon via either:
#   - Env var: CHILEMON_ROOT_PATH=/chilemon
#   - Or header: X-Forwarded-Prefix: /chilemon (recommended)
#
# This ensures url_for('static', ...) becomes /chilemon/static/... when needed,
# without hardcoding /chilemon in templates.
# ----------------------------------------------------------------------------
def _normalize_prefix(p: str) -> str:
    p = (p or "").strip()
    if not p:
        return ""
    return "/" + p.strip("/")


ROOT_PATH_ENV = _normalize_prefix(os.getenv("CHILEMON_ROOT_PATH", ""))

app = FastAPI(
    title="ChileMon",
    root_path=ROOT_PATH_ENV,
)


@app.middleware("http")
async def forwarded_prefix_middleware(request: Request, call_next):
    xf_prefix = request.headers.get("x-forwarded-prefix") or request.headers.get("X-Forwarded-Prefix")
    if xf_prefix:
        request.scope["root_path"] = _normalize_prefix(xf_prefix)
    return await call_next(request)


# ----------------------------------------------------------------------------
# Static & Templates
# ----------------------------------------------------------------------------
app.mount("/static", StaticFiles(directory=str(STATIC_DIR)), name="static")
templates = Jinja2Templates(directory=str(TEMPLATES_DIR))


# ----------------------------------------------------------------------------
# Pages
# ----------------------------------------------------------------------------
@app.get("/", response_class=HTMLResponse, name="dashboard")
async def dashboard(request: Request):
    # dashboard.html extends base.html (your current structure)
    return templates.TemplateResponse("dashboard.html", {"request": request, "title": "ChileMon"})


@app.get("/nodes", response_class=HTMLResponse, name="nodes")
async def nodes(request: Request):
    return templates.TemplateResponse("nodes.html", {"request": request, "title": "Nodos"})


@app.get("/settings", response_class=HTMLResponse, name="settings")
async def settings(request: Request):
    return templates.TemplateResponse("settings.html", {"request": request, "title": "Ajustes"})


# ----------------------------------------------------------------------------
# API
# ----------------------------------------------------------------------------
@app.get("/status", name="status")
async def status():
    return get_system_status("chilemon")
